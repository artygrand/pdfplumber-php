<?php

// Dump what pdfplumber-php extracts from a PDF as JSON, mirroring dump_py.py.

require __DIR__.'/../vendor/autoload.php';
ini_set('serialize_precision', '-1');

use Artygrand\Pdfplumber\Char;
use Artygrand\Pdfplumber\Graphic;
use Artygrand\Pdfplumber\Page;
use Artygrand\Pdfplumber\Pdf;

const REGIONS = [[0, 0, 0.5, 0.5], [0.25, 0.25, 0.75, 0.75], [0, 0.8, 1, 1], [0, 0, 0.5, 1]];

function r(float $value): float
{
    return $value;
}

function region(Page $page, array $rel): array
{
    [$x0, $top, $x1, $bottom] = $page->bbox;
    $w = $x1 - $x0;
    $h = $bottom - $top;

    return [$x0 + $rel[0] * $w, $top + $rel[1] * $h, $x0 + $rel[2] * $w, $top + $rel[3] * $h];
}

$out = ['pages' => [], 'error' => null];
try {
    $pdf = Pdf::open($argv[1], $argv[2] ?? '');
    foreach ($pdf->pages() as $page) {
        $out['pages'][] = [
            'width' => r($page->width),
            'height' => r($page->height),
            'bbox' => array_map('r', $page->bbox),
            'chars' => array_map(static fn (Char $c) => [
                $c->text, r($c->x0), r($c->top), r($c->x1), r($c->bottom), $c->fontname, r($c->size), $c->upright,
            ], $page->chars()),
            'text' => $page->extractText(),
            'layout' => $page->extractText(layout: true),
            'words' => array_map(static fn (array $w) => [
                $w['text'], r($w['x0']), r($w['top']), r($w['x1']), r($w['bottom']),
            ], $page->extractWords()),
            'crop' => array_map(static fn (array $rel) => $page->crop(region($page, $rel))->extractText(), REGIONS),
            'within' => array_map(static fn (array $rel) => $page->withinBbox(region($page, $rel))->extractText(), REGIONS),
            'graphics' => array_map(static fn (Graphic $g) => [
                $g->type, r($g->x0), r($g->top), r($g->x1), r($g->bottom), r($g->linewidth), $g->pts,
            ], [...$page->lines(), ...$page->rects(), ...$page->curves()]),
            'tables' => $page->extractTables(),
            'tables_text' => $page->extractTables(['vertical_strategy' => 'text', 'horizontal_strategy' => 'text']),
            'tables_crop' => $page->crop(region($page, REGIONS[3]))->extractTables(),
        ];
        $page->close();
    }
} catch (Throwable $e) {
    $out['error'] = get_class($e).': '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine();
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
