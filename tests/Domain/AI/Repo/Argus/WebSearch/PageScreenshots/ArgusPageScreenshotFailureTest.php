<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Repo\Argus\WebSearch\PageScreenshots;

use DDD\Domain\AI\Entities\WebSearch\PageScreenshots\PageScreenshotPart;
use DDD\Domain\AI\Repo\Argus\WebSearch\PageScreenshots\ArgusPageScreenshot;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperation;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Tests\Agents\Support\WebSearchTestConfiguration;

/**
 * A failed render keeps the reason the endpoint names (at the top, under data or error; a rejected call: TIMEOUT or
 * FETCH_FAILED), the model reads one sentence with the next move per reason, and a screenshot is cached in memory only
 * unless the application adds the database.
 */
class ArgusPageScreenshotFailureTest extends UnitTestCase
{
    public function testTheReasonIsReadWhereverTheAnswerPutsIt(): void
    {
        self::assertSame('BOT_CHALLENGE', ArgusPageScreenshot::findFailureReasonOfAnswer(json_decode('{"status":"error","reason":"BOT_CHALLENGE"}')));
        self::assertSame('TIMEOUT', ArgusPageScreenshot::findFailureReasonOfAnswer(json_decode('{"status":"error","data":{"reason":"timeout"}}')));
        self::assertSame('BLOCKED', ArgusPageScreenshot::findFailureReasonOfAnswer(json_decode('{"error":{"reason":"BLOCKED"}}')));
        self::assertSame('UNRESOLVABLE', ArgusPageScreenshot::findFailureReasonOfAnswer(json_decode('{"error":"UNRESOLVABLE"}')));
        self::assertNull(ArgusPageScreenshot::findFailureReasonOfAnswer(json_decode('{"error":"the page could not be loaded"}')), 'a sentence is no code');
        self::assertNull(ArgusPageScreenshot::findFailureReasonOfAnswer(null));

        $apiOperation = self::instantiateWithoutConstructor(ArgusApiOperation::class);
        $apiOperation->rejectionReason = 'Operation timed out after 30001 ms';
        self::assertSame(PageScreenshotPart::REASON_TIMEOUT, ArgusPageScreenshot::findFailureReasonOfAnswer(null, $apiOperation));
        $apiOperation->rejectionReason = 'connection refused';
        self::assertSame(PageScreenshotPart::REASON_FETCH_FAILED, ArgusPageScreenshot::findFailureReasonOfAnswer(null, $apiOperation));
    }

    public function testAFailedAnswerKeepsItsReasonAndNoImage(): void
    {
        $argusPageScreenshot = new ArgusPageScreenshot();
        $argusPageScreenshot->url = 'https://example.com';
        $callResponseData = json_decode('{"status":"error","reason":"BOT_CHALLENGE"}');
        $argusPageScreenshot->handleLoadResponse($callResponseData);

        self::assertNull($argusPageScreenshot->content);
        self::assertSame('BOT_CHALLENGE', $argusPageScreenshot->failureReason);
        self::assertFalse($argusPageScreenshot->getArgusSettings()->isLoadedSuccessfully);
    }

    public function testEveryReasonHasItsSentenceAndAnUnknownOneIsNamed(): void
    {
        $pageScreenshotPart = new PageScreenshotPart();
        $pageScreenshotPart->url = 'https://example.com';
        $pageScreenshotPart->failureReason = PageScreenshotPart::REASON_BOT_CHALLENGE;
        self::assertStringContainsString('getWebPageAsMarkdown', $pageScreenshotPart->buildFailureText());
        self::assertStringContainsString('protects itself against bots', $pageScreenshotPart->buildFailureText());

        $pageScreenshotPart->failureReason = PageScreenshotPart::REASON_TIMEOUT;
        self::assertStringContainsString('Retry ONCE', $pageScreenshotPart->buildFailureText());

        foreach ([PageScreenshotPart::REASON_BLOCKED, PageScreenshotPart::REASON_UNRESOLVABLE, PageScreenshotPart::REASON_NOT_PUBLIC] as $doNotRetryReason) {
            $pageScreenshotPart->failureReason = $doNotRetryReason;
            self::assertStringContainsString('Do not retry it', $pageScreenshotPart->buildFailureText(), $doNotRetryReason);
        }

        $pageScreenshotPart->failureReason = 'QUOTA_EXCEEDED';
        self::assertStringContainsString('(QUOTA_EXCEEDED)', $pageScreenshotPart->buildFailureText());
        $pageScreenshotPart->failureReason = null;
        self::assertStringContainsString('Could not capture a screenshot of https://example.com —', $pageScreenshotPart->buildFailureText());
    }

    public function testAScreenshotIsCachedInMemoryOnlyUnlessTheApplicationAddsTheDatabase(): void
    {
        $configValuesBefore = WebSearchTestConfiguration::declareWebSearchConfiguration(WebSearchTestConfiguration::buildEnabledConfiguration());
        try {
            $argusPageScreenshot = new ArgusPageScreenshot();
            self::assertTrue($argusPageScreenshot->applyConfiguredLoadSettings());
            self::assertSame(ArgusCache::CACHELEVEL_MEMORY, $argusPageScreenshot->getArgusSettings()->getCacheLevel());
        } finally {
            WebSearchTestConfiguration::restoreConfigValues($configValuesBefore);
        }

        $webSearchConfiguration = WebSearchTestConfiguration::buildEnabledConfiguration();
        $webSearchConfiguration['cacheInDatabase'] = ['screenshot' => true];
        $configValuesBefore = WebSearchTestConfiguration::declareWebSearchConfiguration($webSearchConfiguration);
        try {
            $argusPageScreenshot = new ArgusPageScreenshot();
            self::assertTrue($argusPageScreenshot->applyConfiguredLoadSettings());
            self::assertSame(ArgusCache::CACHELEVEL_MEMORY_AND_DB, $argusPageScreenshot->getArgusSettings()->getCacheLevel());
        } finally {
            WebSearchTestConfiguration::restoreConfigValues($configValuesBefore);
        }
    }
}
