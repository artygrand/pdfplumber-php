<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber;

/**
 * Bounding box helpers from pdfplumber; boxes are [x0, top, x1, bottom].
 */
final class Geometry
{
    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $a
     * @param array{0: float, 1: float, 2: float, 3: float} $b
     *
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    public static function overlap(array $a, array $b): ?array
    {
        $left = max($a[0], $b[0]);
        $right = min($a[2], $b[2]);
        $bottom = min($a[3], $b[3]);
        $top = max($a[1], $b[1]);
        $width = $right - $left;
        $height = $bottom - $top;
        if ($height >= 0 && $width >= 0 && $height + $width > 0) {
            return [$left, $top, $right, $bottom];
        }

        return null;
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $bbox
     */
    public static function area(array $bbox): float
    {
        if ($bbox[0] > $bbox[2] || $bbox[1] > $bbox[3]) {
            throw new \InvalidArgumentException('Bounding box '.json_encode($bbox).' has a negative width or height.');
        }

        return ($bbox[2] - $bbox[0]) * ($bbox[3] - $bbox[1]);
    }

    /**
     * An array key for exact coordinates, equal for 0.0 and -0.0 as Python's tuple keys are.
     */
    public static function key(float|int ...$values): string
    {
        return 'k'.pack('e*', ...array_map(static fn (float|int $v): float => $v + 0.0, $values));
    }
}
