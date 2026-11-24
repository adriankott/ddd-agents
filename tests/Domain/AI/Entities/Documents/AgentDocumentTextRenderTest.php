<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Documents;

use DDD\Domain\AI\Entities\Documents\AgentDocumentText;
use PHPUnit\Framework\TestCase;

/** One read of a document's text: a header per page, stopping before the limit with the next page named. */
class AgentDocumentTextRenderTest extends TestCase
{
    public function testPagesStopBeforeTheLimitAndNameTheNextPage(): void
    {
        $agentDocumentText = static::buildDocumentText([str_repeat('a', 40), str_repeat('b', 40), str_repeat('c', 40)]);

        $agentDocumentPagesRead = $agentDocumentText->renderPagesForModel(1, null, 120);

        self::assertSame(1, $agentDocumentPagesRead->fromPage);
        self::assertSame(2, $agentDocumentPagesRead->toPage);
        self::assertSame(3, $agentDocumentPagesRead->nextPage);
        self::assertStringStartsWith("--- page 1 ---\n" . str_repeat('a', 40), $agentDocumentPagesRead->text);
        self::assertStringNotContainsString('ccc', $agentDocumentPagesRead->text);
    }

    public function testTheFirstPageIsAlwaysGivenAndCutAtTheLimit(): void
    {
        $agentDocumentText = static::buildDocumentText([str_repeat('a', 500), 'second']);

        $agentDocumentPagesRead = $agentDocumentText->renderPagesForModel(1, null, 100);

        self::assertSame(1, $agentDocumentPagesRead->toPage);
        self::assertSame(2, $agentDocumentPagesRead->nextPage);
        self::assertStringContainsString('[… the rest of this page is cut]', $agentDocumentPagesRead->text);
    }

    public function testARangeEndsWithoutANextPageAndSheetsCarryTheirNames(): void
    {
        $agentDocumentText = static::buildDocumentText(['one', 'two', 'three']);
        $agentDocumentText->pageWord = 'sheet';
        $agentDocumentText->pageNames = ['Plan', 'Costs', 'Notes'];

        $agentDocumentPagesRead = $agentDocumentText->renderPagesForModel(2, 2, 10_000);

        self::assertSame("--- sheet 2 \"Costs\" ---\ntwo", $agentDocumentPagesRead->text);
        self::assertNull($agentDocumentPagesRead->nextPage);
        self::assertNull($agentDocumentText->renderPagesForModel(1, null, 10_000)->nextPage, 'the whole document fits');
    }

    public function testTheShortDocumentIdIsTheStartOfTheSha256(): void
    {
        $agentDocumentText = static::buildDocumentText(['x']);
        $agentDocumentText->sha256 = hash('sha256', 'x');

        self::assertSame(substr(hash('sha256', 'x'), 0, AgentDocumentText::SHORT_DOCUMENT_ID_LENGTH), $agentDocumentText->getShortDocumentId());
    }

    /** @param string[] $pageTexts */
    protected static function buildDocumentText(array $pageTexts): AgentDocumentText
    {
        $agentDocumentText = new AgentDocumentText();
        $agentDocumentText->documentKind = AgentDocumentText::KIND_PDF;
        $agentDocumentText->pageTexts = $pageTexts;
        $agentDocumentText->totalPageCount = count($pageTexts);
        return $agentDocumentText;
    }
}
