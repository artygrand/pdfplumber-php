<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber\Font;

use Artygrand\Pdfplumber\Parser\Dict;
use Artygrand\Pdfplumber\Parser\Document;
use Artygrand\Pdfplumber\Parser\Keyword;
use Artygrand\Pdfplumber\Parser\Lexer;
use Artygrand\Pdfplumber\Parser\Name;
use Artygrand\Pdfplumber\Parser\Parser;
use Artygrand\Pdfplumber\Parser\Ref;
use Artygrand\Pdfplumber\Parser\Stream;

/**
 * Builds fonts from their dictionaries the way pdfminer.six does, so text and positions match.
 */
final class FontLoader
{
    /** @var array<int, Font> */
    private array $cache = [];

    public function __construct(private readonly Document $document)
    {
    }

    public function load(mixed $spec): Font
    {
        $id = $spec instanceof Ref ? $spec->id : 0;
        if ($id > 0 && isset($this->cache[$id])) {
            return $this->cache[$id];
        }
        $dict = $this->document->resolve($spec);
        $font = $this->create($dict instanceof Dict ? $dict : new Dict());

        return $id > 0 ? $this->cache[$id] = $font : $font;
    }

    public function create(Dict $spec): Font
    {
        $subtype = $this->name($spec->get('Subtype')) ?? 'Type1';

        switch ($subtype) {
            case 'Type3':
                return $this->type3($spec);
            case 'CIDFontType0':
            case 'CIDFontType2':
                return $this->cidFont($spec);
            case 'Type0':
                $descendants = $this->resolve($spec->get('DescendantFonts'));
                $descendant = is_array($descendants) ? $this->resolve($descendants[0] ?? null) : null;
                $entries = $descendant instanceof Dict ? $descendant->entries : [];
                foreach (['Encoding', 'ToUnicode'] as $key) {
                    if ($spec->has($key)) {
                        $entries[$key] = $this->resolve($spec->get($key));
                    }
                }

                return $this->create(new Dict($entries));
            default:
                return $this->type1($spec);
        }
    }

    private function type1(Dict $spec): Font
    {
        $font = new Font();
        $baseFont = $this->name($spec->get('BaseFont')) ?? 'unknown';
        $metrics = Resources::metrics($baseFont);
        if ($metrics !== null) {
            $descriptor = new Dict($metrics[0]);
            $font->textWidths = $metrics[1];
        } else {
            $descriptor = $this->dict($spec->get('FontDescriptor'));
            $font->widths = $this->widthList($spec);
        }
        $this->simple($font, $spec, $descriptor);

        if (! $spec->has('Encoding') && $descriptor->has('FontFile')) {
            $file = $this->resolve($descriptor->get('FontFile'));
            if ($file instanceof Stream) {
                $length = $this->resolve($file->get('Length1'));
                $data = $file->data();
                $header = is_int($length) && $length > 0 ? substr($data, 0, $length) : $data;
                $encoding = $this->type1Encoding($header);
                // A font program declaring StandardEncoding lists no codes; keep the standard table then.
                if ($encoding !== null) {
                    $font->encoding = $encoding;
                }
            }
        }

        return $font;
    }

    private function type3(Dict $spec): Font
    {
        $font = new Font();
        $font->widths = $this->widthList($spec);
        $descriptor = $spec->has('FontDescriptor')
            ? $this->dict($spec->get('FontDescriptor'))
            : new Dict(['Ascent' => 0, 'Descent' => 0, 'FontBBox' => $spec->get('FontBBox')]);
        $this->simple($font, $spec, $descriptor);

        $matrix = $this->resolve($spec->get('FontMatrix'));
        $matrix = is_array($matrix) && count($matrix) >= 4
            ? array_map(fn ($value) => $this->number($this->resolve($value)), $matrix)
            : [0.001, 0, 0, 0.001];
        $bbox = $this->bbox($descriptor);
        $font->descent = (float) $bbox[1];
        $font->hscale = (float) ($matrix[0] + $matrix[2]);
        $font->vscale = (float) ($matrix[1] + $matrix[3]);

        return $font;
    }

    private function cidFont(Dict $spec): Font
    {
        $font = new Font();
        $font->multibyte = true;
        $info = $this->dict($spec->get('CIDSystemInfo'));
        $registry = $this->resolve($info->get('Registry'));
        $ordering = $this->resolve($info->get('Ordering'));
        $registry = is_string($registry) ? trim($registry) : 'unknown';
        $ordering = is_string($ordering) ? trim($ordering) : 'unknown';
        $collection = $registry.'-'.$ordering;

        $font->cmap = $this->cidCMap($spec);
        $font->vertical = $font->cmap->vertical;
        $descriptor = $this->dict($spec->get('FontDescriptor'));

        $toUnicode = $this->resolve($spec->get('ToUnicode'));
        if ($toUnicode instanceof Stream) {
            $font->unicode = CMapParser::toUnicode($toUnicode->data());
        } elseif ($spec->has('ToUnicode')) {
            $encoding = $this->resolve($spec->get('Encoding'));
            $encodingName = $encoding instanceof Name ? $encoding->name : '';
            $mapName = $toUnicode instanceof Name ? $toUnicode->name : '';
            if (str_contains($ordering, 'Identity') || str_contains($mapName, 'Identity')
                || str_contains($encodingName, 'Identity')) {
                $font->identityUnicode = true;
            }
        } elseif ($collection === 'Adobe-Identity' || $collection === 'Adobe-UCS') {
            $file = $this->resolve($descriptor->get('FontFile2'));
            if ($file instanceof Stream) {
                $font->unicode = TrueType::unicodeMap($file->data());
            }
        } else {
            $data = Resources::cmap('to-unicode-'.$collection);
            if ($data !== null) {
                $table = $data[$font->vertical ? 'CID2UNICHR_V' : 'CID2UNICHR_H'] ?? [];
                $font->unicode = is_array($table) ? $table : null;
            }
        }

        if ($font->vertical) {
            [$widths, $displacements] = $this->verticalWidths($spec->get('W2'));
            $font->widths = $widths;
            $font->displacements = $displacements;
            $dw2 = $this->resolve($spec->get('DW2'));
            [$vy, $w] = is_array($dw2) && count($dw2) >= 2 ? [$dw2[0], $dw2[1]] : [880, -1000];
            $font->defaultDisplacement = [null, $this->number($this->resolve($vy))];
            $defaultWidth = $this->number($this->resolve($w));
        } else {
            $font->widths = $this->horizontalWidths($spec->get('W'));
            $defaultWidth = $spec->has('DW') ? $this->number($this->resolve($spec->get('DW'))) : 1000;
        }
        $this->metrics($font, $descriptor, $defaultWidth);

        return $font;
    }

    private function simple(Font $font, Dict $spec, Dict $descriptor): void
    {
        $default = $this->name($spec->get('Subtype')) === 'TrueType' ? 'WinAnsiEncoding' : 'StandardEncoding';
        $encoding = $spec->has('Encoding') ? $this->resolve($spec->get('Encoding')) : Name::of($default);
        if ($encoding instanceof Dict) {
            $base = $this->name($encoding->get('BaseEncoding')) ?? $default;
            $differences = $this->resolve($encoding->get('Differences'));
            $font->encoding = Encodings::table($base, is_array($differences) ? $differences : []);
        } else {
            $font->encoding = Encodings::table($encoding instanceof Name ? $encoding->name : '');
        }

        $toUnicode = $this->resolve($spec->get('ToUnicode'));
        if ($toUnicode instanceof Stream) {
            $font->unicode = CMapParser::toUnicode($toUnicode->data());
        }
        $this->metrics($font, $descriptor, null);
    }

    private function metrics(Font $font, Dict $descriptor, float|int|null $defaultWidth): void
    {
        $name = $this->resolve($descriptor->get('FontName'));
        if ($name instanceof Name) {
            // pdfminer keeps a name that is not UTF-8 as bytes and prints it as Python does.
            $font->fontname = mb_check_encoding($name->name, 'UTF-8') ? $name->name : self::bytesRepr($name->name);
        } elseif (is_string($name)) {
            $font->fontname = self::fontnameFromBytes($name);
        }
        $descent = $this->number($this->resolve($descriptor->get('Descent')));
        // Descent must be negative; some writers store it positive.
        $font->descent = (float) ($descent > 0 ? -$descent : $descent);
        $font->defaultWidth = (float) ($defaultWidth ?? $this->number($this->resolve($descriptor->get('MissingWidth'))));
    }

    /**
     * @return array<int, mixed>
     */
    private function widthList(Dict $spec): array
    {
        $first = $this->resolve($spec->get('FirstChar'));
        $first = is_int($first) ? $first : 0;
        $list = $this->resolve($spec->get('Widths'));
        if (! is_array($list)) {
            return array_fill(0, 256, 0);
        }
        $widths = [];
        foreach (array_values($list) as $i => $width) {
            $widths[$first + $i] = $this->resolve($width);
        }

        return $widths;
    }

    /**
     * @return array<int, mixed>
     */
    private function horizontalWidths(mixed $value): array
    {
        $list = $this->resolve($value);
        if (! is_array($list)) {
            return [];
        }
        $widths = [];
        $pending = [];
        foreach ($list as $item) {
            $item = $this->resolve($item);
            if (is_array($item)) {
                if ($pending !== []) {
                    $first = end($pending);
                    if (is_int($first)) {
                        foreach (array_values($item) as $i => $width) {
                            $widths[$first + $i] = $this->resolve($width);
                        }
                    }
                    $pending = [];
                }
            } elseif (is_int($item) || is_float($item)) {
                $pending[] = $item;
                if (count($pending) === 3) {
                    [$from, $to, $width] = $pending;
                    if (is_int($from) && is_int($to)) {
                        for ($cid = $from; $cid <= $to && $cid - $from < 65536; $cid++) {
                            $widths[$cid] = $width;
                        }
                    }
                    $pending = [];
                }
            }
        }

        return $widths;
    }

    /**
     * @return array{0: array<int, mixed>, 1: array<int, array{0: float|int, 1: float|int}>}
     */
    private function verticalWidths(mixed $value): array
    {
        $list = $this->resolve($value);
        $widths = [];
        $displacements = [];
        if (! is_array($list)) {
            return [$widths, $displacements];
        }
        $pending = [];
        foreach ($list as $item) {
            if (is_array($item)) {
                if ($pending !== []) {
                    $first = (int) end($pending);
                    foreach (array_chunk(array_values($item), 3) as $i => $triple) {
                        if (count($triple) === 3) {
                            $widths[$first + $i] = $triple[0];
                            $displacements[$first + $i] = [$triple[1], $triple[2]];
                        }
                    }
                    $pending = [];
                }
            } elseif (is_int($item) || is_float($item)) {
                $pending[] = $item;
                if (count($pending) === 5) {
                    [$from, $to, $width, $vx, $vy] = $pending;
                    for ($cid = (int) $from; $cid <= (int) $to && $cid - $from < 65536; $cid++) {
                        $widths[$cid] = $width;
                        $displacements[$cid] = [$vx, $vy];
                    }
                    $pending = [];
                }
            }
        }

        return [$widths, $displacements];
    }

    private function cidCMap(Dict $spec): CMap
    {
        $encoding = $this->resolve($spec->get('Encoding'));
        if ($encoding instanceof Name) {
            return CMap::named($encoding->name) ?? CMap::empty();
        }
        $dict = $encoding instanceof Stream ? $encoding->dict : $encoding;
        $name = $dict instanceof Dict ? $this->name($dict->get('CMapName')) : null;
        $cmap = $name === null ? null : CMap::named($name);
        if ($cmap !== null) {
            return $cmap;
        }
        if ($encoding instanceof Stream) {
            $vertical = $this->resolve($encoding->get('WMode')) === 1;
            $cmap = CMapParser::encoding($encoding->data(), $vertical);
            $base = $this->name($encoding->get('UseCMap'));
            if ($base !== null && $cmap->kind === CMap::TABLE) {
                return CMap::named($base) ?? $cmap;
            }

            return $cmap;
        }

        return CMap::empty();
    }

    /**
     * Code => text from the encoding vector of a Type 1 font program ("dup 65 /A put"), or null
     * when the program uses StandardEncoding.
     *
     * @return array<int, string>|null
     */
    private function type1Encoding(string $data): ?array
    {
        if (preg_match('/\/Encoding\s+StandardEncoding\s+def/', $data) === 1) {
            return null;
        }
        $lexer = new Lexer($data);
        $stack = [];
        $encoding = [];
        $limit = 200000;
        while (($token = $lexer->next()) !== null && $limit-- > 0) {
            if ($token instanceof Keyword) {
                if ($token->name === 'put' && count($stack) >= 2) {
                    $value = array_pop($stack);
                    $key = array_pop($stack);
                    if (is_int($key) && $value instanceof Name) {
                        $text = Encodings::glyph($value->name);
                        if ($text !== null) {
                            $encoding[$key] = $text;
                        }
                    }

                    continue;
                }
                if ($token->name === 'eexec') {
                    break;
                }
            }
            $stack[] = $token;
            if (count($stack) > 64) {
                $stack = array_slice($stack, -2);
            }
        }

        return $encoding;
    }

    /**
     * @return array{0: float|int, 1: float|int, 2: float|int, 3: float|int}
     */
    private function bbox(Dict $descriptor): array
    {
        $box = $this->resolve($descriptor->get('FontBBox'));
        if (is_array($box) && count($box) >= 4) {
            $values = array_map(fn ($value) => $this->resolve($value), array_slice(array_values($box), 0, 4));
            if (count(array_filter($values, static fn ($v) => is_int($v) || is_float($v))) === 4) {
                return [$values[0], $values[1], $values[2], $values[3]];
            }
        }

        return [0, 0, 0, 0];
    }

    private function resolve(mixed $value): mixed
    {
        return $this->document->resolve($value);
    }

    private function dict(mixed $value): Dict
    {
        $value = $this->resolve($value);
        if ($value instanceof Stream) {
            return $value->dict;
        }

        return $value instanceof Dict ? $value : new Dict();
    }

    /**
     * pdfplumber's fix_fontname_bytes for a FontName given as a string: the subset prefix kept, the
     * Chinese names of common Windows fonts translated, other bytes escaped.
     */
    private static function fontnameFromBytes(string $name): string
    {
        $split = strpos($name, '+');
        [$prefix, $suffix] = $split === false ? ['', $name] : [substr($name, 0, $split + 1), substr($name, $split + 1)];
        $known = [
            "\xcb\xce\xcc\xe5" => 'SimSun,Regular',
            "\xba\xda\xcc\xe5" => 'SimHei,Regular',
            "\xbf\xac\xcc\xe5_GB2312" => 'SimKai,Regular',
            "\xb7\xc2\xcb\xce_GB2312" => 'SimFang,Regular',
            "\xc1\xa5\xca\xe9" => 'SimLi,Regular',
        ];

        return substr(self::bytesRepr($prefix), 2, -1).($known[$suffix] ?? substr(self::bytesRepr($suffix), 2, -1));
    }

    /**
     * Python's repr() of bytes: b'…' with \x escapes.
     */
    private static function bytesRepr(string $bytes): string
    {
        $quote = str_contains($bytes, "'") && ! str_contains($bytes, '"') ? '"' : "'";
        $repr = '';
        foreach (str_split($bytes) as $byte) {
            $code = ord($byte);
            $repr .= match (true) {
                $byte === '\\' => '\\\\',
                $byte === $quote => '\\'.$quote,
                $byte === "\t" => '\\t',
                $byte === "\n" => '\\n',
                $byte === "\r" => '\\r',
                $code < 0x20 || $code >= 0x7F => sprintf('\\x%02x', $code),
                default => $byte,
            };
        }

        return 'b'.$quote.$repr.$quote;
    }

    private function name(mixed $value): ?string
    {
        $value = $this->resolve($value);

        return $value instanceof Name ? $value->name : null;
    }

    private function number(mixed $value): float|int
    {
        return is_int($value) || is_float($value) ? $value : 0;
    }
}
