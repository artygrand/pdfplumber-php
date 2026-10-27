<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber\Parser;

/**
 * Stream filters needed to read text: Flate, LZW, ASCII85, ASCIIHex, RunLength and the
 * PNG/TIFF predictors. Image codecs (DCT, JPX, JBIG2, CCITT) are left encoded.
 */
final class Filters
{
    private const IMAGE_FILTERS = [
        'DCTDecode', 'DCT', 'JPXDecode', 'JBIG2Decode', 'CCITTFaxDecode', 'CCF',
    ];

    public static function decode(string $data, Dict $dict, ?Document $document): string
    {
        $resolve = static fn (mixed $value): mixed => $document === null ? $value : $document->resolve($value);
        $filters = $resolve($dict->get('F') ?? $dict->get('Filter'));
        if ($filters === null || $filters === []) {
            return $data;
        }
        $params = $resolve($dict->get('DP') ?? $dict->get('DecodeParms') ?? $dict->get('FDecodeParms'));
        if (! is_array($filters)) {
            $filters = [$filters];
        }
        if (! is_array($params)) {
            $params = array_fill(0, count($filters), $params);
        }

        foreach ($filters as $i => $filter) {
            $filter = $resolve($filter);
            $name = $filter instanceof Name ? $filter->name : '';
            $param = $resolve($params[$i] ?? null);
            if (in_array($name, self::IMAGE_FILTERS, true)) {
                return $data;
            }
            $data = match ($name) {
                'FlateDecode', 'Fl' => self::inflate($data),
                'LZWDecode', 'LZW' => self::lzw($data, self::int($param, 'EarlyChange', 1, $resolve)),
                'ASCII85Decode', 'A85' => self::ascii85($data),
                'ASCIIHexDecode', 'AHx' => self::asciiHex($data),
                'RunLengthDecode', 'RL' => self::runLength($data),
                default => $data,
            };
            if ($param instanceof Dict && $param->has('Predictor')) {
                $data = self::predict($data, $param, $resolve);
            }
        }

        return $data;
    }

    public static function inflate(string $data): string
    {
        if ($data === '') {
            return '';
        }
        $out = @gzuncompress($data);
        if ($out !== false) {
            return $out;
        }

        // A broken stream: keep whatever inflates before the damage.
        $out = self::salvage($data, ZLIB_ENCODING_DEFLATE);

        return $out !== '' ? $out : self::salvage($data, ZLIB_ENCODING_RAW);
    }

    private static function salvage(string $data, int $encoding): string
    {
        $context = inflate_init($encoding);
        if ($context === false) {
            return '';
        }
        $out = '';
        $length = strlen($data);
        for ($i = 0; $i < $length; $i += 4096) {
            $chunk = @inflate_add($context, substr($data, $i, 4096), ZLIB_SYNC_FLUSH);
            if ($chunk !== false) {
                $out .= $chunk;
                if (inflate_get_status($context) === ZLIB_STREAM_END) {
                    return $out;
                }

                continue;
            }
            // Feed the failing chunk byte by byte on a fresh context to keep its good part.
            $context = inflate_init($encoding);
            $salvaged = '';
            for ($j = 0, $n = min($length, $i + 4096); $j < $n; $j++) {
                $piece = @inflate_add($context, $data[$j], ZLIB_SYNC_FLUSH);
                if ($piece === false) {
                    break;
                }
                $salvaged .= $piece;
            }

            return strlen($salvaged) > strlen($out) ? $salvaged : $out;
        }

        return $out;
    }

    public static function lzw(string $data, int $earlyChange = 1): string
    {
        $out = '';
        $table = [];
        $previous = '';
        $bits = 9;
        $buffer = 0;
        $bufferBits = 0;
        $length = strlen($data);
        $pos = 0;
        $initTable = static function () use (&$table): void {
            $table = [];
            for ($i = 0; $i < 256; $i++) {
                $table[$i] = chr($i);
            }
            $table[256] = '';
            $table[257] = '';
        };
        $initTable();
        while (true) {
            while ($bufferBits < $bits) {
                if ($pos >= $length) {
                    return $out;
                }
                $buffer = (($buffer << 8) | ord($data[$pos++])) & 0xFFFFFF;
                $bufferBits += 8;
            }
            $bufferBits -= $bits;
            $code = ($buffer >> $bufferBits) & ((1 << $bits) - 1);
            if ($code === 256) {
                $initTable();
                $previous = '';
                $bits = 9;

                continue;
            }
            if ($code === 257) {
                return $out;
            }
            if ($previous === '') {
                $entry = $table[$code] ?? null;
                if ($entry === null) {
                    return $out;
                }
            } else {
                $size = count($table);
                if ($code < $size) {
                    $entry = $table[$code];
                    $table[] = $previous.$entry[0];
                } elseif ($code === $size) {
                    $entry = $previous.$previous[0];
                    $table[] = $entry;
                } else {
                    return $out;
                }
                $size = count($table) + $earlyChange;
                if ($size >= 4096) {
                    $bits = 12;
                } elseif ($size >= 2048) {
                    $bits = 12;
                } elseif ($size >= 1024) {
                    $bits = 11;
                } elseif ($size >= 512) {
                    $bits = 10;
                }
            }
            $out .= $entry;
            $previous = $entry;
        }
    }

    public static function ascii85(string $data): string
    {
        $data = (string) preg_replace(['/^\s*<?\s*~\s*/', '/\s*~\s*>?\s*$/'], '', $data);
        $data = str_replace([' ', "\t", "\n", "\r", "\f", "\v", "\0"], '', $data);
        $out = '';
        $group = [];
        $length = strlen($data);
        for ($i = 0; $i < $length; $i++) {
            $c = $data[$i];
            if ($c === 'z' && $group === []) {
                $out .= "\0\0\0\0";

                continue;
            }
            $value = ord($c) - 33;
            if ($value < 0 || $value > 84) {
                continue;
            }
            $group[] = $value;
            if (count($group) === 5) {
                $out .= self::ascii85Group($group, 4);
                $group = [];
            }
        }
        if ($group !== []) {
            $count = count($group);
            $out .= self::ascii85Group(array_pad($group, 5, 84), $count - 1);
        }

        return $out;
    }

    public static function asciiHex(string $data): string
    {
        $end = strpos($data, '>');
        if ($end !== false) {
            $data = substr($data, 0, $end);
        }
        $data = (string) preg_replace('/[^0-9a-fA-F]/', '', $data);
        if (strlen($data) % 2 === 1) {
            $data .= '0';
        }

        return (string) hex2bin($data);
    }

    public static function runLength(string $data): string
    {
        $out = '';
        $length = strlen($data);
        $i = 0;
        while ($i < $length) {
            $n = ord($data[$i++]);
            if ($n === 128) {
                break;
            }
            if ($n < 128) {
                $out .= substr($data, $i, $n + 1);
                $i += $n + 1;
            } elseif ($i < $length) {
                $out .= str_repeat($data[$i++], 257 - $n);
            }
        }

        return $out;
    }

    /**
     * @param list<int> $group
     */
    private static function ascii85Group(array $group, int $bytes): string
    {
        $value = 0;
        foreach ($group as $digit) {
            $value = $value * 85 + $digit;
        }

        return substr(pack('N', $value & 0xFFFFFFFF), 0, $bytes);
    }

    /**
     * @param callable(mixed): mixed $resolve
     */
    private static function int(mixed $params, string $key, int $default, callable $resolve): int
    {
        $value = $params instanceof Dict ? $resolve($params->get($key)) : null;

        return is_int($value) || is_float($value) ? (int) $value : $default;
    }

    /**
     * @param callable(mixed): mixed $resolve
     */
    private static function predict(string $data, Dict $params, callable $resolve): string
    {
        $predictor = self::int($params, 'Predictor', 1, $resolve);
        if ($predictor < 2) {
            return $data;
        }
        $colors = max(1, self::int($params, 'Colors', 1, $resolve));
        $columns = max(1, self::int($params, 'Columns', 1, $resolve));
        $bpc = self::int($params, 'BitsPerComponent', 8, $resolve);
        $bpp = max(1, intdiv($colors * $bpc, 8));
        $rowLength = intdiv($colors * $columns * $bpc + 7, 8);

        if ($predictor === 2) {
            if ($bpc !== 8) {
                return $data;
            }
            $out = '';
            foreach (str_split($data, $rowLength) as $row) {
                $bytes = array_values(unpack('C*', $row) ?: []);
                for ($i = $bpp, $n = count($bytes); $i < $n; $i++) {
                    $bytes[$i] = ($bytes[$i] + $bytes[$i - $bpp]) & 0xFF;
                }
                $out .= pack('C*', ...$bytes);
            }

            return $out;
        }

        $out = '';
        $above = array_fill(0, $rowLength, 0);
        $length = strlen($data);
        for ($pos = 0; $pos < $length; $pos += $rowLength + 1) {
            $type = ord($data[$pos]);
            $line = substr($data, $pos + 1, $rowLength);
            $raw = array_values(unpack('C*', $line) ?: []);
            $n = count($raw);
            switch ($type) {
                case 1:
                    for ($i = $bpp; $i < $n; $i++) {
                        $raw[$i] = ($raw[$i] + $raw[$i - $bpp]) & 0xFF;
                    }
                    break;
                case 2:
                    for ($i = 0; $i < $n; $i++) {
                        $raw[$i] = ($raw[$i] + $above[$i]) & 0xFF;
                    }
                    break;
                case 3:
                    for ($i = 0; $i < $n; $i++) {
                        $left = $i >= $bpp ? $raw[$i - $bpp] : 0;
                        $raw[$i] = ($raw[$i] + (($left + $above[$i]) >> 1)) & 0xFF;
                    }
                    break;
                case 4:
                    for ($i = 0; $i < $n; $i++) {
                        $left = $i >= $bpp ? $raw[$i - $bpp] : 0;
                        $upperLeft = $i >= $bpp ? $above[$i - $bpp] : 0;
                        $up = $above[$i];
                        $p = $left + $up - $upperLeft;
                        $pa = abs($p - $left);
                        $pb = abs($p - $up);
                        $pc = abs($p - $upperLeft);
                        $paeth = ($pa <= $pb && $pa <= $pc) ? $left : ($pb <= $pc ? $up : $upperLeft);
                        $raw[$i] = ($raw[$i] + $paeth) & 0xFF;
                    }
                    break;
            }
            if ($n > 0) {
                $out .= pack('C*', ...$raw);
            }
            $above = array_pad($raw, $rowLength, 0);
        }

        return $out;
    }
}
