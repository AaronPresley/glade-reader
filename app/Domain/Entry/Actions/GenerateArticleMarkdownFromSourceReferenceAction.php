<?php

namespace App\Domain\Entry\Actions;

use App\Domain\SourceReference\Models\SourceReference;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;

class GenerateArticleMarkdownFromSourceReferenceAction
{
    private const MAX_RAW_HTML_BYTES = 120000;

    /**
     * @return array{
     *     markdown: string,
     *     provider: string,
     *     model: string,
     *     usage: array<string, mixed>|null,
     *     raw_html_truncated: bool
     * }
     *
     * @throws JsonException
     */
    public function handle(SourceReference $sourceReference): array
    {
        $apiKey = config('services.open_router.api_key');

        if (! is_string($apiKey) || $apiKey === '') {
            throw new RuntimeException('OpenRouter API key is not configured.');
        }

        $model = config('services.open_router.model');

        if (! is_string($model) || $model === '') {
            throw new RuntimeException('OpenRouter model is not configured.');
        }

        $rawHtml = $sourceReference->raw_content;

        if (! is_string($rawHtml) || trim($rawHtml) === '') {
            throw new RuntimeException('Source reference raw content is required to generate markdown.');
        }

        $screenshotPath = $sourceReference->screenshot_path;

        if (! is_string($screenshotPath) || trim($screenshotPath) === '') {
            throw new RuntimeException('Source reference screenshot is required to generate markdown.');
        }

        if (! Storage::exists($screenshotPath)) {
            throw new RuntimeException('Source reference screenshot file does not exist.');
        }

        $screenshot = Storage::get($screenshotPath);

        if (! is_string($screenshot) || $screenshot === '') {
            throw new RuntimeException('Unable to read source reference screenshot.');
        }

        [$promptRawHtml, $rawHtmlTruncated] = $this->promptRawHtml($rawHtml);

        $client = new Client([
            'base_uri' => 'https://openrouter.ai/api/v1/',
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$apiKey,
                'Content-Type' => 'application/json',
            ],
            'timeout' => 120,
        ]);

        $response = $client->post('chat/completions', [
            'json' => [
                'model' => $model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You convert captured web source material into accurate, high-quality Markdown. Return only Markdown.',
                    ],
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => $this->prompt($sourceReference, $promptRawHtml, $rawHtmlTruncated),
                            ],
                            [
                                'type' => 'image_url',
                                'image_url' => [
                                    'url' => 'data:image/png;base64,'.base64_encode($screenshot),
                                ],
                            ],
                        ],
                    ],
                ],
                'temperature' => 0.2,
            ],
        ]);

        $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException('OpenRouter response was not a JSON object.');
        }

        $markdown = $decoded['choices'][0]['message']['content'] ?? null;

        if (! is_string($markdown) || trim($markdown) === '') {
            throw new RuntimeException('OpenRouter response did not include markdown content.');
        }

        $responseModel = $decoded['model'] ?? $model;
        $usage = $decoded['usage'] ?? null;

        return [
            'markdown' => trim($markdown),
            'provider' => 'open_router',
            'model' => is_string($responseModel) && $responseModel !== '' ? $responseModel : $model,
            'usage' => is_array($usage) ? $usage : null,
            'raw_html_truncated' => $rawHtmlTruncated,
        ];
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function promptRawHtml(string $rawHtml): array
    {
        if (strlen($rawHtml) <= self::MAX_RAW_HTML_BYTES) {
            return [$rawHtml, false];
        }

        return [
            substr($rawHtml, 0, self::MAX_RAW_HTML_BYTES),
            true,
        ];
    }

    private function prompt(SourceReference $sourceReference, string $rawHtml, bool $rawHtmlTruncated): string
    {
        $truncationNote = $rawHtmlTruncated
            ? "\n\nThe raw HTML was truncated for request size; use the screenshot to recover visible structure and context where possible."
            : '';

        return <<<PROMPT
The raw HTML below and the screenshot image are the same source material. Use both inputs: raw HTML for text, links, and structure; screenshot for visual hierarchy and visible page context.

Return high-quality Markdown only. Do not include commentary, extraction notes, or code fences around the final answer. Preserve meaningful headings, paragraphs, lists, tables, quotes, and links. Omit navigation, cookie banners, scripts, styles, and duplicate boilerplate.

Source URL: {$sourceReference->url}{$truncationNote}

Raw HTML:
```html
{$rawHtml}
```
PROMPT;
    }
}
