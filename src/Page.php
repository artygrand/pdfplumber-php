<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber;

use Artygrand\Pdfplumber\Display\PageImage;
use Artygrand\Pdfplumber\Structure\StructTree;
use Artygrand\Pdfplumber\Table\Edges;
use Artygrand\Pdfplumber\Table\Table;
use Artygrand\Pdfplumber\Table\TableFinder;
use Artygrand\Pdfplumber\Table\TableSettings;
use Artygrand\Pdfplumber\Text\Cluster;
use Artygrand\Pdfplumber\Text\TextMap;
use Artygrand\Pdfplumber\Text\TextSettings;
use Artygrand\Pdfplumber\Text\WordExtractor;

/**
 * A page, or a part of one (crop, within, outside, filter), with pdfplumber's objects and its text
 * and table functions.
 *
 * Coordinates are points from the top-left corner: [x0, top, x1, bottom].
 */
final class Page
{
    public readonly float $width;

    public readonly float $height;

    /** @var list<Char>|null */
    private ?array $chars = null;

    /** the page this one was derived from by crop, filter and the like, or itself */
    public readonly Page $root;

    /** @var list<PageObject>|null */
    private ?array $graphics = null;

    /** @var list<string>|null object types in the order they first appear */
    private ?array $kinds = null;

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $mediabox
     * @param array{0: float, 1: float, 2: float, 3: float} $cropbox
     * @param array{0: float, 1: float, 2: float, 3: float} $bbox
     * @param \Closure(bool): array{0: list<Char>, 1: list<PageObject>, 2: list<string>} $loader reads
     *        the chars, and when given true also the graphics and images and the order of object types
     * @param array{0: float, 1: float, 2: float, 3: float}|null $trimbox
     * @param array{0: float, 1: float, 2: float, 3: float}|null $bleedbox
     * @param array{0: float, 1: float, 2: float, 3: float}|null $artbox
     * @param (\Closure(list<array<string, mixed>>): list<array<string, mixed>>)|null $annotationArea
     *        how a crop narrows the annotations
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
        private readonly ?PageSource $source = null,
        public readonly ?array $trimbox = null,
        public readonly ?array $bleedbox = null,
        public readonly ?array $artbox = null,
        private readonly ?\Closure $annotationArea = null,
    ) {
        $this->width = $bbox[2] - $bbox[0];
        $this->height = $bbox[3] - $bbox[1];
        $this->root = $parent === null ? $this : $parent->root;
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
     * Images drawn on the page.
     *
     * @return list<Image>
     */
    public function images(): array
    {
        return $this->graphicsOf('image');
    }

    /**
     * Every object by type ('char', 'line', 'rect', 'curve', 'image'), types in the order they
     * first appear, as pdfplumber's page.objects.
     *
     * @return array<string, list<PageObject>>
     */
    public function objects(): array
    {
        $this->allGraphics();
        $objects = [];
        foreach ($this->kinds ?? [] as $kind) {
            $objects[$kind] = $kind === 'char' ? $this->chars() : $this->graphicsOf($kind);
        }

        return $objects;
    }

    /**
     * Lines, rect sides and curve segments as edges (arrays with x0, x1, top, bottom, width,
     * height and orientation 'h', 'v' or null), as pdfplumber's page.edges.
     *
     * @return list<array<string, mixed>>
     */
    public function edges(): array
    {
        return [...$this->lineEdges(), ...$this->rectEdges(), ...$this->curveEdges()];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function horizontalEdges(): array
    {
        return array_values(array_filter($this->edges(), static fn (array $e): bool => $e['orientation'] === 'h'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function verticalEdges(): array
    {
        return array_values(array_filter($this->edges(), static fn (array $e): bool => $e['orientation'] === 'v'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rectEdges(): array
    {
        return array_merge(...array_map(Edges::fromObject(...), $this->rects()));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function curveEdges(): array
    {
        return array_merge(...array_map(Edges::fromObject(...), $this->curves()));
    }

    /**
     * Links, notes, form fields and other annotations, positioned like the page's objects, with their
     * uri, title and contents decoded and the whole dictionary in 'data'.
     *
     * @return list<array<string, mixed>>
     *
     * @throws Exception when a uri, title or contents is neither UTF-8 nor UTF-16 and the PDF was
     *         opened with raiseUnicodeErrors
     */
    public function annots(): array
    {
        $raw = $this->source === null ? [] : ($this->source->annotations)();
        $rootHeight = $this->root->height;
        $turns = intdiv($this->rotation, 90);
        $rotate = function (float|int $x, float|int $y) use ($turns): array {
            for ($i = 0; $i < $turns; $i++) {
                $side = $i === $turns % 2 ? $this->width : $this->height;
                [$x, $y] = [$y, $side - $x];
            }

            return [$x, $y];
        };
        $parsed = [];
        foreach ($raw as $annot) {
            $rect = $annot['Rect'] ?? null;
            if (! is_array($rect) || count($rect) < 4) {
                continue;
            }
            [$a, $b] = $rotate(...array_slice($rect, 0, 2));
            [$c, $d] = $rotate(...array_slice($rect, 2, 2));
            [$x0, $x1] = $a <= $c ? [$a, $c] : [$c, $a];
            [$y0, $y1] = $b <= $d ? [$b, $d] : [$d, $b];
            [$top, $bottom] = [$rootHeight - $y1, $rootHeight - $y0];
            $action = is_array($annot['A'] ?? null) ? $annot['A'] : [];
            if (array_key_exists('P', $annot)) {
                $annot['P'] = $this;
            }
            $parsed[] = [
                'page_number' => $this->pageNumber,
                'object_type' => 'annot',
                'x0' => $x0,
                'y0' => $rootHeight - $bottom,
                'x1' => $x1,
                'y1' => $rootHeight - $top,
                'doctop' => $this->initialDoctop + $top,
                'top' => $top,
                'bottom' => $bottom,
                'width' => $x1 - $x0,
                'height' => $bottom - $top,
                'uri' => $this->decodeAnnotation('uri', $action['URI'] ?? null),
                'title' => $this->decodeAnnotation('title', $annot['T'] ?? null),
                'contents' => $this->decodeAnnotation('contents', $annot['Contents'] ?? null),
                'data' => $annot,
            ];
        }

        return $this->annotationArea === null ? $parsed : ($this->annotationArea)($parsed);
    }

    /**
     * The page's part of the document's logical structure (for tagged PDFs) as pdfplumber's
     * structure_tree: nested elements with type, mcids (matching the mcid of chars) and children.
     *
     * @return list<array<string, mixed>>
     */
    public function structureTree(): array
    {
        return $this->structTree()?->toArray() ?? [];
    }

    /**
     * The structure tree as objects, to find elements and their boxes; null when the PDF has none.
     */
    public function structTree(): ?StructTree
    {
        try {
            return $this->source === null ? null : ($this->source->structure)($this);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * The page rendered (with Ghostscript) to draw on, as pdfplumber's to_image(). Give at most one
     * of $resolution (pixels per inch, 72 by default), $width or $height (pixels).
     */
    public function toImage(
        ?float $resolution = null,
        ?float $width = null,
        ?float $height = null,
        bool $antialias = false,
        bool $forceMediabox = false,
    ): PageImage {
        $given = count(array_filter([$resolution, $width, $height], static fn (?float $v): bool => $v !== null));
        if ($given > 1) {
            throw new \InvalidArgumentException("Only one of resolution, width and height can be given, not {$given}");
        }
        if ($width !== null) {
            $resolution = 72 * $width / $this->width;
        } elseif ($height !== null) {
            $resolution = 72 * $height / $this->height;
        }

        return new PageImage($this, null, $resolution ?: 72, $antialias, $forceMediabox);
    }

    /**
     * The whole page as PNG data at $resolution pixels per inch.
     *
     * @throws Exception when Ghostscript is missing or fails
     */
    public function render(float $resolution = 72, bool $antialias = false): string
    {
        if ($this->source === null) {
            throw new Exception('This page cannot be rendered');
        }

        return ($this->source->render)($resolution, $antialias);
    }

    /**
     * Annotations that link to a URI.
     *
     * @return list<array<string, mixed>>
     */
    public function hyperlinks(): array
    {
        return array_values(array_filter($this->annots(), static fn (array $a): bool => $a['uri'] !== null));
    }

    /**
     * The table finder with its edges, intersections, cells and tables, to see why a table is or is
     * not found.
     *
     * @param array<string, mixed>|TableSettings $settings
     */
    public function debugTablefinder(array|TableSettings $settings = []): TableFinder
    {
        return new TableFinder($this, TableSettings::resolve($settings));
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
        $this->kinds = null;
    }

    /**
     * The page and its objects as arrays, as pdfplumber's to_dict(): object types are 'char',
     * 'line', 'rect', 'curve', 'image' and 'annot' (all the page has by default).
     *
     * @param list<string>|null $objectTypes
     *
     * @return array<string, mixed>
     */
    public function toDict(?array $objectTypes = null): array
    {
        $dict = [
            'page_number' => $this->pageNumber,
            'initial_doctop' => $this->initialDoctop,
            'rotation' => $this->rotation,
            'cropbox' => $this->cropbox,
            'mediabox' => $this->mediabox,
            'bbox' => $this->bbox,
            'width' => $this->width,
            'height' => $this->height,
        ];
        foreach ($objectTypes ?? [...array_keys($this->objects()), 'annot'] as $type) {
            $dict[$type.'s'] = $this->objectsOf($type);
        }

        return $dict;
    }

    /**
     * The page as JSON, as pdfplumber's to_json(). Floats are rounded to $precision digits when it is
     * given; $includeAttrs or $excludeAttrs pick the object attributes.
     *
     * @param list<string>|null $objectTypes
     * @param list<string>|null $includeAttrs
     * @param list<string>|null $excludeAttrs
     */
    public function toJson(
        ?array $objectTypes = null,
        ?array $includeAttrs = null,
        ?array $excludeAttrs = null,
        ?int $precision = null,
        bool $pretty = false,
    ): string {
        $serializer = new Serializer($precision, $includeAttrs, $excludeAttrs);

        return $serializer->json($this->toDict($objectTypes), $pretty);
    }

    /**
     * The page's objects as CSV, one row per object, as pdfplumber's to_csv().
     *
     * @param list<string>|null $objectTypes
     * @param list<string>|null $includeAttrs
     * @param list<string>|null $excludeAttrs
     */
    public function toCsv(
        ?array $objectTypes = null,
        ?int $precision = null,
        ?array $includeAttrs = null,
        ?array $excludeAttrs = null,
    ): string {
        $serializer = new Serializer($precision, $includeAttrs, $excludeAttrs);

        return $serializer->csv([$this], $objectTypes ?? [...array_keys($this->objects()), 'annot']);
    }

    /**
     * Objects of one type as arrays ('char', 'line', 'rect', 'curve', 'image' or 'annot').
     *
     * @return list<array<string, mixed>>
     */
    public function objectsOf(string $type): array
    {
        if ($type === 'annot') {
            return $this->annots();
        }
        $objects = $type === 'char' ? $this->chars() : $this->graphicsOf($type);

        return array_map(static fn (PageObject $o): array => $o->toArray(), $objects);
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
     * The part of the page inside $bbox, with the objects that cross its edge cut to it.
     *
     * @param array{0: float|int, 1: float|int, 2: float|int, 3: float|int} $bbox [x0, top, x1, bottom]
     */
    public function crop(array $bbox, bool $relative = false, bool $strict = true): self
    {
        $bbox = $this->proposedBbox($bbox, $relative, $strict);

        $select = static function (array $objects) use ($bbox): array {
            $kept = [];
            foreach ($objects as $object) {
                $box = self::boxOf($object);
                $overlap = Geometry::overlap($box, $bbox);
                if ($overlap === null) {
                    continue;
                }
                if ($overlap !== $box) {
                    $object = self::clip($object, $overlap);
                }
                $kept[] = $object;
            }

            return $kept;
        };

        return $this->derive($bbox, $select, $select, $select);
    }

    /**
     * The part of the page inside $bbox, keeping only the objects entirely inside it.
     *
     * @param array{0: float|int, 1: float|int, 2: float|int, 3: float|int} $bbox
     */
    public function withinBbox(array $bbox, bool $relative = false, bool $strict = true): self
    {
        $bbox = $this->proposedBbox($bbox, $relative, $strict);

        $select = static fn (array $objects): array => array_values(array_filter(
            $objects,
            static fn (mixed $o): bool => Geometry::overlap(self::boxOf($o), $bbox) === self::boxOf($o),
        ));

        return $this->derive($bbox, $select, $select, $select);
    }

    /**
     * The page without $bbox: only the objects entirely outside it.
     *
     * @param array{0: float|int, 1: float|int, 2: float|int, 3: float|int} $bbox
     */
    public function outsideBbox(array $bbox, bool $relative = false, bool $strict = true): self
    {
        $bbox = $this->proposedBbox($bbox, $relative, $strict);

        $select = static fn (array $objects): array => array_values(array_filter(
            $objects,
            static fn (mixed $o): bool => Geometry::overlap(self::boxOf($o), $bbox) === null,
        ));

        return $this->derive($this->bbox, $select, $select, $select);
    }

    /**
     * The page with only the objects $test accepts (annotations are kept as they are).
     *
     * @param callable(PageObject): bool $test
     */
    public function filter(callable $test): self
    {
        $select = static fn (array $objects): array => array_values(array_filter($objects, $test));

        return $this->derive($this->bbox, $select, $select);
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
     * @return ($type is 'image' ? list<Image> : list<Graphic>)
     */
    private function graphicsOf(string $type): array
    {
        return array_values(array_filter(
            $this->allGraphics(),
            static fn (PageObject $o): bool => $o->objectType() === $type,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lineEdges(): array
    {
        return array_merge(...array_map(Edges::fromObject(...), $this->lines()));
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private static function boxOf(mixed $object): array
    {
        return is_array($object)
            ? [$object['x0'], $object['top'], $object['x1'], $object['bottom']]
            : [$object->x0, $object->top, $object->x1, $object->bottom];
    }

    /**
     * A copy of the object cut to $box, as pdfplumber's clip_obj.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $box
     */
    private static function clip(mixed $object, array $box): mixed
    {
        if (is_array($object)) {
            $diff = $box[1] - $object['top'];
            [$object['x0'], $object['top'], $object['x1'], $object['bottom']] = $box;
            if (array_key_exists('doctop', $object)) {
                $object['doctop'] += $diff;
            }
            $object['width'] = $object['x1'] - $object['x0'];
            $object['height'] = $object['bottom'] - $object['top'];

            return $object;
        }
        $object = clone $object;
        $object->doctop += $box[1] - $object->top;
        [$object->x0, $object->top, $object->x1, $object->bottom] = $box;
        $object->width = $object->x1 - $object->x0;
        $object->height = $object->bottom - $object->top;

        return $object;
    }

    private function decodeAnnotation(string $field, mixed $value): mixed
    {
        if (! is_string($value) || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }
        $text = Font\Encodings::strictUtf16($value);
        if ($text === null && ($this->source === null || $this->source->raiseUnicodeErrors)) {
            throw new Exception("Could not decode the {$field} of an annotation on page {$this->pageNumber}");
        }

        return $text;
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $bbox
     * @param \Closure(list<Char>): list<Char> $selectChars
     * @param \Closure(list<PageObject>): list<PageObject> $selectGraphics
     * @param (\Closure(list<array<string, mixed>>): list<array<string, mixed>>)|null $selectAnnotations
     *        for a crop; other derived pages keep the page's annotations
     */
    private function derive(
        array $bbox,
        \Closure $selectChars,
        \Closure $selectGraphics,
        ?\Closure $selectAnnotations = null,
    ): self {
        $parent = $this;

        return new self(
            $this->pageNumber,
            $this->rotation,
            $this->mediabox,
            $this->cropbox,
            $bbox,
            $this->initialDoctop,
            static function (bool $all) use ($parent, $selectChars, $selectGraphics): array {
                if (! $all) {
                    return [$selectChars($parent->chars()), [], []];
                }
                $graphics = $selectGraphics($parent->allGraphics());

                return [$selectChars($parent->chars()), $graphics, $parent->kinds ?? []];
            },
            $this,
            $this->source,
            $this->trimbox,
            $this->bleedbox,
            $this->artbox,
            $selectAnnotations,
        );
    }

    /**
     * @return list<PageObject>
     */
    private function allGraphics(): array
    {
        if ($this->graphics === null) {
            [$chars, $this->graphics, $this->kinds] = ($this->loader)(true);
            $this->chars ??= $chars;
        }

        return $this->graphics;
    }
}
