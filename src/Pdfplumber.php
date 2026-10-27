<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber;

use Artygrand\Pdfplumber\Content\Interpreter;
use Artygrand\Pdfplumber\Font\Encodings;
use Artygrand\Pdfplumber\Font\FontLoader;
use Artygrand\Pdfplumber\Parser\Dict;
use Artygrand\Pdfplumber\Layout\Analyzer;
use Artygrand\Pdfplumber\Layout\LAParams;
use Artygrand\Pdfplumber\Layout\Node;
use Artygrand\Pdfplumber\Parser\Document;
use Artygrand\Pdfplumber\Structure\StructTree;

/**
 * A PDF opened for reading text, like pdfplumber.open().
 *
 *     $pdf = Pdfplumber::open('invoice.pdf');
 *     $text = $pdf->page(1)->crop([87, 260, 416, 455])->extractText();
 */
final class Pdfplumber
{
    private readonly FontLoader $fonts;

    /** @var list<Page>|null */
    private ?array $pages = null;

    /**
     * @param list<int>|null $only page numbers to read
     */
    private function __construct(
        private readonly Document $document,
        private readonly ?array $only,
        private readonly ?string $unicodeNorm,
        private readonly bool $raiseUnicodeErrors,
        private readonly string $password,
        private readonly ?LAParams $laparams,
    ) {
        $this->fonts = new FontLoader($document);
    }

    /**
     * Opens a PDF file, like pdfplumber.open().
     *
     * @param list<int>|null $pages read only these pages (1-based); the others are skipped
     * @param 'NFC'|'NFKC'|'NFD'|'NFKD'|null $unicodeNorm normalise the text of every char (needs intl)
     * @param bool $raiseUnicodeErrors throw on an annotation text that is neither UTF-8 nor UTF-16,
     *        instead of leaving it null
     * @param array<string, mixed>|LAParams|null $laparams run pdfminer's layout analysis (lineOverlap,
     *        charMargin, lineMargin, wordMargin, boxesFlow, detectVertical, allTexts): pages then also
     *        hold text boxes, text lines and figures, and chars come in reading order
     * @param bool $repair rewrite the file with Ghostscript first, which fixes many damaged PDFs
     * @param string|null $gsPath the Ghostscript binary, when it is not "gs" on the PATH
     *
     * @throws Exception when the file is missing, not a PDF, or the password is wrong
     */
    public static function open(
        string $path,
        string $password = '',
        ?array $pages = null,
        ?string $unicodeNorm = null,
        bool $raiseUnicodeErrors = true,
        array|LAParams|null $laparams = null,
        bool $repair = false,
        ?string $gsPath = null,
        string $repairSetting = 'default',
    ): self {
        if (! is_file($path) || ! is_readable($path)) {
            throw new Exception("Cannot read {$path}");
        }
        $data = $repair
            ? Repair::run($path, $password, $gsPath, $repairSetting)
            : (string) file_get_contents($path);

        return self::fromString($data, $password, $pages, $unicodeNorm, $raiseUnicodeErrors, $laparams);
    }

    /**
     * Opens a PDF held in memory; the options are those of open().
     *
     * @param list<int>|null $pages
     * @param 'NFC'|'NFKC'|'NFD'|'NFKD'|null $unicodeNorm
     * @param array<string, mixed>|LAParams|null $laparams
     *
     * @throws Exception when the data is not a PDF or the password is wrong
     */
    public static function fromString(
        string $data,
        string $password = '',
        ?array $pages = null,
        ?string $unicodeNorm = null,
        bool $raiseUnicodeErrors = true,
        array|LAParams|null $laparams = null,
    ): self {
        $laparams = $laparams === null ? null : LAParams::from($laparams);
        if ($unicodeNorm !== null) {
            if (! in_array($unicodeNorm, ['NFC', 'NFKC', 'NFD', 'NFKD'], true)) {
                throw new \InvalidArgumentException("Unknown Unicode normalization form {$unicodeNorm}");
            }
            if (! class_exists(\Normalizer::class)) {
                throw new Exception('Unicode normalization needs the intl extension');
            }
        }
        try {
            return new self(new Document($data, $password), $pages, $unicodeNorm, $raiseUnicodeErrors, $password, $laparams);
        } catch (Exception $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new Exception('Cannot read the PDF: '.$e->getMessage(), 0, $e);
        }
    }

    public function pageCount(): int
    {
        return count($this->pages());
    }

    /**
     * The page with this number (1-based, the number it has in the document even when only some
     * pages were opened).
     */
    public function page(int $number): Page
    {
        foreach ($this->pages() as $page) {
            if ($page->pageNumber === $number) {
                return $page;
            }
        }

        throw new \OutOfRangeException("The PDF has no page {$number}");
    }

    /**
     * @return list<Page>
     */
    public function pages(): array
    {
        if ($this->pages !== null) {
            return $this->pages;
        }
        $this->pages = [];
        $doctop = 0.0;
        foreach ($this->document->pages() as $i => [$id, $attrs]) {
            if ($this->only !== null && ! in_array($i + 1, $this->only, true)) {
                continue;
            }
            $page = $this->makePage($i + 1, $attrs, $doctop, $id);
            $this->pages[] = $page;
            $doctop += $page->height;
        }

        return $this->pages;
    }

    /**
     * Text of every page, pages separated by a form feed; each page is freed once read.
     */
    public function extractText(mixed ...$options): string
    {
        $texts = [];
        foreach ($this->pages() as $page) {
            $texts[] = $page->extractText(...$options);
            $page->close();
        }

        return implode("\f", $texts);
    }

    /**
     * The document information dictionary (Title, Author, Producer, CreationDate, ...), texts decoded,
     * as pdfplumber's metadata.
     *
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        $info = $this->document->resolve($this->document->trailer->get('Info'));
        if (! $info instanceof Dict) {
            return [];
        }
        $decode = static function (mixed $value) use (&$decode): mixed {
            return match (true) {
                is_string($value) => Encodings::text($value),
                is_array($value) => array_map($decode, $value),
                default => $value,
            };
        };
        $metadata = [];
        foreach ($info->entries as $key => $value) {
            $metadata[(string) $key] = $decode($this->document->plain($value));
        }

        return $metadata;
    }

    /**
     * Annotations of every page.
     *
     * @return list<array<string, mixed>>
     */
    public function annots(): array
    {
        return array_merge(...array_map(static fn (Page $p): array => $p->annots(), $this->pages()));
    }

    /**
     * Links to URIs on every page.
     *
     * @return list<array<string, mixed>>
     */
    public function hyperlinks(): array
    {
        return array_merge(...array_map(static fn (Page $p): array => $p->hyperlinks(), $this->pages()));
    }

    /**
     * Objects of every page by type.
     *
     * @return array<string, list<PageObject>>
     */
    public function objects(): array
    {
        $objects = [];
        foreach ($this->pages() as $page) {
            foreach ($page->objects() as $type => $list) {
                $objects[$type] = [...$objects[$type] ?? [], ...$list];
            }
        }

        return $objects;
    }

    /**
     * The metadata and every page as arrays, as pdfplumber's to_dict().
     *
     * @param list<string>|null $objectTypes
     *
     * @return array{metadata: array<string, mixed>, pages: list<array<string, mixed>>}
     */
    public function toDict(?array $objectTypes = null): array
    {
        return [
            'metadata' => $this->metadata(),
            'pages' => array_map(static fn (Page $p): array => $p->toDict($objectTypes), $this->pages()),
        ];
    }

    /**
     * The document as JSON, as pdfplumber's to_json(); see Page::toJson().
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
        return (new Serializer($precision, $includeAttrs, $excludeAttrs))->json($this->toDict($objectTypes), $pretty);
    }

    /**
     * Objects of every page as CSV, as pdfplumber's to_csv().
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
        $types = $objectTypes ?? [...array_keys($this->objects()), 'annot'];

        return (new Serializer($precision, $includeAttrs, $excludeAttrs))->csv($this->pages(), $types);
    }

    /**
     * The document's logical structure (for tagged PDFs) as pdfplumber's structure_tree; empty
     * when the PDF has none.
     *
     * @return list<array<string, mixed>>
     */
    public function structureTree(): array
    {
        return $this->structTree()?->toArray() ?? [];
    }

    /**
     * The structure tree as objects; null when the PDF has none.
     */
    public function structTree(): ?StructTree
    {
        try {
            return new StructTree($this->document, $this->pageNumbers(), $this->pagesByNumber());
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Frees what the pages have read.
     */
    public function close(): void
    {
        foreach ($this->pages ?? [] as $page) {
            $page->close();
        }
    }

    /**
     * @return array<int, int> page number by page object id, for the pages read
     */
    private function pageNumbers(): array
    {
        $numbers = [];
        foreach ($this->document->pages() as $i => [$id]) {
            if ($this->only === null || in_array($i + 1, $this->only, true)) {
                $numbers[$id] = $i + 1;
            }
        }

        return $numbers;
    }

    /**
     * @return array<int, Page>
     */
    private function pagesByNumber(): array
    {
        $pages = [];
        foreach ($this->pages() as $page) {
            $pages[$page->pageNumber] = $page;
        }

        return $pages;
    }

    private function makePage(int $number, Dict $attrs, float $doctop, int $objectId = 0): Page
    {
        $document = $this->document;
        $rotate = $document->resolve($attrs->get('Rotate'));
        $rotation = is_int($rotate) || is_float($rotate) ? (((int) $rotate % 360) + 360) % 360 : 0;

        $raw = $this->box($attrs->get('MediaBox')) ?? [0.0, 0.0, 612.0, 792.0];
        $normal = self::normalize($raw, $rotation);
        $mbHeight = $normal[3] - $normal[1];
        $mediabox = self::invert($normal, $mbHeight);
        $crop = $this->box($attrs->get('CropBox'));
        $cropbox = $crop === null ? $mediabox : self::invert(self::normalize($crop, $rotation), $mbHeight);
        $height = $mediabox[3] - $mediabox[1];

        // pdfminer's page transformation: rotation about the MediaBox, origin at its corner.
        [$x0, $y0, $x1, $y1] = $raw;
        $ctm = match ($rotation) {
            90 => [0.0, -1.0, 1.0, 0.0, -$y0, $x1],
            180 => [-1.0, 0.0, 0.0, -1.0, $x1, $y1],
            270 => [0.0, 1.0, -1.0, 0.0, $y1, -$x0],
            default => [1.0, 0.0, 0.0, 1.0, -$x0, -$y0],
        };

        $fonts = $this->fonts;
        $form = $this->unicodeNorm === null ? null : constant(\Normalizer::class.'::'.$this->unicodeNorm);
        $laparams = $this->laparams;
        $width = $mediabox[2] - $mediabox[0];
        $loader = static function (bool $all) use ($document, $fonts, $attrs, $ctm, $height, $width, $mediabox, $doctop, $number, $form, $laparams): array {
            $layout = $laparams !== null;
            $interpreter = new Interpreter(
                $document, $fonts, $height, $mediabox[1], $mediabox[0], $doctop, $number, $all || $layout, $layout,
            );
            $contents = $document->resolve($attrs->get('Contents'));
            if ($contents !== null) {
                $interpreter->run(is_array($contents) ? $contents : [$contents], $attrs->get('Resources'), $ctm);
            }
            if ($form !== null) {
                foreach ($interpreter->chars as $char) {
                    $char->text = (string) \Normalizer::normalize($char->text, $form);
                }
            }
            if (! $layout) {
                return [$interpreter->chars, $interpreter->graphics, array_keys($interpreter->kinds)];
            }
            $nodes = (new Analyzer($laparams, $interpreter->positions))->page($interpreter->tree, [0.0, 0.0, $width, $height]);

            return self::flatten($nodes, $mediabox[0], $mediabox[1], $height, $doctop, $number, $form);
        };
        $annotations = static function () use ($document, $attrs): array {
            $annots = $document->plain($attrs->get('Annots'));

            return is_array($annots) ? array_values(array_filter($annots, 'is_array')) : [];
        };
        $box = fn (string $name): ?array => ($raw = $this->box($attrs->get($name))) === null
            ? null
            : self::invert(self::normalize($raw, $rotation), $mbHeight);

        $source = new PageSource(
            $annotations,
            fn (Page $page): StructTree => new StructTree(
                $document,
                $this->pageNumbers(),
                $this->pagesByNumber(),
                $page,
                $objectId,
                $attrs,
            ),
            fn (float $resolution, bool $antialias): string => Render::page(
                $this->document->data,
                $number,
                $resolution,
                $antialias,
                $this->password,
            ),
            $this->raiseUnicodeErrors,
        );

        return new Page(
            $number,
            $rotation,
            $mediabox,
            $cropbox,
            $mediabox,
            $doctop,
            $loader,
            source: $source,
            trimbox: $box('TrimBox'),
            bleedbox: $box('BleedBox'),
            artbox: $box('ArtBox'),
        );
   }

    /**
     * The analysed layout as pdfplumber lists it: each text box, then its lines, then their chars;
     * figures before what they hold.
     *
     * @param list<Node> $nodes
     *
     * @return array{0: list<Char>, 1: list<PageObject>, 2: list<string>}
     */
    private static function flatten(
        array $nodes,
        float $left,
        float $top,
        float $height,
        float $doctop,
        int $number,
        ?int $form,
    ): array {
        $chars = [];
        $others = [];
        $kinds = [];
        $types = [
            Node::BOX_H => 'textboxhorizontal', Node::BOX_V => 'textboxvertical',
            Node::LINE_H => 'textlinehorizontal', Node::LINE_V => 'textlinevertical',
        ];
        $walk = static function (array $nodes) use (&$walk, &$chars, &$others, &$kinds, $types, $left, $top, $height, $doctop, $number, $form): void {
            foreach ($nodes as $node) {
                if ($node->kind === Node::ANNO) {
                    continue;
                }
                if ($node->kind === Node::LEAF) {
                    $object = $node->object;
                    $kinds[$object->objectType()] = true;
                    if ($object instanceof Char) {
                        $chars[] = $object;
                    } else {
                        $others[] = $object;
                    }
                    if ($object instanceof Figure) {
                        $walk($node->items);
                    }

                    continue;
                }
                $text = $node->text();
                if ($form !== null) {
                    $text = (string) \Normalizer::normalize($text, $form);
                }
                $blockTop = $height - $node->y1 + $top;
                $type = $types[$node->kind];
                $kinds[$type] = true;
                $others[] = new TextBlock(
                    $type,
                    $text,
                    $left !== 0.0 ? $node->x0 + $left : $node->x0,
                    $node->y0,
                    $left !== 0.0 ? $node->x1 + $left : $node->x1,
                    $node->y1,
                    $blockTop,
                    $height - $node->y0 + $top,
                    $doctop + $blockTop,
                    $node->x1 - $node->x0,
                    $node->y1 - $node->y0,
                    $number,
                );
                $walk($node->items);
            }
        };
        $walk($nodes);

        return [$chars, $others, array_keys($kinds)];
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    private function box(mixed $value): ?array
    {
        $value = $this->document->resolve($value);
        if (! is_array($value) || count($value) < 4) {
            return null;
        }
        $box = [];
        foreach (array_slice(array_values($value), 0, 4) as $item) {
            $item = $this->document->resolve($item);
            if (! is_int($item) && ! is_float($item)) {
                return null;
            }
            $box[] = (float) $item;
        }

        /** @var array{0: float, 1: float, 2: float, 3: float} $box */
        return $box;
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $box
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private static function normalize(array $box, int $rotation): array
    {
        [$x0, $x1] = $box[0] <= $box[2] ? [$box[0], $box[2]] : [$box[2], $box[0]];
        [$y0, $y1] = $box[1] <= $box[3] ? [$box[1], $box[3]] : [$box[3], $box[1]];

        return $rotation === 90 || $rotation === 270 ? [$y0, $x0, $y1, $x1] : [$x0, $y0, $x1, $y1];
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $box
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private static function invert(array $box, float $height): array
    {
        return [$box[0], $height - $box[3], $box[2], $height - $box[1]];
    }
}
