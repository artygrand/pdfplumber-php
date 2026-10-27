<?php

declare(strict_types=1);

use Artygrand\Pdfplumber\Graphic;
use Artygrand\Pdfplumber\TextBlock;

// Every fixture page gives what pdfplumber gave for it, one feature per test.

test('size and characters', function (string $file, int $number): void {
    $want = expectedPage($file, $number);
    $page = fixturePage($file, $number);

    expect($page->width)->toEqualWithDelta($want['width'], 1e-9)
        ->and($page->height)->toEqualWithDelta($want['height'], 1e-9)
        ->and($page->chars())->toHaveCount($want['chars']);
})->with('pages');

test('text', function (string $file, int $number): void {
    expect(fixturePage($file, $number)->extractText())->toBe(expectedPage($file, $number)['text']);
})->with('pages');

test('text with layout', function (string $file, int $number): void {
    expect(fixturePage($file, $number)->extractText(layout: true))->toBe(expectedPage($file, $number)['layout']);
})->with('pages');

test('simple text', function (string $file, int $number): void {
    expect(fixturePage($file, $number)->extractTextSimple())->toBe(expectedPage($file, $number)['simple']);
})->with('pages');

test('text of deduplicated characters', function (string $file, int $number): void {
    expect(fixturePage($file, $number)->dedupeChars()->extractText())->toBe(expectedPage($file, $number)['dedupe']);
})->with('pages');

test('words', function (string $file, int $number): void {
    $words = array_map(
        static fn (array $w): array => [$w['text'], $w['x0'], $w['top'], $w['x1'], $w['bottom']],
        fixturePage($file, $number)->extractWords(),
    );

    expect($words)->toBe(expectedPage($file, $number)['words']);
})->with('pages');

test('text lines', function (string $file, int $number): void {
    $lines = array_map(
        static fn (array $l): array => [$l['text'], $l['x0'], $l['top'], $l['x1'], $l['bottom']],
        fixturePage($file, $number)->extractTextLines(),
    );

    expect($lines)->toBe(expectedPage($file, $number)['lines']);
})->with('pages');

test('lines, rects and curves', function (string $file, int $number): void {
    $page = fixturePage($file, $number);
    $graphics = array_map(
        static fn (Graphic $g): array => [$g->type, $g->x0, $g->top, $g->x1, $g->bottom],
        [...$page->lines(), ...$page->rects(), ...$page->curves()],
    );

    expect($graphics)->toBe(expectedPage($file, $number)['graphics'])
        ->and($page->edges())->toHaveCount(expectedPage($file, $number)['edges']);
})->with('pages');

test('tables by lines', function (string $file, int $number): void {
    $page = fixturePage($file, $number);

    expect($page->extractTables())->toBe(expectedPage($file, $number)['tables'])
        ->and($page->extractTable())->toBe(expectedPage($file, $number)['table']);
})->with('pages');

test('tables by text', function (string $file, int $number): void {
    $tables = fixturePage($file, $number)->extractTables(['vertical_strategy' => 'text', 'horizontal_strategy' => 'text']);

    expect($tables)->toBe(expectedPage($file, $number)['tables_text']);
})->with('pages');

test('tables with layout text', function (string $file, int $number): void {
    $tables = fixturePage($file, $number)->extractTables(['text_layout' => true]);

    expect($tables)->toBe(expectedPage($file, $number)['tables_layout']);
})->with('pages');

test('text of an area', function (string $file, int $number, int $region): void {
    $want = expectedPage($file, $number);
    $page = fixturePage($file, $number);
    $box = $want['regions'][$region];

    expect($page->crop($box)->extractText())->toBe($want['crop'][$region])
        ->and($page->withinBbox($box)->extractText())->toBe($want['within'][$region])
        ->and($page->outsideBbox($box)->extractText())->toBe($want['outside'][$region]);
})->with('pages')->with('regions');

test('tables of an area', function (string $file, int $number, int $region): void {
    $want = expectedPage($file, $number);

    expect(fixturePage($file, $number)->crop($want['regions'][$region])->extractTables())
        ->toBe($want['tables_crop'][$region]);
})->with('pages')->with('regions');

test('text boxes of layout analysis', function (string $file, int $number): void {
    $objects = fixturePage($file, $number, laparams: [])->objects();
    $boxes = array_map(
        static fn (TextBlock $b): array => [$b->type, $b->text, $b->x0, $b->top, $b->x1, $b->bottom],
        [...$objects['textboxhorizontal'] ?? [], ...$objects['textboxvertical'] ?? []],
    );

    expect($boxes)->toBe(expectedPage($file, $number)['textboxes']);
})->with('pages');
