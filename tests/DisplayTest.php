<?php

declare(strict_types=1);

use Artygrand\Pdfplumber\Display\PageImage;
use Artygrand\Pdfplumber\Pdfplumber;

function blankPageImage(string $fixture): PageImage
{
    $page = Pdfplumber::open(pdfPath($fixture))->page(1);
    $gd = imagecreatetruecolor((int) ceil($page->width), (int) ceil($page->height));
    imagefill($gd, 0, 0, (int) imagecolorallocate($gd, 255, 255, 255));
    ob_start();
    imagepng($gd);

    return new PageImage($page, (string) ob_get_clean());
}

test('outlineWords passes other options to extractWords', function (): void {
    $plain = blankPageImage('latin.pdf')->outlineWords()->toPng();
    $blank = blankPageImage('latin.pdf')->outlineWords(keepBlankChars: true)->toPng();

    expect($blank)->not->toBe($plain);
    blankPageImage('latin.pdf')->outlineWords(noSuchOption: true);
})->throws(InvalidArgumentException::class, 'Unknown text option');
