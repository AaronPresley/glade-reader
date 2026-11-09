<?php

namespace App\Domain\Entry\Actions;

use App\Domain\SourceReference\Models\SourceReference;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class PreserveArticleSvgAction
{
    public function handle(string $html, SourceReference $sourceReference): string
    {
        $offset = 0;
        $previousEnd = 0;
        $depth = 0;
        $start = 0;
        $parts = [];
        $images = [];

        // Keep original SVG bytes before the HTML4 parser lowercases viewBox,
        // clipPath, etc. A token scan also handles nested SVGs and skips fake
        // markup in comments/raw-text elements without limiting diagram size.
        while (($matched = preg_match(BuildArticleOutlineAction::TOKEN_PATTERN, $html, $match, PREG_OFFSET_CAPTURE, $offset)) === 1) {
            $token = $match[0][0];
            $position = $match[0][1];
            $offset = $position + strlen($token);
            $tag = strtolower($match[1][0] ?? '');

            if ($token === '<!--') {
                $end = strpos($html, '-->', $offset);
                $offset = $end === false ? strlen($html) : $end + 3;
            } elseif (str_starts_with($token, '<![CDATA[')) {
                $end = strpos($html, ']]>', $position + 9);
                $offset = $end === false ? strlen($html) : $end + 3;
            } elseif ($tag !== '' && $token[1] !== '/' && ! str_ends_with($token, '/>')
                && in_array($tag, ['script', 'style', 'textarea', 'title', 'nav', 'button', 'form'], true)) {
                // Navigation/control icons are not article diagrams, even when
                // the model's selected region includes their enclosing wrapper.
                $closed = preg_match('~</'.$tag.'\s*>~i', $html, $closing, PREG_OFFSET_CAPTURE, $offset);
                $offset = $closed === 1 ? $closing[0][1] + strlen($closing[0][0]) : strlen($html);
            } elseif ($tag === 'svg') {
                if ($token[1] === '/') {
                    $depth--;
                } else {
                    if ($depth === 0) {
                        $start = $position;
                    }

                    if (! str_ends_with($token, '/>')) {
                        $depth++;
                    }
                }

                if ($depth === 0) {
                    $svg = substr($html, $start, $offset - $start);
                    preg_match(BuildArticleOutlineAction::TOKEN_PATTERN, $svg, $opening);

                    // Inline HTML SVGs may inherit namespaces from their page;
                    // standalone images need these declarations on their own root.
                    foreach (['xmlns' => 'http://www.w3.org/2000/svg', 'xmlns:xlink' => 'http://www.w3.org/1999/xlink'] as $attribute => $value) {
                        if (! preg_match('~\s'.preg_quote($attribute, '~').'\s*=~i', $opening[0])) {
                            $svg = substr_replace($svg, ' '.$attribute.'="'.$value.'"', 4, 0);
                        }
                    }

                    $hash = hash('sha256', $svg);

                    if (! isset($images[$hash])) {
                        if (! Storage::put($sourceReference->diagramDirectory().'/'.$hash.'.svg', $svg)) {
                            throw new RuntimeException('Unable to save an article SVG diagram.');
                        }

                        $images[$hash] = route('source-references.diagrams.show', [$sourceReference, $hash]);
                    }

                    $parts[] = substr($html, $previousEnd, $start - $previousEnd);
                    $parts[] = '<img alt="Source diagram" src="'.htmlspecialchars($images[$hash], ENT_QUOTES, 'UTF-8').'">';
                    $previousEnd = $offset;
                }
            }
        }

        if ($matched === false) {
            throw new RuntimeException('Unable to preserve article SVG diagrams: '.preg_last_error_msg());
        }

        if ($depth !== 0) {
            throw new RuntimeException('Selected article contains an incomplete SVG diagram.');
        }

        return implode('', $parts).substr($html, $previousEnd);
    }
}
