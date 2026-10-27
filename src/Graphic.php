<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber;

/**
 * A painted path, sorted like pdfplumber sorts them: a straight "line", an axis-aligned "rect",
 * or any other "curve". Points are page coordinates [x, top].
 */
final class Graphic implements PageObject
{
    /**
     * @param 'line'|'rect'|'curve' $type
     * @param list<array{0: float, 1: float}> $pts
     * @param mixed $strokingColor a number for grey, a list of components, or a pattern name
     * @param list<list<mixed>> $path the path as drawn: [operator, [x, top], …] per segment
     * @param array{0: list<int|float>, 1: int|float}|null $dash dash array and phase
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
        public readonly bool $evenodd = false,
        public readonly mixed $strokingColor = 0,
        public readonly mixed $nonStrokingColor = 0,
        public readonly array $path = [],
        public readonly ?array $dash = null,
        public ?int $mcid = null,
        public ?string $tag = null,
    ) {
    }

    public function objectType(): string
    {
        return $this->type;
    }

    /**
     * The object as pdfplumber's dict, keys in its order.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'x0' => $this->x0,
            'y0' => $this->y0,
            'x1' => $this->x1,
            'y1' => $this->y1,
            'width' => $this->width,
            'height' => $this->height,
            'pts' => $this->pts,
            'linewidth' => $this->linewidth,
            'stroke' => $this->stroke,
            'fill' => $this->fill,
            'evenodd' => $this->evenodd,
            'stroking_color' => $this->strokingColor,
            'non_stroking_color' => $this->nonStrokingColor,
            'mcid' => $this->mcid,
            'tag' => $this->tag,
            'object_type' => $this->type,
            'page_number' => $this->pageNumber,
            'path' => $this->path,
            'dash' => $this->dash,
            'top' => $this->top,
            'bottom' => $this->bottom,
            'doctop' => $this->doctop,
        ];
    }
}
