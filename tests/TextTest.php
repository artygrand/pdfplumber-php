<?php

declare(strict_types=1);

use Artygrand\Pdfplumber\Pdfplumber;

test('search returns matches with their box', function (): void {
    $page = Pdfplumber::open(pdfPath('jp-cid.pdf'))->page(1);

    $matches = $page->search('請求番号\s+(\S+)');

    expect($matches)->toHaveCount(1)
        ->and($matches[0]['text'])->toBe('請求番号 INV-2026-0042')
        ->and($matches[0]['groups'])->toBe(['INV-2026-0042'])
        ->and($matches[0]['x1'])->toBeGreaterThan($matches[0]['x0'])
        ->and($page->search('/請求番号\s+(\S+)/u'))->toBe($matches)
        ->and($page->search('inv-2026', regex: false, case: false))->toHaveCount(1);
});

test('a crop outside the page is refused unless not strict', function (): void {
    $page = Pdfplumber::open(pdfPath('latin.pdf'))->page(1);

    expect($page->crop([-50, -50, 10, 10], strict: false)->extractText())->toBe('');
    $page->crop([-50, -50, 10, 10]);
})->throws(InvalidArgumentException::class);

test('a crop of a crop is relative on request', function (): void {
    $page = Pdfplumber::open(pdfPath('latin.pdf'))->page(1);
    $band = $page->crop([0, 40, $page->width, 160]);

    expect($band->crop([40, 0, 400, 80], relative: true)->extractText())
        ->toBe($page->crop([40, 40, 400, 120])->extractText());
});

test('document text joins pages with form feeds', function (): void {
    $text = Pdfplumber::open(pdfPath('cn-cid.pdf'))->extractText();

    expect(explode("\f", $text))->toHaveCount(3)
        ->and($text)->toContain('机壳 LSV');
});
