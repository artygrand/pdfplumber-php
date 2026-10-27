<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber;

/**
 * A text line or text box found by layout analysis (pdfminer's LTTextLine and LTTextBox), with
 * its text: words separated by spaces, lines ending in a newline.
 */
final class TextBlock implements PageObject
{
    public ?int $mcid = null;

    /**
     * @param 'textboxhorizontal'|'textboxvertical'|'textlinehorizontal'|'textlinevertical' $type
     */
    public function __construct(
        public readonly string $type,
        public readonly string $text,
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
    ) {
    }

    public function objectType(): string
    {
        return $this->type;
    }

    public function toArray(): array
    {
        return [
            'x0' => $this->x0,
            'y0' => $this->y0,
            'x1' => $this->x1,
            'y1' => $this->y1,
            'width' => $this->width,
            'height' => $this->height,
            'object_type' => $this->type,
            'page_number' => $this->pageNumber,
            'text' => $this->text,
            'top' => $this->top,
            'bottom' => $this->bottom,
            'doctop' => $this->doctop,
        ];
    }
}
