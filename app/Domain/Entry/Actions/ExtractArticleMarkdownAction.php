<?php

namespace App\Domain\Entry\Actions;

use DOMDocument;
use DOMXPath;
use League\HTMLToMarkdown\HtmlConverter;
use RuntimeException;

class ExtractArticleMarkdownAction
{
    public function handle(string $rawHtml, array $plan): string
    {
        return $this->renderRanges($rawHtml, $this->captureRanges($rawHtml, $plan));
    }

    public function captureRanges(string $rawHtml, array $plan): array
    {
        if (($plan['version'] ?? null) !== 1 || ! is_array($plan['objects'] ?? null)
            || ! array_is_list($plan['objects'])) {
            throw new RuntimeException('Extraction plan must contain version 1 and an ordered list of objects.');
        }

        $ranges = [];
        $backtrackLimit = ini_get('pcre.backtrack_limit');
        $recursionLimit = ini_get('pcre.recursion_limit');

        // Provider-generated regexes are untrusted. Bound their work without imposing
        // a model-response-sized limit on the article that local extraction can produce.
        ini_set('pcre.backtrack_limit', '1000000');
        ini_set('pcre.recursion_limit', '1000');

        try {
            foreach ($plan['objects'] as $objectIndex => $object) {
                if (! is_array($object) || ($object['type'] ?? null) !== 'article') {
                    throw new RuntimeException('Unsupported extraction object; only article is supported.');
                }

                $patterns = $object['patterns'] ?? null;

                if (! is_array($patterns) || ! array_is_list($patterns) || $patterns === []) {
                    throw new RuntimeException('Article objects require an ordered, nonempty list of regex patterns.');
                }

                foreach ($patterns as $patternIndex => $pattern) {
                    $label = 'Object '.($objectIndex + 1).', pattern '.($patternIndex + 1);

                    if (! is_string($pattern) || $pattern === '' || strlen($pattern) > 8192) {
                        throw new RuntimeException($label.' must be a regex of at most 8192 bytes.');
                    }

                    $count = @preg_match_all($pattern, $rawHtml, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);

                    if ($count === false) {
                        throw new RuntimeException($label.' failed: '.preg_last_error_msg());
                    }

                    if ($count === 0) {
                        throw new RuntimeException($label.' did not match the saved HTML.');
                    }

                    foreach ($matches as $match) {
                        [$html, $offset] = $match['content'] ?? [null, -1];

                        if (! is_string($html) || trim($html) === '' || $offset < 0) {
                            throw new RuntimeException($label.' requires a nonempty named content capture.');
                        }

                        // Array order is reading order; matches within a pattern retain
                        // source order. Only capture ranges, not context matches, overlap.
                        $ranges[] = [$offset, $offset + strlen($html)];
                    }
                }
            }
        } finally {
            ini_set('pcre.backtrack_limit', $backtrackLimit);
            ini_set('pcre.recursion_limit', $recursionLimit);
        }

        $sorted = $ranges;
        usort($sorted, fn (array $left, array $right): int => $left[0] <=> $right[0]);
        $end = 0;

        foreach ($sorted as [$start, $nextEnd]) {
            if ($start < $end) {
                throw new RuntimeException('Extraction patterns captured overlapping content.');
            }

            $end = $nextEnd;
        }

        return $ranges;
    }

    public function renderRanges(string $rawHtml, array $ranges): string
    {
        if ($ranges === []) {
            throw new RuntimeException('Extraction did not select any article content.');
        }

        $fragments = [];
        $previousEnd = null;

        // Adjacent source ranges may split a word, tag container or code line.
        // Rejoin their original bytes before conversion, without inserting whitespace.
        foreach ($ranges as [$start, $end]) {
            $html = substr($rawHtml, $start, $end - $start);

            if ($start === $previousEnd) {
                $fragments[array_key_last($fragments)] .= $html;
            } else {
                $fragments[] = $html;
            }

            $previousEnd = $end;
        }

        // Layout wrappers must not surround generated Markdown: CommonMark treats
        // it as literal text inside an HTML block. Preserve tables as complete HTML
        // instead, since the existing reader supports HTML but not GFM pipe tables.
        $document = new DOMDocument;
        $previousErrors = libxml_use_internal_errors(true);

        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.implode("\n\n", $fragments), LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }

        $xpath = new DOMXPath($document);

        foreach ($xpath->query('//script | //style') as $node) {
            $node->parentNode->removeChild($node);
        }

        // Highlighting markup is presentation, not code. Read actual line cells,
        // keeping indentation/newlines without gutters or serialized span/div tags.
        foreach ($xpath->query('//pre') as $pre) {
            $lines = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " ec-line ")]//*[contains(concat(" ", normalize-space(@class), " "), " code ")]', $pre);
            $text = $lines->length > 0
                ? implode("\n", array_map(fn ($line): string => $line->textContent, iterator_to_array($lines)))
                : $pre->textContent;
            $code = $document->createElement('code');
            $language = $pre->getAttribute('data-language');
            $code->setAttribute('class', $language !== '' ? 'language-'.$language : ($xpath->query('.//code', $pre)->item(0)?->getAttribute('class') ?? ''));
            $code->appendChild($document->createTextNode($text));
            $pre->replaceChildren($code);
        }

        foreach ($xpath->query('//code[not(ancestor::pre)]') as $code) {
            $code->replaceChildren($document->createTextNode($code->textContent));
        }

        // Collapse only identical variants within a code widget, never repeated
        // listings or log lines elsewhere in the article. Keep any real captions.
        foreach ($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " code-block-wrapper ")]') as $wrapper) {
            foreach ($xpath->query('.//button', $wrapper) as $button) {
                $button->parentNode->removeChild($button);
            }
            $pres = iterator_to_array($xpath->query('.//pre', $wrapper));

            if ($pres !== [] && $xpath->query('.//figcaption', $wrapper)->length === 0
                && count(array_unique(array_map(fn ($pre): string => $pre->textContent, $pres))) === 1) {
                $wrapper->parentNode->replaceChild($pres[0], $wrapper);
            }
        }

        // Markdown cannot play a video, but its source and caption must survive.
        foreach ($xpath->query('//video') as $video) {
            $src = $video->getAttribute('src') ?: $xpath->query('.//source[@src]', $video)->item(0)?->getAttribute('src');

            if ($src) {
                $link = $document->createElement('a', 'Video');
                $link->setAttribute('href', $src);
                $paragraph = $document->createElement('p');
                $paragraph->appendChild($link);
                $video->parentNode->replaceChild($paragraph, $video);
            }
        }

        $tables = [];

        foreach ($xpath->query('//table[not(ancestor::table)]') as $table) {
            $html = $document->saveHTML($table);
            $placeholder = 'GLADETABLE'.bin2hex(random_bytes(16));
            $tables[$placeholder] = $html;
            $paragraph = $document->createElement('p', $placeholder);
            $table->parentNode->replaceChild($paragraph, $table);
        }

        // Keep source fragment destinations (including footnote targets) when their
        // surrounding wrappers/heading attributes are removed during conversion.
        foreach ($xpath->query('//*[@id and not(ancestor::pre or ancestor::code or self::pre or self::code) and not(self::a[not(@href)])]') as $node) {
            $anchor = $document->createElement('a');
            $anchor->setAttribute('id', $node->getAttribute('id'));
            $node->removeAttribute('id');
            $node->parentNode->insertBefore($anchor, $node);
        }

        // Convert once so nested structures and cross-fragment links stay together.
        $markdown = (new HtmlConverter([
            'header_style' => 'atx',
            'strip_tags' => true,
            'remove_nodes' => 'script style',
        ]))->convert($document->saveHTML($document->documentElement));
        $markdown = strtr($markdown, $tables);

        if (trim($markdown) === '') {
            throw new RuntimeException('Extraction did not produce article content.');
        }

        return trim($markdown);
    }
}
