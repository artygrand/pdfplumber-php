<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber\Content;

use Artygrand\Pdfplumber\Char;
use Artygrand\Pdfplumber\Graphic;
use Artygrand\Pdfplumber\Font\Font;
use Artygrand\Pdfplumber\Font\FontLoader;
use Artygrand\Pdfplumber\Parser\Dict;
use Artygrand\Pdfplumber\Parser\Document;
use Artygrand\Pdfplumber\Parser\Keyword;
use Artygrand\Pdfplumber\Parser\Lexer;
use Artygrand\Pdfplumber\Parser\Name;
use Artygrand\Pdfplumber\Parser\Parser;
use Artygrand\Pdfplumber\Parser\Ref;
use Artygrand\Pdfplumber\Parser\Stream;

/**
 * Runs a page's content streams and collects the characters it shows, with pdfminer.six's
 * geometry (text matrix, character spacing, font descent) so positions match pdfplumber.
 *
 * Only text matters here: paths, colours and images are skipped.
 */
final class Interpreter
{
    private const MAX_FORM_DEPTH = 32;

    /** @var list<Char> */
    public array $chars = [];

    /** @var list<Graphic> */
    public array $graphics = [];

    private ?Font $missingFont = null;

    /** @var array<int, true> form streams being run, to stop circular forms */
    private array $running = [];

    /**
     * @param float $height page height after rotation
     * @param float $top top of the MediaBox in page coordinates
     * @param float $left left of the MediaBox in page coordinates
     */
    public function __construct(
        private readonly Document $document,
        private readonly FontLoader $fonts,
        private readonly float $height,
        private readonly float $top,
        private readonly float $left,
        private readonly float $doctop,
        private readonly int $pageNumber,
        private readonly bool $withGraphics = false,
    ) {
    }

    /**
     * @param list<mixed> $contents content streams (references or streams)
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $ctm
     */
    public function run(array $contents, mixed $resources, array $ctm, int $depth = 0): void
    {
        $data = '';
        foreach ($contents as $content) {
            $stream = $this->document->resolve($content);
            if ($stream instanceof Stream) {
                $data .= $stream->data()."\n";
            }
        }
        if ($data === '') {
            return;
        }
        $resources = $this->document->resolve($resources);
        $resources = $resources instanceof Dict ? $resources : new Dict();
        $fontMap = $this->document->resolve($resources->get('Font'));
        $fontMap = $fontMap instanceof Dict ? $fontMap->entries : [];
        $xobjects = $this->document->resolve($resources->get('XObject'));
        $xobjects = $xobjects instanceof Dict ? $xobjects->entries : [];
        /** @var array<array-key, Font> $loadedFonts */
        $loadedFonts = [];

        // Text state, kept in locals because the loop below runs once per operator.
        $font = null;
        $fontSize = 0.0;
        $charSpace = 0.0;
        $wordSpace = 0.0;
        $scaling = 100.0;
        $leading = 0.0;
        $rise = 0.0;
        $tm = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
        $line = [0.0, 0.0];
        $lineWidth = 0.0;
        $path = [];
        $graphics = $this->withGraphics;
        $stack = [];

        $lexer = new Lexer($data);
        $parser = new Parser($lexer, false);
        $args = [];
        while (($token = $lexer->next()) !== null) {
            if (! $token instanceof Keyword) {
                $args[] = $token;

                continue;
            }
            $count = count($args);
            switch ($token->name) {
                case '[':
                    $args[] = $parser->array();

                    continue 2;
                case '<<':
                    $args[] = $parser->dict();

                    continue 2;
                case 'q':
                    $stack[] = [
                        $ctm, $font, $fontSize, $charSpace, $wordSpace, $scaling, $leading, $rise, $tm, $line,
                        $lineWidth,
                    ];
                    break;
                case 'Q':
                    if ($stack !== []) {
                        [$ctm, $font, $fontSize, $charSpace, $wordSpace, $scaling, $leading, $rise, $tm, $line,
                            $lineWidth] = array_pop($stack);
                    }
                    break;
                case 'w':
                    $value = $count >= 1 ? self::number($args[$count - 1]) : null;
                    if ($value !== null) {
                        $lineWidth = $value * ($ctm[0] ** 2 + $ctm[1] ** 2) ** 0.5;
                    }
                    break;
                case 'm':
                case 'l':
                    if ($graphics && $count >= 2) {
                        $point = self::numbers(array_slice($args, -2));
                        if ($point !== null) {
                            $path[] = [$token->name, ...$point];
                        }
                    }
                    break;
                case 'c':
                    if ($graphics && $count >= 6) {
                        $points = self::numbers(array_slice($args, -6));
                        if ($points !== null) {
                            $path[] = ['c', ...$points];
                        }
                    }
                    break;
                case 'v':
                case 'y':
                    if ($graphics && $count >= 4) {
                        $points = self::numbers(array_slice($args, -4));
                        if ($points !== null) {
                            $path[] = [$token->name, ...$points];
                        }
                    }
                    break;
                case 'h':
                    if ($graphics) {
                        $path[] = ['h'];
                    }
                    break;
                case 're':
                    if ($graphics && $count >= 4) {
                        $rect = self::numbers(array_slice($args, -4));
                        if ($rect !== null) {
                            [$rx, $ry, $rw, $rh] = $rect;
                            $path[] = ['m', $rx, $ry];
                            $path[] = ['l', $rx + $rw, $ry];
                            $path[] = ['l', $rx + $rw, $ry + $rh];
                            $path[] = ['l', $rx, $ry + $rh];
                            $path[] = ['h'];
                        }
                    }
                    break;
                case 'S':
                case 's':
                case 'f':
                case 'f*':
                case 'B':
                case 'B*':
                case 'b':
                case 'b*':
                    if ($graphics) {
                        $name = $token->name;
                        if ($name === 's' || $name === 'b' || $name === 'b*') {
                            $path[] = ['h'];
                        }
                        $stroke = $name !== 'f' && $name !== 'f*';
                        $fill = $name !== 'S' && $name !== 's';
                        $this->paint($path, $ctm, $lineWidth, $stroke, $fill);
                    }
                    $path = [];
                    break;
                case 'n':
                    $path = [];
                    break;
                case 'cm':
                    $matrix = $count >= 6 ? self::matrix(array_slice($args, -6)) : null;
                    if ($matrix !== null) {
                        $ctm = self::multiply($matrix, $ctm);
                    }
                    break;
                case 'BT':
                    $tm = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
                    $line = [0.0, 0.0];
                    break;
                case 'Tc':
                    $value = $count >= 1 ? self::number($args[$count - 1]) : null;
                    $charSpace = $value ?? $charSpace;
                    break;
                case 'Tw':
                    $value = $count >= 1 ? self::number($args[$count - 1]) : null;
                    $wordSpace = $value ?? $wordSpace;
                    break;
                case 'Tz':
                    $value = $count >= 1 ? self::number($args[$count - 1]) : null;
                    $scaling = $value ?? $scaling;
                    break;
                case 'TL':
                    $value = $count >= 1 ? self::number($args[$count - 1]) : null;
                    $leading = $value === null ? $leading : -$value;
                    break;
                case 'Ts':
                    $value = $count >= 1 ? self::number($args[$count - 1]) : null;
                    $rise = $value ?? $rise;
                    break;
                case 'Tf':
                    if ($count < 2) {
                        break;
                    }
                    $name = $args[$count - 2];
                    $key = $name instanceof Name ? $name->name : '';
                    if (! isset($loadedFonts[$key])) {
                        $loadedFonts[$key] = isset($fontMap[$key])
                            ? $this->fonts->load($fontMap[$key])
                            : $this->missingFont ??= $this->fonts->create(new Dict());
                    }
                    $font = $loadedFonts[$key];
                    $fontSize = self::number($args[$count - 1]) ?? $fontSize;
                    break;
                case 'Td':
                case 'TD':
                    if ($count >= 2) {
                        $tx = self::number($args[$count - 2]);
                        $ty = self::number($args[$count - 1]);
                        if ($tx !== null && $ty !== null) {
                            $tm = [
                                $tm[0], $tm[1], $tm[2], $tm[3],
                                $tx * $tm[0] + $ty * $tm[2] + $tm[4],
                                $tx * $tm[1] + $ty * $tm[3] + $tm[5],
                            ];
                        }
                        if ($token->name === 'TD' && $ty !== null) {
                            $leading = $ty;
                        }
                        $line = [0.0, 0.0];
                    }
                    break;
                case 'Tm':
                    $matrix = $count >= 6 ? self::matrix(array_slice($args, -6)) : null;
                    if ($matrix !== null) {
                        $tm = $matrix;
                        $line = [0.0, 0.0];
                    }
                    break;
                case 'T*':
                    $tm = [$tm[0], $tm[1], $tm[2], $tm[3], $leading * $tm[2] + $tm[4], $leading * $tm[3] + $tm[5]];
                    $line = [0.0, 0.0];
                    break;
                case 'Tj':
                case 'TJ':
                case "'":
                case '"':
                    if ($count < 1 || $font === null) {
                        break;
                    }
                    $text = $args[$count - 1];
                    if ($token->name === '"') {
                        if ($count < 3) {
                            break;
                        }
                        $wordSpace = self::number($args[$count - 3]) ?? $wordSpace;
                        $charSpace = self::number($args[$count - 2]) ?? $charSpace;
                    }
                    if ($token->name === "'" || $token->name === '"') {
                        $tm = [$tm[0], $tm[1], $tm[2], $tm[3], $leading * $tm[2] + $tm[4], $leading * $tm[3] + $tm[5]];
                        $line = [0.0, 0.0];
                    }
                    $line = $this->show(
                        $token->name === 'TJ' ? (is_array($text) ? $text : []) : [$text],
                        $font,
                        $fontSize,
                        $charSpace,
                        $wordSpace,
                        $scaling,
                        $rise,
                        self::multiply($tm, $ctm),
                        $line,
                    );
                    break;
                case 'Do':
                    $name = $count >= 1 ? $args[$count - 1] : null;
                    if ($name instanceof Name && isset($xobjects[$name->name]) && $depth < self::MAX_FORM_DEPTH) {
                        $this->form($xobjects[$name->name], $resources, $ctm, $depth);
                    }
                    break;
                case 'BI':
                    $this->skipInlineImage($lexer, $parser);
                    break;
            }
            $args = [];
        }
    }

    /**
     * Shows a string or a TJ array; returns the new text line position.
     *
     * @param list<mixed> $items
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix
     * @param array{0: float, 1: float} $line
     *
     * @return array{0: float, 1: float}
     */
    private function show(
        array $items,
        Font $font,
        float $fontSize,
        float $charSpace,
        float $wordSpace,
        float $scaling,
        float $rise,
        array $matrix,
        array $line,
    ): array {
        $scaling *= 0.01;
        $charSpace *= $scaling;
        $wordSpace = $font->multibyte ? 0.0 : $wordSpace * $scaling;
        $dxscale = 0.001 * $fontSize * $scaling;
        [$x, $y] = $line;
        $vertical = $font->vertical;
        [$a, $b, $c, $d, $e, $f] = $matrix;
        $upright = $a * $d * $scaling > 0 && $b * $c <= 0;
        $descent = $font->descent * $font->vscale * $fontSize;
        $height = $this->height;
        $top = $this->top;
        $left = $this->left;
        $needCharSpace = false;

        foreach ($items as $item) {
            if (is_int($item) || is_float($item)) {
                if ($vertical) {
                    $y -= $item * $dxscale;
                } else {
                    $x -= $item * $dxscale;
                }
                $needCharSpace = true;

                continue;
            }
            if (! is_string($item)) {
                continue;
            }
            foreach ($font->decode($item) as $cid) {
                if ($needCharSpace) {
                    if ($vertical) {
                        $y += $charSpace;
                    } else {
                        $x += $charSpace;
                    }
                }
                // The glyph's matrix: the text rendering matrix moved to the pen position.
                $ge = $x * $a + $y * $c + $e;
                $gf = $x * $b + $y * $d + $f;
                $adv = $font->width($cid) * $fontSize * $scaling;
                if ($vertical) {
                    [$vx, $vy] = $font->displacement($cid);
                    $vx = $vx === null ? $fontSize * 0.5 : $vx * $fontSize * 0.001;
                    $vy = (1000 - $vy) * $fontSize * 0.001;
                    $bx0 = -$vx;
                    $by0 = $vy + $rise + $adv;
                    $bx1 = -$vx + $fontSize;
                    $by1 = $vy + $rise;
                } else {
                    $bx0 = 0;
                    $by0 = $descent + $rise;
                    $bx1 = $adv;
                    $by1 = $descent + $rise + $fontSize;
                }
                $glyphUpright = $vertical ? ($a * $d * $scaling > 0 && $b * $c <= 0) : $upright;
                $left1 = $a * $bx0 + $c * $by0 + $ge;
                $bottom1 = $b * $bx0 + $d * $by0 + $gf;
                $right1 = $a * $bx1 + $c * $by0 + $ge;
                $bottom2 = $b * $bx1 + $d * $by0 + $gf;
                $right2 = $a * $bx1 + $c * $by1 + $ge;
                $top1 = $b * $bx1 + $d * $by1 + $gf;
                $left2 = $a * $bx0 + $c * $by1 + $ge;
                $top2 = $b * $bx0 + $d * $by1 + $gf;
                $x0 = (float) min($left1, $left2, $right1, $right2);
                $y0 = (float) min($bottom1, $bottom2, $top1, $top2);
                $x1 = (float) max($left1, $left2, $right1, $right2);
                $y1 = (float) max($bottom1, $bottom2, $top1, $top2);

                $text = $font->text($cid) ?? "(cid:{$cid})";
                $this->chars[] = new Char(
                    $text,
                    $font->fontname,
                    $vertical ? $x1 - $x0 : $y1 - $y0,
                    $adv,
                    $glyphUpright,
                    $left !== 0.0 ? $x0 + $left : $x0,
                    $y0,
                    $left !== 0.0 ? $x1 + $left : $x1,
                    $y1,
                    $height - $y1 + $top,
                    $height - $y0 + $top,
                    $this->doctop + ($height - $y1 + $top),
                    $this->pageNumber,
                );

                if ($vertical) {
                    $y += $adv;
                } else {
                    $x += $adv;
                }
                if ($cid === 32 && $wordSpace != 0) {
                    if ($vertical) {
                        $y += $wordSpace;
                    } else {
                        $x += $wordSpace;
                    }
                }
                $needCharSpace = true;
            }
        }

        return [(float) $x, (float) $y];
    }

    /**
     * Turns a painted path into a line, a rect or a curve, as pdfminer's paint_path.
     *
     * @param list<list<string|float>> $path
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $ctm
     */
    private function paint(array $path, array $ctm, float $lineWidth, bool $stroke, bool $fill): void
    {
        if ($path === [] || $path[0][0] !== 'm') {
            return;
        }
        $shape = implode('', array_column($path, 0));
        if (substr_count($shape, 'm') > 1) {
            preg_match_all('/m[^m]+/', $shape, $subpaths, PREG_OFFSET_CAPTURE);
            foreach ($subpaths[0] as [$subpath, $offset]) {
                $this->paint(array_slice($path, $offset, strlen($subpath)), $ctm, $lineWidth, $stroke, $fill);
            }

            return;
        }
        [$a, $b, $c, $d, $e, $f] = $ctm;
        $start = $path[0];
        $pts = [];
        foreach ($path as $segment) {
            $source = $segment[0] === 'h' ? $start : $segment;
            $n = count($source);
            $x = $source[$n - 2];
            $y = $source[$n - 1];
            $pts[] = [$a * $x + $c * $y + $e, $b * $x + $d * $y + $f];
        }
        if (strlen($shape) > 3 && str_ends_with($shape, 'lh') && $pts[count($pts) - 2] == $pts[0]) {
            $shape = substr($shape, 0, -2).'h';
            array_pop($pts);
        }

        if ($shape === 'mlh' || $shape === 'ml') {
            $this->graphic('line', [$pts[0], $pts[1]], $lineWidth, $stroke, $fill);

            return;
        }
        if (($shape === 'mlllh' || $shape === 'mllll') && count($pts) === 5) {
            [[$x0, $y0], [$x1, $y1], [$x2, $y2], [$x3, $y3]] = $pts;
            $closed = $pts[0] == $pts[4];
            $square = ($x0 == $x1 && $y1 == $y2 && $x2 == $x3 && $y3 == $y0)
                || ($y0 == $y1 && $x1 == $x2 && $y2 == $y3 && $x3 == $x0);
            if ($closed && $square) {
                $this->graphic('rect', [[$x0, $y0], [$x2, $y0], [$x2, $y2], [$x0, $y2]], $lineWidth, $stroke, $fill);

                return;
            }
        }
        $this->graphic('curve', $pts, $lineWidth, $stroke, $fill);
    }

    /**
     * @param list<array{0: float, 1: float}> $pts points in PDF space relative to the MediaBox
     */
    private function graphic(string $type, array $pts, float $lineWidth, bool $stroke, bool $fill): void
    {
        $x0 = $y0 = 2147483647;
        $x1 = $y1 = -2147483647;
        $points = [];
        foreach ($pts as [$x, $y]) {
            $x0 = min($x0, $x);
            $y0 = min($y0, $y);
            $x1 = max($x1, $x);
            $y1 = max($y1, $y);
            $points[] = [$this->left + $x, $this->top + $this->height - $y];
        }
        $width = $x1 - $x0;
        $height = $y1 - $y0;
        $top = $this->height - $y1 + $this->top;
        $this->graphics[] = new Graphic(
            $type,
            (float) ($this->left !== 0.0 ? $x0 + $this->left : $x0),
            (float) $y0,
            (float) ($this->left !== 0.0 ? $x1 + $this->left : $x1),
            (float) $y1,
            (float) $top,
            (float) ($this->height - $y0 + $this->top),
            (float) ($this->doctop + $top),
            (float) $width,
            (float) $height,
            $points,
            $lineWidth,
            $stroke,
            $fill,
            $this->pageNumber,
        );
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $ctm
     */
    private function form(mixed $reference, Dict $resources, array $ctm, int $depth): void
    {
        $id = $reference instanceof Ref ? $reference->id : 0;
        if ($id > 0 && isset($this->running[$id])) {
            return;
        }
        $form = $this->document->resolve($reference);
        if (! $form instanceof Stream) {
            return;
        }
        $subtype = $form->get('Subtype');
        if (! $subtype instanceof Name || $subtype->name !== 'Form' || ! $form->dict->has('BBox')) {
            return;
        }
        $matrix = $this->document->resolve($form->get('Matrix'));
        $matrix = is_array($matrix) && count($matrix) >= 6
            ? self::matrix(array_map(fn ($value) => $this->document->resolve($value), array_slice($matrix, 0, 6)))
            : null;
        $own = $form->get('Resources');
        if (! $own instanceof Ref && (! $own instanceof Dict || $own->entries === [])) {
            $own = $resources;
        }
        if ($id > 0) {
            $this->running[$id] = true;
        }
        try {
            $this->run([$form], $own, self::multiply($matrix ?? [1.0, 0.0, 0.0, 1.0, 0.0, 0.0], $ctm), $depth + 1);
        } finally {
            unset($this->running[$id]);
        }
    }

    private function skipInlineImage(Lexer $lexer, Parser $parser): void
    {
        $ascii85 = false;
        while (($token = $lexer->next()) !== null) {
            if ($token instanceof Keyword && $token->name === 'ID') {
                break;
            }
            if ($token instanceof Name && ($token->name === 'F' || $token->name === 'Filter')) {
                $filter = $parser->read();
                $first = is_array($filter) ? ($filter[0] ?? null) : $filter;
                $ascii85 = $first instanceof Name && in_array($first->name, ['A85', 'ASCII85Decode'], true);
            }
        }
        $start = $lexer->pos + 1;
        $pattern = $ascii85 ? '/~>[ \t\n\r\x0B\f]/' : '/EI[ \t\n\r\x0B\f]/';
        if (preg_match($pattern, $lexer->data, $match, PREG_OFFSET_CAPTURE, $start) === 1) {
            // After "~>" the EI operator is still ahead and is read as a plain keyword.
            $lexer->pos = $match[0][1] + 2;
        } else {
            $lexer->pos = $lexer->length;
        }
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<float>|null
     */
    private static function numbers(array $values): ?array
    {
        $numbers = [];
        foreach ($values as $value) {
            if (! is_int($value) && ! is_float($value)) {
                return null;
            }
            $numbers[] = (float) $value;
        }

        return $numbers;
    }

    private static function number(mixed $value): ?float
    {
        return is_int($value) || is_float($value) ? (float) $value : null;
    }

    /**
     * @param list<mixed> $values
     *
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}|null
     */
    private static function matrix(array $values): ?array
    {
        $matrix = [];
        foreach (array_values($values) as $value) {
            if (! is_int($value) && ! is_float($value)) {
                return null;
            }
            $matrix[] = (float) $value;
        }

        return count($matrix) === 6 ? $matrix : null;
    }

    /**
     * m1 × m0, as pdfminer's mult_matrix(m1, m0).
     *
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $m1
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $m0
     *
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}
     */
    public static function multiply(array $m1, array $m0): array
    {
        [$a1, $b1, $c1, $d1, $e1, $f1] = $m1;
        [$a0, $b0, $c0, $d0, $e0, $f0] = $m0;

        return [
            $a0 * $a1 + $c0 * $b1,
            $b0 * $a1 + $d0 * $b1,
            $a0 * $c1 + $c0 * $d1,
            $b0 * $c1 + $d0 * $d1,
            $a0 * $e1 + $c0 * $f1 + $e0,
            $b0 * $e1 + $d0 * $f1 + $f0,
        ];
    }
}
