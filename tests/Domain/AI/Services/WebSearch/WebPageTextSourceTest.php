<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\WebSearch;

use DDD\Domain\AI\Entities\WebSearch\WebPageMarkdown;
use DDD\Domain\AI\Services\WebSearch\AIWebSearchService;
use DDD\Tests\Agents\Domain\AI\Services\WebSearch\Fixtures\PublicHostsAIWebSearchService;
use DDD\Tests\Agents\Domain\AI\Services\WebSearch\Fixtures\ScriptedWebPageTextSource;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Tests\Agents\Support\WebSearchTestConfiguration;

/**
 * A registered page reader ({@see \DDD\Domain\AI\Services\WebSearch\WebPageTextSource}) reads the pages instead of the
 * url-to-markdown endpoint: the refusals before a read stay first, every URL gets its element in the order asked, and
 * a reader that is unavailable or throws leaves the read to the endpoint.
 */
class WebPageTextSourceTest extends UnitTestCase
{
    /** @var array<string, mixed> */
    protected array $configValuesBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        $webSearchConfiguration = WebSearchTestConfiguration::buildEnabledConfiguration();
        // no endpoint: only the reader can read
        unset($webSearchConfiguration['endpoints']['urlToMarkdown']);
        $this->configValuesBefore = WebSearchTestConfiguration::declareWebSearchConfiguration($webSearchConfiguration);
    }

    protected function tearDown(): void
    {
        WebSearchTestConfiguration::restoreConfigValues($this->configValuesBefore);
        parent::tearDown();
    }

    public function testTheReaderReadsThePagesAfterTheRefusalsInTheOrderAsked(): void
    {
        $scriptedWebPageTextSource = new ScriptedWebPageTextSource();
        $scriptedWebPageTextSource->unansweredUrls = ['https://example.test/c'];
        $aiWebSearchService = $this->buildServiceWithSource($scriptedWebPageTextSource);

        self::assertTrue($aiWebSearchService->isCapabilityAvailable(AIWebSearchService::CAPABILITY_URL_TO_MARKDOWN), 'the reader stands in for the endpoint');
        $webPageMarkdowns = $aiWebSearchService->getWebPagesAsMarkdown([
            'https://example.test/a',
            'https://www.google.com/search?q=bakery',
            'https://private.test/admin',
            'https://example.test/c',
        ]);

        self::assertSame(['https://example.test/a', 'https://example.test/c'], $scriptedWebPageTextSource->handedUrls);
        $elements = $webPageMarkdowns->getElements();
        self::assertCount(4, $elements);
        self::assertTrue($elements[0]->ok);
        self::assertSame('scripted', $elements[0]->fetchedVia);
        self::assertSame(WebPageMarkdown::REASON_SEARCH_RESULTS_PAGE, $elements[1]->reason);
        self::assertSame(WebPageMarkdown::REASON_BLOCKED, $elements[2]->reason);
        self::assertSame(WebPageMarkdown::REASON_FETCH_FAILED, $elements[3]->reason, 'a URL the reader did not answer');

        $singleWebPageMarkdown = $aiWebSearchService->getWebPageAsMarkdown(' https://example.test/a ');
        self::assertTrue($singleWebPageMarkdown->ok);
        self::assertSame('scripted', $singleWebPageMarkdown->fetchedVia);
    }

    public function testAnUnavailableOrFailingReaderLeavesTheReadToTheEndpoint(): void
    {
        $scriptedWebPageTextSource = new ScriptedWebPageTextSource();
        $scriptedWebPageTextSource->available = false;
        $aiWebSearchService = $this->buildServiceWithSource($scriptedWebPageTextSource);
        self::assertFalse($aiWebSearchService->isCapabilityAvailable(AIWebSearchService::CAPABILITY_URL_TO_MARKDOWN));
        self::assertSame(WebPageMarkdown::REASON_CAPABILITY_OFF, $aiWebSearchService->getWebPageAsMarkdown('https://example.test/a')->reason);
        self::assertSame([], $scriptedWebPageTextSource->handedUrls);

        $scriptedWebPageTextSource->available = true;
        $scriptedWebPageTextSource->failsWith = 'renderer down';
        // the endpoint path: no endpoint configured, so the capability is off there
        $webPageMarkdowns = $aiWebSearchService->getWebPagesAsMarkdown(['https://example.test/a', 'https://example.test/b']);
        self::assertSame(['https://example.test/a', 'https://example.test/b'], $scriptedWebPageTextSource->handedUrls);
        foreach ($webPageMarkdowns->getElements() as $webPageMarkdown) {
            self::assertNull($webPageMarkdown->fetchedVia);
        }
    }

    protected function buildServiceWithSource(ScriptedWebPageTextSource $scriptedWebPageTextSource): AIWebSearchService
    {
        $aiWebSearchService = new PublicHostsAIWebSearchService();
        $aiWebSearchService->setWebPageTextSources([$scriptedWebPageTextSource]);
        return $aiWebSearchService;
    }
}
