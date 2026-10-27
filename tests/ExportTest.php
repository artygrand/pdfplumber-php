<?php

declare(strict_types=1);

use Artygrand\Pdfplumber\Pdfplumber;

test('CSV has pdfplumber columns and rows', function (): void {
    [$header, $row] = explode("\r\n", Pdfplumber::open(pdfPath('objects.pdf'))->toCsv(['line'], 2));

    expect($header)->toBe(
        'object_type,page_number,x0,x1,y0,y1,doctop,top,bottom,width,height,dash,evenodd,fill,linewidth,'
        .'mcid,non_stroking_color,path,pts,stroke,stroking_color,tag',
    )
        ->and($row)->toStartWith('line,1,20.0,280.0,120.0,120.0,80.0,80.0,80.0,260.0,0.0,"[[3,2],1]",0,0,2.0,,')
        ->and($row)->toEndWith(',1,"[0.0,1.0,0.0]",Artifact');
});

test('JSON keeps only the asked attributes', function (): void {
    $json = Pdfplumber::open(pdfPath('objects.pdf'))->toJson(['rect'], ['x0', 'fill']);

    expect(json_decode($json, true)['pages'][0]['rects'])->toBe([
        ['x0' => 20.0, 'fill' => 1, 'object_type' => 'rect'],
        ['x0' => 200.0, 'fill' => 1, 'object_type' => 'rect'],
    ]);
});

test('the command line gives what toJson gives', function (): void {
    $path = pdfPath('objects.pdf');
    $command = [PHP_BINARY, __DIR__.'/../bin/pdfplumber', $path, '--format', 'json', '--types', 'rect', '--include-attrs', 'x0', 'fill'];

    expect(shell_exec(implode(' ', array_map('escapeshellarg', $command))))
        ->toBe(Pdfplumber::open($path)->toJson(['rect'], ['x0', 'fill']));
});
