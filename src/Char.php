<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber;

/**
 * One rendered character, positioned as pdfplumber positions it: points from the top-left corner
 * of the page; y0/y1 are measured from the bottom like in PDF, top/bottom from the top.
 */
final class Char implements PageObject
{
    /**
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix the glyph's
     *        rendering matrix in PDF space, as pdfminer gives it
     * @param string $ncs the non-stroking colour space (DeviceGray, DeviceRGB, ICCBased, …)
     * @param list<mixed> $strokingColor colour components (or a pattern name)
     * @param list<mixed> $nonStrokingColor the fill colour, which is the text colour
     * @param int|null $mcid marked-content id, linking the char to the structure tree
     * @param string|null $tag marked-content tag (P, Span, Artifact, …)
     */
    public function __construct(
        public string $text,
        public string $fontname,
        public float $size,
        public float $adv,
        public bool $upright,
        public float $x0,
        public float $y0,
        public float $x1,
        public float $y1,
        public float $width,
        public float $height,
        public float $top,
        public float $bottom,
        public float $doctop,
        public int $pageNumber,
        public array $matrix = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        public string $ncs = 'DeviceGray',
        public array $strokingColor = [0],
        public array $nonStrokingColor = [0],
        public ?int $mcid = null,
        public ?string $tag = null,
    ) {
    }

    public function objectType(): string
    {
        return 'char';
    }

    /**
     * The char as pdfplumber's dict, keys in its order.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'matrix' => $this->matrix,
            'fontname' => $this->fontname,
            'adv' => $this->adv,
            'upright' => $this->upright,
            'x0' => $this->x0,
            'y0' => $this->y0,
            'x1' => $this->x1,
            'y1' => $this->y1,
            'width' => $this->width,
            'height' => $this->height,
            'size' => $this->size,
            'mcid' => $this->mcid,
            'tag' => $this->tag,
            'object_type' => 'char',
            'page_number' => $this->pageNumber,
            'ncs' => $this->ncs,
            'text' => $this->text,
            'stroking_color' => $this->strokingColor,
            'non_stroking_color' => $this->nonStrokingColor,
            'top' => $this->top,
            'bottom' => $this->bottom,
            'doctop' => $this->doctop,
        ];
    }
}
