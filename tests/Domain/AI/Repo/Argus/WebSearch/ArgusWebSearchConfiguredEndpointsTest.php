<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Repo\Argus\WebSearch;

use DDD\Domain\AI\Repo\Argus\WebSearch\ArgusWebPageMarkdown;
use DDD\Domain\AI\Repo\Argus\WebSearch\ArgusWebSearchHitList;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Tests\Agents\Support\WebSearchTestConfiguration;

/**
 * The repositories take their endpoint, cache lifetime and timeout from the application's configuration, per
 * instance; without an endpoint nothing is set. The cache key of a page read is the canonical page.
 */
class ArgusWebSearchConfiguredEndpointsTest extends UnitTestCase
{
    public function testTheEndpointLifetimeAndTimeoutComeFromTheConfiguration(): void
    {
        $webSearchConfiguration = WebSearchTestConfiguration::buildEnabledConfiguration();
        $webSearchConfiguration['cacheTtlSeconds'] = ['search' => 120];
        $webSearchConfiguration['timeoutSeconds'] = ['search' => 7];
        $configValuesBefore = WebSearchTestConfiguration::declareWebSearchConfiguration($webSearchConfiguration);
        try {
            $argusWebSearchHitList = new ArgusWebSearchHitList();
            self::assertTrue($argusWebSearchHitList->applyConfiguredLoadSettings());
            self::assertSame('POST:/web/search', $argusWebSearchHitList->getArgusSettings()->getLoadEndpoint(), 'no method = POST');
            self::assertSame(120, $argusWebSearchHitList->getArgusSettings()->argusLoad->cacheTtl);
            self::assertSame(7.0, $argusWebSearchHitList->getArgusSettings()->timeout);
            self::assertNull((new ArgusWebSearchHitList())->getArgusSettings()->getLoadEndpoint(), 'set on the instance, never on the class');

            self::assertSame('POST:/web/urlToMarkdown', ArgusWebPageMarkdown::findConfiguredLoadEndpoint());
        } finally {
            WebSearchTestConfiguration::restoreConfigValues($configValuesBefore);
        }
    }

    public function testWithoutAnEndpointNothingIsSet(): void
    {
        $webSearchConfiguration = WebSearchTestConfiguration::buildEnabledConfiguration();
        $webSearchConfiguration['endpoints']['urlToMarkdown'] = null;
        $configValuesBefore = WebSearchTestConfiguration::declareWebSearchConfiguration($webSearchConfiguration);
        try {
            self::assertFalse((new ArgusWebPageMarkdown())->applyConfiguredLoadSettings());
        } finally {
            WebSearchTestConfiguration::restoreConfigValues($configValuesBefore);
        }
    }

    public function testTheCacheKeys(): void
    {
        $firstSpelling = new ArgusWebPageMarkdown();
        $firstSpelling->url = 'https://www.example.com/shop/';
        $secondSpelling = new ArgusWebPageMarkdown();
        $secondSpelling->url = 'example.com/shop';
        $otherPage = new ArgusWebPageMarkdown();
        $otherPage->url = 'https://example.com/contact';
        self::assertSame($firstSpelling->uniqueKey(), $secondSpelling->uniqueKey());
        self::assertNotSame($firstSpelling->uniqueKey(), $otherPage->uniqueKey());
    }
}
