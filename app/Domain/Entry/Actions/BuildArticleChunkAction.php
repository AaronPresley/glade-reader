<?php

namespace App\Domain\Entry\Actions;

use RuntimeException;

class BuildArticleChunkAction
{
    public function handle(string $html, int $offset = 0, array $ancestors = []): ?array
    {
        $budget = 131072;

        while ($offset < strlen($html)) {
            [$end, $nextAncestors, $skip] = $this->boundary($html, $offset, $budget, $ancestors);

            if ($skip) {
                $offset = $end;

                continue;
            }

            $source = substr($html, $offset, $end - $offset);

            if (trim($source) === '') {
                $offset = $end;

                continue;
            }

            $outline = app(BuildArticleOutlineAction::class)->handle($source, $ancestors);

            // Bound each request, not the whole page. Dense markup gets smaller
            // source windows; a long text/code node is split on UTF-8 boundaries.
            if (strlen($outline) > 60000) {
                if ($budget <= 4) {
                    throw new RuntimeException('A single HTML token exceeds the chunk outline budget.');
                }

                $budget = max(4, intdiv($budget, 2));

                continue;
            }

            // Deep code/SVG wrappers must not hide whether this is an article,
            // navigation or an aside. Keep the nearest scope of each known kind
            // plus nearby tags, never the unbounded full ancestor chain.
            $context = array_slice($ancestors, -8, null, true);
            $scopes = [];

            foreach ($ancestors as $index => $ancestor) {
                if (in_array($ancestor['tag'], ['main', 'article', 'nav', 'aside', 'header', 'footer', 'form', 'pre', 'code', 'table', 'ul', 'ol', 'blockquote', 'svg', 'figure'], true)) {
                    $scopes[$ancestor['tag']] = $index;
                }
            }

            foreach ($scopes as $index) {
                $context[$index] = $ancestors[$index];
            }

            ksort($context);

            return [
                'start' => $offset, 'end' => $end, 'outline' => $outline,
                'context' => array_values($context), 'ancestors' => $nextAncestors,
            ];
        }

        return null;
    }

    private function boundary(string $html, int $start, int $budget, array $ancestors): array
    {
        $length = strlen($html);
        $offset = $start;

        while ($offset < $length) {
            $rawTag = $ancestors === [] ? null : $ancestors[array_key_last($ancestors)]['tag'];
            $pattern = in_array($rawTag, ['title', 'textarea'], true)
                ? '~</('.$rawTag.')\s*>~i' : BuildArticleOutlineAction::TOKEN_PATTERN;
            $matched = preg_match($pattern, $html, $match, PREG_OFFSET_CAPTURE, $offset);

            if ($matched === false) {
                throw new RuntimeException('Unable to split article HTML: '.preg_last_error_msg());
            }

            $position = $matched === 1 ? $match[0][1] : $length;

            if ($position - $start >= $budget) {
                $end = $start + strlen(mb_strcut($html, $start, $budget, 'UTF-8'));

                return [$end, $ancestors, false];
            }

            if ($matched === 0) {
                return [$length, $ancestors, false];
            }

            $token = $match[0][0];
            $tag = strtolower($match[1][0] ?? '');
            $end = $position + strlen($token);

            // Isolate ignored source ranges so a generic regex cannot match fake
            // article markup inside a script, stylesheet or comment.
            if ($tag === '' || ($token[1] !== '/' && in_array($tag, ['script', 'style'], true))) {
                if ($position > $start) {
                    return [$position, $ancestors, false];
                }

                if ($token === '<!--') {
                    $close = strpos($html, '-->', $end);
                    $end = $close === false ? $length : $close + 3;
                } elseif ($tag !== '') {
                    $closed = preg_match('~</'.$tag.'\s*>~i', $html, $closing, PREG_OFFSET_CAPTURE, $end);
                    $end = $closed === 1 ? $closing[0][1] + strlen($closing[0][0]) : $length;
                }

                return [$end, $ancestors, true];
            }

            if ($end - $start > $budget && $position > $start) {
                return [$position, $ancestors, false];
            }

            if ($token[1] === '/') {
                for ($index = count($ancestors) - 1; $index >= 0; $index--) {
                    if ($ancestors[$index]['tag'] === $tag) {
                        $ancestors = array_slice($ancestors, 0, $index);

                        break;
                    }
                }
            } elseif (! in_array($tag, BuildArticleOutlineAction::VOID_TAGS, true) && ! str_ends_with($token, '/>')) {
                $ancestors[] = ['tag' => $tag, 'open' => mb_strcut($token, 0, 128, 'UTF-8')];
            }

            $offset = $end;

            if ($offset - $start >= $budget) {
                return [$offset, $ancestors, false];
            }
        }

        return [$length, $ancestors, false];
    }
}
