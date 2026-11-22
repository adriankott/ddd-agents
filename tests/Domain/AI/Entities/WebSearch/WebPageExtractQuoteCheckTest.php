<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\WebSearch;

use DDD\Domain\AI\Entities\WebSearch\WebPageExtract;
use DDD\Domain\AI\Entities\WebSearch\WebPageExtractFact;
use DDD\Domain\AI\Entities\WebSearch\WebPageExtracts;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The reading of a page: the extractor's answer onto the extract, the quote check against the page and the excerpts
 * (an unverified quote is marked, never dropped), and the order in which the readings are shown.
 */
class WebPageExtractQuoteCheckTest extends UnitTestCase
{
    protected const string PAGE_TEXT = "## Plans\n\n| Plan | Price |\n|---|---|\n| Basic | **ab 9,90 €** per month |\n| Pro | 1.500 € per year |\n\nThe Pro plan includes 2 TB of storage and priority support.";

    public function testAQuoteCountsWhenItsPartsStandInOrderAndCarryEveryNumberOfTheValue(): void
    {
        self::assertTrue(WebPageExtractFact::quoteOccursInOrderIn('The Pro plan includes ... priority support', self::PAGE_TEXT), 'an inserted ellipsis splits the quote');
        self::assertFalse(WebPageExtractFact::quoteOccursInOrderIn('priority support ... The Pro plan includes', self::PAGE_TEXT), 'the parts must stand in order');
        self::assertTrue(WebPageExtractFact::quoteOccursInOrderIn('Basic ab 9,90 € per month', self::PAGE_TEXT), 'table and emphasis markup are folded away');
        self::assertTrue(WebPageExtractFact::valueNumbersOccurInQuote('1500 €', 'Pro 1.500 € per year'), 'separators are ignored');
        self::assertFalse(WebPageExtractFact::valueNumbersOccurInQuote('999 €', 'Pro 1.500 € per year'));
        self::assertFalse(WebPageExtractFact::valueNumbersOccurInQuote('20 TB', 'includes 2 TB'), 'a number is matched whole');
    }

    public function testAFactWhoseQuoteIsNotFoundIsKeptAndMarked(): void
    {
        $webPageExtract = WebPageExtract::fromUnreadablePage('https://example.com/pricing', 'Pricing', 'unused');
        $webPageExtract->applyExtractorAnswer(
            '```json' . "\n" . json_encode([
                'status' => 'FOUND',
                'sourceKind' => 'official',
                'asOf' => '2026-11-01',
                'facts' => [
                    ['what' => 'Pro price', 'value' => '1.500 € per year', 'appliesTo' => 'Pro plan', 'basis' => 'manufacturer', 'quote' => 'Pro | 1.500 € per year'],
                    ['what' => 'Basic price', 'value' => '7 €', 'quote' => 'Basic costs 7 €'],
                    ['what' => 'empty', 'value' => ''],
                ],
                'notAnswered' => [' ', 'Enterprise price'],
            ]) . "\n```",
            self::PAGE_TEXT,
            []
        );

        self::assertSame(WebPageExtract::STATUS_FOUND, $webPageExtract->status);
        self::assertSame('2026-11-01', $webPageExtract->asOf);
        self::assertSame(['Enterprise price'], $webPageExtract->unansweredParts);
        self::assertSame(2, $webPageExtract->facts->count(), 'a fact without a value is no fact; an unverified one stays');
        $facts = array_values($webPageExtract->facts->getElements());
        self::assertTrue($facts[0]->quoteVerified);
        self::assertFalse($facts[1]->quoteVerified);
        self::assertStringContainsString('[quote not found in the source text]', $facts[1]->buildLlmMarkdownLine());
        self::assertStringNotContainsString('[quote not found', $facts[0]->buildLlmMarkdownLine());
    }

    public function testTheStatusFollowsTheEvidenceNotTheModelsClaim(): void
    {
        $answerWithoutFacts = json_encode(['status' => 'FOUND', 'facts' => []]);
        $readPage = WebPageExtract::fromUnreadablePage('https://example.com/a', null, 'unused');
        $readPage->applyExtractorAnswer($answerWithoutFacts, self::PAGE_TEXT, []);
        self::assertSame(WebPageExtract::STATUS_NOT_ON_PAGE, $readPage->status);
        self::assertTrue($readPage->isCacheable());

        $excerptOnly = WebPageExtract::fromUnreadablePage('https://example.com/b', null, 'the page could not be read (timeout)');
        $excerptOnly->applyExtractorAnswer(json_encode(['status' => 'PARTIAL', 'facts' => [['what' => 'storage', 'value' => '2 TB', 'quote' => 'includes 2 TB']]]), null, ['The Pro plan includes 2 TB of storage']);
        self::assertSame(WebPageExtract::STATUS_SNIPPET_ONLY, $excerptOnly->status);
        self::assertSame(WebPageExtractFact::EVIDENCE_SNIPPET, array_values($excerptOnly->facts->getElements())[0]->evidenceKind);
        self::assertTrue(array_values($excerptOnly->facts->getElements())[0]->quoteVerified, 'verified against the excerpt');
        self::assertFalse($excerptOnly->isCacheable(), 'a reading without the page is read again next time');

        $noJson = WebPageExtract::fromUnreadablePage('https://example.com/c', null, 'unused');
        $noJson->applyExtractorAnswer('I cannot help with that.', self::PAGE_TEXT, []);
        self::assertSame(WebPageExtract::STATUS_EXTRACTION_FAILED, $noJson->status);

        self::assertNull(WebPageExtract::buildIsoDayOrNull('09/11/26'), 'an ambiguous date is never printed');
        self::assertNull(WebPageExtract::buildIsoDayOrNull('2026-02-30'));
    }

    public function testThePagesWithFactsComeFirstAndThePagesWithoutAreListedByName(): void
    {
        $webPageExtracts = new WebPageExtracts();
        $notOnPage = WebPageExtract::fromUnreadablePage('https://example.com/nothing', null, 'unused');
        $notOnPage->applyExtractorAnswer(json_encode(['status' => 'NOT_ON_PAGE', 'facts' => []]), self::PAGE_TEXT, []);
        $webPageExtracts->add($notOnPage);
        $blocked = WebPageExtract::fromUnreadablePage('https://example.com/blocked', null, 'the page could not be read (blocked)');
        $webPageExtracts->add($blocked);
        $found = WebPageExtract::fromUnreadablePage('https://example.com/pricing', 'Pricing', 'unused');
        $found->applyExtractorAnswer(json_encode(['status' => 'FOUND', 'facts' => [['what' => 'Pro price', 'value' => '1.500 €', 'quote' => '1.500 € per year']]]), self::PAGE_TEXT, []);
        $webPageExtracts->add($found);

        $markdown = $webPageExtracts->buildLlmMarkdown('the prices', 2400);

        self::assertSame(1, $webPageExtracts->countPagesWithFacts());
        self::assertStringContainsString('Reading 1: Pricing — https://example.com/pricing', $markdown);
        self::assertStringContainsString('Read, nothing on the task: https://example.com/nothing', $markdown);
        self::assertStringContainsString('Not readable: https://example.com/blocked (the page could not be read (blocked))', $markdown);
    }
}
