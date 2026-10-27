<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber\Content;

use Artygrand\Pdfplumber\Char;
use Artygrand\Pdfplumber\Figure;
use Artygrand\Pdfplumber\Graphic;
use Artygrand\Pdfplumber\Image;
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
 * geometry (text matrix, character spacing, font descent) so positions match pdfplumber, and,
 * when asked for, the lines, rects, curves and images it draws.
 */
final class Interpreter
{
    private const MAX_FORM_DEPTH = 32;

    /** @var list<Char> */
    public array $chars = [];

    /** @var list<Graphic|Image> lines, rects, curves and images, in drawing order */
    public array $graphics = [];

    /** @var array<string, true> object types in the order they first appear, as pdfplumber's objects keys */
    public array $kinds = [];

    /** @var list<Char|Graphic|Figure> what the page draws, figures holding what they draw (with layout only) */
    public array $tree = [];

    /** @var \SplObjectStorage<Char|Figure, array{0: float, 1: float}> x0 and x1 before the MediaBox shift, for layout */
    public \SplObjectStorage $positions;

    /** the figure being drawn into, while the tree is built */
    private ?Figure $figure = null;

    private ?Font $missingFont = null;

    /** marked-content id and tag of the sequence being drawn */
    private ?int $mcid = null;

    private ?string $tag = null;

    /**
     * The object drawn last in the current page or form: pdfplumber stamps the current marked
     * content on it after every char, path and image, also when a path draws nothing. False stands
     * for an object that is not kept (a form, or a graphic when graphics are not collected).
     */
    private Char|Graphic|Image|false|null $last = null;

    private int $inlineImages = 0;

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
        private readonly bool $withLayout = false,
    ) {
        $this->positions = new \SplObjectStorage();
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
        $colorSpaces = null;

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
        $dash = null;
        // Colour spaces as [name, components], colours as pdfminer keeps them.
        $strokeSpace = $fillSpace = ['DeviceGray', 1];
        $strokeColor = $fillColor = 0;
        $path = [];
        $shape = '';
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
                        $lineWidth, $dash, $strokeSpace, $fillSpace, $strokeColor, $fillColor,
                    ];
                    break;
                case 'Q':
                    if ($stack !== []) {
                        [$ctm, $font, $fontSize, $charSpace, $wordSpace, $scaling, $leading, $rise, $tm, $line,
                            $lineWidth, $dash, $strokeSpace, $fillSpace, $strokeColor, $fillColor] = array_pop($stack);
                    }
                    break;
                case 'd':
                    if ($count >= 2) {
                        $dash = [$args[$count - 2], $args[$count - 1]];
                    }
                    break;
                case 'CS':
                case 'cs':
                    if ($count >= 1 && $args[$count - 1] instanceof Name) {
                        $colorSpaces ??= $this->colorSpaces($resources);
                        $space = $colorSpaces[$args[$count - 1]->name] ?? null;
                        if ($space !== null && $token->name === 'CS') {
                            $strokeSpace = $space;
                        } elseif ($space !== null) {
                            $fillSpace = $space;
                        }
                    }
                    break;
                case 'G':
                case 'g':
                    $value = $count >= 1 ? self::number($args[$count - 1]) : null;
                    if ($value !== null && $token->name === 'G') {
                        [$strokeColor, $strokeSpace] = [$value, ['DeviceGray', 1]];
                    } elseif ($value !== null) {
                        [$fillColor, $fillSpace] = [$value, ['DeviceGray', 1]];
                    }
                    break;
                case 'RG':
                case 'rg':
                    $value = $count >= 3 ? self::numbers(array_slice($args, -3)) : null;
                    if ($value !== null && $token->name === 'RG') {
                        [$strokeColor, $strokeSpace] = [$value, ['DeviceRGB', 3]];
                    } elseif ($value !== null) {
                        [$fillColor, $fillSpace] = [$value, ['DeviceRGB', 3]];
                    }
                    break;
                case 'K':
                case 'k':
                    $value = $count >= 4 ? self::numbers(array_slice($args, -4)) : null;
                    if ($value !== null && $token->name === 'K') {
                        [$strokeColor, $strokeSpace] = [$value, ['DeviceCMYK', 4]];
                    } elseif ($value !== null) {
                        [$fillColor, $fillSpace] = [$value, ['DeviceCMYK', 4]];
                    }
                    break;
                case 'SC':
                case 'SCN':
                    $strokeColor = self::color($args, $strokeSpace) ?? $strokeColor;
                    break;
                case 'sc':
                case 'scn':
                    $fillColor = self::color($args, $fillSpace) ?? $fillColor;
                    break;
                case 'BMC':
                case 'BDC':
                    $props = $token->name === 'BDC' ? 1 : 0;
                    if ($count > $props && $args[$count - 1 - $props] instanceof Name) {
                        $this->tag = $args[$count - 1 - $props]->name;
                        $mcid = $props === 1 && $args[$count - 1] instanceof Dict
                            ? $args[$count - 1]->get('MCID')
                            : null;
                        $this->mcid = is_int($mcid) ? $mcid : null;
                    }
                    break;
                case 'EMC':
                    $this->mcid = null;
                    $this->tag = null;
                    break;
                case 'w':
                    $value = $count >= 1 ? self::number($args[$count - 1]) : null;
                    if ($value !== null) {
                        $lineWidth = $value * ($ctm[0] ** 2 + $ctm[1] ** 2) ** 0.5;
                    }
                    break;
                case 'm':
                case 'l':
                    $point = $count >= 2 ? self::numbers(array_slice($args, -2)) : null;
                    if ($point !== null) {
                        $shape .= $token->name;
                        if ($graphics) {
                            $path[] = [$token->name, ...$point];
                        }
                    }
                    break;
                case 'c':
                    $points = $count >= 6 ? self::numbers(array_slice($args, -6)) : null;
                    if ($points !== null) {
                        $shape .= 'c';
                        if ($graphics) {
                            $path[] = ['c', ...$points];
                        }
                    }
                    break;
                case 'v':
                case 'y':
                    $points = $count >= 4 ? self::numbers(array_slice($args, -4)) : null;
                    if ($points !== null) {
                        $shape .= $token->name;
                        if ($graphics) {
                            $path[] = [$token->name, ...$points];
                        }
                    }
                    break;
                case 'h':
                    $shape .= 'h';
                    if ($graphics) {
                        $path[] = ['h'];
                    }
                    break;
                case 're':
                    $rect = $count >= 4 ? self::numbers(array_slice($args, -4)) : null;
                    if ($rect !== null) {
                        $shape .= 'mlllh';
                        if ($graphics) {
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
                    $name = $token->name;
                    if ($name === 's' || $name === 'b' || $name === 'b*') {
                        $shape .= 'h';
                        $path[] = ['h'];
                    }
                    if ($graphics) {
                        $this->paint($path, $ctm, [
                            'linewidth' => $lineWidth,
                            'stroke' => $name !== 'f' && $name !== 'f*',
                            'fill' => $name !== 'S' && $name !== 's',
                            'evenodd' => str_ends_with($name, '*'),
                            'stroking_color' => $strokeColor,
                            'non_stroking_color' => $fillColor,
                            'dash' => $dash,
                        ]);
                    } elseif (self::draws($shape)) {
                        $this->last = false;
                    }
                    if (! self::draws($shape)) {
                        $this->stamp();
                    }
                    $path = [];
                    $shape = '';
                    break;
                case 'n':
                    $path = [];
                    $shape = '';
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
                        [$fillSpace[0], is_array($strokeColor) ? $strokeColor : [$strokeColor],
                            is_array($fillColor) ? $fillColor : [$fillColor]],
                    );
                    break;
                case 'Do':
                    $name = $count >= 1 ? $args[$count - 1] : null;
                    if ($name instanceof Name && isset($xobjects[$name->name])) {
                        $this->xobject($name->name, $xobjects[$name->name], $resources, $ctm, $depth);
                    }
                    break;
                case 'BI':
                    $image = $this->inlineImage($lexer, $parser);
                    if ($image !== null && $image->dict->has('W') && $image->dict->has('H')) {
                        $this->image('inline-'.++$this->inlineImages, $image, $ctm);
                    }
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
     * @param array{0: string, 1: list<mixed>, 2: list<mixed>} $colors fill colour space, stroke and fill colours
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
        array $colors,
    ): array {
        [$ncs, $strokeColor, $fillColor] = $colors;
        $mcid = $this->mcid;
        $tag = $this->tag;
        $last = null;
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
                $this->chars[] = $last = new Char(
                    $text,
                    $font->fontname,
                    $vertical ? $x1 - $x0 : $y1 - $y0,
                    $adv,
                    $glyphUpright,
                    $left !== 0.0 ? $x0 + $left : $x0,
                    $y0,
                    $left !== 0.0 ? $x1 + $left : $x1,
                    $y1,
                    $x1 - $x0,
                    $y1 - $y0,
                    $height - $y1 + $top,
                    $height - $y0 + $top,
                    $this->doctop + ($height - $y1 + $top),
                    $this->pageNumber,
                    [$a, $b, $c, $d, $ge, $gf],
                    $ncs,
                    $strokeColor,
                    $fillColor,
                    $mcid,
                    $tag,
                );
                if ($this->withLayout) {
                    $this->positions[$last] = [$x0, $x1];
                    $this->place($last);
                }

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

        if ($last !== null) {
            $this->last = $last;
            $this->kinds['char'] ??= true;
        }

        return [(float) $x, (float) $y];
    }

    /**
     * Turns a painted path into a line, a rect or a curve, as pdfminer's paint_path.
     *
     * @param list<list<string|float>> $path
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $ctm
     * @param array<string, mixed> $state line width, stroke, fill, evenodd, colours and dash
     */
    private function paint(array $path, array $ctm, array $state): void
    {
        if ($path === [] || $path[0][0] !== 'm') {
            return;
        }
        $shape = implode('', array_column($path, 0));
        if (substr_count($shape, 'm') > 1) {
            preg_match_all('/m[^m]+/', $shape, $subpaths, PREG_OFFSET_CAPTURE);
            foreach ($subpaths[0] as [$subpath, $offset]) {
                $this->paint(array_slice($path, $offset, strlen($subpath)), $ctm, $state);
            }

            return;
        }
        [$a, $b, $c, $d, $e, $f] = $ctm;
        $start = $path[0];
        $pts = [];
        $drawn = [];
        foreach ($path as $segment) {
            $source = $segment[0] === 'h' ? $start : $segment;
            $n = count($source);
            $x = $source[$n - 2];
            $y = $source[$n - 1];
            $pts[] = [$a * $x + $c * $y + $e, $b * $x + $d * $y + $f];
            $points = [$segment[0]];
            for ($i = 1; $i + 1 < count($segment); $i += 2) {
                [$px, $py] = [$segment[$i], $segment[$i + 1]];
                $points[] = [
                    $this->left + ($a * $px + $c * $py + $e),
                    $this->top + $this->height - ($b * $px + $d * $py + $f),
                ];
            }
            $drawn[] = $points;
        }
        $state['path'] = $drawn;
        if (strlen($shape) > 3 && str_ends_with($shape, 'lh') && $pts[count($pts) - 2] == $pts[0]) {
            $shape = substr($shape, 0, -2).'h';
            array_pop($pts);
        }

        if ($shape === 'mlh' || $shape === 'ml') {
            $this->graphic('line', [$pts[0], $pts[1]], $state);

            return;
        }
        if (($shape === 'mlllh' || $shape === 'mllll') && count($pts) === 5) {
            [[$x0, $y0], [$x1, $y1], [$x2, $y2], [$x3, $y3]] = $pts;
            $closed = $pts[0] == $pts[4];
            $square = ($x0 == $x1 && $y1 == $y2 && $x2 == $x3 && $y3 == $y0)
                || ($y0 == $y1 && $x1 == $x2 && $y2 == $y3 && $x3 == $x0);
            if ($closed && $square) {
                $this->graphic('rect', [[$x0, $y0], [$x2, $y0], [$x2, $y2], [$x0, $y2]], $state);

                return;
            }
        }
        $this->graphic('curve', $pts, $state);
    }

    /**
     * @param list<array{0: float, 1: float}> $pts points in PDF space relative to the MediaBox
     * @param array<string, mixed> $state
     */
    private function graphic(string $type, array $pts, array $state): void
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
        $this->graphics[] = $this->last = new Graphic(
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
            $state['linewidth'],
            $state['stroke'],
            $state['fill'],
            $this->pageNumber,
            $state['evenodd'],
            $state['stroking_color'],
            $state['non_stroking_color'],
            $state['path'],
            $state['dash'],
            $this->mcid,
            $this->tag,
        );
        $this->kinds[$type] ??= true;
        if ($this->withLayout) {
            $this->place($this->last);
        }
    }

    /**
     * Runs a form XObject, or places an image XObject.
     *
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $ctm
     */
    private function xobject(string $name, mixed $reference, Dict $resources, array $ctm, int $depth): void
    {
        $id = $reference instanceof Ref ? $reference->id : 0;
        $xobject = $this->document->resolve($reference);
        if (! $xobject instanceof Stream) {
            return;
        }
        $subtype = $xobject->get('Subtype');
        $subtype = $subtype instanceof Name ? $subtype->name : null;
        if ($subtype === 'Image' && $xobject->dict->has('Width') && $xobject->dict->has('Height')) {
            $this->image($name, $xobject, $ctm);

            return;
        }
        if ($subtype !== 'Form' || ! $xobject->dict->has('BBox')) {
            return;
        }
        $matrix = $this->document->resolve($xobject->get('Matrix'));
        $matrix = is_array($matrix) && count($matrix) >= 6
            ? self::matrix(array_map(fn ($value) => $this->document->resolve($value), array_slice($matrix, 0, 6)))
            : null;
        $own = $xobject->get('Resources');
        if (! $own instanceof Ref && (! $own instanceof Dict || $own->entries === [])) {
            $own = $resources;
        }
        $running = $id > 0 && isset($this->running[$id]);
        if ($id > 0) {
            $this->running[$id] = true;
        }
        // A form is a container of its own: what it draws is not the page's last object.
        $this->last = null;
        $formCtm = self::multiply($matrix ?? [1.0, 0.0, 0.0, 1.0, 0.0, 0.0], $ctm);
        $parent = $this->figure;
        $figure = null;
        if ($this->withLayout) {
            $box = $this->document->resolve($xobject->get('BBox'));
            $box = is_array($box) && count($box) === 4
                ? self::numbers(array_map(fn ($v) => $this->document->resolve($v), $box))
                : null;
            $this->figure = $figure = $this->figure($name, $box ?? [0.0, 0.0, 0.0, 0.0], $formCtm);
        }
        try {
            // A form that is already running (a loop) makes an empty figure, as in pdfminer.
            if (! $running && $depth < self::MAX_FORM_DEPTH) {
                $this->run([$xobject], $own, $formCtm, $depth + 1);
            }
        } finally {
            if (! $running) {
                unset($this->running[$id]);
            }
            $this->last = false;
            $this->figure = $parent;
            if ($figure !== null) {
                $this->place($figure);
            }
        }
    }

    /**
     * An image fills the unit square of the current transformation.
     *
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $ctm
     */
    private function image(string $name, Stream $stream, array $ctm): void
    {
        $this->last = false;
        if (! $this->withGraphics) {
            return;
        }
        [$a, $b, $c, $d, $e, $f] = $ctm;
        $xs = [$e, $a + $e, $a + $c + $e, $c + $e];
        $ys = [$f, $b + $f, $b + $d + $f, $d + $f];
        [$x0, $y0, $x1, $y1] = [(float) min($xs), (float) min($ys), (float) max($xs), (float) max($ys)];
        $raw = static function (array $keys, mixed $default = null) use ($stream): mixed {
            foreach ($keys as $key) {
                if (array_key_exists($key, $stream->dict->entries)) {
                    return $stream->dict->entries[$key];
                }
            }

            return $default;
        };
        $any = fn (array $keys, mixed $default = null): mixed => $this->document->plain($raw($keys, $default));
        // A colour space given by reference is one item, even when it refers to an array.
        $colorspace = $raw(['CS', 'ColorSpace']);
        $colorspace = $this->document->plain(is_array($colorspace) ? $colorspace : [$colorspace]);
        $top = $this->height - $y1 + $this->top;
        $this->graphics[] = $image = new Image(
            $name,
            $stream,
            [$any(['W', 'Width']), $any(['H', 'Height'])],
            $any(['IM', 'ImageMask']),
            $any(['BPC', 'BitsPerComponent'], 1),
            $colorspace,
            $this->left !== 0.0 ? $x0 + $this->left : $x0,
            $y0,
            $this->left !== 0.0 ? $x1 + $this->left : $x1,
            $y1,
            $top,
            $this->height - $y0 + $this->top,
            $this->doctop + $top,
            $x1 - $x0,
            $y1 - $y0,
            $this->pageNumber,
            $this->mcid,
            $this->tag,
        );
        $this->kinds['image'] ??= true;
        if ($this->withLayout) {
            $figure = $this->figure($name, [0.0, 0.0, 1.0, 1.0], $ctm);
            $figure->items[] = $image;
            $this->place($figure);
        }
    }

    /**
     * pdfminer's LTFigure: the box (x, y, width, height) through the matrix.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $box
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix
     */
    private function figure(string $name, array $box, array $matrix): Figure
    {
        [$x, $y, $w, $h] = $box;
        [$a, $b, $c, $d, $e, $f] = $matrix;
        $xs = [];
        $ys = [];
        foreach ([[$x, $y], [$x + $w, $y], [$x + $w, $y + $h], [$x, $y + $h]] as [$px, $py]) {
            $xs[] = $a * $px + $c * $py + $e;
            $ys[] = $b * $px + $d * $py + $f;
        }
        [$x0, $y0, $x1, $y1] = [(float) min($xs), (float) min($ys), (float) max($xs), (float) max($ys)];
        $top = $this->height - $y1 + $this->top;

        $figure = new Figure(
            $name,
            $matrix,
            $this->left !== 0.0 ? $x0 + $this->left : $x0,
            $y0,
            $this->left !== 0.0 ? $x1 + $this->left : $x1,
            $y1,
            $top,
            $this->height - $y0 + $this->top,
            $this->doctop + $top,
            $x1 - $x0,
            $y1 - $y0,
            $this->pageNumber,
        );
        $this->positions[$figure] = [$x0, $x1];

        return $figure;
    }

    private function place(Char|Graphic|Figure $object): void
    {
        if ($this->figure !== null) {
            $this->figure->items[] = $object;
        } else {
            $this->tree[] = $object;
        }
    }

    /**
     * Reads an inline image (BI … ID data EI) as a stream, the way pdfminer's content parser does.
     */
    private function inlineImage(Lexer $lexer, Parser $parser): ?Stream
    {
        $entries = [];
        while (true) {
            $key = $parser->read();
            if ($key === null || ($key instanceof Keyword && $key->name === 'ID')) {
                break;
            }
            $value = $parser->read();
            if ($key instanceof Name) {
                $entries[$key->name] = $value;
            }
        }
        $filter = $entries['F'] ?? null;
        $first = is_array($filter) ? ($filter[0] ?? null) : $filter;
        $ascii85 = $first instanceof Name && in_array($first->name, ['A85', 'ASCII85Decode'], true);

        $start = $lexer->pos + 1;
        $pattern = $ascii85 ? '/~>[ \t\n\r\x0B\f]/' : '/EI[ \t\n\r\x0B\f]/';
        if (preg_match($pattern, $lexer->data, $match, PREG_OFFSET_CAPTURE, $start) !== 1) {
            $lexer->pos = $lexer->length;

            return null;
        }
        $end = $match[0][1];
        // After "~>" the EI operator is still ahead and is read as a plain keyword.
        $lexer->pos = $end + 2;
        $data = (string) preg_replace('/(\r\n|[\r\n])$/', '', substr($lexer->data, $start, $end - $start));

        return $key === null ? null : new Stream(new Dict($entries), $ascii85 ? $data.'~>' : $data);
    }

    /**
     * Colour spaces by resource name, with the device ones, as pdfminer's csmap.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    private function colorSpaces(Dict $resources): array
    {
        $predefined = [
            'DeviceGray' => ['DeviceGray', 1], 'CalRGB' => ['CalRGB', 3], 'CalGray' => ['CalGray', 1],
            'Lab' => ['Lab', 3], 'DeviceRGB' => ['DeviceRGB', 3], 'DeviceCMYK' => ['DeviceCMYK', 4],
            'Separation' => ['Separation', 1], 'Indexed' => ['Indexed', 1], 'Pattern' => ['Pattern', 1],
        ];
        $spaces = $predefined;
        $defined = $this->document->resolve($resources->get('ColorSpace'));
        foreach ($defined instanceof Dict ? $defined->entries : [] as $key => $spec) {
            $spec = $this->document->resolve($spec);
            $name = is_array($spec) ? $this->document->resolve($spec[0] ?? null) : $spec;
            $name = $name instanceof Name ? $name->name : null;
            if ($name === 'ICCBased' && is_array($spec) && count($spec) >= 2) {
                $profile = $this->document->resolve($spec[1]);
                $n = $profile instanceof Stream ? $this->document->resolve($profile->get('N')) : null;
                $spaces[$key] = ['ICCBased', is_int($n) ? $n : 0];
            } elseif ($name === 'DeviceN' && is_array($spec) && count($spec) >= 2) {
                $names = $this->document->resolve($spec[1]);
                $spaces[$key] = ['DeviceN', is_array($names) ? count($names) : 0];
            } elseif ($name !== null && isset($predefined[$name])) {
                $spaces[$key] = $predefined[$name];
            }
        }

        return $spaces;
    }

    /**
     * The colour an SC/SCN/sc/scn operator sets, as pdfminer parses it, or null to keep the old one.
     *
     * @param list<mixed> $args
     * @param array{0: string, 1: int} $space
     */
    private static function color(array $args, array $space): mixed
    {
        [$name, $n] = $space;
        $components = $n > 0 ? array_slice($args, -$n) : [];
        if ($n === 0 || count($components) !== $n) {
            return null;
        }
        if ($name === 'Pattern') {
            $pattern = $components[$n - 1];

            return $pattern instanceof Name ? $pattern->name : null;
        }
        $values = self::numbers($components);
        if ($values === null) {
            return null;
        }

        return match ($n) {
            1 => $values[0],
            3, 4 => $values,
            default => null,
        };
    }

    /**
     * Whether pdfminer's paint_path draws anything for a path of these operators.
     */
    private static function draws(string $shape): bool
    {
        if (! str_starts_with($shape, 'm')) {
            return false;
        }

        return substr_count($shape, 'm') === 1 || preg_match('/m[^m]+/', $shape) === 1;
    }

    /**
     * Gives the current marked content to the last object drawn, as pdfplumber does after a path
     * that draws nothing.
     */
    private function stamp(): void
    {
        if ($this->last instanceof Char || $this->last instanceof Graphic || $this->last instanceof Image) {
            $this->last->mcid = $this->mcid;
            $this->last->tag = $this->tag;
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
