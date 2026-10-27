<?php

declare(strict_types=1);

use Artygrand\Pdfplumber\Pdfplumber;

test('explicit lines make a table without rules', function (): void {
    $tables = Pdfplumber::open(pdfPath('tables.pdf'))->page(1)->extractTables([
        'verticalStrategy' => 'explicit',
        'horizontalStrategy' => 'explicit',
        'explicitVerticalLines' => [40, 140, 260, 400],
        'explicitHorizontalLines' => [301.89, 341.89, 381.89, 421.89],
    ]);

    expect($tables)->toBe([[
        ['R0C0 値', 'R0C1 値', 'R0C2 値'],
        ['R1C0 値', 'R1C1 値', 'R1C2 値'],
        ['R2C0 値', 'R2C1 値', 'R2C2 値'],
    ]]);
});

test('invalid table settings are refused', function (array $settings): void {
    Pdfplumber::open(pdfPath('tables.pdf'))->page(1)->extractTables($settings);
})->throws(InvalidArgumentException::class)->with([
    'one explicit line' => [['verticalStrategy' => 'explicit', 'explicitVerticalLines' => [1]]],
    'negative tolerance' => [['snapTolerance' => -1]],
    'unknown strategy' => [['verticalStrategy' => 'grid']],
    'misspelt setting' => [['snapTolerence' => 1]],
]);
