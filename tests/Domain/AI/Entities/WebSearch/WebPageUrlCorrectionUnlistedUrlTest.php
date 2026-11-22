<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\WebSearch;

use DDD\Domain\AI\Entities\WebSearch\WebPageMarkdown;
use DDD\Domain\AI\Entities\WebSearch\WebPageUrlCorrection;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A page read that failed as if the page did not exist, for a URL no search listed, names the listed pages of the same
 * site; a listed URL, another failure, or a site without listed pages get no note.
 */
class WebPageUrlCorrectionUnlistedUrlTest extends UnitTestCase
{
    public function testAnUnlistedFailedUrlNamesTheListedPagesOfItsSite(): void
    {
        $webPageUrlCorrection = WebPageUrlCorrection::fromRequestedUrls(
            ['https://www.example.org/topics/grants/2026'],
            ['https://example.org/grants/2026', 'https://example.org/grants/faq', 'https://other.example/grants']
        );
        $failedPage = WebPageMarkdown::fromRefusedUrl('https://www.example.org/topics/grants/2026', WebPageMarkdown::REASON_FETCH_FAILED);

        $note = (string)$webPageUrlCorrection->buildUnlistedFailedUrlNote($failedPage);
        self::assertStringContainsString('was not in the search results', $note);
        self::assertStringContainsString('https://example.org/grants/2026, https://example.org/grants/faq', $note);
        self::assertStringNotContainsString('other.example', $note);
    }

    public function testNoNoteForAListedUrlAnotherFailureOrAnUnknownSite(): void
    {
        $webPageUrlCorrection = WebPageUrlCorrection::fromRequestedUrls([], ['https://example.org/grants/2026']);

        self::assertNull($webPageUrlCorrection->buildUnlistedFailedUrlNote(WebPageMarkdown::fromRefusedUrl('https://example.org/grants/2026/', WebPageMarkdown::REASON_FETCH_FAILED)));
        self::assertNull($webPageUrlCorrection->buildUnlistedFailedUrlNote(WebPageMarkdown::fromRefusedUrl('https://example.org/made-up', WebPageMarkdown::REASON_TIMEOUT)));
        self::assertNull($webPageUrlCorrection->buildUnlistedFailedUrlNote(WebPageMarkdown::fromRefusedUrl('https://unknown.example/a', WebPageMarkdown::REASON_FETCH_FAILED)));
    }
}
