import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createServer } from 'vite';
import react from '@vitejs/plugin-react';

let server;
let layouts;

before(async () => {
    // Transform the real TSX with the installed Vite toolchain; no test-only renderer or network.
    server = await createServer({
        configFile: false,
        plugins: [react()],
        server: { middlewareMode: true, watch: null },
        appType: 'custom',
    });
    layouts = (await server.ssrLoadModule('/resources/js/components/reader/reading-layouts.tsx')).readingLayouts;
});

after(async () => server?.close());

function render(layout, overrides = {}) {
    return renderToStaticMarkup(
        createElement(layouts[layout], {
            content: '# Publisher article\n\nArticle body.',
            contentUrl: 'https://publisher.example.com/writing/story',
            sourceUrl: 'https://news.ycombinator.com/item?id=42',
            readingMinutes: 3,
            archived: false,
            ...overrides,
        }),
    );
}

test('default layout preserves the source link and ordinary reading content', () => {
    const html = render('default', { sourceUrl: 'https://publisher.example.com/writing/story' });
    assert.match(html, /href="https:\/\/publisher\.example\.com\/writing\/story"/);
    assert.match(html, /Publisher article/);
    assert.match(html, /3 min read/);
    assert.doesNotMatch(html, /Hacker News|HN discussion|Archived/);
    assert.doesNotThrow(() => render('default', { sourceUrl: null, contentUrl: null, content: null }));
});

test('HN layout separates publisher content from the saved discussion link', () => {
    const html = render('hacker-news');
    assert.match(html, /href="https:\/\/publisher\.example\.com\/writing\/story"/);
    assert.match(html, /href="https:\/\/news\.ycombinator\.com\/item\?id=42"/);
    assert.match(html, /Saved from Hacker News/);
    assert.match(html, /HN discussion/);
    assert.match(html, /Publisher article/);
});

test('text-only HN entries can use the discussion as their content URL and remain archivable', () => {
    const html = render('hacker-news', {
        contentUrl: 'https://news.ycombinator.com/item?id=42',
        content: '# Ask HN\n\nThe story text.',
        archived: true,
    });
    assert.match(html, /Ask HN/);
    assert.match(html, /The story text/);
    assert.match(html, /Archived/);
    assert.match(html, /HN discussion/);
});

test('article links and images use the publisher base while footnotes remain local and unsafe HTML is stripped', () => {
    const html = render('hacker-news', {
        content: [
            '[Related](../related) [Root](/root) [External](https://other.example.com/path)',
            '![Diagram](images/chart.svg)',
            'A footnote.<a href="#fn-note">1</a>',
            '<p id="fn-note">Footnote body.</p>',
            '<script>alert(1)</script><img src="/safe.png" onerror="alert(2)">',
            '<a href="javascript:alert(3)">Unsafe link</a>',
        ].join('\n\n'),
    });
    assert.match(html, /href="https:\/\/publisher\.example\.com\/related"/);
    assert.match(html, /href="https:\/\/publisher\.example\.com\/root"/);
    assert.match(html, /href="https:\/\/other\.example\.com\/path"/);
    assert.match(html, /src="https:\/\/publisher\.example\.com\/writing\/images\/chart\.svg"/);
    assert.match(html, /src="https:\/\/publisher\.example\.com\/safe\.png"/);
    assert.match(html, /href="#user-content-fn-note"/);
    assert.match(html, /id="user-content-fn-note"/);
    assert.doesNotMatch(html, /<script|onerror|javascript:|alert\(/);
});
