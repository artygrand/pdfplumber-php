<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber;

use Artygrand\Pdfplumber\Parser\Stream;

/**
 * An image drawn on the page (an image XObject or an inline image), positioned like pdfplumber's.
 */
final class Image implements PageObject
{
    /**
     * @param array{0: mixed, 1: mixed} $srcsize width and height in pixels, as the image dictionary gives them
     * @param list<mixed> $colorspace
     */
    public function __construct(
        public readonly string $name,
        public readonly Stream $stream,
        public readonly array $srcsize,
        public readonly mixed $imagemask,
        public readonly mixed $bits,
        public readonly array $colorspace,
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
        public ?string $tag = null,
    ) {
    }

    /**
     * The image data with its compression filters other than image ones undone: a JPEG stays a
     * JPEG (DCTDecode), so it can be saved as it is.
     */
    public function data(): string
    {
        return $this->stream->data();
    }

    public function objectType(): string
    {
        return 'image';
    }

    /**
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
            'name' => $this->name,
            'stream' => $this->stream,
            'srcsize' => $this->srcsize,
            'imagemask' => $this->imagemask,
            'bits' => $this->bits,
            'colorspace' => $this->colorspace,
            'mcid' => $this->mcid,
            'tag' => $this->tag,
            'object_type' => 'image',
            'page_number' => $this->pageNumber,
            'top' => $this->top,
            'bottom' => $this->bottom,
            'doctop' => $this->doctop,
        ];
    }
}
