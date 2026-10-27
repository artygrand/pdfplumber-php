# pdfplumber-php

A pure PHP port of [pdfplumber](https://github.com/jsvine/pdfplumber): every character of a PDF
with its position, font and colours, words, page text, text from an area, lines, rectangles,
curves, images, annotations and links, tables, the structure tree of tagged PDFs, pdfminer's
layout analysis, export to JSON and CSV, and a command line. No extensions beyond mbstring and zlib
(openssl for AES-encrypted files), no FFI. Optional: GD for drawing on page images, intl for
Unicode normalization, Ghostscript for rendering pages and repairing broken files.

The output matches pdfplumber's to the last bit of every coordinate on the test corpus (see
[Parity](#parity)), and it is about 5–8 times faster (up to 13 with JIT) with about 60% of the
memory.

```bash
composer require artygrand/pdfplumber-php
```

## Usage

```php
use Artygrand\Pdfplumber\Pdfplumber;

$pdf = Pdfplumber::open('declaration.pdf');      // or Pdfplumber::fromString($bytes); see Opening
$page = $pdf->page(1);                           // 1-based; $pdf->pages() for all, $pdf->pageCount()

$page->extractText();                            // as pdfplumber's extract_text()
$page->extractText(layout: true);                // keeps the visual layout with spaces
$page->crop([87, 260, 416, 455])->extractText(); // text from an area: [x0, top, x1, bottom] in points
$page->search('机壳\s+([A-Z0-9-]+)');            // matches with their bounding boxes
$pdf->extractText();                             // all pages joined with "\f"
```

Coordinates are points (1/72 inch) from the top-left corner of the page, as in pdfplumber:
`x0`/`x1` from the left edge, `top`/`bottom` from the top edge. The PDF markup tool shows them.

### Text

| Method | pdfplumber |
| --- | --- |
| `chars()` | `page.chars` (list of `Char`: text, fontname, size, x0, x1, top, bottom, doctop, upright, matrix, ncs, colours, mcid, tag, …) |
| `extractText(...$options)` | `extract_text()` |
| `extractWords(returnChars: false, ...$options)` | `extract_words()` |
| `extractTextLines(strip: true, returnChars: true, ...$options)` | `extract_text_lines()` |
| `extractTextSimple(xTolerance: 3, yTolerance: 3)` | `extract_text_simple()` |
| `search($pattern, regex: true, case: true, ...)` | `search()`; a bare pattern is wrapped as `~…~u` |

Text options are pdfplumber's, in camelCase or snake_case: `layout`, `xTolerance`, `yTolerance`,
`xToleranceRatio`, `yToleranceRatio`, `keepBlankChars`, `useTextFlow`, `lineDir`, `charDir`,
`lineDirRotated`, `charDirRotated`, `lineDirRender`, `charDirRender`, `xDensity`, `yDensity`,
`xShift`, `yShift`, `layoutWidthChars`, `layoutHeightChars`, `splitAtPunctuation`,
`expandLigatures`, `extraAttrs`.

### Areas

Each returns a new `Page` restricted to the area, on which every method works:

| Method | pdfplumber |
| --- | --- |
| `crop($bbox, relative: false, strict: true)` | `crop()`: objects crossing the edge are cut to it |
| `withinBbox($bbox, relative: false, strict: true)` | `within_bbox()`: only objects entirely inside |
| `outsideBbox($bbox, relative: false, strict: true)` | `outside_bbox()`: only objects entirely outside |
| `filter(fn (PageObject $o): bool)` | `filter()` |
| `dedupeChars(tolerance: 1, extraAttrs: ['fontname', 'size'])` | `dedupe_chars()` |

`strict: false` lets the area reach past the page instead of throwing.

### Lines, rectangles and tables

| Method | pdfplumber |
| --- | --- |
| `lines()`, `rects()`, `curves()` | `page.lines`, `page.rects`, `page.curves` (list of `Graphic`: points, path, line width, stroke, fill, colours, dash, …) |
| `edges()`, `horizontalEdges()`, `verticalEdges()`, `rectEdges()`, `curveEdges()` | `page.edges` and the rest |
| `findTables($settings)`, `findTable($settings)` | `find_tables()`, `find_table()` (`Table`: `cells`, `bbox()`, `rows()`, `columns()`, `extract()`) |
| `extractTables($settings)`, `extractTable($settings)` | `extract_tables()`, `extract_table()` |
| `debugTablefinder($settings)` | `debug_tablefinder()` |

Table settings are pdfplumber's (`verticalStrategy` / `horizontalStrategy`: `lines`,
`lines_strict`, `text`, `explicit`; `explicitVerticalLines`, `snapTolerance`, `joinTolerance`,
`edgeMinLength`, `intersectionTolerance`, their x/y variants, `minWordsVertical`, …), with text
options prefixed `text_` (`['text_layout' => true]`). Lines and rectangles are read from the PDF
only when asked for, so plain text extraction does not pay for them.

### Objects, images and annotations

| Method | pdfplumber |
| --- | --- |
| `images()` | `page.images` (list of `Image`: name, size, bits, colour space, `data()`, …) |
| `objects()` | `page.objects`: every kind by name, in the order they first appear |
| `annots()`, `hyperlinks()` | `page.annots`, `page.hyperlinks` |
| `$pdf->metadata()`, `$pdf->annots()`, `$pdf->hyperlinks()` | `pdf.metadata`, `pdf.annots`, `pdf.hyperlinks` |

Every object is a `PageObject` with `toArray()` giving pdfplumber's dict, keys in its order.

### Layout analysis

`Pdfplumber::open($path, laparams: [])` (or `['line_margin' => 0.3, 'boxes_flow' => null, ...]`, or a
`Layout\LAParams`) runs pdfminer's layout analysis, as pdfplumber does with `laparams`: chars get
the spaces and newlines pdfminer adds, and `objects()` gains `textboxhorizontal`,
`textlinehorizontal`, their vertical kinds and `figure` (list of `TextBlock` and `Figure`).

### Structure tree

`structureTree()` on a page or the document gives pdfplumber's `structure_tree` (elements with
type, mcids, page number, attributes, children); `structTree()` gives the `StructTree` object with
`find()`, `findAll()` and `elementBbox()`.

### Page images

`$page->toImage(resolution: 150)` renders the page with Ghostscript and returns a `PageImage` to draw
on with GD, as pdfplumber's `to_image()`: `drawLine(s)`, `drawRect(s)`, `drawCircle(s)`,
`drawVline(s)`, `drawHline(s)`, `outlineWords()`, `outlineChars()`, `debugTable()`,
`debugTablefinder()`, `reset()`, `copy()`, `save($path)`, `toPng()`. `$page->render()` gives the
PNG data directly.

### Export and command line

`toDict()`, `toJson(objectTypes, includeAttrs, excludeAttrs, precision, pretty)` and
`toCsv(objectTypes, precision, includeAttrs, excludeAttrs)` work on a page and on the document.
`vendor/bin/pdfplumber` is pdfplumber's command line: `--format csv|json|text`, `--types`,
`--include-attrs`, `--exclude-attrs`, `--precision`, `--laparams`, `--pages 1 3 5-8`,
`--structure`, `--structure-text`, `--indent`, `--password`.

### Opening

`Pdfplumber::open()` takes pdfplumber's options: `password`, `pages` (numbers to read; `doctop` counts
over them only), `unicodeNorm` (`NFC`, `NFKC`, `NFD`, `NFKD`; needs intl), `raiseUnicodeErrors`,
`laparams`, and `repair: true` (with `gsPath`, `repairSetting`) to rewrite a broken file with
Ghostscript first.

### Memory

A page keeps its characters once read; `$page->close()` frees them (and `$pdf->extractText()` does
it after each page).

## Parity

`bench/compare.py` runs pdfplumber and pdfplumber-php on 147 PDFs (the test suites of pdfplumber and
pdfminer.six, including their fuzzing files, plus generated Japanese, Chinese, rotated, encrypted
and table samples) and compares text, layout text, words, every character's coordinates as exact
doubles, crops and within-areas, lines, rects and curves, and tables. Every PDF that is not
damaged gives identical output. The differences are in damaged files, where the two recover
differently: pdfplumber fails on some and reads older object versions of others, or finds no
pages where pdfplumber-php finds them.

`bench/objects_compare.py` compares every object pdfplumber's `to_dict()` gives (all attributes of
chars, lines, rects, curves, images and annotations) and the metadata; with `LAPARAMS` set it also
compares text boxes, text lines and figures. The differences are again only in damaged files and
in files where pdfplumber fails. pdfminer orders boxes at equal distances by their memory address,
which changes from run to run; the harness numbers them in creation order instead, as
pdfplumber-php does, and then the boxes match as well. The structure trees of all six tagged PDFs
in the corpus match too.

## Speed and memory

Median wall time and peak memory of reading a whole file and extracting its text, on the same
machine (Linux container, PHP 8.5 without opcache/JIT unless noted, Python 3.13, pdfplumber 0.11):

| File | pdfplumber | pdfplumber-php | pdfplumber-php + JIT | Memory, pdfplumber / PHP |
| --- | --- | --- | --- | --- |
| 300-page report | 13 228 ms | 1 734 ms | 1 035 ms | 52 / 34 MB |
| 50-page IRS form (i1040nr) | 2 514 ms | 338 ms | 264 ms | 52 / 32 MB |
| small invoices and declarations | 82–152 ms | 15–25 ms | — | 37–48 / 21–27 MB |

Layout analysis (`laparams`) on the heaviest page of the corpus, nics-background-checks rotated
with 1 516 text boxes: pdfplumber 13.2 s and 422 MB, pdfplumber-php 8.4 s and 200 MB.

Finding and extracting every table (`extract_tables()` on each page, `bench/tables_bench.py`,
time after start-up):

| File | pdfplumber | pdfplumber-php |
| --- | --- | --- |
| nics-background-checks (1 page, 1 large table) | 101 ms | 28 ms |
| pr-138-example (2 pages, 4 tables) | 186 ms | 46 ms |
| senate-expenditures | 77 ms | 17 ms |
| issue-140-example | 26 ms | 12 ms |

pdfplumber also builds the graphics objects for every page; pdfplumber-php reads them only when
asked for (`lines()`, `rects()`, `curves()`, `images()`, `objects()`, `edges()`, tables).

## Differences from pdfplumber

- Pages are rendered with Ghostscript, not pypdfium2, so page images differ in antialiasing.
- CSV cells holding lists are JSON (`[0.0,1.0,0.0]`), not Python's `(0.0, 1.0, 0.0)`.
- Inline images are named `inline-1`, `inline-2`, … where pdfminer uses a memory address.
- Text strings starting with a UTF-8 byte order mark are read as UTF-8; pdfplumber reads them as
  PDFDocEncoding.
- Errors in damaged PDFs are recovered from where pdfplumber would give up, but not always the
  same way it does when it succeeds.
- `search()` takes PCRE patterns (with delimiters, or bare and wrapped as `~…~u`) instead of
  Python regular expressions.

## Development

```bash
composer install
vendor/bin/phpunit                     # fixtures checked against pdfplumber's recorded output
python3 bench/make_samples.py          # regenerate the fixtures (reportlab, fpdf2, pypdf)
python3 tools/make_expected.py         # record pdfplumber's output for them
python3 bench/compare.py               # compare on the whole corpus (bench/corpus.py lists it)
python3 bench/objects_compare.py       # every object's attributes; LAPARAMS='{}' for layout analysis
python3 bench/bench.py                 # speed and memory
```

## License

MIT, see [LICENSE](LICENSE), which also lists the licenses of pdfplumber, pdfminer.six and the
Adobe data in `resources/`.
