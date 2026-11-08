<?php

namespace App\Domain\SourceReference\Actions;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * An HN save should read the linked publisher article, not turn the discussion into
 * article content. Select the story link from structured HN data, not an LLM guess;
 * the caller preserves the discussion URL for returning to the conversation.
 */
class ResolveHackerNewsTarget
{
    public function handle(string $url): array
    {
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
        $id = $query['id'] ?? null;

        if (! is_string($id) || ! preg_match('/^[1-9][0-9]*$/D', $id)) {
            throw new RuntimeException('A Hacker News item URL must contain a valid story ID.');
        }

        $item = Http::acceptJson()->timeout(20)->withoutRedirecting()
            ->get('https://hacker-news.firebaseio.com/v0/item/'.$id.'.json')
            ->throw()->json();

        if (! is_array($item) || ($item['deleted'] ?? false) || ($item['dead'] ?? false)) {
            throw new RuntimeException('This Hacker News item is unavailable.');
        }

        // A comment permalink does not identify an article to read. Ask for the story
        // rather than silently guessing a parent or extracting a comment as an article.
        if (($item['type'] ?? null) !== 'story') {
            throw new RuntimeException('Save the Hacker News story URL, not a comment permalink.');
        }

        $target = $item['url'] ?? null;
        $reading = ['story_id' => $id, 'title' => $item['title'] ?? null];

        if ($target === null || $target === '') {
            $text = $item['text'] ?? '';

            if (! is_string($text) || trim($text) === '') {
                throw new RuntimeException('This Hacker News story has no article link or story text.');
            }

            // Ask HN and other text-only stories still need a useful read view. Supply
            // only the author's story text so replies never masquerade as that story.
            return [
                'content_url' => 'https://news.ycombinator.com/item?id='.$id,
                'reading' => $reading,
                'html' => '<article><h1>'.e($item['title'] ?? 'Hacker News').'</h1>'.$text.'</article>',
            ];
        }

        $host = is_string($target) ? strtolower(rtrim(parse_url($target, PHP_URL_HOST) ?? '', '.')) : '';
        $ip = trim($host, '[]');
        $isIp = filter_var($ip, FILTER_VALIDATE_IP);

        // An outbound link is discovered input, not trusted capture configuration.
        // Reject unsupported/local targets and HN loops instead of silently reading
        // the discussion. DNS resolution and redirects remain the Apify actors' concern.
        if (! is_string($target) || ! filter_var($target, FILTER_VALIDATE_URL)
            || ! in_array(strtolower(parse_url($target, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true)
            || parse_url($target, PHP_URL_USER) !== null || parse_url($target, PHP_URL_PASS) !== null
            || (! $isIp && ! str_contains($host, '.')) || $host === 'localhost' || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local') || str_ends_with($host, '.internal')
            || ($isIp && ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))
            || $host === 'news.ycombinator.com') {
            throw new RuntimeException('The Hacker News article link must be a public HTTP or HTTPS URL.');
        }

        return ['content_url' => $target, 'reading' => $reading];
    }
}
