<?php
require __DIR__.'/../vendor/autoload.php';
$doc = new Artygrand\Pdfplumber\Parser\Document(file_get_contents($argv[1]));
foreach ($doc->pages() as [$id, $attrs]) {
    echo "page obj $id: ", implode(',', array_keys($attrs->entries)), "\n";
    var_dump($doc->resolve($attrs->get('Rotate')));
}
