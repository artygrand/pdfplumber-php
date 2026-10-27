<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber;

/**
 * A painted path, sorted like pdfplumber sorts them: a straight "line", an axis-aligned "rect",
 * or any other "curve". Points are page coordinates [x, top].
 */
final class Graphic
{
    /**
     * @param 'line'|'rect'|'curve' $type
     * @param list<array{0: float, 1: float}> $pts
     */
    public function __construct(
        public readonly string $type,
        public float $x0,
        public float $y0,
        public float $x1,
        public float $y1,
        public float $top,
        public float $bottom,
        public float $doctop,
        public float $width,
        public float $height,
        public readonly array $pts,
        public readonly float $linewidth,
        public readonly bool $stroke,
        public readonly bool $fill,
        public readonly int $pageNumber,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'object_type' => $this->type,
            'x0' => $this->x0,
            'y0' => $this->y0,
            'x1' => $this->x1,
            'y1' => $this->y1,
            'top' => $this->top,
            'bottom' => $this->bottom,
            'doctop' => $this->doctop,
            'width' => $this->width,
            'height' => $this->height,
            'pts' => $this->pts,
            'linewidth' => $this->linewidth,
            'stroke' => $this->stroke,
            'fill' => $this->fill,
            'page_number' => $this->pageNumber,
        ];
    }
}
