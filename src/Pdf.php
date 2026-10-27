<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber;

use Artygrand\Pdfplumber\Content\Interpreter;
use Artygrand\Pdfplumber\Font\Encodings;
use Artygrand\Pdfplumber\Font\FontLoader;
use Artygrand\Pdfplumber\Parser\Dict;
use Artygrand\Pdfplumber\Parser\Document;
use Artygrand\Pdfplumber\Parser\Name;

/**
 * A PDF opened for reading text, like pdfplumber.open().
 *
 *     $pdf = Pdf::open('invoice.pdf');
 *     $text = $pdf->page(1)->crop([87, 260, 416, 455])->extractText();
 */
final class Pdf
{
    private readonly FontLoader $fonts;

    /** @var list<Page>|null */
    private ?array $pages = null;

    private function __construct(private readonly Document $document)
    {
        $this->fonts = new FontLoader($document);
    }

    /**
     * @throws Exception when the file is missing, not a PDF, or the password is wrong
     */
    public static function open(string $path, string $password = ''): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new Exception("Cannot read {$path}");
        }

        return self::fromString((string) file_get_contents($path), $password);
    }

    /**
     * @throws Exception when the data is not a PDF or the password is wrong
     */
    public static function fromString(string $data, string $password = ''): self
    {
        try {
            return new self(new Document($data, $password));
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
     * @param int $number 1-based
     */
    public function page(int $number): Page
    {
        $pages = $this->pages();
        if (! isset($pages[$number - 1])) {
            throw new \OutOfRangeException("The PDF has no page {$number}");
        }

        return $pages[$number - 1];
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
        foreach ($this->document->pages() as $i => [, $attrs]) {
            $page = $this->makePage($i + 1, $attrs, $doctop);
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
     * The document information dictionary (Title, Author, Producer, CreationDate, ...).
     *
     * @return array<string, string>
     */
    public function metadata(): array
    {
        $info = $this->document->resolve($this->document->trailer->get('Info'));
        if (! $info instanceof Dict) {
            return [];
        }
        $metadata = [];
        foreach ($info->entries as $key => $value) {
            $value = $this->document->resolve($value);
            if (is_string($value)) {
                $metadata[(string) $key] = self::decodeText($value);
            } elseif ($value instanceof Name) {
                $metadata[(string) $key] = $value->name;
            } elseif (is_int($value) || is_float($value) || is_bool($value)) {
                $metadata[(string) $key] = (string) $value;
            }
        }

        return $metadata;
    }

    private function makePage(int $number, Dict $attrs, float $doctop): Page
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
        $loader = static function (bool $graphics) use ($document, $fonts, $attrs, $ctm, $height, $mediabox, $doctop, $number): array {
            $interpreter = new Interpreter(
                $document, $fonts, $height, $mediabox[1], $mediabox[0], $doctop, $number, $graphics,
            );
            $contents = $document->resolve($attrs->get('Contents'));
            if ($contents !== null) {
                $interpreter->run(is_array($contents) ? $contents : [$contents], $attrs->get('Resources'), $ctm);
            }

            return [$interpreter->chars, $interpreter->graphics];
        };

        return new Page($number, $rotation, $mediabox, $cropbox, $mediabox, $doctop, $loader);
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

    private static function decodeText(string $value): string
    {
        if (str_starts_with($value, "\xFE\xFF")) {
            return Encodings::utf16(substr($value, 2));
        }
        if (str_starts_with($value, "\xEF\xBB\xBF")) {
            return substr($value, 3);
        }
        static $table = null;
        $table ??= Encodings::table('PDFDocEncoding');
        $text = '';
        for ($i = 0, $n = strlen($value); $i < $n; $i++) {
            $text .= $table[ord($value[$i])] ?? '';
        }

        return $text;
    }
}
