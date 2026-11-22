<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\WebSearch;

use DDD\Domain\AI\Entities\WebSearch\PageScreenshots\PageScreenshotPart;
use DDD\Domain\AI\Entities\WebSearch\WebFetchFailureReasons;
use DDD\Domain\AI\Entities\WebSearch\WebPageMarkdown;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The reasons a page read and a screenshot share have ONE sentence each with the next move, used by both; an unknown
 * code keeps the generic sentence with the code named.
 */
class WebFetchFailureReasonsTest extends UnitTestCase
{
    public function testEverySharedReasonHasItsOwnSentenceInBothCapabilities(): void
    {
        $expectedFragmentsByReason = [
            WebFetchFailureReasons::UNSUPPORTED_TYPE => ['screenshot' => 'getWebPageAsMarkdown instead', 'page' => 'cannot be read as text'],
            WebFetchFailureReasons::TOO_LARGE => ['screenshot' => 'too large to render. Retrying is pointless', 'page' => 'too large to read. Retrying is pointless'],
            WebFetchFailureReasons::NO_CONTENT => ['screenshot' => 'Do not retry it', 'page' => 'Do not retry it'],
            WebFetchFailureReasons::TOO_MANY_REDIRECTS => ['screenshot' => 'redirects in a loop', 'page' => 'redirects in a loop'],
            WebFetchFailureReasons::UNRESOLVABLE => ['screenshot' => 'check the spelling', 'page' => 'check the spelling'],
        ];
        foreach ($expectedFragmentsByReason as $reason => $expectedFragments) {
            $pageScreenshotPart = new PageScreenshotPart();
            $pageScreenshotPart->url = 'https://example.com/a';
            $pageScreenshotPart->failureReason = $reason;
            $screenshotText = $pageScreenshotPart->buildFailureText();
            self::assertStringContainsString($expectedFragments['screenshot'], $screenshotText, $reason);
            self::assertStringNotContainsString('Could not capture a screenshot', $screenshotText, $reason);

            $pageText = WebPageMarkdown::fromRefusedUrl('https://example.com/a', $reason)->buildFailureExplanationForAgent();
            self::assertStringContainsString($expectedFragments['page'], $pageText, $reason);
            self::assertStringNotContainsString('could not be read right now', $pageText, $reason);
        }
    }

    public function testAnUnknownCodeKeepsTheGenericSentence(): void
    {
        $pageScreenshotPart = new PageScreenshotPart();
        $pageScreenshotPart->url = 'https://example.com/a';
        $pageScreenshotPart->failureReason = 'SOMETHING_NEW';
        self::assertStringContainsString('(SOMETHING_NEW)', $pageScreenshotPart->buildFailureText());
        self::assertNull(WebFetchFailureReasons::findSharedFailureSentence('SOMETHING_NEW', 'x', WebFetchFailureReasons::CAPABILITY_PAGE_READ));
    }

    public function testAnUnreachableAddressIsNeverSentToAnotherReader(): void
    {
        foreach ([WebPageMarkdown::REASON_UNRESOLVABLE, WebPageMarkdown::REASON_BLOCKED, 'NOT_PUBLIC'] as $reason) {
            $pageText = WebPageMarkdown::fromRefusedUrl('https://exmaple.invalid/a', $reason)->buildFailureExplanationForAgent();
            self::assertStringNotContainsString('is read by extractInformationFromWebPagesWithFastLLM with this URL', $pageText, $reason);
            self::assertStringContainsString('NEVER pass this URL to another tool', $pageText, $reason);
        }
        $timeoutText = WebPageMarkdown::fromRefusedUrl('https://example.com/a', WebPageMarkdown::REASON_TIMEOUT)->buildFailureExplanationForAgent();
        self::assertStringContainsString('extractInformationFromWebPagesWithFastLLM with this URL', $timeoutText);
    }
}
