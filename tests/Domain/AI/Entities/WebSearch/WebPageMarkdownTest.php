<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\WebSearch;

use DDD\Domain\AI\Entities\WebSearch\WebPageMarkdown;
use DDD\Domain\AI\Entities\WebSearch\WebSearchHitListFilters;
use DDD\Tests\Agents\Domain\AI\Entities\WebSearch\Fixtures\HostWebPageMarkdown;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A page read: the canonical key, the refusal of a results page, the bot-challenge downgrade, the capped rendering, the
 * failure texts; and the clamped filters of a search.
 */
class WebPageMarkdownTest extends UnitTestCase
{
    public function testEverySpellingOfOnePageHasOneKey(): void
    {
        self::assertSame('example.com/shop', WebPageMarkdown::canonicalUrlKey('https://www.EXAMPLE.com/shop/#top'));
        self::assertSame('example.com/shop', WebPageMarkdown::canonicalUrlKey('example.com/shop'));
        self::assertNotSame(WebPageMarkdown::canonicalUrlKey('https://example.com/shop?x=1'), WebPageMarkdown::canonicalUrlKey('https://example.com/shop'));
    }

    public function testASearchEnginesResultsPageIsRecognised(): void
    {
        self::assertTrue(WebPageMarkdown::isSearchEngineResultsPageUrl('https://www.google.de/search?q=x'));
        self::assertTrue(WebPageMarkdown::isSearchEngineResultsPageUrl('https://duckduckgo.com/?q=x'));
        self::assertFalse(WebPageMarkdown::isSearchEngineResultsPageUrl('https://support.google.com/answer/1'));
        self::assertFalse(WebPageMarkdown::isSearchEngineResultsPageUrl('https://example.com/search'));
    }

    public function testAShortChallengePageBecomesAFailure(): void
    {
        $webPageMarkdown = new WebPageMarkdown();
        $webPageMarkdown->url = 'https://example.com';
        $webPageMarkdown->ok = true;
        $webPageMarkdown->markdown = '# Checking your browser before accessing example.com';
        $webPageMarkdown->downgradeBotChallengeToFailure();

        self::assertFalse($webPageMarkdown->ok);
        self::assertSame(WebPageMarkdown::REASON_BOT_CHALLENGE, $webPageMarkdown->reason);
        self::assertStringContainsString('Do NOT retry', $webPageMarkdown->buildFailureExplanationForAgent());
    }

    public function testALongPageIsCutWithANoticeAndItsTextIsMarked(): void
    {
        $webPageMarkdown = new WebPageMarkdown();
        $webPageMarkdown->url = 'https://example.com/long';
        $webPageMarkdown->ok = true;
        $webPageMarkdown->title = 'Long';
        $webPageMarkdown->markdown = str_repeat('a', 50);

        $rendered = $webPageMarkdown->buildLlmMarkdown(20);

        self::assertStringStartsWith('# Long — https://example.com/long', $rendered);
        self::assertStringContainsString('[Page truncated: showing 20 of 50 characters', $rendered);
        self::assertStringContainsString('<untrusted-content source="https://example.com/long">', $rendered);
    }

    public function testEveryRefusalSaysWhetherARetryHelps(): void
    {
        self::assertStringContainsString('You may retry ONCE', WebPageMarkdown::fromRefusedUrl('https://example.com', WebPageMarkdown::REASON_TIMEOUT)->buildFailureExplanationForAgent());
        self::assertStringContainsString('NEVER retry', WebPageMarkdown::fromRefusedUrl('https://example.com', WebPageMarkdown::REASON_UNRESOLVABLE)->buildFailureExplanationForAgent());
        self::assertStringContainsString('switched off', WebPageMarkdown::fromRefusedUrl('https://example.com', WebPageMarkdown::REASON_CAPABILITY_OFF)->buildFailureExplanationForAgent());
    }

    public function testTheFiltersAreClampedToWhatTheEngineAccepts(): void
    {
        $webSearchHitListFilters = WebSearchHitListFilters::fromToolArguments('deep', 'de ', 'fortnight', [' Example.com', 'example.com', ''], ['EN', 'english'], 99, 8, 4096);

        self::assertSame(WebSearchHitListFilters::MODE_FAST, $webSearchHitListFilters->mode);
        self::assertSame('DE', $webSearchHitListFilters->countryCode);
        self::assertNull($webSearchHitListFilters->recencyWindow);
        self::assertSame(['example.com'], $webSearchHitListFilters->domains);
        self::assertSame(['en'], $webSearchHitListFilters->languageCodes);
        self::assertSame(20, $webSearchHitListFilters->maxHitsPerQuery);
        self::assertSame(
            ['query' => 'q', 'max_results' => 20, 'max_tokens_per_page' => 4096, 'search_type' => 'fast', 'country' => 'DE', 'search_domain_filter' => ['example.com'], 'search_language_filter' => ['en']],
            $webSearchHitListFilters->buildSearchRequestBody('q')
        );
        $webFilters = WebSearchHitListFilters::fromToolArguments('web', 'DE', null, ['example.com'], ['en'], 99, 8, 4096);
        self::assertNotSame($webSearchHitListFilters->buildCacheKeyPart(), $webFilters->buildCacheKeyPart());
    }

    public function testAnApplicationAddsItsLinesAboveThePageTextAndItsOwnContinueSentence(): void
    {
        $webPageMarkdown = new HostWebPageMarkdown();
        $webPageMarkdown->url = 'https://acme.example';
        $webPageMarkdown->title = 'Acme';
        $webPageMarkdown->markdown = 'Welcome to Acme.';
        $rendered = $webPageMarkdown->buildLlmMarkdown(1000);

        $platformLinePosition = mb_strpos($rendered, 'Platform-verified social profile URLs on this page:');
        $pageTextPosition = mb_strpos($rendered, 'Welcome to Acme.');
        self::assertNotFalse($platformLinePosition);
        self::assertNotFalse($pageTextPosition);
        self::assertLessThan($pageTextPosition, $platformLinePosition);
        self::assertStringStartsWith("# Acme — https://acme.example\n\nPlatform-verified", $rendered);

        $webPageMarkdown->ok = false;
        $webPageMarkdown->reason = WebPageMarkdown::REASON_TIMEOUT;
        self::assertStringContainsString('Continue with the facts already on file.', $webPageMarkdown->buildFailureExplanationForAgent());

        $packageWebPageMarkdown = new WebPageMarkdown();
        $packageWebPageMarkdown->url = 'https://acme.example';
        $packageWebPageMarkdown->markdown = 'Welcome to Acme.';
        self::assertStringNotContainsString('Platform-verified', $packageWebPageMarkdown->buildLlmMarkdown(1000));
    }
}
