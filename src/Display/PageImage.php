<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber\Display;

use Artygrand\Pdfplumber\Exception;
use Artygrand\Pdfplumber\Page;
use Artygrand\Pdfplumber\PageObject;
use Artygrand\Pdfplumber\Table\Table;
use Artygrand\Pdfplumber\Table\TableFinder;
use Artygrand\Pdfplumber\Table\TableSettings;

/**
 * A rendered page to draw on, as pdfplumber's PageImage: outline chars, words, tables and any
 * boxes, lines or points given in page coordinates, then save it as PNG.
 *
 * Colours are [r, g, b] or [r, g, b, alpha] (0–255, alpha 255 is opaque) or "#rrggbb[aa]".
 */
final class PageImage
{
    public const RED = [255, 0, 0];

    public const GREEN = [0, 255, 0];

    public const BLUE = [0, 0, 255];

    public const TRANSPARENT = [0, 0, 0, 0];

    public const DEFAULT_FILL = [0, 0, 255, 50];

    public const DEFAULT_STROKE = [255, 0, 0, 200];

    /** pixels per page point */
    public readonly float $scale;

    /** @var array{0: float, 1: float, 2: float, 3: float} the part of the page shown, in page coordinates */
    public readonly array $bbox;

    private \GdImage $original;

    private \GdImage $annotated;

    /**
     * @param string|null $png the rendered page; rendered with Ghostscript when null
     */
    public function __construct(
        public readonly Page $page,
        ?string $png = null,
        public readonly float $resolution = 72,
        bool $antialias = false,
        bool $forceMediabox = false,
    ) {
        if (! extension_loaded('gd')) {
            throw new Exception('Drawing on a page image needs the gd extension');
        }
        $png ??= $page->render($resolution, $antialias);
        $original = imagecreatefromstring($png);
        if ($original === false) {
            throw new Exception('The page image is not a valid PNG');
        }
        $this->scale = imagesx($original) / ($page->cropbox[2] - $page->cropbox[0]);
        $this->bbox = $page->bbox !== $page->mediabox
            ? $page->bbox
            : ($forceMediabox ? $page->mediabox : $page->cropbox);
        if ($page->bbox !== $page->cropbox) {
            $crop = $this->reprojectBox($page->cropbox);
            $box = $this->reprojectBox($this->bbox);
            $cropped = imagecrop($original, [
                'x' => $box[0] - $crop[0],
                'y' => $box[1] - $crop[1],
                'width' => $box[2] - $box[0],
                'height' => $box[3] - $box[1],
            ]);
            $original = $cropped === false ? $original : $cropped;
        }
        $this->original = $original;
        $this->reset();
    }

    /**
     * Drops what was drawn.
     */
    public function reset(): self
    {
        $this->annotated = imagecreatetruecolor(imagesx($this->original), imagesy($this->original));
        imagecopy($this->annotated, $this->original, 0, 0, 0, 0, imagesx($this->original), imagesy($this->original));
        imagealphablending($this->annotated, true);

        return $this;
    }

    /**
     * A fresh image of the same page, without drawings.
     */
    public function copy(): self
    {
        return new self($this->page, $this->png($this->original, false), $this->resolution);
    }

    /**
     * The image with its drawings as PNG data, reduced to $colors colours when $quantize.
     */
    public function toPng(bool $quantize = true, int $colors = 256): string
    {
        return $this->png($this->annotated, $quantize, $colors);
    }

    public function save(string $path, bool $quantize = true, int $colors = 256): void
    {
        file_put_contents($path, $this->toPng($quantize, $colors));
    }

    /**
     * A line through points ([[x, top], …]), or along an object's pts, or from an object's top-left to
     * its bottom-right corner.
     *
     * @param list<array{0: float|int, 1: float|int}>|PageObject|array<string, mixed> $pointsOrObject
     * @param list<int>|string $stroke
     */
    public function drawLine(
        array|PageObject $pointsOrObject,
        array|string $stroke = self::DEFAULT_STROKE,
        int $strokeWidth = 1,
    ): self {
        $object = self::asArray($pointsOrObject);
        $points = match (true) {
            array_is_list($object) => $object,
            isset($object['pts']) => $object['pts'],
            default => [[$object['x0'], $object['top']], [$object['x1'], $object['bottom']]],
        };
        $color = $this->color($stroke);
        imagesetthickness($this->annotated, $strokeWidth);
        $points = array_map($this->reproject(...), $points);
        for ($i = 1; $i < count($points); $i++) {
            imageline($this->annotated, $points[$i - 1][0], $points[$i - 1][1], $points[$i][0], $points[$i][1], $color);
        }
        imagesetthickness($this->annotated, 1);

        return $this;
    }

    /**
     * @param iterable<mixed> $lines
     * @param list<int>|string $stroke
     */
    public function drawLines(iterable $lines, array|string $stroke = self::DEFAULT_STROKE, int $strokeWidth = 1): self
    {
        foreach ($lines as $line) {
            $this->drawLine($line, $stroke, $strokeWidth);
        }

        return $this;
    }

    /**
     * @param list<int>|string $stroke
     */
    public function drawVline(float $location, array|string $stroke = self::DEFAULT_STROKE, int $strokeWidth = 1): self
    {
        return $this->segment([$location, $this->bbox[1], $location, $this->bbox[3]], $stroke, $strokeWidth);
    }

    /**
     * @param iterable<float> $locations
     * @param list<int>|string $stroke
     */
    public function drawVlines(iterable $locations, array|string $stroke = self::DEFAULT_STROKE, int $strokeWidth = 1): self
    {
        foreach ($locations as $location) {
            $this->drawVline($location, $stroke, $strokeWidth);
        }

        return $this;
    }

    /**
     * @param list<int>|string $stroke
     */
    public function drawHline(float $location, array|string $stroke = self::DEFAULT_STROKE, int $strokeWidth = 1): self
    {
        return $this->segment([$this->bbox[0], $location, $this->bbox[2], $location], $stroke, $strokeWidth);
    }

    /**
     * @param iterable<float> $locations
     * @param list<int>|string $stroke
     */
    public function drawHlines(iterable $locations, array|string $stroke = self::DEFAULT_STROKE, int $strokeWidth = 1): self
    {
        foreach ($locations as $location) {
            $this->drawHline($location, $stroke, $strokeWidth);
        }

        return $this;
    }

    /**
     * A box ([x0, top, x1, bottom] or an object), filled and outlined.
     *
     * @param array{0: float|int, 1: float|int, 2: float|int, 3: float|int}|PageObject|array<string, mixed> $bboxOrObject
     * @param list<int>|string $fill
     * @param list<int>|string $stroke
     */
    public function drawRect(
        array|PageObject $bboxOrObject,
        array|string $fill = self::DEFAULT_FILL,
        array|string $stroke = self::DEFAULT_STROKE,
        int $strokeWidth = 1,
    ): self {
        $object = self::asArray($bboxOrObject);
        [$x0, $top, $x1, $bottom] = array_is_list($object)
            ? $object
            : [$object['x0'], $object['top'], $object['x1'], $object['bottom']];
        $half = $strokeWidth / 2;
        $x0 = min($x0 + $half, ($x0 + $x1) / 2);
        $top = min($top + $half, ($top + $bottom) / 2);
        $x1 = max($x1 - $half, ($x0 + $x1) / 2);
        $bottom = max($bottom - $half, ($top + $bottom) / 2);
        [$px0, $ptop, $px1, $pbottom] = $this->reprojectBox([$x0, $top, $x1, $bottom]);
        imagefilledrectangle($this->annotated, $px0, $ptop, $px1, $pbottom, $this->color($fill));
        if ($strokeWidth > 0) {
            $this->drawLines([
                [[$x0, $top], [$x1, $top]],
                [[$x0, $bottom], [$x1, $bottom]],
                [[$x0, $top], [$x0, $bottom]],
                [[$x1, $top], [$x1, $bottom]],
            ], $stroke, $strokeWidth);
        }

        return $this;
    }

    /**
     * @param iterable<mixed> $rects
     * @param list<int>|string $fill
     * @param list<int>|string $stroke
     */
    public function drawRects(
        iterable $rects,
        array|string $fill = self::DEFAULT_FILL,
        array|string $stroke = self::DEFAULT_STROKE,
        int $strokeWidth = 1,
    ): self {
        foreach ($rects as $rect) {
            $this->drawRect($rect, $fill, $stroke, $strokeWidth);
        }

        return $this;
    }

    /**
     * A circle around a point ([x, top]) or an object's centre.
     *
     * @param array{0: float|int, 1: float|int}|PageObject|array<string, mixed> $centerOrObject
     * @param list<int>|string $fill
     * @param list<int>|string $stroke
     */
    public function drawCircle(
        array|PageObject $centerOrObject,
        float $radius = 5,
        array|string $fill = self::DEFAULT_FILL,
        array|string $stroke = self::DEFAULT_STROKE,
    ): self {
        $object = self::asArray($centerOrObject);
        [$cx, $cy] = array_is_list($object)
            ? $object
            : [($object['x0'] + $object['x1']) / 2, ($object['top'] + $object['bottom']) / 2];
        [$x0, $top, $x1, $bottom] = $this->reprojectBox([$cx - $radius, $cy - $radius, $cx + $radius, $cy + $radius]);
        $centerX = intdiv($x0 + $x1, 2);
        $centerY = intdiv($top + $bottom, 2);
        imagefilledellipse($this->annotated, $centerX, $centerY, $x1 - $x0, $bottom - $top, $this->color($fill));
        imageellipse($this->annotated, $centerX, $centerY, $x1 - $x0, $bottom - $top, $this->color($stroke));

        return $this;
    }

    /**
     * @param iterable<mixed> $circles
     * @param list<int>|string $fill
     * @param list<int>|string $stroke
     */
    public function drawCircles(
        iterable $circles,
        float $radius = 5,
        array|string $fill = self::DEFAULT_FILL,
        array|string $stroke = self::DEFAULT_STROKE,
    ): self {
        foreach ($circles as $circle) {
            $this->drawCircle($circle, $radius, $fill, $stroke);
        }

        return $this;
    }

    /**
     * The cells of a table.
     *
     * @param list<int>|string $fill
     * @param list<int>|string $stroke
     */
    public function debugTable(
        Table $table,
        array|string $fill = self::DEFAULT_FILL,
        array|string $stroke = self::DEFAULT_STROKE,
        int $strokeWidth = 1,
    ): self {
        return $this->drawRects($table->cells, $fill, $stroke, $strokeWidth);
    }

    /**
     * The tables, edges and intersections the table finder sees with these settings.
     *
     * @param array<string, mixed>|TableSettings|TableFinder $settings
     */
    public function debugTablefinder(array|TableSettings|TableFinder $settings = []): self
    {
        $finder = $settings instanceof TableFinder ? $settings : $this->page->debugTablefinder($settings);
        foreach ($finder->tables as $table) {
            $this->debugTable($table);
        }
        $this->drawLines($finder->edges, strokeWidth: 1);
        $this->drawCircles(
            array_column($finder->intersections, 'point'),
            3,
            self::TRANSPARENT,
            [0, 0, 255, 200],
        );

        return $this;
    }

    /**
     * @param list<int>|string $stroke
     * @param list<int>|string $fill
     */
    public function outlineWords(
        array|string $stroke = self::DEFAULT_STROKE,
        array|string $fill = self::DEFAULT_FILL,
        int $strokeWidth = 1,
        float $xTolerance = 3,
        float $yTolerance = 3,
    ): self {
        $words = $this->page->extractWords(xTolerance: $xTolerance, yTolerance: $yTolerance);

        return $this->drawRects($words, $fill, $stroke, $strokeWidth);
    }

    /**
     * @param list<int>|string $stroke
     * @param list<int>|string $fill
     */
    public function outlineChars(
        array|string $stroke = [255, 0, 0, 255],
        array|string $fill = [255, 0, 0, 63],
        int $strokeWidth = 1,
    ): self {
        return $this->drawRects($this->page->chars(), $fill, $stroke, $strokeWidth);
    }

    /**
     * @param array{0: float|int, 1: float|int} $point page coordinates
     *
     * @return array{0: int, 1: int} pixels
     */
    private function reproject(array $point): array
    {
        return [(int) (($point[0] - $this->bbox[0]) * $this->scale), (int) (($point[1] - $this->bbox[1]) * $this->scale)];
    }

    /**
     * @param array{0: float|int, 1: float|int, 2: float|int, 3: float|int} $box
     *
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function reprojectBox(array $box): array
    {
        return [...$this->reproject([$box[0], $box[1]]), ...$this->reproject([$box[2], $box[3]])];
    }

    /**
     * @param array{0: float|int, 1: float|int, 2: float|int, 3: float|int} $box
     * @param list<int>|string $stroke
     */
    private function segment(array $box, array|string $stroke, int $strokeWidth): self
    {
        [$x0, $top, $x1, $bottom] = $this->reprojectBox($box);
        imagesetthickness($this->annotated, $strokeWidth);
        imageline($this->annotated, $x0, $top, $x1, $bottom, $this->color($stroke));
        imagesetthickness($this->annotated, 1);

        return $this;
    }

    /**
     * @param list<int>|string $color
     */
    private function color(array|string $color): int
    {
        if (is_string($color)) {
            $hex = ltrim($color, '#');
            if (! preg_match('/^([0-9a-f]{6}|[0-9a-f]{8})$/i', $hex)) {
                throw new \InvalidArgumentException("Unknown colour {$color}");
            }
            $color = array_map('hexdec', str_split($hex, 2));
        }
        [$r, $g, $b] = $color;
        $alpha = $color[3] ?? 255;

        return (int) imagecolorallocatealpha($this->annotated, $r, $g, $b, 127 - (int) round($alpha * 127 / 255));
    }

    /**
     * @return array<mixed>
     */
    private static function asArray(mixed $object): array
    {
        return $object instanceof PageObject ? $object->toArray() : $object;
    }

    private function png(\GdImage $image, bool $quantize, int $colors = 256): string
    {
        if ($quantize) {
            $copy = imagecreatetruecolor(imagesx($image), imagesy($image));
            imagecopy($copy, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
            imagetruecolortopalette($copy, false, $colors);
            $image = $copy;
        }
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
