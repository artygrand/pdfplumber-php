<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber\Font;

use Artygrand\Pdfplumber\Parser\Keyword;
use Artygrand\Pdfplumber\Parser\Lexer;
use Artygrand\Pdfplumber\Parser\Name;
use Artygrand\Pdfplumber\Parser\Parser;

/**
 * Reads CMap programs: ToUnicode maps (CID => text) and embedded encoding CMaps (code => CID).
 */
final class CMapParser
{
    /**
     * CID => text from a ToUnicode CMap, following pdfminer.six's reading of each section.
     *
     * @return array<int, string>
     */
    public static function toUnicode(string $data): array
    {
        $map = [];
        $add = static function (int $cid, mixed $code) use (&$map): void {
            if ($code instanceof Name) {
                $text = Encodings::glyph($code->name);
                if ($text === null) {
                    return;
                }
            } elseif (is_string($code)) {
                $text = Encodings::utf16($code);
            } elseif (is_int($code)) {
                $text = Encodings::chr($code);
            } else {
                return;
            }
            // Some fonts map a CID to both a space and a no-break space; keep the space.
            if ($text === "\u{A0}" && ($map[$cid] ?? null) === ' ') {
                return;
            }
            $map[$cid] = $text;
        };

        self::run($data, static function (string $keyword, array $operands) use ($add): void {
            switch ($keyword) {
                case 'endcidrange':
                    foreach (array_chunk($operands, 3) as $triple) {
                        if (count($triple) < 3) {
                            break;
                        }
                        [$start, $end, $cid] = $triple;
                        if (! is_string($start) || ! is_string($end) || ! is_int($cid)
                            || strlen($start) !== strlen($end) || substr($start, 0, -4) !== substr($end, 0, -4)) {
                            continue;
                        }
                        $prefix = substr($start, 0, -4);
                        $low = Encodings::unpackInt(substr($start, -4));
                        $high = Encodings::unpackInt(substr($end, -4));
                        $size = strlen(substr($start, -4));
                        for ($i = 0; $i <= $high - $low && $i < 65536; $i++) {
                            $add($cid + $i, $prefix.substr(pack('N', ($low + $i) & 0xFFFFFFFF), -$size));
                        }
                    }
                    break;
                case 'endbfrange':
                    foreach (array_chunk($operands, 3) as $triple) {
                        if (count($triple) < 3) {
                            break;
                        }
                        [$start, $end, $code] = $triple;
                        if (! is_string($start) || ! is_string($end) || strlen($start) !== strlen($end)) {
                            continue;
                        }
                        $low = Encodings::unpackInt($start);
                        $high = Encodings::unpackInt($end);
                        if (is_array($code)) {
                            foreach (array_values($code) as $i => $item) {
                                if ($low + $i > $high) {
                                    break;
                                }
                                $add($low + $i, $item);
                            }
                        } elseif (is_string($code)) {
                            $prefix = substr($code, 0, -4);
                            $tail = substr($code, -4);
                            $base = Encodings::unpackInt($tail);
                            $size = strlen($tail);
                            for ($i = 0; $i <= $high - $low && $i < 65536; $i++) {
                                $add($low + $i, $prefix.substr(pack('N', ($base + $i) & 0xFFFFFFFF), -$size));
                            }
                        }
                    }
                    break;
                case 'endbfchar':
                    foreach (array_chunk($operands, 2) as $pair) {
                        if (count($pair) === 2 && is_string($pair[0]) && is_string($pair[1])) {
                            $add(Encodings::unpackInt($pair[0]), $pair[1]);
                        }
                    }
                    break;
            }
        });

        return $map;
    }

    /**
     * An encoding CMap embedded as a stream (code => CID), for fonts whose CMap is not predefined.
     */
    public static function encoding(string $data, bool $vertical = false): CMap
    {
        $codespaces = [];
        $ranges = [];
        $singles = [];
        $base = null;
        self::run($data, static function (string $keyword, array $operands) use (
            &$codespaces, &$ranges, &$singles, &$base, &$vertical,
        ): void {
            switch ($keyword) {
                case 'endcodespacerange':
                    foreach (array_chunk($operands, 2) as $pair) {
                        if (count($pair) === 2 && is_string($pair[0]) && is_string($pair[1])
                            && strlen($pair[0]) === strlen($pair[1]) && $pair[0] !== '') {
                            $codespaces[] = [$pair[0], $pair[1]];
                        }
                    }
                    break;
                case 'endcidrange':
                    foreach (array_chunk($operands, 3) as $triple) {
                        if (count($triple) === 3 && is_string($triple[0]) && is_string($triple[1])
                            && is_int($triple[2])) {
                            $ranges[] = [
                                strlen($triple[0]),
                                Encodings::unpackInt($triple[0]),
                                Encodings::unpackInt($triple[1]),
                                $triple[2],
                            ];
                        }
                    }
                    break;
                case 'endcidchar':
                    foreach (array_chunk($operands, 2) as $pair) {
                        if (count($pair) === 2 && is_string($pair[0]) && is_int($pair[1])) {
                            $singles[strlen($pair[0])][Encodings::unpackInt($pair[0])] = $pair[1];
                        }
                    }
                    break;
                case 'usecmap':
                    $name = end($operands);
                    if ($name instanceof Name) {
                        $base = CMap::named($name->name);
                    }
                    break;
                case 'def':
                    if (count($operands) >= 2 && $operands[count($operands) - 2] instanceof Name
                        && $operands[count($operands) - 2]->name === 'WMode') {
                        $vertical = end($operands) === 1;
                    }
                    break;
            }
        });
        if ($codespaces === [] && $base === null && $ranges === [] && $singles === []) {
            return CMap::empty();
        }
        usort($codespaces, static fn (array $a, array $b): int => strlen($a[0]) <=> strlen($b[0]));

        return new CMap(CMap::RANGES, $vertical, [], $codespaces, $ranges, $singles, $base);
    }

    /**
     * Feeds each keyword with the operands collected since the previous one.
     *
     * @param callable(string, list<mixed>): void $handler
     */
    private static function run(string $data, callable $handler): void
    {
        $lexer = new Lexer($data);
        $parser = new Parser($lexer, false);
        $operands = [];
        $inCmap = true;
        while (($token = $lexer->next()) !== null) {
            if ($token instanceof Keyword && ! in_array($token->name, ['[', '<<'], true)) {
                $keyword = $token->name;
                if ($keyword === 'begincmap') {
                    $inCmap = true;
                    $operands = [];

                    continue;
                }
                if ($keyword === 'endcmap') {
                    $inCmap = false;

                    continue;
                }
                if (! $inCmap) {
                    continue;
                }
                if (str_starts_with($keyword, 'begin') || in_array($keyword, ['def', 'usecmap'], true)) {
                    if ($keyword === 'def' || $keyword === 'usecmap') {
                        $handler($keyword, $operands);
                    }
                    $operands = [];

                    continue;
                }
                if (str_starts_with($keyword, 'end')) {
                    $handler($keyword, $operands);
                    $operands = [];

                    continue;
                }
                $operands[] = $token;

                continue;
            }
            $operands[] = $parser->value($token);
        }
    }
}
