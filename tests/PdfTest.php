<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber\Tests;

use Artygrand\Pdfplumber\Exception;
use Artygrand\Pdfplumber\Pdf;
use PHPUnit\Framework\TestCase;

final class PdfTest extends TestCase
{
    public function testReadsEncryptedFilesWithThePassword(): void
    {
        foreach (['encrypted-rc4.pdf', 'encrypted-aes256.pdf'] as $name) {
            $text = Pdf::open(__DIR__.'/fixtures/'.$name, 'secret')->page(1)->extractText();

            $this->assertStringContainsString('Invoice No. 2026/10-77', $text, $name);
        }
    }

    public function testRefusesAWrongPassword(): void
    {
        $this->expectException(Exception::class);

        Pdf::open(__DIR__.'/fixtures/encrypted-aes256.pdf', 'wrong');
    }

    public function testRefusesSomethingThatIsNotAPdf(): void
    {
        $this->expectException(Exception::class);

        Pdf::fromString('hello');
    }

    public function testSearchReturnsMatchesWithTheirBox(): void
    {
        $page = Pdf::open(__DIR__.'/fixtures/jp-cid.pdf')->page(1);

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
        $page = Pdf::open(__DIR__.'/fixtures/latin.pdf')->page(1);

        $this->assertSame('', $page->crop([-50, -50, 10, 10], strict: false)->extractText());
        $this->expectException(\InvalidArgumentException::class);
        $page->crop([-50, -50, 10, 10]);
    }

    public function testCropOfACropIsRelativeOnRequest(): void
    {
        $page = Pdf::open(__DIR__.'/fixtures/latin.pdf')->page(1);
        $band = $page->crop([0, 40, $page->width, 160]);

        $this->assertSame(
            $page->crop([40, 40, 400, 120])->extractText(),
            $band->crop([40, 0, 400, 80], relative: true)->extractText(),
        );
    }

    public function testDocumentTextJoinsPagesWithFormFeeds(): void
    {
        $pdf = Pdf::open(__DIR__.'/fixtures/cn-cid.pdf');

        $this->assertCount(3, explode("\f", $pdf->extractText()));
        $this->assertStringContainsString('机壳 LSV', $pdf->extractText());
    }

    public function testMetadataIsDecoded(): void
    {
        $metadata = Pdf::open(__DIR__.'/fixtures/latin.pdf')->metadata();

        $this->assertArrayHasKey('Producer', $metadata);
        $this->assertStringContainsString('ReportLab', $metadata['Producer']);
    }

    public function testExplicitLinesMakeATableWithoutRules(): void
    {
        $page = Pdf::open(__DIR__.'/fixtures/tables.pdf')->page(1);

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
        $page = Pdf::open(__DIR__.'/fixtures/tables.pdf')->page(1);

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
}
