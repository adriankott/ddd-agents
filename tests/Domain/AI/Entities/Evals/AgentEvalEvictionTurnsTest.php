<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilitiesEvictionSnapshot;
use DDD\Domain\AI\Entities\Evals\AgentEvalEvictionFixture;
use DDD\Domain\AI\Entities\Evals\AgentEvalEvictionTurn;
use DDD\Domain\AI\Entities\Evals\AgentEvalEvictionTurnFigures;
use DDD\Domain\AI\Entities\Evals\AgentEvalEvictionTurns;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The owner turns of an eviction trajectory and the eviction fixture: what was loaded and evicted per turn, the
 * trajectory's peak, sums and eviction verdict, the turn rows of the deprecated reader, and the fixture's trajectories
 * and retention settings.
 *
 * The expected turn rows are the rows the trajectory run has always produced for these snapshots and figures, read
 * from `tests/Symfony/Commands/Agents/Fixtures/EvalProbes/expected-outputs.json` (key `evictionFixtureRun.rows`),
 * which holds the outputs of the command's former code on the same input.
 */
class AgentEvalEvictionTurnsTest extends UnitTestCase
{
    /** @var array<string, mixed>|null The expected outputs, decoded once */
    protected static ?array $expectedOutputs = null;

    /** @return array<string, mixed> */
    public static function expectedOutputs(): array
    {
        self::$expectedOutputs ??= (array)json_decode((string)file_get_contents(dirname(__DIR__, 4) . '/Symfony/Commands/Agents/Fixtures/EvalProbes/expected-outputs.json'), true);
        return self::$expectedOutputs;
    }

    /**
     * Three turns of a trajectory: the second loads past the hard ceiling, the third evicts three capabilities.
     *
     * @return AgentEvalEvictionTurns
     */
    public static function buildThreeTurns(): AgentEvalEvictionTurns
    {
        $turnInputs = [
            ['How do my search positions look for the main keyword — today?', [1, 12000, 1800, 2400, 4200, ['Search.Positions', 'getPositions']], [3, 0, 0, 20000, 15000, 12.3]],
            ['And the reviews?', [2, 30000, 3000, 4500, 7500, ['Reviews.Overview', 'getReviews', 'Search.Positions', 'getPositions']], [4, 2, 1, 0, 0, 4.0]],
            ['Show me the planned posts', [3, 25000, 2000, 1500, 3500, ['Posts.Plan', 'getPosts', 'Reviews.Overview']], [2, 0, 0, 9000, 3000, 0.1]],
        ];
        $agentEvalEvictionTurns = AgentEvalEvictionTurns::newInstance();
        $capabilityNamesOfTurnBefore = [];
        foreach ($turnInputs as [$prompt, $snapshotValues, $figureValues]) {
            $snapshot = LoadedCapabilitiesEvictionSnapshot::create(
                $snapshotValues[0],
                $snapshotValues[1],
                $snapshotValues[2],
                $snapshotValues[3],
                $snapshotValues[4],
                self::buildCapabilityRows($snapshotValues[5])
            );
            $agentEvalEvictionTurn = AgentEvalEvictionTurn::forTurn($prompt, $snapshot, $capabilityNamesOfTurnBefore, self::buildFigures(...$figureValues));
            $agentEvalEvictionTurns->add($agentEvalEvictionTurn);
            $capabilityNamesOfTurnBefore = $snapshot->getCapabilityNames();
        }
        return $agentEvalEvictionTurns;
    }

    /**
     * @param string[] $capabilityNames
     * @return array<int, array<string, mixed>>
     */
    protected static function buildCapabilityRows(array $capabilityNames): array
    {
        $capabilityRows = [];
        foreach ($capabilityNames as $index => $capabilityName) {
            $capabilityRows[] = [
                'name' => $capabilityName,
                'kind' => str_contains($capabilityName, '.') ? 'skill' : 'tool',
                'residentTokens' => 100 * ($index + 1),
                'score' => round(1 / ($index + 2), 3),
                'path' => 'a/b',
            ];
        }
        return $capabilityRows;
    }

    protected static function buildFigures(int $modelCalls, int $loadToolHops, int $refusedReads, int $inputTokens, int $cachedInputTokens, float $seconds): AgentEvalEvictionTurnFigures
    {
        $agentEvalEvictionTurnFigures = AgentEvalEvictionTurnFigures::newInstance();
        $agentEvalEvictionTurnFigures->modelCalls = $modelCalls;
        $agentEvalEvictionTurnFigures->loadToolHops = $loadToolHops;
        $agentEvalEvictionTurnFigures->refusedReads = $refusedReads;
        $agentEvalEvictionTurnFigures->inputTokens = $inputTokens;
        $agentEvalEvictionTurnFigures->cachedInputTokens = $cachedInputTokens;
        $agentEvalEvictionTurnFigures->seconds = $seconds;
        return $agentEvalEvictionTurnFigures;
    }

    public function testEachTurnKnowsWhatItLoadedAndEvicted(): void
    {
        $turns = self::buildThreeTurns()->getElements();

        self::assertSame(['Search.Positions', 'getPositions'], $turns[0]->newlyLoadedCapabilityNames);
        self::assertSame([], $turns[0]->evictedCapabilityNames);
        self::assertSame(['Reviews.Overview', 'getReviews'], $turns[1]->newlyLoadedCapabilityNames);
        self::assertFalse($turns[1]->hasEvicted());
        self::assertSame(['Posts.Plan', 'getPosts'], $turns[2]->newlyLoadedCapabilityNames);
        self::assertSame(['getReviews', 'Search.Positions', 'getPositions'], $turns[2]->evictedCapabilityNames);
        self::assertTrue($turns[2]->hasEvicted());
    }

    public function testTheTrajectoryKnowsItsPeakItsSumsAndWhetherEvictionFired(): void
    {
        $agentEvalEvictionTurns = self::buildThreeTurns();
        $totalFigures = $agentEvalEvictionTurns->buildTotalFigures();

        self::assertTrue($agentEvalEvictionTurns->hasEviction());
        self::assertSame(7500, $agentEvalEvictionTurns->getPeakTotalResidentTokens());
        self::assertSame([9, 2, 1, 29000, 18000], [$totalFigures->modelCalls, $totalFigures->loadToolHops, $totalFigures->refusedReads, $totalFigures->inputTokens, $totalFigures->cachedInputTokens]);
        self::assertEqualsWithDelta(16.4, $totalFigures->seconds, 0.0000001, 'the sum of the rounded seconds, not rounded again');

        $firstTurnOnly = AgentEvalEvictionTurns::newInstance();
        $firstTurn = $agentEvalEvictionTurns->first();
        $firstTurnOnly->add($firstTurn);
        self::assertFalse($firstTurnOnly->hasEviction());
        self::assertSame(0, AgentEvalEvictionTurns::newInstance()->getPeakTotalResidentTokens());
    }

    public function testTheRowsOfTheDeprecatedReaderAreTheRowsTheRunHasAlwaysProduced(): void
    {
        // compared as JSON: the fixture file cannot tell 4.0 from 4, the rows' encoding can neither
        self::assertSame(
            json_encode(self::expectedOutputs()['evictionFixtureRun']['rows'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            json_encode(self::buildThreeTurns()->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        self::assertSame(4.0, self::buildThreeTurns()->toArray()[1]['metrics']['seconds'], 'the figures keep their float seconds');
    }

    public function testTheFixtureReadsItsTrajectoriesAndItsRetentionSettings(): void
    {
        $agentEvalEvictionFixture = AgentEvalEvictionFixture::fromDecodedFields([
            'settings' => ['eager' => true, 'soft' => 4000, 'hard' => 7000, 'ref' => 500, 'unloadToolAfterUnusedTokens' => 4000, 'unloadToolAfterUnusedTurns' => 3, 'unloadSkillAfterUnusedTokens' => 3000, 'unloadSkillAfterUnusedTurns' => 2, 'minResidencyTurns' => '1'],
            'trajectories' => [
                ['name' => 'first', 'agentSpaceId' => 12, 'prompts' => ['one', 2, 'two']],
                'no object',
                ['prompts' => 'no list'],
            ],
        ]);
        $trajectories = $agentEvalEvictionFixture->getTrajectories()->getElements();
        $contextRetentionSettings = $agentEvalEvictionFixture->buildRetentionSettings();

        self::assertCount(2, $trajectories);
        self::assertSame('first', $trajectories[0]->name);
        self::assertSame(['one', 'two'], $trajectories[0]->prompts);
        self::assertSame(12, $trajectories[0]->buildScopeCase()->getAgentSpaceId());
        self::assertSame('first', $trajectories[0]->buildScopeCase()->getId());
        self::assertSame('trajectory', $trajectories[1]->name);
        self::assertFalse($trajectories[1]->hasPrompts());
        self::assertTrue($contextRetentionSettings->loadToolsOnSkillLoad);
        self::assertSame(
            [4000, 7000, 500, 4000, 3, 3000, 2, 1],
            [
                $contextRetentionSettings->pressureBudgetTokens,
                $contextRetentionSettings->hardCapTokens,
                $contextRetentionSettings->referenceResidentTokens,
                $contextRetentionSettings->unloadToolAfterUnusedTokens,
                $contextRetentionSettings->unloadToolAfterUnusedTurns,
                $contextRetentionSettings->unloadSkillAfterUnusedTokens,
                $contextRetentionSettings->unloadSkillAfterUnusedTurns,
                $contextRetentionSettings->minResidencyTurns,
            ]
        );

        $lazyFixture = AgentEvalEvictionFixture::fromDecodedFields(['settings' => ['eager' => false], 'trajectories' => ['no object']]);
        self::assertFalse($lazyFixture->buildRetentionSettings()->loadToolsOnSkillLoad);
        self::assertNull($lazyFixture->buildRetentionSettings()->hardCapTokens);
        self::assertTrue($lazyFixture->listsTrajectories(), 'a list of no objects is a list: the run ends with no trajectory run');
        self::assertSame(0, $lazyFixture->getTrajectories()->count());
        self::assertFalse(AgentEvalEvictionFixture::fromFile('/no/such/fixture.json')->listsTrajectories());
    }
}
