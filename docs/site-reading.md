# Site-specific reading

`config/reading.php` maps exact host lists (and optional paths) to site profiles. A profile selects a target resolver, a named parsing flow, and a reader layout independently. Multiple domains can share a profile, and multiple profiles can share a flow.

## Processing

`ResolveReadingTarget` runs before the capture batch and persists the plan. `SourceReference.url` remains the submitted link; `content_url` is the capture/extraction target. Retries reuse that target. A matching publisher profile can override the parsing flow after HN resolves its outbound link, without changing the HN origin profile or reader layout. Resolution does not recursively invoke publisher resolvers.

The initial `article` flow retains the existing requirements: HTML and screenshot must both succeed, followed by Markdown generation. Flow configuration selects the HTML capture, screenshot capture, and generator actions. Capture actions accept a URL and return HTML or JPEG bytes; generators accept a source reference and return the existing Markdown/provider/model/usage/truncation result. A future flow that produces non-article content or needs different required inputs will also need corresponding orchestration and output-contract changes.

### Article extraction

OpenRouter now reviews the saved HTML and screenshot and returns JSON, not rewritten article text:

```json
{
    "version": 1,
    "objects": [
        {
            "type": "article",
            "patterns": ["~<article\\b[^>]*>(?<content>.*?)</article>~is"]
        }
    ]
}
```

Only `article` objects are currently supported. Each owns one or more cumulative PHP PCRE patterns, including delimiters and a named `content` capture. Object order, then pattern order, determines reading order; repeated matches within a pattern retain source order. Future object types such as `title`, `body`, or `header_image` can have their own handling without changing this ordering contract. The example pattern is not a universal nested-HTML parser: plans must use boundaries appropriate to the captured page.

`ExtractArticleMarkdownAction` runs every pattern against the original saved HTML and converts the assembled captures locally with `league/html-to-markdown`. Headings, lists, quotations, code, links and images come from the capture, not the model response. Layout wrappers are stripped so they cannot make Markdown render literally; complete HTML tables and fragment anchors are preserved for the existing sanitized reader. Scripts and styles are removed. Invalid regexes, unmatched/empty captures, unknown object types, and overlapping selections fail generation rather than publishing partial or duplicated text. Regex size, backtracking and recursion are bounded because provider output is untrusted.

Successful entries snapshot the extraction plan and SHA-256 of its source HTML in `metadata.generation.extraction`. Existing entries remain readable without regeneration. Regeneration asks for a fresh plan; there is no extraction-plan cache. No provider is called when reading an entry.

Local extraction removes the model completion-size cap on article text, but the full captured HTML is still sent to planning. Model input-context limits, capture completeness, regex execution budgets, and local memory/storage limits still apply; bounded/chunked planning input is not implemented yet. Unsupported interactive media is not reconstructed from the screenshot.

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

The `portobello-clock` fixture bundle in `test/Domain/SourceReference/Fixtures` contains the already-saved HTML (`.html.fixture`, to avoid Vite treating it as an app entry) and JPEG screenshot of [Fixing the Portobello Police Station Clock](https://pointinthecloud.com/2026-04-11-211700.html), retrieved read-only from test-app source #64 on November 8, 2026. Its OpenRouter JSON response is hand-authored, not generated by a provider call; three patterns assemble the original heading opening, title/closing heading, and complete body while excluding the decorative site-logo image and footer navigation. The fixture controller test starts with these files, mocks the plan response, and exercises real extraction/conversion and the reader response. Tests make **no live OpenRouter or capture-provider calls**. Other smoke tests verify ordered objects/patterns, repeated matches, overlap rejection, and the final paragraph of a long article.

`./npm test` renders the real reading layouts with React and the installed Vite toolchain. It covers the default and HN presentations, text-only/archived content, article-relative links and images, local fragment targets, and unsafe HTML. Both PHP and reader tests run in CI.

Run `./run composer test`, `./npm test`, `./npm run build`, and `./run vendor/bin/pint --test`.
