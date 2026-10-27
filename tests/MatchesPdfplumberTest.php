<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber\Tests;

use Artygrand\Pdfplumber\Graphic;
use Artygrand\Pdfplumber\Pdf;
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
        $pdf = Pdf::open($path, $expected['password']);

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
    }
}
