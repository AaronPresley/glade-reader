# Site-specific reading

`config/reading.php` maps exact host lists (and optional paths) to site profiles. A profile selects a target resolver, a named parsing flow, and a reader layout independently. Multiple domains can share a profile, and multiple profiles can share a flow.

## Processing

`ResolveReadingTarget` runs before the capture batch and persists the plan. `SourceReference.url` remains the submitted link; `content_url` is the capture/extraction target. Retries reuse that target. A matching publisher profile can override the parsing flow after HN resolves its outbound link, without changing the HN origin profile or reader layout. Resolution does not recursively invoke publisher resolvers.

The initial `article` flow retains the existing requirements: HTML and screenshot must both succeed, followed by Markdown generation. Flow configuration selects the HTML capture, screenshot capture, and generator actions. Capture actions accept a URL and return HTML or JPEG bytes; generators accept a source reference and an optional saved checkpoint, returning either a continuation checkpoint or the final Markdown alongside provider/model/usage/truncation metadata. Existing single-step generators can keep returning their final result. A future flow that produces non-article content or needs different required inputs will also need corresponding orchestration and output-contract changes.

### Article extraction

OpenRouter reviews a compact source outline and exact HTML boundary snippets for one source chunk at a time. The first request also includes the saved screenshot as a layout reference; continuations use their outline and ancestor context without paying repeated image-token costs. Each response is a JSON regex plan, not rewritten article text:

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

Only `article` objects are currently supported. Each owns one or more cumulative PHP PCRE patterns, including delimiters and a named `content` capture. Chunks retain source order; within each chunk, object order, then pattern order, determines reading order. Repeated matches within a pattern retain source order. Future object types such as `title`, `body`, or `header_image` can have their own handling without changing this ordering contract. The example pattern is not a universal nested-HTML parser: plans must use boundaries appropriate to the captured page. Nonarticle chunks return `{"version":1,"objects":[]}`; an entirely unselected article fails generation.

`BuildArticleOutlineAction` scans the original source without reserializing its tags. Regions retain source order and parent IDs. Small regions (up to 256 bytes) are shown as exact HTML, without repeating their child regions. Larger regions contain byte lengths, descendant tag counts, up to 64 bytes each of normalized opening/ending text samples, and up to 64 bytes each of source-exact boundary snippets. UTF-8 boundaries are preserved. Paragraphs and inline elements are counted inside their regions; lists, tables, quotations, code, figures and SVGs summarize their nested structure instead of expanding every child.

Scripts, styles and comments are skipped, including fake markup inside them. Boundary snippets intersecting these regions are omitted in favor of the opening/closing tag where available. The outline follows explicit source nesting rather than repairing markup like a browser; missing closing tags do not produce invented closing snippets. The prompt distinguishes normalized text samples from literal boundaries and tells the model to select whole regions, including article text not shown in samples. The original HTML remains unchanged and local.

`BuildArticleChunkAction` walks disjoint windows of the original saved HTML, targeting 128 KiB of source per window. Tags are kept intact; long text and code nodes split on UTF-8 boundaries. Ignored scripts/styles/comments are isolated and skipped locally. Dense markup gets progressively smaller windows until its JSON outline fits 60,000 bytes. This is a per-request byte budget, not a model-specific token estimate or a page-size limit. There is no fixed chunk-count cap, full-HTML fallback, or additional LLM call to normalize the plans.

A bounded set of ancestor opening snippets describes the scope of a continuation, including article/navigation and code/table/list context even through deep wrappers. Context is not selectable source and adds no synthetic HTML. Outlines continue suppressing nested code/list/table detail until their actual closing tags. A window can begin or end inside a paragraph, quotation, list, table or code block, so its patterns can use `\A` and `\z` rather than requiring complete article wrappers.

`ExtractArticleMarkdownAction` runs every pattern against its original source slice, records absolute capture ranges, and converts the assembled captures locally with `league/html-to-markdown` only after every chunk finishes. Adjacent ranges rejoin byte-for-byte so chunk boundaries cannot insert spaces into words or split fenced code blocks. Ignored/whitespace gaps between selected chunk edges retain their original bytes; explicitly unselected chunks are not restored. Disjoint selections retain plan order. Headings, lists, quotations, code, links and images come from the capture, not the model response. Layout wrappers are stripped so they cannot make Markdown render literally; complete HTML tables and fragment anchors are preserved for the existing sanitized reader. Scripts and styles are removed. Invalid regexes, unmatched/empty captures, unknown object types, and overlapping selections fail generation rather than publishing partial or duplicated text. Regex size, backtracking and recursion are bounded because provider output is untrusted.

Each queue job makes at most one provider call (60-second request timeout, 80-second job timeout). With the configured database queue, checkpoint persistence and continuation enqueueing commit in one transaction. Worker retries resume the current checkpoint without replanning successfully checkpointed chunks; a crash after a provider responds but before persistence can still repeat that call. The entry stays unpublished until complete. SHA-256 verification rejects continuations if the saved HTML changes. A manual reprocess clears old generation metrics/checkpoints and assigns a new run ID; stale queued or in-flight work cannot overwrite the new run. Cumulative token/cost totals include checkpointed successful and rejected-plan responses, with per-call usage retained; provider charges for transport failures or lost responses cannot be inferred locally.

Rejected plans do not advance the source cursor. Before throwing for the normal worker retry, the job commits the provider's response, source offsets, bounded outline, ancestor context and validation error to `metadata.generation.checkpoint.failures`. The next request for that same source window includes its previous validation error and up to 12,000 bytes of the rejected message as untrusted feedback, rather than repeating a blind request. Retry counts remain governed by the existing queue worker configuration. Successful generation retains this diagnostic history in `metadata.generation.extraction.failures`, available through the existing authenticated read API; it does not retain credentials or screenshot data URLs.

Successful entries snapshot a version-2 scoped extraction plan and source SHA-256 in `metadata.generation.extraction`: `plan.chunks` stores each chunk's inclusive `start` and exclusive `end` source offsets, its version-1 provider `plan`, absolute capture `ranges`, model and usage. This prevents a chunk-local regex from being misinterpreted as a whole-page regex. Existing entries and older version-1 snapshots remain readable without regeneration. Regeneration asks for fresh plans; there is no cross-run extraction-plan cache. No provider is called when reading an entry.

Local extraction removes the model completion-size cap on article text, and chunking removes the page-wide outline limit. Larger pages require more provider calls and may cost more overall, despite bounded input per call and a single image request. Use the asynchronous database queue in production, not synchronous recursive dispatch. Capture completeness, provider context and availability, tokenizer/regex execution budgets, final conversion time, and local memory/storage limits still apply: the complete source and final article are still held locally, not streamed. Unsupported interactive media is not reconstructed from the screenshot. Mocked tests establish chunk mechanics, not a live model's selection accuracy.

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

The `portobello-clock` fixture bundle in `test/Domain/SourceReference/Fixtures` contains the already-saved HTML (`.html.fixture`, to avoid Vite treating it as an app entry) and JPEG screenshot of [Fixing the Portobello Police Station Clock](https://pointinthecloud.com/2026-04-11-211700.html), retrieved read-only from test-app source #64 on November 8, 2026. Its OpenRouter JSON response is hand-authored, not generated by a provider call; three patterns assemble the original heading opening, title/closing heading, and complete body while excluding the decorative site-logo image and footer navigation. The fixture controller test starts with these files, verifies a reduced outline is sent instead of full HTML, mocks the unchanged plan response, and exercises real extraction/conversion and the reader response. Tests make **no live OpenRouter or capture-provider calls**. Other smoke tests verify source-exact snippets, full extraction of unseen middle text, dense pages requiring several bounded outlines, long Unicode paragraphs and code across chunks, inline ignored gaps versus unselected navigation, ordered objects/patterns, repeated matches, overlap rejection, checkpointed retry, and manual reprocessing during an in-flight request.

`./npm test` renders the real reading layouts with React and the installed Vite toolchain. It covers the default and HN presentations, text-only/archived content, article-relative links and images, local fragment targets, and unsafe HTML. Both PHP and reader tests run in CI.

Run `./run composer test`, `./npm test`, `./npm run build`, and `./run vendor/bin/pint --test`.
