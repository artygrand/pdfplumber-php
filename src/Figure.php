<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber;

/**
 * A form XObject or an image as placed on the page (pdfminer's LTFigure); pdfplumber lists these
 * only when the PDF is opened with layout analysis (laparams).
 */
final class Figure implements PageObject
{
    /** @var list<PageObject> what the figure draws, in order */
    public array $items = [];

    /**
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix
     */
    public function __construct(
        public readonly string $name,
        public readonly array $matrix,
        public float $x0,
        public float $y0,
        public float $x1,
        public float $y1,
        public float $top,
        public float $bottom,
        public float $doctop,
        public float $width,
        public float $height,
        public readonly int $pageNumber,
        public ?int $mcid = null,
    ) {
    }

    public function objectType(): string
    {
        return 'figure';
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'matrix' => $this->matrix,
            'x0' => $this->x0,
            'y0' => $this->y0,
            'x1' => $this->x1,
            'y1' => $this->y1,
            'width' => $this->width,
            'height' => $this->height,
            'object_type' => 'figure',
            'page_number' => $this->pageNumber,
            'top' => $this->top,
            'bottom' => $this->bottom,
            'doctop' => $this->doctop,
        ];
    }
}
