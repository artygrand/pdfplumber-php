<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber\Text;

use Artygrand\Pdfplumber\Char;

/**
 * pdfplumber's WordExtractor: groups characters into lines and words.
 */
final class WordExtractor
{
    private string $punctuation;

    public function __construct(private readonly TextSettings $settings)
    {
        $split = $settings->splitAtPunctuation;
        $this->punctuation = $split === true ? '!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~' : ($split ?: '');
    }

    /**
     * Words with the characters each one is made of.
     *
     * @param list<Char> $chars
     *
     * @return list<array{0: array<string, mixed>, 1: list<Char>}>
     */
    public function wordsWithChars(array $chars): array
    {
        $result = [];
        foreach ($this->groups($chars) as $group) {
            $lines = $this->settings->useTextFlow
                ? [[$group, $this->settings->charDir]]
                : $this->lines($group);
            foreach ($lines as [$lineChars, $direction]) {
                foreach ($this->words($lineChars, $direction) as $wordChars) {
                    $result[] = [$this->merge($wordChars), $wordChars];
                }
            }
        }

        return $result;
    }

    /**
     * @param list<Char> $chars
     *
     * @return list<array<string, mixed>>
     */
    public function words(array $chars, ?string $direction = null): array
    {
        if ($direction === null) {
            return array_map(static fn (array $pair): array => $pair[0], $this->wordsWithChars($chars));
        }

        return $this->splitWords($chars, $direction);
    }

    /**
     * Runs of consecutive characters sharing upright and the extra attributes.
     *
     * @param list<Char> $chars
     *
     * @return list<list<Char>>
     */
    private function groups(array $chars): array
    {
        $groups = [];
        $previous = null;
        foreach ($chars as $char) {
            $key = $this->groupKey($char);
            if ($key !== $previous || $groups === []) {
                $groups[] = [];
                $previous = $key;
            }
            $groups[count($groups) - 1][] = $char;
        }

        return $groups;
    }

    private function groupKey(Char $char): string
    {
        $key = $char->upright ? '1' : '0';
        foreach ($this->settings->extraAttrs as $attr) {
            $key .= "\0".serialize($char->{self::property($attr)});
        }

        return $key;
    }

    /**
     * @param list<Char> $chars
     *
     * @return list<array{0: list<Char>, 1: string}>
     */
    private function lines(array $chars): array
    {
        $upright = $chars[0]->upright;
        $lineDir = $upright ? $this->settings->lineDir : $this->settings->lineDirRotated();
        $charDir = $this->settings->charDirFor($upright);
        $tolerance = in_array($lineDir, ['ttb', 'btt'], true) ? $this->settings->yTolerance : $this->settings->xTolerance;
        $lines = [];
        foreach (Cluster::objects($chars, self::lineKey($lineDir), $tolerance) as $cluster) {
            $lines[] = [self::sortChars($cluster, $charDir), $charDir];
        }

        return $lines;
    }

    /**
     * @param list<Char> $chars
     *
     * @return list<list<Char>>
     */
    private function splitWords(array $chars, string $direction): array
    {
        $words = [];
        $current = [];
        $settings = $this->settings;
        foreach ($chars as $char) {
            $text = $char->text;
            if (! $settings->keepBlankChars && self::isSpace($text)) {
                if ($current !== []) {
                    $words[] = $current;
                }
                $current = [];
            } elseif (str_contains($this->punctuation, $text)) {
                if ($current !== []) {
                    $words[] = $current;
                }
                $words[] = [$char];
                $current = [];
            } elseif ($current !== [] && $this->beginsNewWord($current[count($current) - 1], $char, $direction)) {
                $words[] = $current;
                $current = [$char];
            } else {
                $current[] = $char;
            }
        }
        if ($current !== []) {
            $words[] = $current;
        }

        return $words;
    }

    private function beginsNewWord(Char $previous, Char $current, string $direction): bool
    {
        $settings = $this->settings;
        $xTolerance = $settings->xToleranceRatio === null
            ? $settings->xTolerance
            : $settings->xToleranceRatio * $previous->size;
        $yTolerance = $settings->yToleranceRatio === null
            ? $settings->yTolerance
            : $settings->yToleranceRatio * $previous->size;

        if ($direction === 'ltr' || $direction === 'rtl') {
            $x = $xTolerance;
            $y = $yTolerance;
            $ay = $previous->top;
            $cy = $current->top;
            if ($direction === 'ltr') {
                $ax = $previous->x0;
                $bx = $previous->x1;
                $cx = $current->x0;
            } else {
                $ax = -$previous->x1;
                $bx = -$previous->x0;
                $cx = -$current->x1;
            }
        } else {
            $x = $yTolerance;
            $y = $xTolerance;
            $ay = $previous->x0;
            $cy = $current->x0;
            if ($direction === 'ttb') {
                $ax = $previous->top;
                $bx = $previous->bottom;
                $cx = $current->top;
            } else {
                $ax = -$previous->bottom;
                $bx = -$previous->top;
                $cx = -$current->bottom;
            }
        }

        return $cx < $ax || $cx > $bx + $x || abs($cy - $ay) > $y;
    }

    /**
     * @param list<Char> $chars
     *
     * @return array<string, mixed>
     */
    private function merge(array $chars): array
    {
        $x0 = $chars[0]->x0;
        $top = $chars[0]->top;
        $x1 = $chars[0]->x1;
        $bottom = $chars[0]->bottom;
        $text = '';
        $expand = $this->settings->expandLigatures;
        foreach ($chars as $char) {
            $x0 = min($x0, $char->x0);
            $top = min($top, $char->top);
            $x1 = max($x1, $char->x1);
            $bottom = max($bottom, $char->bottom);
            $text .= $expand ? (TextSettings::LIGATURES[$char->text] ?? $char->text) : $char->text;
        }
        $first = $chars[0];
        $word = [
            'text' => $text,
            'x0' => $x0,
            'x1' => $x1,
            'top' => $top,
            'doctop' => $top + ($first->doctop - $first->top),
            'bottom' => $bottom,
            'upright' => $first->upright,
            'height' => $bottom - $top,
            'width' => $x1 - $x0,
            'direction' => $this->settings->charDirFor($first->upright),
        ];
        foreach ($this->settings->extraAttrs as $attr) {
            $word[$attr] = $first->{self::property($attr)};
        }

        return $word;
    }

    /**
     * @return callable(Char|array<string, mixed>): float
     */
    public static function lineKey(string $direction): callable
    {
        return match ($direction) {
            'ttb' => static fn ($o): float => is_array($o) ? $o['top'] : $o->top,
            'btt' => static fn ($o): float => -(is_array($o) ? $o['bottom'] : $o->bottom),
            'ltr' => static fn ($o): float => is_array($o) ? $o['x0'] : $o->x0,
            default => static fn ($o): float => -(is_array($o) ? $o['x1'] : $o->x1),
        };
    }

    /**
     * Sorts like pdfplumber's get_char_sort_key; the sort is stable.
     *
     * @template T of Char|array<string, mixed>
     *
     * @param list<T> $items
     *
     * @return list<T>
     */
    public static function sortChars(array $items, string $direction): array
    {
        $get = static fn ($o, string $key): float => is_array($o) ? $o[$key] : $o->{$key};
        $keys = [];
        foreach ($items as $i => $item) {
            $keys[$i] = match ($direction) {
                'ttb' => [$get($item, 'top'), $get($item, 'bottom')],
                'btt' => [-($get($item, 'top') + $get($item, 'height')), -$get($item, 'top')],
                'ltr' => [$get($item, 'x0'), $get($item, 'x0')],
                default => [-$get($item, 'x1'), -$get($item, 'x0')],
            };
        }
        uksort($keys, static fn ($a, $b): int => $keys[$a] <=> $keys[$b]);
        $sorted = [];
        foreach (array_keys($keys) as $i) {
            $sorted[] = $items[$i];
        }

        return $sorted;
    }

    /**
     * Python's str.isspace(): true for a non-empty string of whitespace only.
     */
    public static function isSpace(string $text): bool
    {
        if ($text === '') {
            return false;
        }
        if (strlen($text) === 1) {
            return $text === ' ' || $text === "\n" || $text === "\t" || $text === "\r"
                || $text === "\x0B" || $text === "\x0C" || ($text >= "\x1C" && $text <= "\x1F");
        }

        return preg_match('/^[\t\n\x0B\f\r\x1C-\x1F\x{85}\p{Z}]+$/u', $text) === 1;
    }

    public static function property(string $attr): string
    {
        return lcfirst(str_replace('_', '', ucwords($attr, '_')));
    }
}
