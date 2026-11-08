<?php

use App\Domain\Entry\Enums\EntryStatus;
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

function siteReadingHtmlResponses(string $html = '<article><h1>Linked article</h1><p>Publisher body, not comments.</p></article>'): array
{
    return [
        siteReadingJson(['data' => ['id' => 'html-run']]),
        siteReadingJson(['data' => ['status' => 'SUCCEEDED', 'defaultDatasetId' => 'html']]),
        siteReadingJson([['fullHtml' => $html]]),
    ];
}

function siteReadingMarkdownResponse(): Response
{
    return siteReadingJson([
        'choices' => [['message' => ['content' => "# Linked article\n\nPublisher body, not comments.\n\n[Next](../next)\n\n![Diagram](/images/chart.svg)"]]],
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
        ...siteReadingScreenshotResponses(), ...siteReadingHtmlResponses(), siteReadingMarkdownResponse(),
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
        ->and($generationInput['messages'][1]['content'][0]['text'])->toContain('Source URL: '.$target, $source->raw_content);

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

it('sends the entire captured HTML to generation for large pages', function (): void {
    $html = '<article><p>'.str_repeat('Full article text. ', 10000).'</p><p>Final paragraph beyond the old limit.</p></article>';
    $history = [];
    fakeSiteReadingProviders([
        ...siteReadingScreenshotResponses(), ...siteReadingHtmlResponses($html), siteReadingMarkdownResponse(),
    ], $history);

    $this->post('/source-references', ['url' => 'https://publisher.example.com/long-article'])->assertRedirect();

    $prompt = json_decode((string) $history[7]['request']->getBody(), true)['messages'][1]['content'][0]['text'];
    expect($prompt)->toContain($html)
        ->and(Entry::firstOrFail()->status)->toBe(EntryStatus::Completed)
        ->and(Entry::firstOrFail()->metadata['generation']['raw_html_truncated'])->toBeFalse();
});

it('extracts only the story text for text-only Hacker News posts', function (): void {
    Http::fake(['*' => Http::response([
        'type' => 'story', 'title' => 'Ask HN: A < B?',
        'text' => '<p>My original question.</p>', 'kids' => [99],
    ])]);
    $history = [];
    fakeSiteReadingProviders([...siteReadingScreenshotResponses(), siteReadingMarkdownResponse()], $history);

    $this->post('/source-references', ['url' => 'https://news.ycombinator.com/item?id=42'])->assertRedirect();

    $source = SourceReference::firstOrFail();
    expect($source->raw_content)->toBe('<article><h1>Ask HN: A &lt; B?</h1><p>My original question.</p></article>')
        ->and(Entry::firstOrFail()->status)->toBe(EntryStatus::Completed);
    $prompt = json_decode((string) $history[4]['request']->getBody(), true)['messages'][1]['content'][0]['text'];
    expect($prompt)->toContain($source->raw_content);
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
    fakeSiteReadingProviders([...siteReadingScreenshotResponses(), ...siteReadingHtmlResponses(), siteReadingMarkdownResponse()], $history);
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
        siteReadingJson(['error' => 'unavailable'], 503), siteReadingMarkdownResponse(),
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
