<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber\Text;

use Artygrand\Pdfplumber\Char;

/**
 * pdfplumber's TextMap: the page text with each character pointing back to its Char
 * (or to nothing for whitespace added by the layout).
 */
final class TextMap
{
    public readonly string $text;

    /**
     * @param list<array{0: string, 1: Char|null}> $tuples
     */
    public function __construct(
        public readonly array $tuples,
        private readonly string $lineDirRender,
        private readonly string $charDirRender,
    ) {
        TextSettings::directions($lineDirRender, $charDirRender, '_render');
        $this->text = $this->render();
    }

    /**
     * Builds the map from words, as pdfplumber's WordMap.to_textmap.
     *
     * @param list<array{0: array<string, mixed>, 1: list<Char>}> $words
     */
    public static function fromWords(array $words, TextSettings $s): self
    {
        $lineRender = $s->lineDirRender ?? $s->lineDir;
        $charRender = $s->charDirRender ?? $s->charDir;
        if ($words === []) {
            return new self([], $lineRender, $charRender);
        }

        $map = [];
        $layoutWidthChars = $s->layoutWidthChars;
        $layoutHeightChars = $s->layoutHeightChars;
        if ($s->layout) {
            if ($layoutWidthChars !== 0) {
                if ($s->layoutWidth) {
                    throw new \InvalidArgumentException('layout_width and layout_width_chars cannot both be set.');
                }
            } else {
                $layoutWidthChars = (int) self::round(($s->layoutWidth ?? 0) / $s->xDensity);
            }
            if ($layoutHeightChars !== 0) {
                if ($s->layoutHeight) {
                    throw new \InvalidArgumentException('layout_height and layout_height_chars cannot both be set.');
                }
            } else {
                $layoutHeightChars = (int) self::round(($s->layoutHeight ?? 0) / $s->yDensity);
            }
            $blankLine = array_fill(0, max(0, $layoutWidthChars), [' ', null]);
        } else {
            $blankLine = [];
        }

        $lineKey = WordExtractor::lineKey($s->lineDir);
        $bbox = $s->layoutBbox ?? [0.0, 0.0, 0.0, 0.0];
        $yOrigin = $bbox[['ttb' => 1, 'btt' => 3, 'ltr' => 0, 'rtl' => 2][$s->lineDir]];
        $xOrigin = $bbox[['ttb' => 1, 'btt' => 3, 'ltr' => 0, 'rtl' => 2][$s->charDir]];
        $linePosition = ['ttb' => 'top', 'btt' => 'bottom', 'ltr' => 'x0', 'rtl' => 'x1'][$s->lineDir];
        $charPosition = ['ttb' => 'top', 'btt' => 'bottom', 'ltr' => 'x0', 'rtl' => 'x1'][$s->charDir];
        $keepOrder = $s->presorted || $s->useTextFlow;

        if (! $keepOrder) {
            $keys = array_map(static fn (array $pair): float => $lineKey($pair[0]), $words);
            asort($keys);
            $sorted = [];
            foreach (array_keys($keys) as $i) {
                $sorted[] = $words[$i];
            }
            $words = $sorted;
        }
        $lines = Cluster::objects(
            $words,
            static fn (array $pair): float => $lineKey($pair[0]),
            $s->yTolerance,
            $keepOrder,
        );

        $newlines = 0;
        $expansions = $s->expandLigatures ? TextSettings::LIGATURES : [];
        foreach ($lines as $i => $lineWords) {
            if ($s->layout) {
                $raw = $lineWords[0][0][$linePosition] - ($yOrigin + $s->yShift);
                $adjust = in_array($s->lineDir, ['btt', 'rtl'], true) ? -1 : 1;
                $distance = $raw * $adjust / $s->yDensity;
            } else {
                $distance = 0;
            }
            $prepend = max($i > 0 ? 1 : 0, (int) self::round($distance) - $newlines);
            for ($n = 0; $n < $prepend; $n++) {
                if ($map === [] || $map[count($map) - 1][0] === "\n") {
                    array_push($map, ...$blankLine);
                }
                $map[] = ["\n", null];
            }
            $newlines += $prepend;

            $lineLength = 0;
            if (! $keepOrder) {
                $lineWords = self::sortWords($lineWords, $s->charDir);
            }
            foreach ($lineWords as [$word, $chars]) {
                if ($s->layout) {
                    $raw = $word[$charPosition] - ($xOrigin + $s->xShift);
                    $adjust = in_array($s->charDir, ['btt', 'rtl'], true) ? -1 : 1;
                    $distance = $raw * $adjust / $s->xDensity;
                } else {
                    $distance = 0;
                }
                $spaces = max(min(1, $lineLength), (int) self::round($distance) - $lineLength);
                for ($n = 0; $n < $spaces; $n++) {
                    $map[] = [' ', null];
                }
                $lineLength += max(0, $spaces);
                foreach ($chars as $char) {
                    $letters = $expansions[$char->text] ?? $char->text;
                    foreach (self::letters($letters) as $letter) {
                        $map[] = [$letter, $char];
                        $lineLength++;
                    }
                }
            }
            if ($s->layout) {
                for ($n = $lineLength; $n < $layoutWidthChars; $n++) {
                    $map[] = [' ', null];
                }
            }
        }

        if ($s->layout) {
            $append = $layoutHeightChars - ($newlines + 1);
            for ($n = 0; $n < $append; $n++) {
                if ($n > 0) {
                    array_push($map, ...$blankLine);
                }
                $map[] = ["\n", null];
            }
            $last = $map[count($map) - 1] ?? null;
            if ($last !== null && $last[0] === "\n" && $last[1] === null) {
                array_pop($map);
            }
        }

        return new self($map, $lineRender, $charRender);
    }

    /**
     * Lines of text with their position, as pdfplumber's extract_text_lines.
     *
     * @return list<array<string, mixed>>
     */
    public function lines(bool $strip = true, bool $returnChars = true): array
    {
        $pattern = $strip ? '/ *([^\n]+?) *(\n|$)/u' : '/([^\n]+)/u';

        return $this->search($pattern, true, true, 1, $returnChars, false);
    }

    /**
     * Matches of a regular expression (PCRE, with delimiters) or a plain string in the text.
     *
     * @return list<array<string, mixed>>
     */
    public function search(
        string $pattern,
        bool $regex = true,
        bool $case = true,
        int $mainGroup = 0,
        bool $returnChars = true,
        bool $returnGroups = true,
    ): array {
        if (! $regex) {
            $pattern = '/'.preg_quote($pattern, '/').'/u';
        }
        if (! $case) {
            $pattern .= 'i';
        }
        if (preg_match_all($pattern, $this->text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            throw new \InvalidArgumentException('Invalid search pattern: '.preg_last_error_msg());
        }

        $results = [];
        foreach ($matches as $match) {
            $group = $match[$mainGroup] ?? null;
            if ($group === null || $group[1] < 0 || trim($group[0]) === '') {
                continue;
            }
            // PCRE offsets are in bytes; the map is indexed by characters.
            $start = mb_strlen(substr($this->text, 0, $group[1]));
            $length = mb_strlen($group[0]);
            $chars = [];
            foreach (array_slice($this->tuples, $start, $length) as [, $char]) {
                if ($char !== null) {
                    $chars[] = $char;
                }
            }
            if ($chars === []) {
                continue;
            }
            $result = [
                'text' => $group[0],
                'x0' => min(array_map(static fn (Char $c): float => $c->x0, $chars)),
                'top' => min(array_map(static fn (Char $c): float => $c->top, $chars)),
                'x1' => max(array_map(static fn (Char $c): float => $c->x1, $chars)),
                'bottom' => max(array_map(static fn (Char $c): float => $c->bottom, $chars)),
            ];
            if ($returnGroups) {
                $result['groups'] = array_map(
                    static fn (array $g): ?string => $g[1] < 0 ? null : $g[0],
                    array_slice($match, 1),
                );
            }
            if ($returnChars) {
                $result['chars'] = $chars;
            }
            $results[] = $result;
        }

        return $results;
    }

    private function render(): string
    {
        $base = '';
        foreach ($this->tuples as [$letter]) {
            $base .= $letter;
        }
        $charDir = $this->charDirRender;
        $lineDir = $this->lineDirRender;
        if ($charDir === 'ltr' && $lineDir === 'ttb') {
            return $base;
        }
        $lines = explode("\n", $base);
        if ($lineDir === 'btt' || $lineDir === 'rtl') {
            $lines = array_reverse($lines);
        }
        if ($charDir === 'rtl') {
            $lines = array_map(static fn (string $line): string => implode('', array_reverse(self::letters($line))), $lines);
        }
        if ($lineDir === 'rtl' || $lineDir === 'ltr') {
            $split = array_map(self::letters(...), $lines);
            $max = max(array_map('count', $split));
            foreach ($split as $i => $letters) {
                $padding = array_fill(0, $max - count($letters), ' ');
                $split[$i] = $charDir === 'btt' ? [...$padding, ...$letters] : [...$letters, ...$padding];
            }
            $columns = [];
            for ($c = 0; $c < $max; $c++) {
                $column = '';
                foreach ($split as $letters) {
                    $column .= $letters[$c];
                }
                $columns[] = $column;
            }

            return implode("\n", $columns);
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array{0: array<string, mixed>, 1: list<Char>}> $words
     *
     * @return list<array{0: array<string, mixed>, 1: list<Char>}>
     */
    private static function sortWords(array $words, string $direction): array
    {
        $keys = [];
        foreach ($words as $i => [$word]) {
            $keys[$i] = match ($direction) {
                'ttb' => [$word['top'], $word['bottom']],
                'btt' => [-($word['top'] + $word['height']), -$word['top']],
                'ltr' => [$word['x0'], $word['x0']],
                default => [-$word['x1'], -$word['x0']],
            };
        }
        uksort($keys, static fn ($a, $b): int => $keys[$a] <=> $keys[$b]);
        $result = [];
        foreach (array_keys($keys) as $i) {
            $result[] = $words[$i];
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private static function letters(string $text): array
    {
        if ($text === '') {
            return [];
        }
        if (strlen($text) === 1) {
            return [$text];
        }

        return mb_str_split($text, 1, 'UTF-8');
    }

    /**
     * Python's round(): halves go to the even number.
     */
    private static function round(float|int $value): float
    {
        return round($value, 0, PHP_ROUND_HALF_EVEN);
    }
}
