# Site-specific reading

`config/reading.php` maps exact host lists (and optional paths) to site profiles. A profile selects a target resolver, a named parsing flow, and a reader layout independently. Multiple domains can share a profile, and multiple profiles can share a flow.

## Processing

`ResolveReadingTarget` runs before the capture batch and persists the plan. `SourceReference.url` remains the submitted link; `content_url` is the capture/extraction target. Retries reuse that target. A matching publisher profile can override the parsing flow after HN resolves its outbound link, without changing the HN origin profile or reader layout. Resolution does not recursively invoke publisher resolvers.

The initial `article` flow retains the existing requirements: HTML and screenshot must both succeed, followed by Markdown generation. Flow configuration selects the HTML capture, screenshot capture, and generator actions. Capture actions accept a URL and return HTML or JPEG bytes; generators accept a source reference and return the existing Markdown/provider/model/usage/truncation result. A future flow that produces non-article content or needs different required inputs will also need corresponding orchestration and output-contract changes.

On successful generation, the entry snapshots its reading plan and content URL in `metadata.reading`. Reading never consults current site rules or calls providers. Old entries without a snapshot retain the default layout and saved-URL base. Unknown frontend layout keys fall back to the default reader.

## Hacker News

- Story URLs on `news.ycombinator.com/item` resolve through the public HN item API; no HN credentials are needed.
- Linked stories use the existing article flow against the publisher URL. Both captures and the extraction prompt use that URL.
- Text-only stories use the HN story's own HTML, excluding comments, alongside a screenshot of the HN page as a layout reference only.
- Comment permalinks, deleted/dead/missing items, malformed IDs, empty stories, and unsupported article URLs fail with a resolution error in source metadata.
- Discovered links reject non-HTTP(S) schemes, embedded credentials, local hostnames, and private/reserved IP literals. This is not DNS-rebinding protection: DNS resolution and redirect handling remain with the existing Apify actors.
- The reader shows the publisher and an HN discussion link. The saved outbound source link stays on HN; article-relative links and images resolve against the stored content URL. As with the existing capture adapters, a publisher redirect's final URL is not currently returned to the reader.

Add another profile and flow in the config as needed; add a layout to `resources/js/components/reader/reading-layouts.tsx` only when its presentation differs. The shared article renderer owns Markdown sanitization and relative URL handling.

## Compatibility and verification

Deploy the nullable `content_url` migration before processing new sources. No credentials or environment variables are added. Existing entries are not automatically reprocessed. Regenerating an old entry from its existing capture does not recapture or resolve a new target; a new source submission uses the new pipeline.

`SiteReadingTest.php` submits URLs through the controller and exercises jobs, real provider adapters, storage, generation, and reader responses. HN HTTP requests are faked with stray requests blocked; Guzzle uses a mock handler with no network fallback and dummy credentials. Success and capture-failure tests drain an isolated database queue to exercise serialization and batch completion. Other tests cover text-only posts, invalid items/targets, multiple domains, custom flow execution, and generation retry. Entry controller tests cover legacy defaults and snapshot stability.

`./npm test` renders the real reading layouts with React and the installed Vite toolchain. It covers the default and HN presentations, text-only/archived content, article-relative links and images, local fragment targets, and unsafe HTML. Both PHP and reader tests run in CI.

Run `./run composer test`, `./npm test`, `./npm run build`, and `./run vendor/bin/pint --test`.
