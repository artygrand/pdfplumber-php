<?php

declare(strict_types=1);

use Artygrand\Pdfplumber\Page;
use Artygrand\Pdfplumber\Pdfplumber;
use Artygrand\Pdfplumber\Serializer;

function pdfPath(string $name): string
{
    return __DIR__.'/fixtures/'.$name;
}

/**
 * What pdfplumber gave for a fixture, recorded by tools/make_expected.py.
 *
 * @return array<string, mixed>
 */
function expected(string $file): array
{
    static $cache = [];

    return $cache[$file] ??= json_decode(
        (string) file_get_contents(__DIR__.'/fixtures/expected/'.basename($file, '.pdf').'.json'),
        true,
    );
}

/**
 * @return array<string, mixed>
 */
function expectedPage(string $file, int $number): array
{
    return expected($file)['pages'][$number - 1];
}

/**
 * A fixture's page, opened once for all the tests that read it.
 *
 * @param array<string, mixed>|null $laparams
 */
function fixturePage(string $file, int $number, ?array $laparams = null): Page
{
    static $documents = [];
    $key = $file.json_encode($laparams);
    $documents[$key] ??= Pdfplumber::open(pdfPath($file), expected($file)['password'], laparams: $laparams);

    return $documents[$key]->page($number);
}

/**
 * A value as pdfplumber's JSON gives it: objects serialized, then decoded to plain arrays.
 */
function plain(mixed $value): mixed
{
    return json_decode(
        json_encode((new Serializer())->serialize($value), JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
        true,
    );
}

/**
 * A page's toDict() without what the recording drops: annotation and image streams, and inline
 * image names (pdfminer's are memory addresses).
 *
 * @return array<string, mixed>
 */
function comparableObjects(Page $page): array
{
    $data = plain($page->toDict());
    foreach ($data['annots'] ?? [] as $i => $annot) {
        unset($data['annots'][$i]['data']);
    }
    foreach (['figures', 'images'] as $key) {
        foreach ($data[$key] ?? [] as $i => $item) {
            unset($data[$key][$i]['stream']);
            if (str_starts_with($item['name'], 'inline-')) {
                $data[$key][$i]['name'] = 'inline';
            }
        }
    }

    return $data;
}

dataset('pages', function (): iterable {
    foreach (glob(__DIR__.'/fixtures/expected/*.json') ?: [] as $path) {
        $file = basename($path, '.json').'.pdf';
        foreach (array_keys(expected($file)['pages']) as $i) {
            yield "{$file} p".($i + 1) => [$file, $i + 1];
        }
    }
});

dataset('regions', [
    'top-left quarter' => [0],
    'centre' => [1],
    'bottom band' => [2],
]);
