<?php
require __DIR__.'/../vendor/autoload.php';
$pdf = Artygrand\Pdfplumber\Pdfplumber::open($argv[1]);
$n = $argv[2] ?? 1;
echo $pdf->pageCount(), " pages\n";
echo $pdf->page((int) $n)->extractText(), "\n";
