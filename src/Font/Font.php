<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber\Font;

/**
 * What the text interpreter needs from a font: codes to CIDs, CIDs to text, widths and metrics.
 */
final class Font
{
    public string $fontname = 'unknown';

    /** Descent below the baseline in text space units for a font size of 1, normally negative. */
    public float $descent = 0.0;

    public float $hscale = 0.001;

    public float $vscale = 0.001;

    public bool $vertical = false;

    public bool $multibyte = false;

    /** @var array<int, mixed> CID => width in glyph units */
    public array $widths = [];

    /** @var array<array-key, mixed> text => width, for the standard 14 fonts */
    public array $textWidths = [];

    public float $defaultWidth = 0.0;

    /** @var array<int, string>|null CID => text from a ToUnicode map or a CID collection */
    public ?array $unicode = null;

    public bool $identityUnicode = false;

    /** @var array<int, string>|null code => text from the font encoding, for simple fonts */
    public ?array $encoding = null;

    public ?CMap $cmap = null;

    /** @var array<int, array{0: float|int|null, 1: float|int}> vertical displacement per CID */
    public array $displacements = [];

    /** @var array{0: float|int|null, 1: float|int} */
    public array $defaultDisplacement = [null, 880];

    /** @var array<int, string|null> */
    private array $textCache = [];

    /** @var array<int, float> */
    private array $widthCache = [];

    /**
     * @return list<int>|array<int, int>
     */
    public function decode(string $bytes): array
    {
        if ($this->cmap !== null) {
            return $this->cmap->decode($bytes);
        }

        return $bytes === '' ? [] : (unpack('C*', $bytes) ?: []);
    }

    /**
     * The text of a CID, or null when the font gives none.
     */
    public function text(int $cid): ?string
    {
        if (array_key_exists($cid, $this->textCache)) {
            return $this->textCache[$cid];
        }
        $text = $this->unicode[$cid] ?? null;
        if ($text === null && $this->identityUnicode) {
            $text = $cid >= 0xD800 && $cid <= 0xDFFF ? "\u{FFFD}" : Encodings::chr($cid);
        }
        if ($text === null && $this->encoding !== null) {
            $text = $this->encoding[$cid] ?? null;
        }

        return $this->textCache[$cid] = $text;
    }

    /**
     * Advance width of a CID in text space units for a font size of 1.
     */
    public function width(int $cid): float
    {
        if (isset($this->widthCache[$cid])) {
            return $this->widthCache[$cid];
        }
        $width = $this->widths[$cid] ?? null;
        if (! is_int($width) && ! is_float($width) && $this->textWidths !== []) {
            $text = $this->text($cid);
            $width = $text === null ? null : ($this->textWidths[$text] ?? null);
        }
        if (! is_int($width) && ! is_float($width)) {
            $width = $this->defaultWidth;
        }

        return $this->widthCache[$cid] = $width * $this->hscale;
    }

    /**
     * @return array{0: float|int|null, 1: float|int}
     */
    public function displacement(int $cid): array
    {
        return $this->displacements[$cid] ?? $this->defaultDisplacement;
    }
}
