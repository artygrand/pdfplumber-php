<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber\Table;

use Artygrand\Pdfplumber\Char;
use Artygrand\Pdfplumber\Geometry;
use Artygrand\Pdfplumber\Page;
use Artygrand\Pdfplumber\Text\Cluster;
use Artygrand\Pdfplumber\Text\TextMap;
use Artygrand\Pdfplumber\Text\TextSettings;
use Artygrand\Pdfplumber\Text\WordExtractor;

/**
 * A table found on a page: its cells as [x0, top, x1, bottom].
 */
final class Table
{
    /**
     * @param list<array{0: float, 1: float, 2: float, 3: float}> $cells
     */
    public function __construct(public readonly Page $page, public readonly array $cells)
    {
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public function bbox(): array
    {
        return [
            min(array_column($this->cells, 0)),
            min(array_column($this->cells, 1)),
            max(array_column($this->cells, 2)),
            max(array_column($this->cells, 3)),
        ];
    }

    /**
     * Rows of cells, null where a row has no cell in a column.
     *
     * @return list<list<array{0: float, 1: float, 2: float, 3: float}|null>>
     */
    public function rows(): array
    {
        return $this->groups(0);
    }

    /**
     * Columns of cells, null where a column has no cell in a row.
     *
     * @return list<list<array{0: float, 1: float, 2: float, 3: float}|null>>
     */
    public function columns(): array
    {
        return $this->groups(1);
    }

    /**
     * The text of every cell, row by row: null for a missing cell, '' for an empty one.
     *
     * @param array<string, mixed> $options text options (layout, xTolerance, …)
     *
     * @return list<list<string|null>>
     */
    public function extract(array $options = []): array
    {
        $chars = $this->page->chars();
        $table = [];
        foreach ($this->rows() as $row) {
            $rowBbox = self::groupBbox($row);
            $rowChars = array_filter($chars, static fn (Char $c): bool => self::contains($c, $rowBbox));
            $texts = [];
            foreach ($row as $cell) {
                if ($cell === null) {
                    $texts[] = null;
                    continue;
                }
                $cellChars = array_values(array_filter($rowChars, static fn (Char $c): bool => self::contains($c, $cell)));
                if ($cellChars === []) {
                    $texts[] = '';
                    continue;
                }
                $cellOptions = $options;
                if (array_key_exists('layout', $options)) {
                    $cellOptions['layout_width'] = $cell[2] - $cell[0];
                    $cellOptions['layout_height'] = $cell[3] - $cell[1];
                    $cellOptions['layout_bbox'] = $cell;
                }
                $texts[] = self::text($cellChars, $cellOptions);
            }
            $table[] = $texts;
        }

        return $table;
    }

    /**
     * pdfplumber's utils.extract_text.
     *
     * @param list<Char> $chars
     * @param array<string, mixed> $options
     */
    private static function text(array $chars, array $options): string
    {
        $settings = TextSettings::from($options);
        $extractor = new WordExtractor($settings);
        if ($settings->layout) {
            $settings->presorted = true;

            return TextMap::fromWords($extractor->wordsWithChars($chars), $settings)->text;
        }
        $lineRender = $settings->lineDirRender ?? $settings->lineDir;
        $charRender = $settings->charDirRender ?? $settings->charDir;
        $words = array_column($extractor->wordsWithChars($chars), 0);
        $lines = Cluster::objects(
            $words,
            WordExtractor::lineKey($settings->lineDir),
            in_array($lineRender, ['ttb', 'btt'], true) ? $settings->yTolerance : $settings->xTolerance,
        );
        $text = implode("\n", array_map(
            static fn (array $line): string => implode(' ', array_column($line, 'text')),
            $lines,
        ));
        $tuples = array_map(static fn (string $letter): array => [$letter, null], mb_str_split($text));

        return (new TextMap($tuples, $lineRender, $charRender))->text;
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $bbox
     */
    private static function contains(Char $char, array $bbox): bool
    {
        $vMid = ($char->top + $char->bottom) / 2;
        $hMid = ($char->x0 + $char->x1) / 2;

        return $hMid >= $bbox[0] && $hMid < $bbox[2] && $vMid >= $bbox[1] && $vMid < $bbox[3];
    }

    /**
     * @param list<array{0: float, 1: float, 2: float, 3: float}|null> $cells
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private static function groupBbox(array $cells): array
    {
        $cells = array_filter($cells);

        return [
            min(array_column($cells, 0)),
            min(array_column($cells, 1)),
            max(array_column($cells, 2)),
            max(array_column($cells, 3)),
        ];
    }

    /**
     * @return list<list<array{0: float, 1: float, 2: float, 3: float}|null>>
     */
    private function groups(int $axis): array
    {
        $antiaxis = 1 - $axis;
        $cells = $this->cells;
        usort($cells, static fn (array $a, array $b): int => [$a[$antiaxis], $a[$axis]] <=> [$b[$antiaxis], $b[$axis]]);
        $positions = [];
        foreach ($this->cells as $cell) {
            $positions[Geometry::key($cell[$axis])] = $cell[$axis];
        }
        asort($positions);

        $groups = [];
        $current = null;
        $byPosition = [];
        foreach ($cells as $cell) {
            if ($current !== null && $cell[$antiaxis] != $current) {
                $groups[] = $byPosition;
                $byPosition = [];
            }
            $current = $cell[$antiaxis];
            $byPosition[Geometry::key($cell[$axis])] = $cell;
        }
        $groups[] = $byPosition;

        return array_map(
            static fn (array $group): array => array_map(
                static fn (string $key): ?array => $group[$key] ?? null,
                array_keys($positions),
            ),
            $groups,
        );
    }
}
