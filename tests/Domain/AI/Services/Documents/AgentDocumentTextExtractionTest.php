<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Documents;

use DDD\Domain\AI\Entities\Documents\AgentDocumentText;
use DDD\Domain\AI\Entities\Drives\AgentDriveFileContent;
use DDD\Tests\Agents\Support\UnitTestCase;
use Symfony\Component\Process\ExecutableFinder;

/**
 * The text of every supported kind, page by page, with the kind taken from the bytes; legacy Office, a plain ZIP and
 * binary bytes are refused with a reason; repeats of an OpenDocument sheet are capped; the text is cached by sha256;
 * PDF pages render as PNG.
 */
class AgentDocumentTextExtractionTest extends UnitTestCase
{
    public function testAPdfIsReadPageByPage(): void
    {
        $this->skipWithoutPoppler();
        $extractionService = new AgentDocumentTextExtractionServiceDouble();

        $agentDocumentText = $extractionService->extractDocumentText(AgentDriveFileContent::fromBytes(
            AgentDocumentFixtures::buildPdf('Offer for the summer festival with all positions listed', 'Second page about the delivery terms and the prices'),
            'Offer.bin'
        ));

        self::assertNull($agentDocumentText->failureReason);
        self::assertSame(AgentDocumentText::KIND_PDF, $agentDocumentText->documentKind);
        self::assertSame(2, $agentDocumentText->countExtractedPages());
        self::assertSame(2, $agentDocumentText->totalPageCount);
        self::assertStringContainsString('summer festival', $agentDocumentText->pageTexts[0]);
        self::assertStringContainsString('delivery terms', $agentDocumentText->pageTexts[1]);
        self::assertFalse($agentDocumentText->readByOcr);
    }

    public function testThePageCapKeepsTheFirstPagesAndCountsAll(): void
    {
        $this->skipWithoutPoppler();
        $extractionService = new AgentDocumentTextExtractionServiceDouble();
        $extractionService->documentsConfigNode = ['maxExtractedPages' => 1];

        $agentDocumentText = $extractionService->extractDocumentText(AgentDriveFileContent::fromBytes(
            AgentDocumentFixtures::buildPdf('First page with enough words to count as a text layer', 'Second page with enough words to count as a text layer'),
            'Two.pdf'
        ));

        self::assertSame(1, $agentDocumentText->countExtractedPages());
        self::assertSame(2, $agentDocumentText->totalPageCount);
    }

    public function testAPdfPageRendersAsPng(): void
    {
        $this->skipWithoutPoppler();
        $extractionService = new AgentDocumentTextExtractionServiceDouble();
        $pdfBytes = AgentDocumentFixtures::buildPdf('One page');

        $pageImageBytes = $extractionService->renderPdfPageImage($pdfBytes, 1, 72);

        self::assertNotNull($pageImageBytes);
        self::assertStringStartsWith("\x89PNG", $pageImageBytes);
        self::assertNull($extractionService->renderPdfPageImage($pdfBytes, 5, 72), 'a page past the end renders nothing');
    }

    public function testAWordDocumentIsReadAsFlowPagesAndMacrosAreNeverRead(): void
    {
        $extractionService = new AgentDocumentTextExtractionServiceDouble();
        $paragraphs = [];
        for ($paragraphNumber = 1; $paragraphNumber <= 40; $paragraphNumber++) {
            $paragraphs[] = "Paragraph {$paragraphNumber}: " . str_repeat('lorem ipsum ', 12);
        }

        $agentDocumentText = $extractionService->extractDocumentText(AgentDriveFileContent::fromBytes(AgentDocumentFixtures::buildDocx($paragraphs), 'Contract.docx'));

        self::assertSame(AgentDocumentText::KIND_WORD, $agentDocumentText->documentKind);
        self::assertGreaterThan(1, $agentDocumentText->countExtractedPages(), 'about 6000 characters make more than one flow page');
        $wholeText = implode("\n", $agentDocumentText->pageTexts);
        self::assertStringContainsString('Paragraph 1:', $agentDocumentText->pageTexts[0]);
        self::assertStringContainsString('Paragraph 40:', $wholeText);
        self::assertStringContainsString("Tab\tafter", $wholeText);
        self::assertStringNotContainsString('MACRO', $wholeText);
        foreach ($agentDocumentText->pageTexts as $pageText) {
            self::assertLessThanOrEqual(AgentDocumentText::FLOW_PAGE_CHARACTERS + 200, mb_strlen($pageText));
        }
    }

    public function testAWorkbookIsReadSheetBySheetWithRowNumbersAndColumns(): void
    {
        $extractionService = new AgentDocumentTextExtractionServiceDouble();

        $agentDocumentText = $extractionService->extractDocumentText(AgentDriveFileContent::fromBytes(AgentDocumentFixtures::buildXlsx(), 'Plan.xlsx'));

        self::assertSame(AgentDocumentText::KIND_SPREADSHEET, $agentDocumentText->documentKind);
        self::assertSame('sheet', $agentDocumentText->pageWord);
        self::assertSame(['Plan', 'Costs'], $agentDocumentText->pageNames);
        self::assertSame("1\tTask\tOwner\n2\tLaunch\t\t42", $agentDocumentText->pageTexts[0]);
        self::assertSame("1\tRent\t1200", $agentDocumentText->pageTexts[1]);
    }

    public function testAPresentationIsReadInSlideOrder(): void
    {
        $extractionService = new AgentDocumentTextExtractionServiceDouble();

        $agentDocumentText = $extractionService->extractDocumentText(AgentDriveFileContent::fromBytes(AgentDocumentFixtures::buildPptx(['Opening', 'Numbers', 'Closing']), 'Deck.pptx'));

        self::assertSame(AgentDocumentText::KIND_PRESENTATION, $agentDocumentText->documentKind);
        self::assertSame('slide', $agentDocumentText->pageWord);
        self::assertSame(['Opening', 'Numbers', 'Closing'], $agentDocumentText->pageTexts);
    }

    public function testOpenDocumentTextAndSheetsAreRead(): void
    {
        $extractionService = new AgentDocumentTextExtractionServiceDouble();

        $textDocument = $extractionService->extractDocumentText(AgentDriveFileContent::fromBytes(AgentDocumentFixtures::buildOdt(['First line', 'Second line']), 'Notes.odt'));
        $sheetDocument = $extractionService->extractDocumentText(AgentDriveFileContent::fromBytes(AgentDocumentFixtures::buildOds(), 'Stock.ods'));

        self::assertSame(AgentDocumentText::KIND_WORD, $textDocument->documentKind);
        self::assertSame("Heading\nFirst line\nSecond line", $textDocument->pageTexts[0]);
        self::assertSame(AgentDocumentText::KIND_SPREADSHEET, $sheetDocument->documentKind);
        self::assertSame(['Stock'], $sheetDocument->pageNames);
        $rowLines = explode("\n", $sheetDocument->pageTexts[0]);
        self::assertSame("1\tItem\tx\tx\tx", $rowLines[0]);
        self::assertCount(1 + 100, $rowLines, 'a repeated row is written at most 100 times; empty repeated rows not at all');
    }

    public function testPlainTextIsReadAndBinaryBytesAreRefused(): void
    {
        $extractionService = new AgentDocumentTextExtractionServiceDouble();

        $textDocument = $extractionService->extractDocumentText(AgentDriveFileContent::fromBytes("\xEF\xBB\xBFname;city\r\nAnna;Köln\r\n", 'people.csv'));
        $binaryDocument = $extractionService->extractDocumentText(AgentDriveFileContent::fromBytes("\x00\x01\x02binary", 'blob.txt'));
        $unknownTextDocument = $extractionService->extractDocumentText(AgentDriveFileContent::fromBytes('plain words', 'program.exe'));

        self::assertSame(AgentDocumentText::KIND_TEXT, $textDocument->documentKind);
        self::assertSame("name;city\nAnna;Köln", $textDocument->pageTexts[0]);
        self::assertSame(AgentDocumentText::KIND_UNSUPPORTED, $binaryDocument->documentKind);
        self::assertNotNull($binaryDocument->failureReason);
        self::assertSame(AgentDocumentText::KIND_UNSUPPORTED, $unknownTextDocument->documentKind, 'the extension or the type must say text');
    }

    public function testLegacyOfficeAndAPlainZipAreRefusedWithAReason(): void
    {
        $extractionService = new AgentDocumentTextExtractionServiceDouble();

        $legacyDocument = $extractionService->extractDocumentText(AgentDriveFileContent::fromBytes(AgentDocumentFixtures::buildLegacyOfficeBytes(), 'Old.doc'));
        $zipDocument = $extractionService->extractDocumentText(AgentDriveFileContent::fromBytes(AgentDocumentFixtures::buildPlainZip(), 'Archive.zip'));
        $damagedDocument = $extractionService->extractDocumentText(AgentDriveFileContent::fromBytes("PK\x03\x04damaged", 'Broken.docx'));

        self::assertStringContainsString('legacy Office', (string)$legacyDocument->failureReason);
        self::assertStringContainsString('ZIP archive', (string)$zipDocument->failureReason);
        self::assertStringContainsString('could not be read', (string)$damagedDocument->failureReason);
        self::assertFalse($damagedDocument->hasText());
    }

    public function testTheTextIsCachedBySha256(): void
    {
        $extractionService = new AgentDocumentTextExtractionServiceDouble();
        $extractionService->documentsConfigNode = ['cacheTtlSeconds' => 600];
        $agentDriveFileContent = AgentDriveFileContent::fromBytes(AgentDocumentFixtures::buildPptx(['Cached']), 'Deck.pptx');

        $firstRead = $extractionService->extractDocumentText($agentDriveFileContent);
        $cacheKeys = array_keys($extractionService->cacheGroup->valuesByKey);
        $cachedDocumentText = clone $extractionService->cacheGroup->valuesByKey[$cacheKeys[0]];
        $cachedDocumentText->pageTexts = ['From the cache'];
        $extractionService->cacheGroup->valuesByKey[$cacheKeys[0]] = $cachedDocumentText;
        $secondRead = $extractionService->extractDocumentText($agentDriveFileContent);

        self::assertSame(['Cached'], $firstRead->pageTexts);
        self::assertCount(1, $cacheKeys);
        self::assertStringContainsString($agentDriveFileContent->sha256, $cacheKeys[0]);
        self::assertSame(600, $extractionService->cacheGroup->ttlSecondsByKey[$cacheKeys[0]]);
        self::assertSame(['From the cache'], $secondRead->pageTexts);
    }

    public function testAFailingCacheOnlyCostsTheExtraction(): void
    {
        $extractionService = new AgentDocumentTextExtractionServiceDouble();
        $extractionService->cacheGroup->failsEveryCall = true;

        $agentDocumentText = $extractionService->extractDocumentText(AgentDriveFileContent::fromBytes(AgentDocumentFixtures::buildPptx(['Still read']), 'Deck.pptx'));

        self::assertSame(['Still read'], $agentDocumentText->pageTexts);
    }

    protected function skipWithoutPoppler(): void
    {
        if ((new ExecutableFinder())->find('pdftotext') === null || (new ExecutableFinder())->find('pdftoppm') === null) {
            self::markTestSkipped('poppler-utils (pdftotext, pdftoppm) are not installed');
        }
    }
}
