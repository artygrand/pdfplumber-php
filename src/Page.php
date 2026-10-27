<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber;

use Artygrand\Pdfplumber\Table\Edges;
use Artygrand\Pdfplumber\Table\Table;
use Artygrand\Pdfplumber\Table\TableFinder;
use Artygrand\Pdfplumber\Table\TableSettings;
use Artygrand\Pdfplumber\Text\Cluster;
use Artygrand\Pdfplumber\Text\TextMap;
use Artygrand\Pdfplumber\Text\TextSettings;
use Artygrand\Pdfplumber\Text\WordExtractor;

/**
 * A page, or a part of one (crop, within, outside, filter), with pdfplumber's text and table functions.
 *
 * Coordinates are points from the top-left corner: [x0, top, x1, bottom].
 */
final class Page
{
    public readonly float $width;

    public readonly float $height;

    /** @var list<Char>|null */
    private ?array $chars = null;

    /** @var list<Graphic>|null */
    private ?array $graphics = null;

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $mediabox
     * @param array{0: float, 1: float, 2: float, 3: float} $cropbox
     * @param array{0: float, 1: float, 2: float, 3: float} $bbox
     * @param \Closure(bool): array{0: list<Char>, 1: list<Graphic>} $loader reads the chars, and the
     *        graphics too when given true
     */
    public function __construct(
        public readonly int $pageNumber,
        public readonly int $rotation,
        public readonly array $mediabox,
        public readonly array $cropbox,
        public readonly array $bbox,
        public readonly float $initialDoctop,
        private readonly \Closure $loader,
        public readonly ?Page $parent = null,
    ) {
        $this->width = $bbox[2] - $bbox[0];
        $this->height = $bbox[3] - $bbox[1];
    }

    /**
     * Characters on the page (or in this part of it) in the order the PDF draws them.
     *
     * @return list<Char>
     */
    public function chars(): array
    {
        if ($this->chars === null) {
            [$this->chars] = ($this->loader)(false);
        }

        return $this->chars;
    }

    /**
     * Straight lines drawn on the page.
     *
     * @return list<Graphic>
     */
    public function lines(): array
    {
        return $this->graphicsOf('line');
    }

    /**
     * Axis-aligned rectangles drawn on the page (table cells are often drawn as these).
     *
     * @return list<Graphic>
     */
    public function rects(): array
    {
        return $this->graphicsOf('rect');
    }

    /**
     * Any other paths drawn on the page.
     *
     * @return list<Graphic>
     */
    public function curves(): array
    {
        return $this->graphicsOf('curve');
    }

    /**
     * Lines, rect sides and curve segments as edges (arrays with x0, x1, top, bottom, width,
     * height and orientation 'h', 'v' or null), as pdfplumber's page.edges.
     *
     * @return list<array<string, mixed>>
     */
    public function edges(): array
    {
        $edges = [];
        foreach (['line', 'rect', 'curve'] as $type) {
            foreach ($this->graphicsOf($type) as $graphic) {
                array_push($edges, ...Edges::fromObject($graphic));
            }
        }

        return $edges;
    }

    /**
     * Tables found by pdfplumber's algorithm. Settings: verticalStrategy and horizontalStrategy
     * ('lines', 'lines_strict', 'text', 'explicit'), explicitVerticalLines, explicitHorizontalLines,
     * snapTolerance, snapXTolerance, snapYTolerance, joinTolerance, joinXTolerance, joinYTolerance,
     * edgeMinLength, edgeMinLengthPrefilter, minWordsVertical, minWordsHorizontal,
     * intersectionTolerance, intersectionXTolerance, intersectionYTolerance, and text options with
     * a text_ prefix (snake_case names work too).
     *
     * @param array<string, mixed>|TableSettings $settings
     *
     * @return list<Table>
     */
    public function findTables(array|TableSettings $settings = []): array
    {
        return (new TableFinder($this, TableSettings::resolve($settings)))->tables;
    }

    /**
     * The largest table (by number of cells), or null when there is none.
     *
     * @param array<string, mixed>|TableSettings $settings
     */
    public function findTable(array|TableSettings $settings = []): ?Table
    {
        $tables = $this->findTables($settings);
        if ($tables === []) {
            return null;
        }
        usort($tables, static fn (Table $a, Table $b): int => [-count($a->cells), $a->bbox()[1], $a->bbox()[0]]
            <=> [-count($b->cells), $b->bbox()[1], $b->bbox()[0]]);

        return $tables[0];
    }

    /**
     * Text of every table: rows of cells, null where a row has no cell in that column.
     *
     * @param array<string, mixed>|TableSettings $settings
     *
     * @return list<list<list<string|null>>>
     */
    public function extractTables(array|TableSettings $settings = []): array
    {
        $settings = TableSettings::resolve($settings);

        return array_map(static fn (Table $t): array => $t->extract($settings->textSettings), $this->findTables($settings));
    }

    /**
     * Text of the largest table, or null when there is none.
     *
     * @param array<string, mixed>|TableSettings $settings
     *
     * @return list<list<string|null>>|null
     */
    public function extractTable(array|TableSettings $settings = []): ?array
    {
        $settings = TableSettings::resolve($settings);

        return $this->findTable($settings)?->extract($settings->textSettings);
    }

    /**
     * Frees the characters and graphics; they are read again from the PDF when needed.
     */
    public function close(): void
    {
        $this->chars = null;
        $this->graphics = null;
    }

    /**
     * Page text as pdfplumber's extract_text(). Options: layout, xTolerance, yTolerance,
     * xToleranceRatio, yToleranceRatio, keepBlankChars, useTextFlow, lineDir, charDir,
     * lineDirRotated, charDirRotated, lineDirRender, charDirRender, xDensity, yDensity,
     * xShift, yShift, layoutWidthChars, layoutHeightChars, splitAtPunctuation,
     * expandLigatures, extraAttrs (snake_case names work too).
     */
    public function extractText(mixed ...$options): string
    {
        return $this->textMap($options)->text;
    }

    /**
     * Words with x0, x1, top, bottom, doctop, width, height, upright, direction (and their
     * chars when $returnChars is true).
     *
     * @return list<array<string, mixed>>
     */
    public function extractWords(bool $returnChars = false, mixed ...$options): array
    {
        $extractor = new WordExtractor(TextSettings::from($options));
        $words = [];
        foreach ($extractor->wordsWithChars($this->chars()) as [$word, $chars]) {
            if ($returnChars) {
                $word['chars'] = $chars;
            }
            $words[] = $word;
        }

        return $words;
    }

    /**
     * Lines of text with their bounding boxes, as pdfplumber's extract_text_lines().
     *
     * @return list<array<string, mixed>>
     */
    public function extractTextLines(bool $strip = true, bool $returnChars = true, mixed ...$options): array
    {
        return $this->textMap($options)->lines($strip, $returnChars);
    }

    /**
     * Finds a regular expression (PCRE with delimiters, or a bare pattern) or a plain string
     * in the page text and returns each match with its bounding box.
     *
     * @return list<array<string, mixed>>
     */
    public function search(
        string $pattern,
        bool $regex = true,
        bool $case = true,
        int $mainGroup = 0,
        bool $returnChars = true,
        bool $returnGroups = true,
        mixed ...$options,
    ): array {
        if ($regex && @preg_match($pattern, '') === false) {
            $pattern = '~'.str_replace('~', '\~', $pattern).'~u';
        }

        return $this->textMap($options)->search($pattern, $regex, $case, $mainGroup, $returnChars, $returnGroups);
    }

    /**
     * Text by lines of characters clustered on doctop, as pdfplumber's extract_text_simple().
     */
    public function extractTextSimple(float $xTolerance = 3, float $yTolerance = 3): string
    {
        $lines = [];
        foreach (Cluster::objects($this->chars(), static fn (Char $c): float => $c->doctop, $yTolerance) as $line) {
            usort($line, static fn (Char $a, Char $b): int => $a->x0 <=> $b->x0);
            $text = '';
            $lastX1 = null;
            foreach ($line as $char) {
                if ($lastX1 !== null && $char->x0 > $lastX1 + $xTolerance) {
                    $text .= ' ';
                }
                $lastX1 = $char->x1;
                $text .= $char->text;
            }
            $lines[] = $text;
        }

        return implode("\n", $lines);
    }

    /**
     * The part of the page inside $bbox, with characters and graphics that cross its edge cut to it.
     *
     * @param array{0: float|int, 1: float|int, 2: float|int, 3: float|int} $bbox [x0, top, x1, bottom]
     */
    public function crop(array $bbox, bool $relative = false, bool $strict = true): self
    {
        $bbox = $this->proposedBbox($bbox, $relative, $strict);

        return $this->derive($bbox, static function (array $objects) use ($bbox): array {
            $kept = [];
            foreach ($objects as $object) {
                $overlap = Geometry::overlap([$object->x0, $object->top, $object->x1, $object->bottom], $bbox);
                if ($overlap === null) {
                    continue;
                }
                if ($overlap !== [$object->x0, $object->top, $object->x1, $object->bottom]) {
                    $object = clone $object;
                    $object->doctop += $overlap[1] - $object->top;
                    [$object->x0, $object->top, $object->x1, $object->bottom] = $overlap;
                    if ($object instanceof Graphic) {
                        $object->width = $object->x1 - $object->x0;
                        $object->height = $object->bottom - $object->top;
                    }
                }
                $kept[] = $object;
            }

            return $kept;
        });
    }

    /**
     * The part of the page inside $bbox, keeping only characters and graphics entirely inside it.
     *
     * @param array{0: float|int, 1: float|int, 2: float|int, 3: float|int} $bbox
     */
    public function withinBbox(array $bbox, bool $relative = false, bool $strict = true): self
    {
        $bbox = $this->proposedBbox($bbox, $relative, $strict);

        return $this->derive($bbox, static fn (array $objects): array => array_values(array_filter(
            $objects,
            static fn (Char|Graphic $o): bool => Geometry::overlap([$o->x0, $o->top, $o->x1, $o->bottom], $bbox)
                === [$o->x0, $o->top, $o->x1, $o->bottom],
        )));
    }

    /**
     * The page without $bbox: only characters and graphics entirely outside it.
     *
     * @param array{0: float|int, 1: float|int, 2: float|int, 3: float|int} $bbox
     */
    public function outsideBbox(array $bbox, bool $relative = false, bool $strict = true): self
    {
        $bbox = $this->proposedBbox($bbox, $relative, $strict);

        return $this->derive($this->bbox, static fn (array $objects): array => array_values(array_filter(
            $objects,
            static fn (Char|Graphic $o): bool => Geometry::overlap([$o->x0, $o->top, $o->x1, $o->bottom], $bbox) === null,
        )));
    }

    /**
     * The page with only the characters and graphics $test accepts.
     *
     * @param callable(Char|Graphic): bool $test
     */
    public function filter(callable $test): self
    {
        return $this->derive($this->bbox, static fn (array $chars): array => array_values(array_filter($chars, $test)));
    }

    /**
     * The page without duplicate characters (same text and position within $tolerance), which
     * some PDFs draw twice to fake bold text.
     *
     * @param list<string> $extraAttrs
     */
    public function dedupeChars(float $tolerance = 1, array $extraAttrs = ['fontname', 'size']): self
    {
        return $this->derive($this->bbox, static function (array $chars) use ($tolerance, $extraAttrs): array {
            $groups = [];
            foreach ($chars as $index => $char) {
                $key = [$char->upright, $char->text];
                foreach ($extraAttrs as $attr) {
                    $key[] = $char->{WordExtractor::property($attr)};
                }
                $groups[serialize($key)][] = $index;
            }
            ksort($groups, SORT_STRING);
            $keep = [];
            foreach ($groups as $indexes) {
                $members = array_map(static fn (int $i): array => [$i, $chars[$i]], $indexes);
                foreach (Cluster::objects($members, static fn (array $m): float => $m[1]->doctop, $tolerance) as $row) {
                    foreach (Cluster::objects($row, static fn (array $m): float => $m[1]->x0, $tolerance) as $column) {
                        usort($column, static fn (array $a, array $b): int => [$a[1]->doctop, $a[1]->x0]
                            <=> [$b[1]->doctop, $b[1]->x0]);
                        $keep[$column[0][0]] = true;
                    }
                }
            }
            ksort($keep);

            return array_values(array_intersect_key($chars, $keep));
        }, static fn (array $graphics): array => $graphics);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function textMap(array $options): TextMap
    {
        $settings = TextSettings::from($options);
        $settings->presorted = true;
        $settings->layoutBbox ??= $this->bbox;
        if ($settings->layoutWidthChars === 0) {
            $settings->layoutWidth ??= $this->width;
        }
        if ($settings->layoutHeightChars === 0) {
            $settings->layoutHeight ??= $this->height;
        }
        $extractor = new WordExtractor($settings);

        return TextMap::fromWords($extractor->wordsWithChars($this->chars()), $settings);
    }

    /**
     * @param array{0: float|int, 1: float|int, 2: float|int, 3: float|int} $bbox
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function proposedBbox(array $bbox, bool $relative, bool $strict): array
    {
        $bbox = array_map('floatval', array_values($bbox));
        if (count($bbox) !== 4) {
            throw new \InvalidArgumentException('A bounding box is [x0, top, x1, bottom].');
        }
        if ($relative) {
            $bbox = [
                $bbox[0] + $this->bbox[0], $bbox[1] + $this->bbox[1],
                $bbox[2] + $this->bbox[0], $bbox[3] + $this->bbox[1],
            ];
        }
        if ($strict) {
            $area = Geometry::area($bbox);
            if ($area == 0) {
                throw new \InvalidArgumentException('Bounding box '.json_encode($bbox).' has an area of zero.');
            }
            $overlap = Geometry::overlap($bbox, $this->bbox);
            if ($overlap === null) {
                throw new \InvalidArgumentException(
                    'Bounding box '.json_encode($bbox).' is entirely outside parent page bounding box '
                    .json_encode($this->bbox),
                );
            }
            if (Geometry::area($overlap) < $area) {
                throw new \InvalidArgumentException(
                    'Bounding box '.json_encode($bbox).' is not fully within parent page bounding box '
                    .json_encode($this->bbox),
                );
            }
        }

        /** @var array{0: float, 1: float, 2: float, 3: float} $bbox */
        return $bbox;
    }

    /**
     * @return list<Graphic>
     */
    private function graphicsOf(string $type): array
    {
        return array_values(array_filter($this->allGraphics(), static fn (Graphic $g): bool => $g->type === $type));
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $bbox
     * @param \Closure(list<Char|Graphic>): list<Char|Graphic> $select
     * @param (\Closure(list<Graphic>): list<Graphic>)|null $selectGraphics when graphics need another rule
     */
    private function derive(array $bbox, \Closure $select, ?\Closure $selectGraphics = null): self
    {
        $parent = $this;
        $selectGraphics ??= $select;

        return new self(
            $this->pageNumber,
            $this->rotation,
            $this->mediabox,
            $this->cropbox,
            $bbox,
            $this->initialDoctop,
            static fn (bool $graphics): array => [
                $select($parent->chars()),
                $graphics ? $selectGraphics($parent->allGraphics()) : [],
            ],
            $this,
        );
    }

    /**
     * @return list<Graphic>
     */
    private function allGraphics(): array
    {
        if ($this->graphics === null) {
            [$chars, $this->graphics] = ($this->loader)(true);
            $this->chars ??= $chars;
        }

        return $this->graphics;
    }
}
