<?php
$t0 = hrtime(true);
require __DIR__.'/../vendor/autoload.php';
$chars = 0;
$pdf = Artygrand\Pdfplumber\Pdf::open($argv[1], $argv[2] ?? '');
foreach ($pdf->pages() as $page) {
    $chars += mb_strlen($page->extractText());
    $page->close();
}
$t1 = hrtime(true);
echo json_encode(['work_ms' => ($t1 - $t0) / 1e6, 'text_len' => $chars, 'peak_mb' => memory_get_peak_usage(true) / 1048576]);
