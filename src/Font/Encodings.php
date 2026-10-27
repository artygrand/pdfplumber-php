<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber\Font;

/**
 * Glyph names and the Latin text encodings, as pdfminer.six reads them.
 */
final class Encodings
{
    /** @var array<string, string>|null */
    private static ?array $glyphs = null;

    /** @var array<string, array<int, string>>|null */
    private static ?array $tables = null;

    /**
     * Unicode text for an Adobe glyph name (A, uni0041, u1F600, f_i), or null when unknown.
     */
    public static function glyph(string $name): ?string
    {
        $name = explode('.', $name, 2)[0];
        $parts = explode('_', $name);
        if (count($parts) > 1) {
            $text = '';
            foreach ($parts as $part) {
                $piece = self::glyph($part);
                if ($piece === null) {
                    return null;
                }
                $text .= $piece;
            }

            return $text;
        }

        self::$glyphs ??= require __DIR__.'/../../resources/glyphlist.php';
        if (isset(self::$glyphs[$name])) {
            return self::$glyphs[$name];
        }

        if (str_starts_with($name, 'uni')) {
            $hex = trim($name, 'uni');
            if (preg_match('/^[0-9a-fA-F]/', $hex) === 1 && strlen($hex) % 4 === 0) {
                $text = '';
                foreach (str_split($hex, 4) as $chunk) {
                    if (! ctype_xdigit($chunk)) {
                        return null;
                    }
                    $code = (int) hexdec($chunk);
                    if ($code > 0xD7FF && $code < 0xE000) {
                        return null;
                    }
                    $text .= self::chr($code);
                }

                return $text;
            }

            return null;
        }

        if (str_starts_with($name, 'u')) {
            $hex = trim($name, 'u');
            $length = strlen($hex);
            if ($length >= 4 && $length <= 6 && ctype_xdigit($hex)) {
                $code = (int) hexdec($hex);
                if (($code > 0xD7FF && $code < 0xE000) || $code > 0x10FFFF) {
                    return null;
                }

                return self::chr($code);
            }
        }

        return null;
    }

    /**
     * Code => text for a named encoding (StandardEncoding when the name is unknown),
     * with a Differences array applied.
     *
     * @param list<mixed> $differences
     *
     * @return array<int, string>
     */
    public static function table(string $name, array $differences = []): array
    {
        self::$tables ??= require __DIR__.'/../../resources/encodings.php';
        $table = self::$tables[$name] ?? self::$tables['StandardEncoding'];
        $code = 0;
        foreach ($differences as $item) {
            if (is_int($item)) {
                $code = $item;
            } elseif ($item instanceof \Artygrand\Pdfplumber\Parser\Name) {
                $text = self::glyph($item->name);
                if ($text !== null) {
                    $table[$code] = $text;
                }
                $code++;
            }
        }

        return $table;
    }

    /**
     * A PDF text string as pdfplumber's decode_text: UTF-16BE after its byte order mark, otherwise
     * PDFDocEncoding (control bytes kept as they are). A UTF-8 byte order mark, allowed since
     * PDF 2.0, is also understood.
     */
    public static function text(string $value): string
    {
        if (str_starts_with($value, "\xFE\xFF")) {
            return self::utf16(substr($value, 2));
        }
        if (str_starts_with($value, "\xEF\xBB\xBF")) {
            return substr($value, 3);
        }
        static $table = null;
        if ($table === null) {
            $table = self::table('PDFDocEncoding');
            // pdfminer's table keeps the control bytes, with 0x16 mapped to U+0017.
            for ($code = 0; $code < 0x18; $code++) {
                $table[$code] ??= chr($code === 0x16 ? 0x17 : $code);
            }
            $table[0x7F] ??= "\0";
            $table[0x9F] ??= "\0";
            $table[0xAD] ??= "\0";
        }
        $text = '';
        for ($i = 0, $n = strlen($value); $i < $n; $i++) {
            $text .= $table[ord($value[$i])] ?? '';
        }

        return $text;
    }

    /**
     * Python's strict bytes.decode('utf-16'): a byte order mark picks the byte order, little-endian
     * without one; null when the bytes are not valid UTF-16.
     */
    public static function strictUtf16(string $bytes): ?string
    {
        if (strlen($bytes) % 2 !== 0) {
            return null;
        }
        $format = 'v*';
        if (str_starts_with($bytes, "\xFE\xFF") || str_starts_with($bytes, "\xFF\xFE")) {
            $format = $bytes[0] === "\xFE" ? 'n*' : 'v*';
            $bytes = substr($bytes, 2);
        }
        $units = $bytes === '' ? [] : array_values(unpack($format, $bytes) ?: []);
        $text = '';
        for ($i = 0, $n = count($units); $i < $n; $i++) {
            $unit = $units[$i];
            if ($unit >= 0xD800 && $unit <= 0xDBFF) {
                $low = $units[$i + 1] ?? 0;
                if ($low < 0xDC00 || $low > 0xDFFF) {
                    return null;
                }
                $text .= self::chr(0x10000 + (($unit - 0xD800) << 10) + ($low - 0xDC00));
                $i++;
            } elseif ($unit >= 0xDC00 && $unit <= 0xDFFF) {
                return null;
            } else {
                $text .= self::chr($unit);
            }
        }

        return $text;
    }

    /**
     * Decodes UTF-16BE, dropping unpaired surrogates and a trailing odd byte.
     */
    public static function utf16(string $bytes): string
    {
        $length = strlen($bytes) & ~1;
        if ($length === 0) {
            return '';
        }
        $units = unpack('n*', substr($bytes, 0, $length)) ?: [];
        $text = '';
        $high = 0;
        foreach ($units as $unit) {
            if ($unit >= 0xD800 && $unit <= 0xDBFF) {
                $high = $unit;

                continue;
            }
            if ($unit >= 0xDC00 && $unit <= 0xDFFF) {
                if ($high !== 0) {
                    $text .= self::chr(0x10000 + (($high - 0xD800) << 10) + ($unit - 0xDC00));
                }
                $high = 0;

                continue;
            }
            $high = 0;
            $text .= self::chr($unit);
        }

        return $text;
    }

    public static function chr(int $code): string
    {
        if ($code < 0x80) {
            return chr($code);
        }
        if ($code < 0x800) {
            return chr(0xC0 | ($code >> 6)).chr(0x80 | ($code & 0x3F));
        }
        if ($code < 0x10000) {
            return chr(0xE0 | ($code >> 12)).chr(0x80 | (($code >> 6) & 0x3F)).chr(0x80 | ($code & 0x3F));
        }
        if ($code <= 0x10FFFF) {
            return chr(0xF0 | ($code >> 18)).chr(0x80 | (($code >> 12) & 0x3F))
                .chr(0x80 | (($code >> 6) & 0x3F)).chr(0x80 | ($code & 0x3F));
        }

        return "\u{FFFD}";
    }

    /**
     * Big-endian unsigned integer from up to 8 bytes.
     */
    public static function unpackInt(string $bytes): int
    {
        $value = 0;
        for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
            $value = ($value << 8) | ord($bytes[$i]);
        }

        return $value;
    }
}
