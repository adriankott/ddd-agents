<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\DecisionFixtures;

use DDD\Symfony\Commands\Agents\DecisionFixtures\AgentDecisionFixture;
use DDD\Symfony\Commands\Agents\DecisionFixtures\AgentDecisionFixtureScore;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The score of a decision-fixtures run: the median latency is the middle value of the sorted latencies (the upper one
 * of two for an even count, 0 without a call), the hit percent is whole and `n/a` without a scored call, and the score
 * lines keep the wording the command printed before it moved into the module. The fixture reads its cases in order and
 * filters them by id.
 */
class AgentDecisionFixtureScoreTest extends UnitTestCase
{
    public function testTheMedianLatencyIsTheMiddleOfTheSortedLatencies(): void
    {
        $agentDecisionFixtureScore = AgentDecisionFixtureScore::newInstance();
        self::assertSame(0, $agentDecisionFixtureScore->getMedianLatencyMs(), 'no answered call');

        $agentDecisionFixtureScore->recordLatencyMs(40);
        self::assertSame(40, $agentDecisionFixtureScore->getMedianLatencyMs());

        foreach ([10, 30, 20] as $latencyMs) {
            $agentDecisionFixtureScore->recordLatencyMs($latencyMs);
        }
        self::assertSame(30, $agentDecisionFixtureScore->getMedianLatencyMs(), 'of 10, 20, 30, 40 the upper middle value');
        self::assertSame([40, 10, 30, 20], $agentDecisionFixtureScore->latenciesMs, 'the recorded order is kept');

        $agentDecisionFixtureScore->recordLatencyMs(25);
        self::assertSame(25, $agentDecisionFixtureScore->getMedianLatencyMs());
    }

    public function testTheScoreLinesKeepTheirWording(): void
    {
        $agentDecisionFixtureScore = AgentDecisionFixtureScore::newInstance();
        self::assertSame('Score: 0 / 0 expected picks hit (n/a).', $agentDecisionFixtureScore->buildPickScoreLine());

        $agentDecisionFixtureScore->recordScoredPick(true);
        $agentDecisionFixtureScore->recordScoredPick(false);
        $agentDecisionFixtureScore->recordScoredPick(true);
        self::assertSame('Score: 2 / 3 expected picks hit (67 %).', $agentDecisionFixtureScore->buildPickScoreLine());

        $replyLanguageScore = AgentDecisionFixtureScore::newInstance();
        $replyLanguageScore->recordReplyLanguageAnswer(true, false, true);
        $replyLanguageScore->recordReplyLanguageAnswer(false, true, true);
        $replyLanguageScore->recordLatencyMs(120);
        $replyLanguageScore->recordLatencyMs(80);
        self::assertSame(
            'Arm: an arm · written 1 / 2 · requested 1 / 2 · REPLY 2 / 2 (100 %) · median latency 120 ms',
            $replyLanguageScore->buildReplyLanguageScoreLine('an arm')
        );
    }

    public function testTheFixtureReadsItsCasesInOrderAndFiltersThemById(): void
    {
        $agentDecisionFixture = AgentDecisionFixture::fromJson((string)json_encode([
            'site' => 'replyLanguage',
            'cases' => [['id' => 'a', 'recentTurns' => ['owner: hi', 7]], ['ownerWords' => 'no id'], ['id' => 'a', 'ownerWords' => 'second a']],
        ]));

        self::assertSame('replyLanguage', $agentDecisionFixture->getSite());
        $agentDecisionFixtureCases = $agentDecisionFixture->getCases();
        self::assertSame(['a', '?', 'a'], array_map(static fn ($agentDecisionFixtureCase): string => $agentDecisionFixtureCase->getId(), $agentDecisionFixtureCases->getElements()));
        self::assertSame(['owner: hi', '7'], $agentDecisionFixtureCases->first()->getTexts('recentTurns'));
        self::assertNull($agentDecisionFixtureCases->first()->getNullableText('conversationLanguage'));
        self::assertSame(2, $agentDecisionFixtureCases->buildWithId('a')->count());
        self::assertSame(0, $agentDecisionFixtureCases->buildWithId('?')->count(), 'a case without an id is never picked by id');
        self::assertSame(3, $agentDecisionFixtureCases->buildWithId(null)->count());
        self::assertSame(['en'], $agentDecisionFixture->getSupportedLanguageCodes(['en']));
    }
}
