<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The cold unloadings of an append-only conversation, one per compaction epoch, live in the working state: an epoch is
 * recorded once (also an empty drop, so it is never unloaded twice), found by its start index, and round-trips through
 * the working-state JSON and its own slice. Rows the mapping cannot read are skipped.
 */
class AgentWorkingStateColdCapabilityDropsTest extends UnitTestCase
{
    public function testADropIsRecordedAndFoundByItsEpoch(): void
    {
        $agentWorkingState = new AgentWorkingState();
        self::assertSame([], $agentWorkingState->getColdCapabilityDrops()->toRepositoryList());
        self::assertNull($agentWorkingState->findColdCapabilityDropOfEpoch(12));

        $agentWorkingState->recordColdCapabilityDrop(12, 40, ['nameKey' => 'Seo.Rankings', 'otherKey' => 'getRankings']);

        self::assertSame(
            ['epochStartIndex' => 12, 'afterMessageIndex' => 40, 'names' => ['Seo.Rankings', 'getRankings']],
            $agentWorkingState->findColdCapabilityDropOfEpoch(12)?->toRepositoryArray(),
            'the names are stored as a list'
        );
        self::assertNull($agentWorkingState->findColdCapabilityDropOfEpoch(13));
    }

    public function testAnEpochIsNeverDroppedTwice(): void
    {
        $agentWorkingState = new AgentWorkingState();
        $agentWorkingState->recordColdCapabilityDrop(12, 40, ['Seo.Rankings']);
        $agentWorkingState->recordColdCapabilityDrop(12, 55, ['Reputation.Reviews']);

        self::assertCount(1, $agentWorkingState->getColdCapabilityDrops()->getElements());
        self::assertSame(['Seo.Rankings'], $agentWorkingState->findColdCapabilityDropOfEpoch(12)->names, 'the first record stays');
    }

    public function testAnEmptyDropIsRecordedAndBlocksALaterOneOfTheSameEpoch(): void
    {
        $agentWorkingState = new AgentWorkingState();
        $agentWorkingState->recordColdCapabilityDrop(12, 40, []);
        $agentWorkingState->recordColdCapabilityDrop(12, 41, ['Seo.Rankings']);
        $agentWorkingState->recordColdCapabilityDrop(60, 70, ['Seo.Rankings']);

        self::assertSame(
            [
                ['epochStartIndex' => 12, 'afterMessageIndex' => 40, 'names' => []],
                ['epochStartIndex' => 60, 'afterMessageIndex' => 70, 'names' => ['Seo.Rankings']],
            ],
            $agentWorkingState->getColdCapabilityDrops()->toRepositoryList(),
            'one record per epoch, a later epoch records its own'
        );
    }

    public function testTheDropsRoundTripThroughTheWorkingStateJson(): void
    {
        $agentWorkingState = new AgentWorkingState();
        $agentWorkingState->recordColdCapabilityDrop(12, 40, []);
        $agentWorkingState->recordColdCapabilityDrop(60, 70, ['Seo.Rankings', 'getRankings']);

        $repositoryArray = $agentWorkingState->mapToRepository();
        self::assertSame($agentWorkingState->getColdCapabilityDrops()->toRepositoryList(), $repositoryArray['coldCapabilityDrops']);
        self::assertSame(['coldCapabilityDrops' => $agentWorkingState->getColdCapabilityDrops()->toRepositoryList()], $agentWorkingState->buildSlice('coldCapabilityDrops'));

        $rehydratedWorkingState = new AgentWorkingState();
        $rehydratedWorkingState->mapFromRepository(json_decode((string)json_encode($repositoryArray), true));

        self::assertSame($agentWorkingState->getColdCapabilityDrops()->toRepositoryList(), $rehydratedWorkingState->getColdCapabilityDrops()->toRepositoryList());
        self::assertSame([], $rehydratedWorkingState->findColdCapabilityDropOfEpoch(12)->names);
        $rehydratedWorkingState->recordColdCapabilityDrop(60, 99, ['Presence.Listings']);
        self::assertSame(['Seo.Rankings', 'getRankings'], $rehydratedWorkingState->findColdCapabilityDropOfEpoch(60)->names, 'still dropped once after a reload');
    }

    public function testTheMappingSkipsUnreadableRowsAndCoercesTheRest(): void
    {
        $rehydratedWorkingState = new AgentWorkingState();
        $rehydratedWorkingState->mapFromRepository([
            'coldCapabilityDrops' => [
                'not a row',
                ['afterMessageIndex' => 3, 'names' => ['X']],
                ['epochStartIndex' => 'abc'],
                ['epochStartIndex' => '12', 'names' => [7, 'Seo.Rankings']],
                ['epochStartIndex' => 20, 'afterMessageIndex' => '25', 'names' => 'not a list'],
            ],
        ]);

        self::assertSame(
            [
                ['epochStartIndex' => 12, 'afterMessageIndex' => -1, 'names' => ['7', 'Seo.Rankings']],
                ['epochStartIndex' => 20, 'afterMessageIndex' => 25, 'names' => []],
            ],
            $rehydratedWorkingState->getColdCapabilityDrops()->toRepositoryList()
        );
    }

    public function testAWorkingStateWithoutDropsReadsAsNone(): void
    {
        $rehydratedWorkingState = new AgentWorkingState();
        $rehydratedWorkingState->recordColdCapabilityDrop(1, 2, ['stale']);
        $rehydratedWorkingState->mapFromRepository([]);

        self::assertSame([], $rehydratedWorkingState->getColdCapabilityDrops()->toRepositoryList(), 'a stored state without the key carries no drop');
    }
}
