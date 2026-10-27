<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber\Tests;

use Artygrand\Pdfplumber\Graphic;
use Artygrand\Pdfplumber\Pdfplumber;
use Artygrand\Pdfplumber\Serializer;
use Artygrand\Pdfplumber\TextBlock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each fixture's text, words, lines, areas, graphics and tables equal what pdfplumber gave for it
 * (tests/fixtures/expected, written by tools/make_expected.py).
 */
final class MatchesPdfplumberTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function fixtures(): iterable
    {
        foreach (glob(__DIR__.'/fixtures/*.pdf') ?: [] as $path) {
            yield basename($path) => [$path];
        }
    }

    #[DataProvider('fixtures')]
    public function testPagesMatchPdfplumber(string $path): void
    {
        $expected = json_decode(
            (string) file_get_contents(__DIR__.'/fixtures/expected/'.basename($path, '.pdf').'.json'),
            true,
        );
        $pdf = Pdfplumber::open($path, $expected['password']);

        $this->assertSame(count($expected['pages']), $pdf->pageCount());
        foreach ($expected['pages'] as $i => $want) {
            $page = $pdf->page($i + 1);
            $label = basename($path).' page '.($i + 1);
            $this->assertEqualsWithDelta($want['width'], $page->width, 1e-9, $label);
            $this->assertEqualsWithDelta($want['height'], $page->height, 1e-9, $label);
            $this->assertCount($want['chars'], $page->chars(), $label);
            $this->assertSame($want['text'], $page->extractText(), $label);
            $this->assertSame($want['layout'], $page->extractText(layout: true), $label);
            $this->assertSame($want['simple'], $page->extractTextSimple(), $label);
            $this->assertSame($want['dedupe'], $page->dedupeChars()->extractText(), $label);
            $this->assertSame(
                $want['words'],
                array_map(
                    static fn (array $w): array => [$w['text'], $w['x0'], $w['top'], $w['x1'], $w['bottom']],
                    $page->extractWords(),
                ),
                $label,
            );
            $this->assertSame(
                $want['lines'],
                array_map(
                    static fn (array $l): array => [$l['text'], $l['x0'], $l['top'], $l['x1'], $l['bottom']],
                    $page->extractTextLines(),
                ),
                $label,
            );
            $this->assertSame(
                $want['graphics'],
                array_map(
                    static fn (Graphic $g): array => [$g->type, $g->x0, $g->top, $g->x1, $g->bottom],
                    [...$page->lines(), ...$page->rects(), ...$page->curves()],
                ),
                $label,
            );
            $this->assertCount($want['edges'], $page->edges(), $label);
            $this->assertSame($want['tables'], $page->extractTables(), $label);
            $this->assertSame($want['table'], $page->extractTable(), $label);
            $this->assertSame(
                $want['tables_text'],
                $page->extractTables(['vertical_strategy' => 'text', 'horizontal_strategy' => 'text']),
                $label,
            );
            $this->assertSame($want['tables_layout'], $page->extractTables(['text_layout' => true]), $label);
            foreach ($want['regions'] as $r => $region) {
                $this->assertSame($want['tables_crop'][$r], $page->crop($region)->extractTables(), "{$label} tables crop {$r}");
                $this->assertSame($want['crop'][$r], $page->crop($region)->extractText(), "{$label} crop {$r}");
                $this->assertSame($want['within'][$r], $page->withinBbox($region)->extractText(), "{$label} within {$r}");
                $this->assertSame($want['outside'][$r], $page->outsideBbox($region)->extractText(), "{$label} outside {$r}");
            }
        }

        $layout = Pdfplumber::open($path, $expected['password'], laparams: []);
        foreach ($expected['pages'] as $i => $want) {
            $boxes = array_filter(
                $layout->page($i + 1)->objects(),
                static fn (string $type): bool => str_starts_with($type, 'textbox'),
                ARRAY_FILTER_USE_KEY,
            );
            $this->assertSame(
                $want['textboxes'],
                array_map(
                    static fn (TextBlock $b): array => [$b->type, $b->text, $b->x0, $b->top, $b->x1, $b->bottom],
                    array_merge($boxes['textboxhorizontal'] ?? [], $boxes['textboxvertical'] ?? []),
                ),
                basename($path).' page '.($i + 1).' textboxes',
            );
        }
    }

    /**
     * Every attribute of every object, annotation, link and structure element of a fixture made
     * to hold them, equal to pdfplumber's to_dict() and structure_tree.
     */
    public function testObjectsMatchPdfplumber(): void
    {
        $path = __DIR__.'/fixtures/objects.pdf';
        $expected = json_decode((string) file_get_contents(__DIR__.'/fixtures/expected/objects.json'), true);
        $serializer = new Serializer();
        $plain = static fn (mixed $value): mixed => json_decode(
            json_encode($serializer->serialize($value), JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
            true,
        );

        $pdf = Pdfplumber::open($path);
        $this->assertEquals($expected['metadata'], $plain($pdf->metadata()));
        $this->assertEquals($expected['hyperlinks'], $plain($pdf->hyperlinks()));
        $this->assertEquals($expected['structure'], $pdf->structureTree());
        foreach ($pdf->pages() as $i => $page) {
            $this->assertEquals($expected['objects'][$i], self::withoutStreams($plain($page->toDict())), "page {$i}");
            $this->assertEquals($expected['page_structure'][$i], $page->structureTree(), "page {$i} structure");
        }
        foreach (Pdfplumber::open($path, laparams: [])->pages() as $i => $page) {
            $this->assertEquals(
                $expected['layout_objects'][$i],
                self::withoutStreams($plain($page->toDict())),
                "page {$i} with layout",
            );
        }
    }

    /**
     * pdfplumber's harness drops annotation and image streams and names inline images alike.
     *
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    private static function withoutStreams(array $page): array
    {
        foreach ($page['annots'] ?? [] as $i => $annot) {
            unset($page['annots'][$i]['data']);
        }
        foreach (['figures', 'images'] as $key) {
            foreach ($page[$key] ?? [] as $i => $item) {
                unset($page[$key][$i]['stream']);
                if (str_starts_with($item['name'], 'inline-')) {
                    $page[$key][$i]['name'] = 'inline';
                }
            }
        }

        return $page;
    }
}
