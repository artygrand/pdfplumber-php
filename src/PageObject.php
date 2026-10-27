<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber;

/**
 * Anything placed on a page: a char, line, rect, curve, image, figure or a text box or line from
 * layout analysis. Positions are points from the page's top-left corner, with y0/y1 measured up
 * from the bottom as in PDF.
 *
 * @property float $x0
 * @property float $y0
 * @property float $x1
 * @property float $y1
 * @property float $top
 * @property float $bottom
 * @property float $doctop
 * @property float $width
 * @property float $height
 * @property int|null $mcid
 */
interface PageObject
{
    /**
     * pdfplumber's object_type: char, line, rect, curve, image, figure, textboxhorizontal, …
     */
    public function objectType(): string;

    /**
     * The object as pdfplumber's dict.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
