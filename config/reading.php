<?php

use App\Actions\GetRawContentFromUrlAction;
use App\Actions\GetScreenshotFromUrlAction;
use App\Domain\Entry\Actions\GenerateArticleMarkdownFromSourceReferenceAction;
use App\Domain\SourceReference\Actions\ResolveHackerNewsTarget;

return [
    // New sites may need different link resolution, Apify/extraction rules, or reading
    // presentation, but not necessarily all three. Select these independently so adding
    // a site does not require copying an entire pipeline. Host aliases share a profile;
    // unrelated profiles can also reuse a flow or layout. Keep this registry in code
    // until there is a business need for user-managed rules.
    'profiles' => [
        'hacker-news' => [
            'hosts' => ['news.ycombinator.com'],
            'path' => '/item',
            'resolver' => ResolveHackerNewsTarget::class,
            'flow' => 'article',
            'layout' => 'hacker-news',
        ],
        // Unrecognized sites must retain today's parsing and reading experience.
        'default' => [
            'hosts' => [],
            'resolver' => null,
            'flow' => 'article',
            'layout' => 'default',
        ],
    ],
    // Flows name reusable capture/extraction recipes, not domains. The initial article
    // contract still requires HTML + screenshot and produces Markdown; other content
    // types will need their own orchestration/output contract, not just another key.
    // Container-resolved adapters also let tests exercise processing without paid calls.
    'flows' => [
        'article' => [
            'raw_content' => GetRawContentFromUrlAction::class,
            'screenshot' => GetScreenshotFromUrlAction::class,
            'generator' => GenerateArticleMarkdownFromSourceReferenceAction::class,
        ],
    ],
];
