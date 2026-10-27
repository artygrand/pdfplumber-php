<?php

declare(strict_types=1);

use Artygrand\Pdfplumber\Pdfplumber;

// objects.pdf holds what the generated fixtures lack (colours, marked content, a structure tree,
// images, a form, links, a rotated page); every attribute equals pdfplumber's.

test('metadata', function (): void {
    expect(plain(Pdfplumber::open(pdfPath('objects.pdf'))->metadata()))->toEqual(expected('objects.pdf')['metadata']);
});

test('hyperlinks', function (): void {
    expect(plain(Pdfplumber::open(pdfPath('objects.pdf'))->hyperlinks()))->toEqual(expected('objects.pdf')['hyperlinks']);
});

test('structure tree', function (): void {
    expect(Pdfplumber::open(pdfPath('objects.pdf'))->structureTree())->toEqual(expected('objects.pdf')['structure']);
});

test('structure tree of a page', function (int $number): void {
    expect(fixturePage('objects.pdf', $number)->structureTree())
        ->toEqual(expected('objects.pdf')['page_structure'][$number - 1]);
})->with([1, 2]);

test('every object', function (int $number): void {
    expect(comparableObjects(fixturePage('objects.pdf', $number)))
        ->toEqual(expected('objects.pdf')['objects'][$number - 1]);
})->with([1, 2]);

test('every object with layout analysis', function (int $number): void {
    expect(comparableObjects(fixturePage('objects.pdf', $number, laparams: [])))
        ->toEqual(expected('objects.pdf')['layout_objects'][$number - 1]);
})->with([1, 2]);
