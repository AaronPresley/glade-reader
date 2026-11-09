<?php

use App\Domain\Entry\Enums\EntryStatus;
use App\Domain\Entry\Jobs\GenerateArticleEntryJob;
use App\Domain\Entry\Models\Entry;
use App\Domain\SourceReference\Enums\SourceReferenceStatus;
use App\Domain\SourceReference\Models\SourceReference;
use App\Domain\User\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
    Storage::fake('local');
    config([
        'filesystems.default' => 'local',
        'services.apify.api_token' => 'fake-apify-token',
        'services.open_router.api_key' => 'fake-openrouter-token',
        'services.open_router.model' => 'fixture-model',
    ]);
    // All Guzzle traffic is blocked unless a test supplies queued responses.
    $this->app->bind(Client::class, fn ($app, $parameters) => new Client([
        ...$parameters['config'],
        'handler' => HandlerStack::create(new MockHandler),
    ]));
    $this->actingAs(User::factory()->create());
});

function siteReadingJson(mixed $data, int $status = 200): Response
{
    return new Response($status, ['Content-Type' => 'application/json'], json_encode($data));
}

function siteReadingScreenshotResponses(): array
{
    return [
        siteReadingJson(['data' => ['id' => 'screenshot-run']]),
        siteReadingJson(['data' => ['status' => 'SUCCEEDED', 'defaultDatasetId' => 'screenshots', 'defaultKeyValueStoreId' => 'images']]),
        siteReadingJson([['screenshotKey' => 'screenshot.jpg']]),
        new Response(200, ['Content-Type' => 'image/jpeg'], 'fixture-screenshot'),
    ];
}

function siteReadingHtmlResponses(string $html = '<article><h1>Linked article</h1><p>Publisher body, not comments.</p><p><a href="../next">Next</a></p><img alt="Diagram" src="/images/chart.svg"></article>'): array
{
    return [
        siteReadingJson(['data' => ['id' => 'html-run']]),
        siteReadingJson(['data' => ['status' => 'SUCCEEDED', 'defaultDatasetId' => 'html']]),
        siteReadingJson([['fullHtml' => $html]]),
    ];
}

function siteReadingPlanResponse(?array $objects = null): Response
{
    return siteReadingJson([
        'choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode([
            'version' => 1,
            'objects' => $objects ?? [['type' => 'article', 'patterns' => ['~<article\b[^>]*>(?<content>.*?)</article>~is']]],
        ])]]],
        'model' => 'fixture-model',
        'usage' => ['total_tokens' => 42],
    ]);
}

function fakeSiteReadingProviders(array $responses, array &$history): void
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    app()->bind(Client::class, fn ($app, $parameters) => new Client([
        ...$parameters['config'],
        'handler' => $stack,
    ]));
}

it('processes a saved URL through real jobs and provider adapters without network calls', function (string $url, string $profile): void {
    config(['queue.default' => 'database']);
    $target = $profile === 'hacker-news' ? 'https://publisher.example.com/writing/article' : $url;
    Http::fake(['https://hacker-news.firebaseio.com/v0/item/50399933.json' => Http::response([
        'type' => 'story', 'title' => 'Linked article', 'url' => $target,
    ])]);
    $history = [];
    fakeSiteReadingProviders([
        ...siteReadingScreenshotResponses(), ...siteReadingHtmlResponses(), siteReadingPlanResponse(),
    ], $history);

    $this->post('/source-references', ['url' => $url])->assertRedirect('/entries');
    Artisan::call('queue:work', ['--stop-when-empty' => true, '--tries' => 1, '--sleep' => 0]);

    $source = SourceReference::firstOrFail();
    $entry = Entry::firstOrFail();
    expect($source->url)->toBe($url)
        ->and($source->content_url)->toBe($target)
        ->and($source->status)->toBe(SourceReferenceStatus::Completed)
        ->and($source->metadata['reading'])->toMatchArray(['profile' => $profile, 'flow' => 'article', 'layout' => $profile])
        ->and($source->raw_content)->toContain('Publisher body, not comments.')
        ->and($entry->status)->toBe(EntryStatus::Completed)
        ->and($entry->content)->toContain('# Linked article', '[Next](../next)')
        ->and($entry->metadata['reading']['content_url'])->toBe($target)
        ->and($entry->metadata['generation']['usage']['total_tokens'])->toBe(42);
    Storage::assertExists($source->screenshot_path);

    // These are the public provider payloads: both captures and extraction must use the article.
    $screenshotInput = json_decode((string) $history[0]['request']->getBody(), true);
    $htmlInput = json_decode((string) $history[4]['request']->getBody(), true);
    $generationInput = json_decode((string) $history[7]['request']->getBody(), true);
    expect($screenshotInput['startUrls'][0]['url'])->toBe($target)
        ->and($htmlInput['requestListSources'][0]['url'])->toBe($target)
        ->and($generationInput['messages'][1]['content'][0]['text'])->toContain('Source URL: '.$target, 'Source outline (JSON):');

    $this->get('/entries/'.$entry->id)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('entries/read')
        ->where('entry.reading.layout', $profile)
        ->where('entry.reading.content_url', $target)
        ->where('entry.source_reference.url', $url));
    $this->get('/source-references/'.$source->id)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('sourceReference.url', $url)->where('sourceReference.content_url', $target));

    if ($profile === 'default') {
        Http::assertNothingSent();
    } else {
        Http::assertSentCount(1);
    }
})->with([
    'default' => ['https://publisher.example.com/writing/article?lang=en', 'default'],
    'HN' => ['https://news.ycombinator.com/item?id=50399933', 'hacker-news'],
]);

it('extracts long paragraphs and code across bounded chunks without duplicating or breaking source text', function (): void {
    config(['queue.default' => 'database']);
    $opening = "<article data-label='A > B &amp; café'>";
    $html = '<script>const fake = "<article>Script-only fake article</article>";'.str_repeat('script-payload ', 10000).'</script>'
        .'<style>/* Style-only payload */</style><!-- <div>Comment-only fake region</div> -->'
        .$opening.'<h2>Opening section</h2><p>'.str_repeat('Full article text. café 🚲 ', 5000)
        .'Unsent middle wording.'.str_repeat('Full article text. café 🚲 ', 5000).'</p>'
        .'<pre><code class="language-php">'.str_repeat("    echo \"chunked\";\n", 6000).'</code></pre>'
        .'<div data-region="tail"><h2>Last section</h2><p>Final paragraph beyond the old limit.</p></div></article>';
    $history = [];
    fakeSiteReadingProviders([
        ...siteReadingScreenshotResponses(), ...siteReadingHtmlResponses($html),
        ...array_fill(0, 30, siteReadingPlanResponse([['type' => 'article', 'patterns' => ['~\A(?<content>[\s\S]+)\z~']]])),
    ], $history);

    $this->post('/source-references', ['url' => 'https://publisher.example.com/long-article'])->assertRedirect();
    Artisan::call('queue:work', ['--stop-when-empty' => true, '--tries' => 1, '--sleep' => 0]);

    $prompt = json_decode((string) $history[7]['request']->getBody(), true)['messages'][1]['content'][0]['text'];
    $outline = json_decode(explode("Source outline (JSON):\n", $prompt, 2)[1], true, 512, JSON_THROW_ON_ERROR);
    $article = collect($outline)->firstWhere('tag', 'article');
    expect(strlen($prompt))->toBeLessThan(strlen($html) / 10)
        ->and($prompt)->not->toContain('Unsent middle wording.', 'Script-only fake article', 'script-payload', 'Style-only payload', 'Comment-only fake region')
        ->and($article['start_snippet'])->toStartWith($opening)
        ->and(Entry::firstOrFail()->status)->toBe(EntryStatus::Completed)
        ->and(Entry::firstOrFail()->content)->toContain('Unsent middle wording.', '## Last section', 'Final paragraph beyond the old limit.')
        ->and(Entry::firstOrFail()->content)->not->toContain('Script-only fake article', 'Comment-only fake region')
        ->and(substr_count(Entry::firstOrFail()->content, 'Full article text.'))->toBe(10000)
        ->and(substr_count(Entry::firstOrFail()->content, 'café 🚲'))->toBe(10000)
        ->and(substr_count(Entry::firstOrFail()->content, '    echo "chunked";'))->toBe(6000)
        ->and(substr_count(Entry::firstOrFail()->content, '```php'))->toBe(1)
        ->and(Entry::firstOrFail()->metadata['generation']['usage']['total_tokens'])->toBe((count($history) - 7) * 42)
        ->and(Entry::firstOrFail()->metadata['generation']['raw_html_truncated'])->toBeFalse();

    $chunks = Entry::firstOrFail()->metadata['generation']['extraction']['plan']['chunks'];
    expect(count($chunks))->toBeGreaterThan(2);

    // Bounded requests and literal boundaries, even when source quoting/Unicode
    // differ from a DOM serializer and a single code block spans several calls.
    foreach (array_slice($history, 7) as $index => $request) {
        $input = json_decode((string) $request['request']->getBody(), true)['messages'][1]['content'];
        expect($input)->toHaveCount($index === 0 ? 2 : 1);
        $prompt = $input[0]['text'];
        $outline = explode("Source outline (JSON):\n", $prompt, 2)[1];
        expect(strlen($outline))->toBeLessThanOrEqual(60000);
        $boundary = json_decode(explode("\n", explode('Chunk boundary state (JSON): ', $prompt, 2)[1], 2)[0], true);
        expect($boundary)->toBe([
            'starts_inside_article' => $index > 0,
            'ends_inside_article' => $index < count($chunks) - 1,
        ]);

        if ($index > 0) {
            foreach (json_decode($outline, true) as $region) {
                // Earlier article wrappers are context, not tags in this source slice.
                expect($region['descendants'] ?? [])->not->toHaveKey('article');
            }
        }

        foreach (json_decode($outline, true) as $region) {
            foreach (['html', 'start_snippet', 'end_snippet'] as $key) {
                if (isset($region[$key]) && $region[$key] !== '') {
                    expect($html)->toContain($region[$key]);
                }
            }
        }
    }
});

it('generates an article from saved HTML and screenshot fixtures with a mocked regex plan', function (): void {
    $fixture = __DIR__.'/../Fixtures/portobello-clock';
    $html = file_get_contents($fixture.'.html.fixture');
    $screenshot = file_get_contents($fixture.'.jpg');
    Storage::put('fixture.jpg', $screenshot);
    $source = SourceReference::create([
        'user_id' => auth()->id(),
        'url' => 'https://pointinthecloud.com/2026-04-11-211700.html',
        'status' => SourceReferenceStatus::Completed,
        'raw_content' => $html,
        'screenshot_path' => 'fixture.jpg',
    ]);
    $history = [];
    fakeSiteReadingProviders([
        new Response(200, ['Content-Type' => 'application/json'], file_get_contents($fixture.'-openrouter.json')),
    ], $history);

    $this->post('/source-references/'.$source->id.'/entries/article')->assertRedirect();

    $entry = Entry::firstOrFail();
    expect($entry->status)->toBe(EntryStatus::Completed)
        ->and($entry->content)->toContain(
            'Fixing the Portobello Police Station Clock',
            '### Setting the clock',
            '### Setting the chime',
            '### It was fun',
            'Maybe I can go back and become more involved.',
            '[Porty Pride](https://portypride.com)',
            'media/PXL_20260411_150433725-6.jpg',
            '- Make it chime 13 at Halloween.',
        )
        ->and($entry->content)->not->toContain('Go to the beginning', 'Contact me', 'rosie-flying-dog.jpg')
        ->and(strpos($entry->content, '### Setting the clock'))->toBeLessThan(strpos($entry->content, '### Setting the chime'))
        ->and($entry->metadata['generation']['extraction']['source_sha256'])->toBe(hash('sha256', $html))
        ->and($entry->metadata['generation']['extraction']['plan']['chunks'][0]['plan']['objects'][0]['patterns'])->toHaveCount(3);

    // Only the planned OpenRouter request is mocked: a stray provider/capture call
    // exhausts the handler. Both captured inputs come from disk, never live services.
    expect($history)->toHaveCount(1);
    $payload = json_decode((string) $history[0]['request']->getBody(), true);
    $prompt = $payload['messages'][1]['content'][0]['text'];
    $outline = explode("Source outline (JSON):\n", $prompt, 2)[1];
    expect($prompt)->not->toContain($html)
        ->and(strlen($outline))->toBeLessThan(strlen($html))
        ->and($prompt)->toContain('Fixing the Portobello Police Station Clock', 'It was fun')
        ->and($payload['messages'][1]['content'][1]['image_url']['url'])->toBe('data:image/jpeg;base64,'.base64_encode($screenshot));
    Http::assertNothingSent();

    $this->get('/entries/'.$entry->id)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('entries/read')
        ->where('entry.content', $entry->content));

    $user = auth()->user();
    $this->withToken($user->createToken('Fixture reader', ['read'])->plainTextToken)
        ->getJson('/api/entries/'.$entry->id)->assertOk()
        ->assertJsonPath('data.content_html', fn (string $html): bool => str_contains($html, '<h3>It was fun</h3>')
            && str_contains($html, '<h1>Fixing the Portobello Police Station Clock</h1>')
            && substr_count($html, '<img ') === 9
            && ! str_contains($html, '### '));
});

it('processes dense markup through multiple bounded outlines instead of rejecting the whole page', function (): void {
    config(['queue.default' => 'database']);
    $html = '<article>'.str_repeat('<section><h2>Distinct section</h2><p>Article text.</p></section>', 1000).'<p>Still saved at the end.</p></article>';
    Storage::put('fixture.jpg', 'fixture-screenshot');
    $source = SourceReference::create([
        'user_id' => auth()->id(), 'url' => 'https://publisher.example.com/too-many-regions',
        'status' => SourceReferenceStatus::Completed, 'raw_content' => $html, 'screenshot_path' => 'fixture.jpg',
    ]);
    $history = [];
    fakeSiteReadingProviders(array_fill(0, 30, siteReadingPlanResponse([['type' => 'article', 'patterns' => ['~\A(?<content>[\s\S]+)\z~']]])), $history);

    $this->post('/source-references/'.$source->id.'/entries/article')->assertRedirect();
    Artisan::call('queue:work', ['--stop-when-empty' => true, '--tries' => 1, '--sleep' => 0]);

    $entry = Entry::firstOrFail();
    expect($entry->status)->toBe(EntryStatus::Completed)
        ->and(substr_count($entry->content, '## Distinct section'))->toBe(1000)
        ->and($entry->content)->toContain('Still saved at the end.')
        ->and($source->refresh()->raw_content)->toBe($html)
        ->and(count($history))->toBeGreaterThan(1);

    foreach ($history as $request) {
        $prompt = json_decode((string) $request['request']->getBody(), true)['messages'][1]['content'][0]['text'];
        expect(strlen(explode("Source outline (JSON):\n", $prompt, 2)[1]))->toBeLessThanOrEqual(60000);
    }

    Http::assertNothingSent();
});

it('preserves inline text across ignored markup without restoring unselected chunks', function (): void {
    config(['queue.default' => 'database']);
    Storage::put('fixture.jpg', 'fixture-screenshot');
    $source = SourceReference::create([
        'user_id' => auth()->id(), 'url' => 'https://publisher.example.com/inline-gaps',
        'status' => SourceReferenceStatus::Completed, 'screenshot_path' => 'fixture.jpg',
        'raw_content' => '<article><p>hello<!-- <article>fake</article> -->world<script>fake()</script>!</p>'
            .'<!-- boundary --><nav>Unselected navigation</nav><!-- boundary -->'
            .'<p>tail</p></article><footer>Unselected footer</footer>',
    ]);
    $selectChunk = [['type' => 'article', 'patterns' => ['~\A(?<content>[\s\S]+)\z~']]];
    $history = [];
    fakeSiteReadingProviders([
        siteReadingPlanResponse($selectChunk), siteReadingPlanResponse($selectChunk), siteReadingPlanResponse($selectChunk),
        siteReadingPlanResponse([]),
        siteReadingPlanResponse([['type' => 'article', 'patterns' => ['~\A(?<content>.*?</article>)~s']]]),
    ], $history);

    $this->post('/source-references/'.$source->id.'/entries/article')->assertRedirect();
    Artisan::call('queue:work', ['--stop-when-empty' => true, '--tries' => 1, '--sleep' => 0]);

    expect(Entry::firstOrFail()->status)->toBe(EntryStatus::Completed)
        ->and(Entry::firstOrFail()->content)->toBe("helloworld!\n\ntail")
        ->and($history)->toHaveCount(5);
});

it('resumes a failed chunk without repeating completed calls or publishing partial content', function (): void {
    config(['queue.default' => 'database']);
    Storage::put('fixture.jpg', 'fixture-screenshot');
    $source = SourceReference::create([
        'user_id' => auth()->id(), 'url' => 'https://publisher.example.com/resumable',
        'status' => SourceReferenceStatus::Completed, 'screenshot_path' => 'fixture.jpg',
        'raw_content' => '<article><p>'.str_repeat('Original wording. ', 10000).'</p><p>Last words.</p></article>',
    ]);
    $selectChunk = [['type' => 'article', 'patterns' => ['~\A(?<content>[\s\S]+)\z~']]];
    $history = [];
    fakeSiteReadingProviders([
        siteReadingPlanResponse($selectChunk), siteReadingJson(['error' => 'temporary failure'], 503), siteReadingPlanResponse($selectChunk),
    ], $history);

    $this->post('/source-references/'.$source->id.'/entries/article')->assertRedirect();
    $entry = Entry::firstOrFail();
    $initialJob = new GenerateArticleEntryJob($entry);
    Artisan::call('queue:work', ['--once' => true, '--tries' => 3, '--sleep' => 0]);
    expect($entry->refresh()->status)->toBe(EntryStatus::Processing)
        ->and($entry->content)->toBeNull()
        ->and($entry->metadata['generation']['usage']['total_tokens'])->toBe(42);

    Artisan::call('queue:work', ['--once' => true, '--tries' => 3, '--sleep' => 0]);
    expect($entry->refresh()->status)->toBe(EntryStatus::Failed)
        ->and($entry->content)->toBeNull();

    Artisan::call('queue:work', ['--stop-when-empty' => true, '--tries' => 3, '--sleep' => 0]);
    expect($entry->refresh()->status)->toBe(EntryStatus::Completed)
        ->and(substr_count($entry->content, 'Original wording.'))->toBe(10000)
        ->and($entry->content)->toEndWith('Last words.')
        ->and($entry->metadata['generation']['usage']['total_tokens'])->toBe(84)
        ->and($entry->metadata['generation']['error'])->toBeNull()
        ->and($history)->toHaveCount(3)
        ->and((string) $history[1]['request']->getBody())->toBe((string) $history[2]['request']->getBody());

    // A duplicate initial delivery must not restart an already completed run.
    dispatch_sync($initialJob);
    expect($entry->refresh()->status)->toBe(EntryStatus::Completed)->and($history)->toHaveCount(3);
});

it('retains a rejected plan and gives its validation error to the next chunk attempt', function (): void {
    config(['queue.default' => 'database']);
    Storage::put('fixture.jpg', 'fixture-screenshot');
    $source = SourceReference::create([
        'user_id' => auth()->id(), 'url' => 'https://publisher.example.com/repair-plan',
        'status' => SourceReferenceStatus::Completed, 'screenshot_path' => 'fixture.jpg',
        'raw_content' => '<article><h1>Saved article</h1><p>All original words.</p></article>',
    ]);
    $badPattern = '~(?<content><h2>Missing heading</h2>)~';
    $rejected = siteReadingPlanResponse([['type' => 'article', 'patterns' => [
        '~(?<content><h1>.*?</h1>)~', $badPattern,
    ]]]);
    $history = [];
    fakeSiteReadingProviders([$rejected, siteReadingPlanResponse()], $history);

    $this->post('/source-references/'.$source->id.'/entries/article')->assertRedirect();
    Artisan::call('queue:work', ['--once' => true, '--tries' => 3, '--sleep' => 0]);
    $entry = Entry::firstOrFail();
    expect($entry->status)->toBe(EntryStatus::Failed)
        ->and($entry->content)->toBeNull()
        ->and($entry->metadata['generation']['usage']['total_tokens'])->toBe(42);
    $failure = $entry->metadata['generation']['checkpoint']['failures'][0];
    expect($failure['error'])->toBe('Object 1, pattern 2 did not match the saved HTML.')
        ->and($failure['start'])->toBe(0)
        ->and($failure['end'])->toBe(strlen($source->raw_content))
        ->and($failure['response'])->toBe(json_decode((string) $rejected->getBody(), true));

    Artisan::call('queue:work', ['--stop-when-empty' => true, '--tries' => 3, '--sleep' => 0]);
    expect($entry->refresh()->status)->toBe(EntryStatus::Completed)
        ->and($entry->content)->toBe("# Saved article\n\nAll original words.")
        ->and($entry->metadata['generation']['usage']['total_tokens'])->toBe(84)
        ->and($entry->metadata['generation']['extraction']['failures'])->toHaveCount(1)
        ->and($history)->toHaveCount(2);
    $retry = json_decode((string) $history[1]['request']->getBody(), true);
    expect($retry['messages'][1]['content'][0]['text'])->toContain($failure['error'], 'Missing heading');
});

it('discards in-flight work and old metrics when a manual reprocess starts a new run', function (): void {
    config(['queue.default' => 'database']);
    Storage::put('fixture.jpg', 'fixture-screenshot');
    $source = SourceReference::create([
        'user_id' => auth()->id(), 'url' => 'https://publisher.example.com/new-run',
        'status' => SourceReferenceStatus::Completed, 'screenshot_path' => 'fixture.jpg',
        'raw_content' => '<article><p>'.str_repeat('Original wording. ', 10000).'</p><p>Last words.</p></article>',
    ]);
    $selectChunk = [['type' => 'article', 'patterns' => ['~\A(?<content>[\s\S]+)\z~']]];
    $history = [];
    fakeSiteReadingProviders([
        siteReadingPlanResponse($selectChunk),
        function () use ($source): Response {
            $this->post('/source-references/'.$source->id.'/entries/article')->assertRedirect();

            return siteReadingJson(['error' => 'old request failed'], 503);
        },
        siteReadingPlanResponse($selectChunk), siteReadingPlanResponse($selectChunk),
    ], $history);

    $this->post('/source-references/'.$source->id.'/entries/article')->assertRedirect();
    $entry = Entry::firstOrFail();
    $oldRunId = $entry->metadata['generation']['run_id'];
    Artisan::call('queue:work', ['--once' => true, '--tries' => 3, '--sleep' => 0]);
    expect($entry->refresh()->metadata['generation']['usage']['total_tokens'])->toBe(42);
    Artisan::call('queue:work', ['--once' => true, '--tries' => 3, '--sleep' => 0]);
    expect($entry->refresh()->status)->toBe(EntryStatus::Pending)
        ->and($entry->metadata['generation']['run_id'])->not->toBe($oldRunId)
        ->and(array_keys($entry->metadata['generation']))->toBe(['run_id']);

    Artisan::call('queue:work', ['--stop-when-empty' => true, '--tries' => 3, '--sleep' => 0]);
    expect($entry->refresh()->status)->toBe(EntryStatus::Completed)
        ->and(substr_count($entry->content, 'Original wording.'))->toBe(10000)
        ->and($entry->metadata['generation']['usage']['total_tokens'])->toBe(84)
        ->and($history)->toHaveCount(4);
});

it('assembles multiple article objects and repeated matches in plan order', function (): void {
    $html = <<<'HTML'
<h1>First in source, last in the plan</h1>
<article><p>Second in source.</p><p>Third in source.</p>
<pre><code class="language-php">if ($ready) {
    echo "&lt;done&gt;";
}</code></pre>
<table><tr><th>Item</th><th>Count</th></tr><tr><td>Widgets</td><td>7</td></tr></table>
<script>Unrelated script wording</script><style>Unrelated style wording</style></article>
HTML;
    $history = [];
    fakeSiteReadingProviders([
        ...siteReadingScreenshotResponses(), ...siteReadingHtmlResponses($html),
        siteReadingPlanResponse([
            ['type' => 'article', 'patterns' => ['~(?<content><p>.*?</p>)~s', '~(?<content><pre>.*?</pre>)~s']],
            ['type' => 'article', 'patterns' => ['~(?<content><table>.*?</table>)~s', '~(?<content><h1>.*?</h1>)~s']],
        ]),
        siteReadingPlanResponse([]),
    ], $history);

    $this->post('/source-references', ['url' => 'https://publisher.example.com/multiple-regions'])->assertRedirect();

    $entry = Entry::firstOrFail();
    expect($entry->status)->toBe(EntryStatus::Completed)
        ->and($entry->content)->toStartWith("Second in source.\n\nThird in source.")
        ->and($entry->content)->toContain("```php\nif (\$ready) {\n    echo \"<done>\";\n}", '<table>', '<td>7</td>')
        ->and($entry->content)->toEndWith('# First in source, last in the plan')
        ->and($entry->content)->not->toContain('Unrelated script wording', 'Unrelated style wording');
});

it('renders highlighted code without theme duplicates or UI markup and retains video destinations', function (): void {
    $code = '<pre data-language="rust"><code><div class="ec-line"><div class="gutter">101</div><div class="code">fn main() {</div></div>'
        .'<div class="ec-line"><div class="gutter">102</div><div class="code">    println!("&lt;done&gt;");</div></div>'
        .'<div class="ec-line"><div class="gutter">103</div><div class="code">}</div></div></code></pre>';
    $html = '<article><p>Use <code><span class="justif-seg">&lt;dialog&gt;</span></code> literally.</p>'
        .'<div class="code-block-wrapper"><div class="theme-light"><button>Copy code</button>'.$code.'</div>'
        .'<div class="theme-dark"><button>Copied!</button>'.$code.'</div></div>'
        .'<figure><video><source src="/demo.mp4" type="video/mp4">Your browser does not support video.</video>'
        .'<figcaption>Recorded demonstration.</figcaption></figure></article>';
    $history = [];
    fakeSiteReadingProviders([
        ...siteReadingScreenshotResponses(), ...siteReadingHtmlResponses($html), siteReadingPlanResponse(),
    ], $history);

    $this->post('/source-references', ['url' => 'https://publisher.example.com/highlighted-code'])->assertRedirect();

    $entry = Entry::firstOrFail();
    expect($entry->status)->toBe(EntryStatus::Completed)
        ->and($entry->content)->toContain('Use `<dialog>` literally.', "```rust\nfn main() {\n    println!(\"<done>\");\n}\n```", '[Video](/demo.mp4)', 'Recorded demonstration.')
        ->and(substr_count($entry->content, 'fn main()'))->toBe(1)
        ->and($entry->content)->not->toContain('justif-seg', '<div', '101', '102', 'Copy code', 'Copied!', 'Your browser does not support video.');
});

it('preserves saved SVG diagrams as private images without changing their drawing data', function (): void {
    $svg = file_get_contents(__DIR__.'/../Fixtures/scooter-can-diagram.svg');
    $history = [];
    fakeSiteReadingProviders([
        ...siteReadingScreenshotResponses(),
        ...siteReadingHtmlResponses('<article><nav><svg><path d="M0 0 L5 5"/></svg></nav>'
            .'<h1>Protocol diagrams</h1><p>Before '.$svg.' after.</p>'
            .'<figure>'.$svg.'<figcaption>Original CAN layout.</figcaption></figure></article>'),
        siteReadingPlanResponse(),
    ], $history);

    $this->post('/source-references', ['url' => 'https://publisher.example.com/diagrams'])->assertRedirect();
    $entry = Entry::firstOrFail();
    expect($entry->status)->toBe(EntryStatus::Completed);
    preg_match_all('~!\[[^\]]*\]\(([^)]+/diagrams/[^)]+)\)~', $entry->content, $images);
    expect($images[1])->toHaveCount(2);
    expect($images[1][0])->toBe($images[1][1]);
    expect($entry->content)->toContain('Before', 'after.', 'Original CAN layout.')
        ->not->toContain('<svg', '<path', '<symbol');

    $image = $images[1][0];
    $this->get($image)->assertOk()->assertContent($svg)
        ->assertHeader('Content-Type', 'image/svg+xml')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox")
        ->assertHeader('Cache-Control', 'no-cache, private');
    $this->get(str_replace(hash('sha256', $svg), str_repeat('0', 64), $image))->assertNotFound();
    $owner = auth()->user();
    $this->actingAs(User::factory()->create())->get($image)->assertNotFound();
    $this->post('/logout');
    $this->get($image)->assertRedirect('/login');

    $this->app['auth']->forgetGuards();
    $this->withToken($owner->createToken('Cannot read', ['entries:generate'])->plainTextToken)
        ->getJson($image)->assertForbidden();
    $this->app['auth']->forgetGuards();
    $this->withToken($owner->createToken('Diagram reader', ['read'])->plainTextToken)
        ->get($image)->assertOk()->assertContent($svg);
    $this->getJson('/api/entries/'.$entry->id)->assertOk()
        ->assertJsonPath('data.content_html', fn (string $html): bool => substr_count($html, '<img ') === 2
            && str_contains($html, $image));

    $this->actingAs($owner)->delete('/source-references/'.$entry->source_reference_id)->assertRedirect();
    $this->get($image)->assertNotFound();
    expect(Storage::allFiles())->toBe([]);
    Http::assertNothingSent();
    expect($history)->toHaveCount(8);
});

it('fails generation rather than publishing duplicated content from overlapping patterns', function (): void {
    $history = [];
    fakeSiteReadingProviders([
        ...siteReadingScreenshotResponses(), ...siteReadingHtmlResponses(),
        siteReadingPlanResponse([['type' => 'article', 'patterns' => [
            '~(?<content><article>.*?</article>)~s',
            '~(?<content><h1>.*?</h1>)~s',
        ]]]),
    ], $history);

    $this->post('/source-references', ['url' => 'https://publisher.example.com/overlap'])->assertRedirect();

    $entry = Entry::firstOrFail();
    expect($entry->status)->toBe(EntryStatus::Failed)
        ->and($entry->content)->toBeNull()
        ->and($entry->metadata['generation']['error'])->toContain('overlapping content');
});

it('extracts only the story text for text-only Hacker News posts', function (): void {
    Http::fake(['*' => Http::response([
        'type' => 'story', 'title' => 'Ask HN: A < B?',
        'text' => '<p>My original question.</p>', 'kids' => [99],
    ])]);
    $history = [];
    fakeSiteReadingProviders([...siteReadingScreenshotResponses(), siteReadingPlanResponse()], $history);

    $this->post('/source-references', ['url' => 'https://news.ycombinator.com/item?id=42'])->assertRedirect();

    $source = SourceReference::firstOrFail();
    expect($source->raw_content)->toBe('<article><h1>Ask HN: A &lt; B?</h1><p>My original question.</p></article>')
        ->and(Entry::firstOrFail()->status)->toBe(EntryStatus::Completed);
    $prompt = json_decode((string) $history[4]['request']->getBody(), true)['messages'][1]['content'][0]['text'];
    expect($prompt)->toContain('Source outline (JSON):', 'My original question.');
    Http::assertSentCount(1);
});

it('records resolution failures without starting capture or generating entries', function (mixed $item, string $error): void {
    Http::fake(['*' => Http::response($item)]);
    $this->post('/source-references', ['url' => 'https://news.ycombinator.com/item?id=42'])->assertServerError();

    $source = SourceReference::firstOrFail();
    expect($source->status)->toBe(SourceReferenceStatus::Failed)
        ->and($source->content_url)->toBeNull()
        ->and($source->metadata['resolution']['status'])->toBe('FAILED')
        ->and($source->metadata['resolution']['error'])->toContain($error);
    $this->assertDatabaseCount('entries', 0);
})->with([
    'missing' => [null, 'unavailable'],
    'deleted' => [['type' => 'story', 'deleted' => true], 'unavailable'],
    'dead' => [['type' => 'story', 'dead' => true], 'unavailable'],
    'comment' => [['type' => 'comment', 'parent' => 1], 'comment permalink'],
    'empty story' => [['type' => 'story'], 'no article link or story text'],
    'javascript' => [['type' => 'story', 'url' => 'javascript:alert(1)'], 'public HTTP'],
    'ftp' => [['type' => 'story', 'url' => 'ftp://example.com/file'], 'public HTTP'],
    'loopback' => [['type' => 'story', 'url' => 'http://127.0.0.1/private'], 'public HTTP'],
    'private IP' => [['type' => 'story', 'url' => 'http://10.0.0.8/private'], 'public HTTP'],
    'IPv6 loopback' => [['type' => 'story', 'url' => 'http://[::1]/private'], 'public HTTP'],
    'localhost' => [['type' => 'story', 'url' => 'http://service.localhost/private'], 'public HTTP'],
    'credentials' => [['type' => 'story', 'url' => 'https://user:pass@example.com/'], 'public HTTP'],
    'HN recursion' => [['type' => 'story', 'url' => 'https://news.ycombinator.com/item?id=2'], 'public HTTP'],
]);

it('rejects malformed HN story IDs before making an HTTP request', function (string $query): void {
    $this->post('/source-references', ['url' => 'https://news.ycombinator.com/item'.$query])->assertServerError();
    expect(SourceReference::firstOrFail()->metadata['resolution']['error'])->toContain('valid story ID');
    Http::assertNothingSent();
})->with(['', '?id=0', '?id=-1', '?id=1abc', '?id[]=42']);

it('records HN transport failures without using the discussion as an article', function (): void {
    Http::fake(['*' => Http::response([], 503)]);
    $this->post('/source-references', ['url' => 'https://news.ycombinator.com/item?id=42'])->assertServerError();
    expect(SourceReference::firstOrFail()->metadata['resolution']['status'])->toBe('FAILED');
    $this->assertDatabaseCount('entries', 0);
});

it('does not match lookalike hosts or non-item HN paths', function (string $url): void {
    $history = [];
    fakeSiteReadingProviders([...siteReadingScreenshotResponses(), ...siteReadingHtmlResponses(), siteReadingPlanResponse()], $history);
    $this->post('/source-references', ['url' => $url])->assertRedirect();
    expect(SourceReference::firstOrFail()->metadata['reading']['profile'])->toBe('default');
    Http::assertNothingSent();
})->with(['https://news.ycombinator.com.evil.example/item?id=42', 'https://news.ycombinator.com/newest']);

it('lets multiple domains share a profile and a publisher choose a flow without replacing the HN layout', function (): void {
    $this->app->instance('publisher.capture', new class
    {
        public function handle(string $url): string
        {
            return '<article>Custom publisher capture: '.$url.'</article>';
        }
    });
    $this->app->instance('publisher.generator', new class
    {
        public function handle(SourceReference $source): array
        {
            return [
                'markdown' => 'Custom extraction: '.$source->raw_content,
                'provider' => 'fixture', 'model' => 'custom', 'usage' => null, 'raw_html_truncated' => false,
            ];
        }
    });
    config([
        'reading.profiles.publisher' => [
            'hosts' => ['publisher.example.com', 'alternate.example.com'],
            'resolver' => null, 'flow' => 'publisher-article', 'layout' => 'default',
        ],
        'reading.flows.publisher-article' => [
            ...config('reading.flows.article'),
            'raw_content' => 'publisher.capture',
            'generator' => 'publisher.generator',
        ],
    ]);
    Http::fake(['*' => Http::response(['type' => 'story', 'url' => 'https://alternate.example.com/article'])]);
    $history = [];
    fakeSiteReadingProviders([
        ...siteReadingScreenshotResponses(), ...siteReadingScreenshotResponses(),
    ], $history);

    $this->post('/source-references', ['url' => 'https://news.ycombinator.com/item?id=42'])->assertRedirect();
    $this->post('/source-references', ['url' => 'https://publisher.example.com/article'])->assertRedirect();
    $entries = Entry::orderBy('id')->get();
    expect($entries[0]->metadata['reading'])->toMatchArray(['profile' => 'hacker-news', 'flow' => 'publisher-article', 'layout' => 'hacker-news'])
        ->and($entries[1]->metadata['reading'])->toMatchArray(['profile' => 'publisher', 'flow' => 'publisher-article', 'layout' => 'default'])
        ->and($entries[0]->content)->toBe('Custom extraction: <article>Custom publisher capture: https://alternate.example.com/article</article>')
        ->and($entries[1]->content)->toBe('Custom extraction: <article>Custom publisher capture: https://publisher.example.com/article</article>');
});

it('does not generate an article when a capture fails', function (string $capture): void {
    config(['queue.default' => 'database']);
    $history = [];
    fakeSiteReadingProviders($capture === 'screenshot'
        ? [siteReadingJson(['error' => 'unavailable'], 503), ...siteReadingHtmlResponses()]
        : [...siteReadingScreenshotResponses(), siteReadingJson(['error' => 'unavailable'], 503)], $history);
    $this->post('/source-references', ['url' => 'https://example.com/article'])->assertRedirect();
    Artisan::call('queue:work', ['--stop-when-empty' => true, '--tries' => 1, '--sleep' => 0]);
    $source = SourceReference::firstOrFail();
    expect($source->status)->toBe(SourceReferenceStatus::Failed)
        ->and($source->metadata[$capture]['status'])->toBe('FAILED');
    $this->assertDatabaseCount('entries', 0);
})->with(['screenshot', 'raw_content']);

it('can retry failed generation using the persisted article target without resolving HN again', function (): void {
    Http::fake(['*' => Http::response(['type' => 'story', 'url' => 'https://publisher.example.com/article'])]);
    $history = [];
    fakeSiteReadingProviders([
        ...siteReadingScreenshotResponses(), ...siteReadingHtmlResponses(),
        siteReadingJson(['error' => 'unavailable'], 503), siteReadingPlanResponse(),
    ], $history);
    $this->post('/source-references', ['url' => 'https://news.ycombinator.com/item?id=42'])->assertRedirect();
    $source = SourceReference::firstOrFail();
    $entry = Entry::firstOrFail();
    expect($entry->status)->toBe(EntryStatus::Failed);

    $this->post('/source-references/'.$source->id.'/entries/article')->assertRedirect();
    expect($entry->refresh()->status)->toBe(EntryStatus::Completed)
        ->and($entry->metadata['generation']['error'])->toBeNull()
        ->and($entry->metadata['reading']['content_url'])->toBe('https://publisher.example.com/article');
    $this->assertDatabaseCount('entries', 1);
    Http::assertSentCount(1);
});
