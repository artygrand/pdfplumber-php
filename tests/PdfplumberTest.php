<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber\Tests;

use Artygrand\Pdfplumber\Exception;
use Artygrand\Pdfplumber\Pdfplumber;
use PHPUnit\Framework\TestCase;

final class PdfplumberTest extends TestCase
{
    public function testReadsEncryptedFilesWithThePassword(): void
    {
        foreach (['encrypted-rc4.pdf', 'encrypted-aes256.pdf'] as $name) {
            $text = Pdfplumber::open(__DIR__.'/fixtures/'.$name, 'secret')->page(1)->extractText();

            $this->assertStringContainsString('Invoice No. 2026/10-77', $text, $name);
        }
    }

    public function testRefusesAWrongPassword(): void
    {
        $this->expectException(Exception::class);

        Pdfplumber::open(__DIR__.'/fixtures/encrypted-aes256.pdf', 'wrong');
    }

    public function testRefusesSomethingThatIsNotAPdf(): void
    {
        $this->expectException(Exception::class);

        Pdfplumber::fromString('hello');
    }

    public function testSearchReturnsMatchesWithTheirBox(): void
    {
        $page = Pdfplumber::open(__DIR__.'/fixtures/jp-cid.pdf')->page(1);

        $matches = $page->search('請求番号\s+(\S+)');

        $this->assertCount(1, $matches);
        $this->assertSame('請求番号 INV-2026-0042', $matches[0]['text']);
        $this->assertSame(['INV-2026-0042'], $matches[0]['groups']);
        $this->assertGreaterThan($matches[0]['x0'], $matches[0]['x1']);
        $this->assertSame($matches, $page->search('/請求番号\s+(\S+)/u'));
        $this->assertCount(1, $page->search('inv-2026', regex: false, case: false));
    }

    public function testCropRejectsBoxesOutsideThePageUnlessNotStrict(): void
    {
        $page = Pdfplumber::open(__DIR__.'/fixtures/latin.pdf')->page(1);

        $this->assertSame('', $page->crop([-50, -50, 10, 10], strict: false)->extractText());
        $this->expectException(\InvalidArgumentException::class);
        $page->crop([-50, -50, 10, 10]);
    }

    public function testCropOfACropIsRelativeOnRequest(): void
    {
        $page = Pdfplumber::open(__DIR__.'/fixtures/latin.pdf')->page(1);
        $band = $page->crop([0, 40, $page->width, 160]);

        $this->assertSame(
            $page->crop([40, 40, 400, 120])->extractText(),
            $band->crop([40, 0, 400, 80], relative: true)->extractText(),
        );
    }

    public function testDocumentTextJoinsPagesWithFormFeeds(): void
    {
        $pdf = Pdfplumber::open(__DIR__.'/fixtures/cn-cid.pdf');

        $this->assertCount(3, explode("\f", $pdf->extractText()));
        $this->assertStringContainsString('机壳 LSV', $pdf->extractText());
    }

    public function testMetadataIsDecoded(): void
    {
        $metadata = Pdfplumber::open(__DIR__.'/fixtures/latin.pdf')->metadata();

        $this->assertArrayHasKey('Producer', $metadata);
        $this->assertStringContainsString('ReportLab', $metadata['Producer']);
    }

    public function testExplicitLinesMakeATableWithoutRules(): void
    {
        $page = Pdfplumber::open(__DIR__.'/fixtures/tables.pdf')->page(1);

        $this->assertSame(
            [[['R0C0 値', 'R0C1 値', 'R0C2 値'], ['R1C0 値', 'R1C1 値', 'R1C2 値'], ['R2C0 値', 'R2C1 値', 'R2C2 値']]],
            $page->extractTables([
                'verticalStrategy' => 'explicit',
                'horizontalStrategy' => 'explicit',
                'explicitVerticalLines' => [40, 140, 260, 400],
                'explicitHorizontalLines' => [301.89, 341.89, 381.89, 421.89],
            ]),
        );
    }

    public function testInvalidTableSettingsAreRefused(): void
    {
        $page = Pdfplumber::open(__DIR__.'/fixtures/tables.pdf')->page(1);

        foreach ([
            ['verticalStrategy' => 'explicit', 'explicitVerticalLines' => [1]],
            ['snapTolerance' => -1],
            ['verticalStrategy' => 'grid'],
            ['snapTolerence' => 1],
        ] as $settings) {
            try {
                $page->extractTables($settings);
                $this->fail('Accepted '.json_encode($settings));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testASubsetOfPagesCountsDoctopOverItsOwnPages(): void
    {
        $pdf = Pdfplumber::open(__DIR__.'/fixtures/objects.pdf', pages: [2]);

        $this->assertCount(1, $pdf->pages());
        $this->assertSame(0.0, (float) $pdf->page(2)->initialDoctop);
        $this->assertSame(20.0, $pdf->page(2)->chars()[0]->doctop);
    }

    public function testCsvHasPdfplumbersColumnsAndRows(): void
    {
        $csv = Pdfplumber::open(__DIR__.'/fixtures/objects.pdf')->toCsv(['line'], 2);

        [$header, $row] = explode("\r\n", $csv);
        $this->assertSame(
            'object_type,page_number,x0,x1,y0,y1,doctop,top,bottom,width,height,dash,evenodd,fill,linewidth,'
            .'mcid,non_stroking_color,path,pts,stroke,stroking_color,tag',
            $header,
        );
        $this->assertStringStartsWith('line,1,20.0,280.0,120.0,120.0,80.0,80.0,80.0,260.0,0.0,"[[3,2],1]",0,0,2.0,,', $row);
        $this->assertStringEndsWith(',1,"[0.0,1.0,0.0]",Artifact', $row);
    }

    public function testJsonAndTheCommandLineKeepOnlyTheAskedAttributes(): void
    {
        $path = __DIR__.'/fixtures/objects.pdf';
        $json = Pdfplumber::open($path)->toJson(['rect'], ['x0', 'fill']);

        $this->assertSame(
            [['x0' => 20.0, 'fill' => 1, 'object_type' => 'rect'], ['x0' => 200.0, 'fill' => 1, 'object_type' => 'rect']],
            json_decode($json, true)['pages'][0]['rects'],
        );
        $command = [PHP_BINARY, __DIR__.'/../bin/pdfplumber', $path, '--format', 'json', '--types', 'rect', '--include-attrs', 'x0', 'fill'];
        $this->assertSame($json, shell_exec(implode(' ', array_map('escapeshellarg', $command))));
    }
}
