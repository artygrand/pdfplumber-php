<?php

// Dump pdfplumber-php's metadata and every page's toDict() as JSON, mirroring objects_py.py.

require __DIR__.'/../vendor/autoload.php';

use Artygrand\Pdfplumber\Pdfplumber;
use Artygrand\Pdfplumber\Serializer;

$out = ['metadata' => null, 'pages' => [], 'error' => null];
$serializer = new Serializer();
try {
    $pdf = Pdfplumber::open($argv[1], $argv[2] ?? '', laparams: isset($argv[3]) ? json_decode($argv[3], true) : null);
    $out['metadata'] = (object) $serializer->serialize($pdf->metadata());
    foreach ($pdf->pages() as $page) {
        $data = $serializer->serialize($page->toDict());
        foreach ($data['annots'] ?? [] as $i => $annot) {
            unset($data['annots'][$i]['data']);
        }
        foreach ($data['figures'] ?? [] as $i => $figure) {
            if (str_starts_with($figure['name'], 'inline-')) {
                $data['figures'][$i]['name'] = 'inline';
            }
        }
        foreach ($data['images'] ?? [] as $i => $image) {
            unset($data['images'][$i]['stream']);
            if (str_starts_with($image['name'], 'inline-')) {
                $data['images'][$i]['name'] = 'inline';
            }
        }
        $out['pages'][] = $data;
        $page->close();
    }
} catch (Throwable $e) {
    $out['error'] = get_class($e).': '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine();
}
echo json_encode($out, JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
