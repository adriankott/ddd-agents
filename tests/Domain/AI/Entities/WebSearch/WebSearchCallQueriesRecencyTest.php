<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\WebSearch;

use DateTimeImmutable;
use DateTimeZone;
use DDD\Domain\AI\Entities\WebSearch\WebSearchCallQueries;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A time phrase in a query becomes the call's `recency` in five languages; the current month's name counts as a
 * month window, another month with its year only earns the note; a requested `recency` is never overridden.
 */
class WebSearchCallQueriesRecencyTest extends UnitTestCase
{
    protected function buildNow(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-22 10:00:00', new DateTimeZone('Europe/Berlin'));
    }

    public function testTimePhrasesOfFiveLanguagesBecomeTheWindow(): void
    {
        $expectedWindowsByQuery = [
            'electric cars news this week' => 'week',
            'Elektroautos Neuigkeiten diese Woche' => 'week',
            'voitures électriques cette semaine' => 'week',
            'coches eléctricos esta semana' => 'week',
            'auto elettriche questa settimana' => 'week',
            'Wetter heute Berlin' => 'day',
            "météo aujourd'hui Paris" => 'day',
            'interest rates last 3 days' => 'week',
            'Zinsen letzten 30 Tage' => 'month',
            'taux 30 derniers jours' => 'month',
            'noticias últimos 2 días' => 'week',
            'notizie ultimi 90 giorni' => 'year',
            'Förderung November 2026' => 'month',
            'subsidies november' => 'month',
            'ce mois-ci promotions' => 'month',
        ];
        foreach ($expectedWindowsByQuery as $query => $expectedWindow) {
            $callQueries = WebSearchCallQueries::fromRawQueries([$query], [], null, $this->buildNow());
            self::assertSame($expectedWindow, $callQueries->recency, $query);
            self::assertStringContainsString('pass `recency` yourself next time', (string)$callQueries->noteForModel, $query);
        }
    }

    public function testAnotherMonthWithItsYearOnlyEarnsTheNote(): void
    {
        $callQueries = WebSearchCallQueries::fromRawQueries(['grant deadlines March 2026'], [], null, $this->buildNow());
        self::assertNull($callQueries->recency);
        self::assertStringContainsString('instead of a date or year in the query', (string)$callQueries->noteForModel);
    }

    public function testOrdinaryWordsAndYearsAloneDoNotSetAWindow(): void
    {
        $mayNow = new DateTimeImmutable('2026-05-10 10:00:00', new DateTimeZone('UTC'));
        self::assertNull(WebSearchCallQueries::fromRawQueries(['what may cause battery drain'], [], null, $mayNow)->recency);
        self::assertSame('month', WebSearchCallQueries::fromRawQueries(['events May 2026'], [], null, $mayNow)->recency);
        self::assertNull(WebSearchCallQueries::fromRawQueries(['heat pump prices 2026'], [], null, $this->buildNow())->recency);
        self::assertNull(WebSearchCallQueries::fromRawQueries(['heute show'], [], 'year', $this->buildNow())->noteForModel);
        self::assertSame('year', WebSearchCallQueries::fromRawQueries(['heute show'], [], 'year', $this->buildNow())->recency);
    }

    public function testTwoDifferentWindowsAreNotGuessed(): void
    {
        $callQueries = WebSearchCallQueries::fromRawQueries(['prices today', 'prices this month'], [], null, $this->buildNow());
        self::assertNull($callQueries->recency);
        self::assertStringContainsString('pass `recency`', (string)$callQueries->noteForModel);
    }

    public function testNewsWordsOfOtherLanguagesEarnTheNote(): void
    {
        $callQueries = WebSearchCallQueries::fromRawQueries(['Nachrichten Elektromobilität'], [], null, $this->buildNow());
        self::assertNull($callQueries->recency);
        self::assertStringContainsString('give every news item its date', (string)$callQueries->noteForModel);
    }
}
