<?php

namespace App\Domain\Entry\Actions;

use RuntimeException;

class BuildArticleOutlineAction
{
    public function handle(string $html): string
    {
        // Index source tokens rather than reserializing a DOM: model-written regexes
        // must match the original capture, including its quoting and whitespace.
        $regions = [];
        $ignored = [];
        $stack = [$this->region('#document', 0, 0, null, '', false)];
        $offset = 0;
        $nextId = 1;
        $length = strlen($html);
        $regionTags = explode(' ', 'body title main article section div nav aside header footer form details summary h1 h2 h3 h4 h5 h6 pre blockquote ul ol dl figure figcaption table iframe video audio svg');
        $atomicTags = explode(' ', 'pre blockquote ul ol dl figure table svg');
        $voidTags = explode(' ', 'area base br col embed hr img input link meta param source track wbr');
        $tokenPattern = '~<!--|<![^>]*>|<\?.*?\?>|</?([a-z][a-z0-9:-]*)\b(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*>~is';

        while ($offset < $length) {
            $matched = preg_match($tokenPattern, $html, $match, PREG_OFFSET_CAPTURE, $offset);

            if ($matched === false) {
                throw new RuntimeException('Unable to build the article source outline: '.preg_last_error_msg());
            }

            $position = $matched === 1 ? $match[0][1] : $length;
            $text = html_entity_decode(strip_tags(substr($html, $offset, $position - $offset)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
            $this->appendText($stack[array_key_last($stack)], strlen($text), $text, $text);

            if ($matched === 0) {
                break;
            }

            $token = $match[0][0];
            $offset = $position + strlen($token);
            $tag = strtolower($match[1][0] ?? '');

            if ($tag === '') {
                if ($token === '<!--') {
                    $end = strpos($html, '-->', $offset);
                    $offset = $end === false ? $length : $end + 3;
                }

                $ignored[] = [$position, $offset];

                continue;
            }

            if ($token[1] === '/') {
                for ($index = count($stack) - 1; $index > 0; $index--) {
                    if ($stack[$index]['tag'] === $tag) {
                        while (count($stack) > $index) {
                            $explicit = count($stack) - 1 === $index;
                            $this->closeRegion($stack, $regions, $explicit ? $offset : $position, $explicit ? $token : '');
                        }

                        break;
                    }
                }

                continue;
            }

            // Raw-text elements can contain fake tags and enormous serialized data.
            // Skip them before tokenizing further, not after collecting their text.
            if (in_array($tag, ['script', 'style'], true)) {
                $closed = preg_match('~</'.$tag.'\s*>~i', $html, $closing, PREG_OFFSET_CAPTURE, $offset);
                $offset = $closed === 1 ? $closing[0][1] + strlen($closing[0][0]) : $length;
                $ignored[] = [$position, $offset];

                continue;
            }

            $parent = $stack[array_key_last($stack)];
            $id = ! $parent['atomic'] && in_array($tag, $regionTags, true) ? $nextId++ : null;
            $stack[] = $this->region($tag, $position, $id, $parent['id'] ?? $parent['parent'], $token,
                $parent['atomic'] || in_array($tag, $atomicTags, true));

            if (in_array($tag, $voidTags, true) || str_ends_with($token, '/>')) {
                $this->closeRegion($stack, $regions, $offset, '');
            } elseif (in_array($tag, ['title', 'textarea'], true)) {
                // These elements treat apparent markup as text, not child regions.
                $closed = preg_match('~</'.$tag.'\s*>~i', $html, $closing, PREG_OFFSET_CAPTURE, $offset);
                $end = $closed === 1 ? $closing[0][1] : $length;
                $text = html_entity_decode(substr($html, $offset, $end - $offset), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
                $this->appendText($stack[array_key_last($stack)], strlen($text), $text, $text);
                $offset = $closed === 1 ? $end + strlen($closing[0][0]) : $length;
                $this->closeRegion($stack, $regions, $offset, $closed === 1 ? $closing[0][0] : '');
            }
        }

        while (count($stack) > 1) {
            $this->closeRegion($stack, $regions, $length, '');
        }

        $coveredText = array_sum(array_column(array_filter($regions, fn (array $region): bool => $region['parent'] === 0), 'text_bytes'));
        $includeDocument = $regions === [] || $coveredText < $stack[0]['text_bytes'];

        if ($includeDocument) {
            $regions[] = [...$stack[0], 'end' => $length, 'close' => ''];
        }

        usort($regions, fn (array $left, array $right): int => $left['id'] <=> $right['id']);
        $outline = [];
        $inlineRegions = [];

        foreach ($regions as $region) {
            if (isset($inlineRegions[$region['parent']])) {
                $inlineRegions[$region['id']] = true;

                continue;
            }

            $bytes = $region['end'] - $region['start'];
            $row = [
                'id' => $region['id'],
                'parent' => $region['parent'] === 0 && ! $includeDocument ? null : $region['parent'],
                'tag' => $region['tag'],
            ];

            // Small regions need only one exact snippet, not duplicated summaries.
            $source = $bytes <= 256 ? $this->snippet($html, $region['start'], $bytes, $ignored) : '';

            if ($source !== '') {
                $inlineRegions[$region['id']] = true;
            }

            $outline[] = $row + ($source !== '' ? ['html' => $source] : [
                'source_bytes' => $bytes,
                'text_bytes' => $region['text_bytes'],
                'descendants' => $region['descendants'],
                'first_text' => $region['first_text'],
                'last_text' => $region['last_text'],
                'start_snippet' => $this->snippet($html, $region['start'], min(64, $bytes), $ignored) ?: mb_strcut($region['open'], 0, 64, 'UTF-8'),
                'end_snippet' => $this->snippet($html, max($region['start'], $region['end'] - 64), min(64, $bytes), $ignored) ?: $region['close'],
            ]);
        }

        $json = json_encode($outline, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);

        // Never silently prune later sections just to fit a provider request.
        if (strlen($json) > 60000) {
            throw new RuntimeException('Article outline exceeds the 60000-byte planning budget. The saved HTML has not been truncated.');
        }

        return $json;
    }

    private function region(string $tag, int $start, ?int $id, ?int $parent, string $open, bool $atomic): array
    {
        return compact('tag', 'start', 'id', 'parent', 'open', 'atomic') + [
            'text_bytes' => 0, 'first_text' => '', 'last_text' => '', 'descendants' => [],
        ];
    }

    private function appendText(array &$region, int $bytes, string $first, string $last): void
    {
        if ($bytes === 0) {
            return;
        }

        $region['text_bytes'] += $bytes;
        $region['first_text'] = mb_strcut(ltrim($region['first_text'].' '.$first), 0, 64, 'UTF-8');
        $tail = ltrim($region['last_text'].' '.$last);
        $region['last_text'] = mb_strcut($tail, max(0, strlen($tail) - 64), 64, 'UTF-8');
    }

    private function closeRegion(array &$stack, array &$regions, int $end, string $close): void
    {
        $region = array_pop($stack);
        $parent = &$stack[array_key_last($stack)];
        $this->appendText($parent, $region['text_bytes'], $region['first_text'], $region['last_text']);

        $parent['descendants'][$region['tag']] = ($parent['descendants'][$region['tag']] ?? 0) + 1;

        foreach ($region['descendants'] as $tag => $count) {
            $parent['descendants'][$tag] = ($parent['descendants'][$tag] ?? 0) + $count;
        }

        if ($region['id'] !== null) {
            $regions[] = [...$region, 'end' => $end, 'close' => $close];
        }
    }

    private function snippet(string $html, int $start, int $length, array $ignored): string
    {
        foreach ($ignored as [$from, $to]) {
            if ($start < $to && $start + $length > $from) {
                return '';
            }
        }

        // Move a clipped UTF-8 start forward, not backward, so ending snippets
        // retain their closing tag instead of losing its last byte.
        while ($length > 0 && (ord($html[$start]) & 0xC0) === 0x80) {
            $start++;
            $length--;
        }

        return mb_strcut($html, $start, $length, 'UTF-8');
    }
}
