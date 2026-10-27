<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber\Table;

use Artygrand\Pdfplumber\Geometry;
use Artygrand\Pdfplumber\Graphic;
use Artygrand\Pdfplumber\Page;

/**
 * pdfplumber's TableFinder: edges from drawn lines (or from aligned words), their intersections,
 * the cells those form, and the tables of touching cells.
 */
final class TableFinder
{
    /** @var list<array<string, mixed>> */
    public readonly array $edges;

    /** @var array<string, array{point: array{0: float, 1: float}, v: list<array<string, mixed>>, h: list<array<string, mixed>>}> */
    public readonly array $intersections;

    /** @var list<array{0: float, 1: float, 2: float, 3: float}> */
    public readonly array $cells;

    /** @var list<Table> */
    public readonly array $tables;

    public function __construct(private readonly Page $page, private readonly TableSettings $settings)
    {
        $this->edges = $this->findEdges();
        $this->intersections = self::intersections(
            $this->edges,
            (float) $settings->intersectionXTolerance,
            (float) $settings->intersectionYTolerance,
        );
        $this->cells = self::cells($this->intersections);
        $this->tables = array_map(static fn (array $cells): Table => new Table($page, $cells), self::tables($this->cells));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findEdges(): array
    {
        $s = $this->settings;
        foreach (['vertical' => $s->explicitVerticalLines, 'horizontal' => $s->explicitHorizontalLines] as $axis => $lines) {
            $strategy = $axis === 'vertical' ? $s->verticalStrategy : $s->horizontalStrategy;
            if ($strategy === 'explicit' && count($lines ?? []) < 2) {
                throw new \InvalidArgumentException(
                    "With {$axis}Strategy 'explicit', explicit".ucfirst($axis).'Lines needs two or more lines.',
                );
            }
        }

        $words = $s->verticalStrategy === 'text' || $s->horizontalStrategy === 'text'
            ? $this->page->extractWords(false, ...$s->textSettings)
            : [];
        $bbox = $this->page->bbox;
        $lineStrategies = ['lines', 'lines_strict'];
        $pageEdges = in_array($s->verticalStrategy, $lineStrategies, true)
            || in_array($s->horizontalStrategy, $lineStrategies, true) ? $this->page->edges() : [];

        $vertical = match ($s->verticalStrategy) {
            'lines' => Edges::filter($pageEdges, 'v', minLength: $s->edgeMinLengthPrefilter),
            'lines_strict' => Edges::filter($pageEdges, 'v', 'line', $s->edgeMinLengthPrefilter),
            'text' => Edges::fromWordsVertical($words, $s->minWordsVertical),
            default => [],
        };
        foreach ($s->explicitVerticalLines ?? [] as $line) {
            if ($line instanceof Graphic || is_array($line)) {
                foreach (Edges::fromObject($line) as $edge) {
                    if ($edge['orientation'] === 'v') {
                        $vertical[] = $edge;
                    }
                }
            } else {
                $vertical[] = [
                    'x0' => $line,
                    'x1' => $line,
                    'top' => $bbox[1],
                    'bottom' => $bbox[3],
                    'height' => $bbox[3] - $bbox[1],
                    'orientation' => 'v',
                ];
            }
        }

        $horizontal = match ($s->horizontalStrategy) {
            'lines' => Edges::filter($pageEdges, 'h', minLength: $s->edgeMinLengthPrefilter),
            'lines_strict' => Edges::filter($pageEdges, 'h', 'line', $s->edgeMinLengthPrefilter),
            'text' => Edges::fromWordsHorizontal($words, $s->minWordsHorizontal),
            default => [],
        };
        foreach ($s->explicitHorizontalLines ?? [] as $line) {
            if ($line instanceof Graphic || is_array($line)) {
                foreach (Edges::fromObject($line) as $edge) {
                    if ($edge['orientation'] === 'h') {
                        $horizontal[] = $edge;
                    }
                }
            } else {
                $horizontal[] = [
                    'x0' => $bbox[0],
                    'x1' => $bbox[2],
                    'width' => $bbox[2] - $bbox[0],
                    'top' => $line,
                    'bottom' => $line,
                    'orientation' => 'h',
                ];
            }
        }

        $merged = Edges::merge(
            [...$vertical, ...$horizontal],
            (float) $s->snapXTolerance,
            (float) $s->snapYTolerance,
            (float) $s->joinXTolerance,
            (float) $s->joinYTolerance,
        );

        return Edges::filter($merged, minLength: $s->edgeMinLength);
    }

    /**
     * Points where a vertical and a horizontal edge cross (within the tolerances), with those edges.
     *
     * @param list<array<string, mixed>> $edges
     *
     * @return array<string, array{point: array{0: float, 1: float}, v: list<array<string, mixed>>, h: list<array<string, mixed>>}>
     */
    private static function intersections(array $edges, float $xTolerance, float $yTolerance): array
    {
        $vertical = array_values(array_filter($edges, static fn (array $e): bool => $e['orientation'] === 'v'));
        $horizontal = array_values(array_filter($edges, static fn (array $e): bool => $e['orientation'] === 'h'));
        usort($vertical, static fn (array $a, array $b): int => [$a['x0'], $a['top']] <=> [$b['x0'], $b['top']]);
        usort($horizontal, static fn (array $a, array $b): int => [$a['top'], $a['x0']] <=> [$b['top'], $b['x0']]);

        $intersections = [];
        foreach ($vertical as $v) {
            foreach ($horizontal as $h) {
                if ($v['top'] <= $h['top'] + $yTolerance
                    && $v['bottom'] >= $h['top'] - $yTolerance
                    && $v['x0'] >= $h['x0'] - $xTolerance
                    && $v['x0'] <= $h['x1'] + $xTolerance
                ) {
                    $key = Geometry::key($v['x0'], $h['top']);
                    $intersections[$key] ??= ['point' => [$v['x0'], $h['top']], 'v' => [], 'h' => []];
                    $intersections[$key]['v'][] = $v;
                    $intersections[$key]['h'][] = $h;
                }
            }
        }

        return $intersections;
    }

    /**
     * The smallest rectangle whose four corners are joined by edges, for each intersection.
     *
     * @param array<string, array{point: array{0: float, 1: float}, v: list<array<string, mixed>>, h: list<array<string, mixed>>}> $intersections
     *
     * @return list<array{0: float, 1: float, 2: float, 3: float}>
     */
    private static function cells(array $intersections): array
    {
        $edgeSets = [];
        foreach ($intersections as $key => $intersection) {
            foreach (['v', 'h'] as $orientation) {
                foreach ($intersection[$orientation] as $edge) {
                    $edgeSets[$key][$orientation][Geometry::key($edge['x0'], $edge['top'], $edge['x1'], $edge['bottom'])] = true;
                }
            }
        }
        $connects = static function (array $p1, array $p2) use ($edgeSets): bool {
            $k1 = Geometry::key(...$p1);
            $k2 = Geometry::key(...$p2);
            if ($p1[0] == $p2[0] && array_intersect_key($edgeSets[$k1]['v'], $edgeSets[$k2]['v']) !== []) {
                return true;
            }

            return $p1[1] == $p2[1] && array_intersect_key($edgeSets[$k1]['h'], $edgeSets[$k2]['h']) !== [];
        };

        $points = array_column($intersections, 'point');
        usort($points, static fn (array $a, array $b): int => $a <=> $b);
        $count = count($points);
        $cells = [];
        for ($i = 0; $i < $count - 1; $i++) {
            $point = $points[$i];
            $below = $right = [];
            for ($j = $i + 1; $j < $count; $j++) {
                if ($points[$j][0] == $point[0]) {
                    $below[] = $points[$j];
                }
                if ($points[$j][1] == $point[1]) {
                    $right[] = $points[$j];
                }
            }
            foreach ($below as $belowPoint) {
                if (! $connects($point, $belowPoint)) {
                    continue;
                }
                foreach ($right as $rightPoint) {
                    if (! $connects($point, $rightPoint)) {
                        continue;
                    }
                    $corner = [$rightPoint[0], $belowPoint[1]];
                    if (isset($intersections[Geometry::key(...$corner)])
                        && $connects($corner, $rightPoint)
                        && $connects($corner, $belowPoint)
                    ) {
                        $cells[] = [$point[0], $point[1], $corner[0], $corner[1]];
                        continue 3;
                    }
                }
            }
        }

        return $cells;
    }

    /**
     * Groups cells that share corners into tables, top to bottom, then left to right; a lone cell
     * is not a table.
     *
     * @param list<array{0: float, 1: float, 2: float, 3: float}> $cells
     *
     * @return list<list<array{0: float, 1: float, 2: float, 3: float}>>
     */
    private static function tables(array $cells): array
    {
        $corners = static fn (array $c): array => [
            Geometry::key($c[0], $c[1]), Geometry::key($c[0], $c[3]),
            Geometry::key($c[2], $c[1]), Geometry::key($c[2], $c[3]),
        ];
        $remaining = $cells;
        $currentCorners = [];
        $current = [];
        $tables = [];
        while ($remaining !== []) {
            $before = count($current);
            foreach ($remaining as $index => $cell) {
                $cellCorners = $corners($cell);
                if ($current !== []) {
                    $touching = false;
                    foreach ($cellCorners as $corner) {
                        if (isset($currentCorners[$corner])) {
                            $touching = true;
                            break;
                        }
                    }
                    if (! $touching) {
                        continue;
                    }
                }
                foreach ($cellCorners as $corner) {
                    $currentCorners[$corner] = true;
                }
                $current[] = $cell;
                unset($remaining[$index]);
            }
            if (count($current) === $before) {
                $tables[] = $current;
                $currentCorners = [];
                $current = [];
            }
        }
        if ($current !== []) {
            $tables[] = $current;
        }

        $tops = array_map(static function (array $table): array {
            $min = null;
            foreach ($table as $cell) {
                $key = [$cell[1], $cell[0]];
                if ($min === null || $key < $min) {
                    $min = $key;
                }
            }

            return $min;
        }, $tables);
        $order = array_keys($tables);
        usort($order, static fn (int $a, int $b): int => $tops[$a] <=> $tops[$b]);

        $sorted = [];
        foreach ($order as $index) {
            if (count($tables[$index]) > 1) {
                $sorted[] = $tables[$index];
            }
        }

        return $sorted;
    }
}
