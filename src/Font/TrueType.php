<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber\Font;

/**
 * Reads the cmap table of an embedded TrueType font to map glyph ids back to Unicode.
 */
final class TrueType
{
    /**
     * Glyph id => text, or null when the font has no Unicode cmap.
     *
     * @return array<int, string>|null
     */
    public static function unicodeMap(string $font): ?array
    {
        try {
            return self::read($font);
        } catch (\ValueError) {
            return null;
        }
    }

    /**
     * @return array<int, string>|null
     */
    private static function read(string $font): ?array
    {
        if (strlen($font) < 12) {
            return null;
        }
        $tableCount = self::u16($font, 4);
        $cmapOffset = null;
        for ($i = 0; $i < $tableCount; $i++) {
            $record = 12 + $i * 16;
            if ($record + 16 > strlen($font)) {
                break;
            }
            if (substr($font, $record, 4) === 'cmap') {
                $cmapOffset = self::u32($font, $record + 8);
            }
        }
        if ($cmapOffset === null || $cmapOffset + 4 > strlen($font)) {
            return null;
        }

        $charToGlyph = [];
        $subtables = self::u16($font, $cmapOffset + 2);
        for ($i = 0; $i < $subtables; $i++) {
            $record = $cmapOffset + 4 + $i * 8;
            if ($record + 8 > strlen($font)) {
                break;
            }
            $platform = self::u16($font, $record);
            $encoding = self::u16($font, $record + 2);
            if (! ($platform === 0 || ($platform === 3 && ($encoding === 1 || $encoding === 10)))) {
                continue;
            }
            $at = $cmapOffset + self::u32($font, $record + 4);
            try {
                self::readSubtable($font, $at, $charToGlyph);
            } catch (\ValueError|\TypeError) {
                continue;
            }
        }
        if ($charToGlyph === []) {
            return null;
        }
        $map = [];
        foreach ($charToGlyph as $char => $glyph) {
            $text = Encodings::chr($char);
            if ($text === "\u{A0}" && ($map[$glyph] ?? null) === ' ') {
                continue;
            }
            $map[$glyph] = $text;
        }

        return $map;
    }

    /**
     * @param array<int, int> $map
     */
    private static function readSubtable(string $font, int $at, array &$map): void
    {
        $format = self::u16($font, $at);
        switch ($format) {
            case 0:
                if ($at + 6 + 256 > strlen($font)) {
                    throw new \ValueError('Truncated font');
                }
                for ($c = 0; $c < 256; $c++) {
                    $map[$c] = ord($font[$at + 6 + $c]);
                }
                break;
            case 2:
                $keys = [];
                for ($i = 0; $i < 256; $i++) {
                    $keys[$i] = self::u16($font, $at + 6 + $i * 2);
                }
                $firstBytes = array_fill(0, 8192, 0);
                foreach ($keys as $i => $key) {
                    $firstBytes[intdiv($key, 8)] = $i;
                }
                $headerCount = intdiv(max($keys), 8) + 1;
                $headersAt = $at + 6 + 512;
                for ($i = 0; $i < $headerCount; $i++) {
                    $h = $headersAt + $i * 8;
                    $firstCode = self::u16($font, $h);
                    $count = self::u16($font, $h + 2);
                    $delta = self::s16($font, $h + 4);
                    $pos = $h + 6 + self::u16($font, $h + 6);
                    $first = $firstCode + ($firstBytes[$i] << 8);
                    for ($c = 0; $c < $count; $c++) {
                        $glyph = self::u16($font, $pos + $c * 2);
                        if ($glyph !== 0) {
                            $glyph += $delta;
                        }
                        $map[$first + $c] = $glyph;
                    }
                }
                break;
            case 4:
                $segments = intdiv(self::u16($font, $at + 6), 2);
                $ends = $at + 14;
                $starts = $ends + $segments * 2 + 2;
                $deltas = $starts + $segments * 2;
                $offsets = $deltas + $segments * 2;
                for ($s = 0; $s < $segments; $s++) {
                    $end = self::u16($font, $ends + $s * 2);
                    $start = self::u16($font, $starts + $s * 2);
                    $delta = self::s16($font, $deltas + $s * 2);
                    $rangeOffset = self::u16($font, $offsets + $s * 2);
                    if ($rangeOffset !== 0) {
                        $pos = $offsets + $rangeOffset;
                        for ($c = $start; $c <= $end; $c++) {
                            $map[$c] = (self::u16($font, $pos) + $delta) & 0xFFFF;
                            $pos += 2;
                        }
                    } else {
                        for ($c = $start; $c <= $end; $c++) {
                            $map[$c] = ($c + $delta) & 0xFFFF;
                        }
                    }
                }
                break;
            case 6:
                $first = self::u16($font, $at + 6);
                $count = self::u16($font, $at + 8);
                for ($i = 0; $i < $count; $i++) {
                    $map[$first + $i] = self::u16($font, $at + 10 + $i * 2);
                }
                break;
            case 10:
                $first = self::u32($font, $at + 12);
                $count = self::u32($font, $at + 16);
                for ($i = 0; $i < $count; $i++) {
                    $map[$first + $i] = self::u16($font, $at + 20 + $i * 2);
                }
                break;
            case 12:
                $groups = self::u32($font, $at + 12);
                for ($g = 0; $g < $groups; $g++) {
                    $p = $at + 16 + $g * 12;
                    $start = self::u32($font, $p);
                    $end = self::u32($font, $p + 4);
                    $glyph = self::u32($font, $p + 8);
                    for ($c = $start; $c <= $end && $c - $start < 0x110000; $c++) {
                        $map[$c] = $glyph++;
                    }
                }
                break;
        }
    }

    private static function u16(string $data, int $at): int
    {
        if ($at < 0 || $at + 2 > strlen($data)) {
            throw new \ValueError('Truncated font');
        }

        return (ord($data[$at]) << 8) | ord($data[$at + 1]);
    }

    private static function s16(string $data, int $at): int
    {
        $value = self::u16($data, $at);

        return $value >= 0x8000 ? $value - 0x10000 : $value;
    }

    private static function u32(string $data, int $at): int
    {
        if ($at < 0 || $at + 4 > strlen($data)) {
            throw new \ValueError('Truncated font');
        }

        return (int) unpack('N', $data, $at)[1];
    }
}
