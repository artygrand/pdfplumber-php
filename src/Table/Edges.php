<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber\Table;

use Artygrand\Pdfplumber\Geometry;
use Artygrand\Pdfplumber\Graphic;
use Artygrand\Pdfplumber\Text\Cluster;

/**
 * pdfplumber's edge geometry: edges are arrays with x0, x1, top, bottom, width, height and an
 * orientation ('h', 'v', or null for a slanted curve segment).
 */
final class Edges
{
    /**
     * @param Graphic|array<string, mixed> $object a graphic or an edge array
     *
     * @return list<array<string, mixed>>
     */
    public static function fromObject(Graphic|array $object): array
    {
        $object = $object instanceof Graphic ? $object->toArray() : $object;
        $type = (string) ($object['object_type'] ?? '');
        if (str_contains($type, '_edge')) {
            return [$object];
        }

        return match ($type) {
            'line' => [['orientation' => $object['top'] == $object['bottom'] ? 'h' : 'v'] + $object],
            'rect' => self::rectEdges($object),
            'curve' => self::curveEdges($object),
            default => throw new \InvalidArgumentException("Cannot make edges of a {$type}."),
        };
    }

    /**
     * @param list<array<string, mixed>> $edges
     *
     * @return list<array<string, mixed>>
     */
    public static function filter(
        array $edges,
        ?string $orientation = null,
        ?string $edgeType = null,
        float $minLength = 1,
    ): array {
        if ($orientation !== null && $orientation !== 'h' && $orientation !== 'v') {
            throw new \InvalidArgumentException("Orientation must be 'v' or 'h'");
        }

        return array_values(array_filter($edges, static function (array $e) use ($orientation, $edgeType, $minLength): bool {
            $dimension = $e['orientation'] === 'v' ? 'height' : 'width';

            return ($edgeType === null || $e['object_type'] === $edgeType)
                && ($orientation === null || $e['orientation'] === $orientation)
                && $e[$dimension] >= $minLength;
        }));
    }

    /**
     * Snaps edges close to each other onto their average position, then joins the collinear ones
     * that touch or nearly do.
     *
     * @param list<array<string, mixed>> $edges
     *
     * @return list<array<string, mixed>>
     */
    public static function merge(array $edges, float $snapX, float $snapY, float $joinX, float $joinY): array
    {
        if ($snapX > 0 || $snapY > 0) {
            $vertical = $horizontal = [];
            foreach ($edges as $edge) {
                if ($edge['orientation'] === 'v') {
                    $vertical[] = $edge;
                } elseif ($edge['orientation'] === 'h') {
                    $horizontal[] = $edge;
                } else {
                    throw new \InvalidArgumentException('Only horizontal and vertical edges can be merged.');
                }
            }
            $edges = [...self::snap($vertical, 'x0', $snapX), ...self::snap($horizontal, 'top', $snapY)];
        }

        $group = static fn (array $e): array => $e['orientation'] === 'h' ? ['h', $e['top']] : ['v', $e['x0']];
        usort($edges, static fn (array $a, array $b): int => $group($a) <=> $group($b));
        $merged = [];
        $run = [];
        $key = null;
        foreach ($edges as $edge) {
            $edgeKey = $group($edge);
            if ($key !== null && $edgeKey != $key) {
                array_push($merged, ...self::join($run, $key[0], $key[0] === 'h' ? $joinX : $joinY));
                $run = [];
            }
            $key = $edgeKey;
            $run[] = $edge;
        }
        if ($run !== []) {
            array_push($merged, ...self::join($run, $key[0], $key[0] === 'h' ? $joinX : $joinY));
        }

        return $merged;
    }

    /**
     * Imaginary horizontal lines along the tops and bottoms of rows of words.
     *
     * @param list<array<string, mixed>> $words
     *
     * @return list<array<string, mixed>>
     */
    public static function fromWordsHorizontal(array $words, int $threshold): array
    {
        $rects = [];
        foreach (Cluster::objects($words, static fn (array $w): float => $w['top'], 1) as $cluster) {
            if (count($cluster) >= $threshold) {
                $rects[] = self::bbox($cluster);
            }
        }
        if ($rects === []) {
            return [];
        }
        $minX0 = min(array_column($rects, 0));
        $maxX1 = max(array_column($rects, 2));
        $edges = [];
        foreach ($rects as $rect) {
            foreach ([$rect[1], $rect[3]] as $top) {
                $edges[] = [
                    'x0' => $minX0,
                    'x1' => $maxX1,
                    'top' => $top,
                    'bottom' => $top,
                    'width' => $maxX1 - $minX0,
                    'orientation' => 'h',
                ];
            }
        }

        return $edges;
    }

    /**
     * Imaginary vertical lines along the left sides, right sides or centres of columns of words.
     *
     * @param list<array<string, mixed>> $words
     *
     * @return list<array<string, mixed>>
     */
    public static function fromWordsVertical(array $words, int $threshold): array
    {
        $clusters = [
            ...Cluster::objects($words, static fn (array $w): float => $w['x0'], 1),
            ...Cluster::objects($words, static fn (array $w): float => $w['x1'], 1),
            ...Cluster::objects($words, static fn (array $w): float => ($w['x0'] + $w['x1']) / 2, 1),
        ];
        usort($clusters, static fn (array $a, array $b): int => count($b) <=> count($a));

        $condensed = [];
        foreach ($clusters as $cluster) {
            if (count($cluster) < $threshold) {
                continue;
            }
            $bbox = self::bbox($cluster);
            foreach ($condensed as $kept) {
                if (Geometry::overlap($bbox, $kept) !== null) {
                    continue 2;
                }
            }
            $condensed[] = $bbox;
        }
        if ($condensed === []) {
            return [];
        }
        usort($condensed, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $maxX1 = max(array_column($condensed, 2));
        $minTop = min(array_column($condensed, 1));
        $maxBottom = max(array_column($condensed, 3));
        $edges = [];
        foreach ([...array_column($condensed, 0), $maxX1] as $x) {
            $edges[] = [
                'x0' => $x,
                'x1' => $x,
                'top' => $minTop,
                'bottom' => $maxBottom,
                'height' => $maxBottom - $minTop,
                'orientation' => 'v',
            ];
        }

        return $edges;
    }

    /**
     * @param array<string, mixed> $rect
     *
     * @return list<array<string, mixed>>
     */
    private static function rectEdges(array $rect): array
    {
        return [
            ['object_type' => 'rect_edge', 'height' => 0, 'y0' => $rect['y1'], 'bottom' => $rect['top'],
                'orientation' => 'h'] + $rect,
            ['object_type' => 'rect_edge', 'height' => 0, 'y1' => $rect['y0'],
                'top' => $rect['top'] + $rect['height'], 'doctop' => $rect['doctop'] + $rect['height'],
                'orientation' => 'h'] + $rect,
            ['object_type' => 'rect_edge', 'width' => 0, 'x1' => $rect['x0'], 'orientation' => 'v'] + $rect,
            ['object_type' => 'rect_edge', 'width' => 0, 'x0' => $rect['x1'], 'orientation' => 'v'] + $rect,
        ];
    }

    /**
     * @param array<string, mixed> $curve
     *
     * @return list<array<string, mixed>>
     */
    private static function curveEdges(array $curve): array
    {
        $edges = [];
        $pts = $curve['pts'];
        for ($i = 1, $n = count($pts); $i < $n; $i++) {
            [$p0, $p1] = [$pts[$i - 1], $pts[$i]];
            $edges[] = [
                'object_type' => 'curve_edge',
                'x0' => min($p0[0], $p1[0]),
                'x1' => max($p0[0], $p1[0]),
                'top' => min($p0[1], $p1[1]),
                'doctop' => min($p0[1], $p1[1]) + ($curve['doctop'] - $curve['top']),
                'bottom' => max($p0[1], $p1[1]),
                'width' => abs($p0[0] - $p1[0]),
                'height' => abs($p0[1] - $p1[1]),
                'orientation' => $p0[0] == $p1[0] ? 'v' : ($p0[1] == $p1[1] ? 'h' : null),
            ];
        }

        return $edges;
    }

    /**
     * pdfplumber's snap_objects: moves each cluster of edges onto its average position.
     *
     * @param list<array<string, mixed>> $edges
     *
     * @return list<array<string, mixed>>
     */
    private static function snap(array $edges, string $attr, float $tolerance): array
    {
        $snapped = [];
        foreach (Cluster::objects($edges, static fn (array $e): float => $e[$attr], $tolerance) as $cluster) {
            $sum = 0;
            foreach ($cluster as $edge) {
                $sum += $edge[$attr];
            }
            $average = $sum / count($cluster);
            foreach ($cluster as $edge) {
                $value = $average - $edge[$attr];
                if ($attr === 'x0') {
                    $edge['x0'] += $value;
                    $edge['x1'] += $value;
                } else {
                    $edge['top'] += $value;
                    $edge['bottom'] += $value;
                    if (isset($edge['doctop'])) {
                        $edge['doctop'] += $value;
                    }
                    if (isset($edge['y0'])) {
                        $edge['y0'] -= $value;
                        $edge['y1'] -= $value;
                    }
                }
                $snapped[] = $edge;
            }
        }

        return $snapped;
    }

    /**
     * pdfplumber's join_edge_group: joins edges on one line that are within $tolerance of each other.
     *
     * @param list<array<string, mixed>> $edges
     *
     * @return list<array<string, mixed>>
     */
    private static function join(array $edges, string $orientation, float $tolerance): array
    {
        [$min, $max] = $orientation === 'h' ? ['x0', 'x1'] : ['top', 'bottom'];
        usort($edges, static fn (array $a, array $b): int => $a[$min] <=> $b[$min]);
        $joined = [array_shift($edges)];
        foreach ($edges as $edge) {
            $last = count($joined) - 1;
            if ($edge[$min] <= $joined[$last][$max] + $tolerance) {
                if ($edge[$max] > $joined[$last][$max]) {
                    $joined[$last] = self::resize($joined[$last], $max, $edge[$max]);
                }
            } else {
                $joined[] = $edge;
            }
        }

        return $joined;
    }

    /**
     * @param array<string, mixed> $edge
     *
     * @return array<string, mixed>
     */
    private static function resize(array $edge, string $key, float $value): array
    {
        $diff = $value - $edge[$key];
        $edge[$key] = $value;
        if ($key === 'x1') {
            $edge['width'] = $value - $edge['x0'];
        } else {
            $edge['height'] += $diff;
            if (isset($edge['y0'])) {
                $edge['y0'] -= $diff;
            }
        }

        return $edge;
    }

    /**
     * @param list<array<string, mixed>> $objects
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private static function bbox(array $objects): array
    {
        return [
            min(array_column($objects, 'x0')),
            min(array_column($objects, 'top')),
            max(array_column($objects, 'x1')),
            max(array_column($objects, 'bottom')),
        ];
    }
}
