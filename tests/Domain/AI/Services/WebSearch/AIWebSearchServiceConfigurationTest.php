<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\WebSearch;

use DDD\Domain\AI\Entities\WebSearch\WebPageExcerpts;
use DDD\Domain\AI\Entities\WebSearch\WebPageExtract;
use DDD\Domain\AI\Entities\WebSearch\WebPageMarkdown;
use DDD\Domain\AI\Entities\WebSearch\WebSearchHitListFilters;
use DDD\Domain\AI\Entities\WebSearch\WebSearchPayer;
use DDD\Domain\AI\Services\WebSearch\AIWebSearchService;
use DDD\Domain\AI\Services\WebSearch\WebSearchHostService;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\WebSearch\WebPageMarkdowns;
use DDD\Presentation\Mcp\Client\Agents\WebSearchMCPPackage;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Tests\Agents\Support\WebSearchTestConfiguration;

/**
 * The web research degrades without configuration: switched off by default, a capability without an endpoint or
 * switched off answers as a failure on the returned objects (no request, no exception), and the packages are not
 * offered. Switched on with endpoints, the capabilities are available and the limits come from the configuration. The
 * host hooks answer their documented defaults.
 */
class AIWebSearchServiceConfigurationTest extends UnitTestCase
{
    public function testThePackageDefaultIsOffAndNothingIsAsked(): void
    {
        $webSearchConfiguration = require dirname(__DIR__, 5) . '/config/app/AI/webSearch.php';
        self::assertFalse($webSearchConfiguration['enabled']);
        $configValuesBefore = WebSearchTestConfiguration::declareWebSearchConfiguration($webSearchConfiguration);
        try {
            $aiWebSearchService = new AIWebSearchService();
            foreach (AIWebSearchService::CAPABILITIES as $capability) {
                self::assertFalse($aiWebSearchService->isCapabilityAvailable($capability), $capability);
            }
            self::assertFalse(WebSearchMCPPackage::isAvailable());

            $webSearchHitLists = $aiWebSearchService->findHitListsForQueries(['a', ' ', 'b'], new WebSearchHitListFilters(), WebSearchPayer::forAccount(null));
            self::assertSame(2, $webSearchHitLists->count());
            foreach ($webSearchHitLists->getElements() as $webSearchHitList) {
                self::assertFalse($webSearchHitList->isAnswered());
            }

            $webPageMarkdowns = $aiWebSearchService->getWebPagesAsMarkdown(['https://example.com/a', 'https://example.com/b']);
            self::assertSame(2, $webPageMarkdowns->count());
            foreach ($webPageMarkdowns->getElements() as $webPageMarkdown) {
                self::assertSame(WebPageMarkdown::REASON_CAPABILITY_OFF, $webPageMarkdown->reason);
            }
        } finally {
            WebSearchTestConfiguration::restoreConfigValues($configValuesBefore);
        }
    }

    public function testWithEndpointsTheCapabilitiesAreAvailableAndOneCanBeSwitchedOff(): void
    {
        $webSearchConfiguration = WebSearchTestConfiguration::buildEnabledConfiguration();
        $webSearchConfiguration['capabilities']['urlToMarkdown'] = false;
        $webSearchConfiguration['hits']['maxQueriesPerCall'] = 3;
        $configValuesBefore = WebSearchTestConfiguration::declareWebSearchConfiguration($webSearchConfiguration);
        try {
            $aiWebSearchService = new AIWebSearchService();
            self::assertTrue($aiWebSearchService->isCapabilityAvailable(AIWebSearchService::CAPABILITY_SEARCH));
            self::assertFalse($aiWebSearchService->isCapabilityAvailable(AIWebSearchService::CAPABILITY_URL_TO_MARKDOWN), 'switched off although it has an endpoint');
            self::assertTrue(WebSearchMCPPackage::isAvailable());
            self::assertSame(3, $aiWebSearchService->getConfiguredLimit('hits.maxQueriesPerCall', 5));
            self::assertSame(10, $aiWebSearchService->getConfiguredLimit('extraction.maxPagesPerCall', 99), 'the package default file');
            self::assertSame(7, $aiWebSearchService->getConfiguredLimit('no.such.limit', 7));
        } finally {
            WebSearchTestConfiguration::restoreConfigValues($configValuesBefore);
        }
    }

    public function testWithoutAnAccountNoPageIsReadByAModel(): void
    {
        $configValuesBefore = WebSearchTestConfiguration::declareWebSearchConfiguration(WebSearchTestConfiguration::buildEnabledConfiguration());
        try {
            $webPageExtracts = (new AIWebSearchService())->findWebPageExtracts(['https://example.com/a', 'https://www.example.com/a/'], 'the prices', new WebPageExcerpts(), WebSearchPayer::forAccount(null));

            self::assertSame(1, $webPageExtracts->count(), 'one reading per canonical page');
            $webPageExtract = $webPageExtracts->first();
            self::assertSame(WebPageExtract::STATUS_EXTRACTION_FAILED, $webPageExtract->status);
            self::assertSame('no account to bill the reading to', $webPageExtract->failureReason);
        } finally {
            WebSearchTestConfiguration::restoreConfigValues($configValuesBefore);
        }
    }

    public function testAResultsPageIsRefusedBeforeAnyCrawl(): void
    {
        $configValuesBefore = WebSearchTestConfiguration::declareWebSearchConfiguration(WebSearchTestConfiguration::buildEnabledConfiguration());
        try {
            $webPageMarkdown = (new AIWebSearchService())->getWebPageAsMarkdown('https://www.google.com/search?q=example');

            self::assertFalse($webPageMarkdown->ok);
            self::assertSame(WebPageMarkdown::REASON_SEARCH_RESULTS_PAGE, $webPageMarkdown->reason);
        } finally {
            WebSearchTestConfiguration::restoreConfigValues($configValuesBefore);
        }
    }

    public function testTheHostHooksAnswerTheirDefaults(): void
    {
        $webSearchHostService = new WebSearchHostService();
        $callProvenance = new AgentToolCallProvenance();

        self::assertNull($webSearchHostService->findDefaultCountryCode($callProvenance));
        self::assertNull($webSearchHostService->buildNoteAfterWebPagesRead(new WebPageMarkdowns(), $callProvenance));
        $webSearchHostService->throwIfWebSearchSpendingIsRefused(WebSearchPayer::forAccount(null));
        $webSearchHostService->bookWebSearchRequests(WebSearchPayer::forAccount(null), new WebSearchHitListFilters(), 2, 0.002);
    }
}
