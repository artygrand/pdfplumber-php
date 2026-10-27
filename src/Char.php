<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber;

/**
 * One rendered character, positioned as pdfplumber positions it: points from the top-left corner
 * of the page; y0/y1 are measured from the bottom like in PDF, top/bottom from the top.
 */
final class Char
{
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
        public float $top,
        public float $bottom,
        public float $doctop,
        public int $pageNumber,
    ) {
    }

    public function width(): float
    {
        return $this->x1 - $this->x0;
    }

    public function height(): float
    {
        return $this->bottom - $this->top;
    }

    /**
     * @return array{text: string, fontname: string, size: float, adv: float, upright: bool, x0: float,
     *     y0: float, x1: float, y1: float, top: float, bottom: float, doctop: float, width: float,
     *     height: float, page_number: int}
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'fontname' => $this->fontname,
            'size' => $this->size,
            'adv' => $this->adv,
            'upright' => $this->upright,
            'x0' => $this->x0,
            'y0' => $this->y0,
            'x1' => $this->x1,
            'y1' => $this->y1,
            'top' => $this->top,
            'bottom' => $this->bottom,
            'doctop' => $this->doctop,
            'width' => $this->width(),
            'height' => $this->height(),
            'page_number' => $this->pageNumber,
        ];
    }
}
