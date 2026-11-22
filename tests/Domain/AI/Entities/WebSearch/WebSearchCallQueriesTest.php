<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\WebSearch;

use DDD\Domain\AI\Entities\WebSearch\WebSearchCallQueries;
use DDD\Domain\AI\Entities\WebSearch\WebSearchQueryNormalization;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * Search operators in a query are removed before the search: `site:` domains become the call's `domains` filter only
 * when the call has none and every query named a site; the note says what changed; plain short queries get no note.
 */
class WebSearchCallQueriesTest extends UnitTestCase
{
    public function testOperatorsAreRemovedAndTheSiteDomainIsKept(): void
    {
        $normalization = WebSearchQueryNormalization::fromRawQuery('site:https://www.example.org/ "heat pump" lifespan OR durability -site:spam.example intitle:test');

        self::assertSame('heat pump lifespan durability test', $normalization->query);
        self::assertSame(['www.example.org', '-spam.example'], $normalization->siteDomains);
        self::assertSame(['site:', 'intitle:', 'OR', 'quotes'], $normalization->removedOperators);
    }

    public function testAPlainQueryStaysAsItIs(): void
    {
        $normalization = WebSearchQueryNormalization::fromRawQuery('electric bike trends 2026');

        self::assertSame('electric bike trends 2026', $normalization->query);
        self::assertFalse($normalization->hasRemovedOperators());
        self::assertNull(WebSearchCallQueries::fromRawQueries(['electric bike trends 2026'], [])->noteForModel);
    }

    public function testSiteDomainsBecomeTheFilterWhenEveryQueryNamedOneAndNoDomainsWereGiven(): void
    {
        $callQueries = WebSearchCallQueries::fromRawQueries(['site:example.org pricing', 'site:example.com pricing'], []);

        self::assertSame(['pricing'], $callQueries->queries, 'the two queries are the same words once the operators are gone');
        self::assertSame(['example.org', 'example.com'], $callQueries->domains);
        self::assertStringContainsString('applied as `domains`', (string)$callQueries->noteForModel);
    }

    public function testSiteDomainsAreDroppedWhenTheyWouldNarrowAnotherQuery(): void
    {
        $callQueries = WebSearchCallQueries::fromRawQueries(['site:example.org pricing', 'average plan price 2026'], []);

        self::assertSame([], $callQueries->domains, 'the filter applies to every query of the call');
        self::assertStringContainsString('were dropped', (string)$callQueries->noteForModel);

        $withRequestedDomains = WebSearchCallQueries::fromRawQueries(['site:example.org pricing'], ['example.net']);
        self::assertSame(['example.net'], $withRequestedDomains->domains, 'the requested filter wins');
    }

    public function testAQueryOfOnlyASiteSearchesTheDomainName(): void
    {
        self::assertSame(['example.org'], WebSearchCallQueries::fromRawQueries(['site:example.org'], [])->queries);
        self::assertSame([], WebSearchCallQueries::fromRawQueries(['""'], [])->queries);
    }

    public function testAKeywordChainIsNoted(): void
    {
        $callQueries = WebSearchCallQueries::fromRawQueries(['heat pump cost lifespan efficiency maintenance noise subsidy installation brand comparison test 2026'], []);

        self::assertStringContainsString('long keyword chains', (string)$callQueries->noteForModel);
    }
}
