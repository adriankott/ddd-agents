<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Compaction\CompactionCharacterization;

use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionConsistencyCheck;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionConsistencyReport;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNode;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNodes;
use DDD\Domain\AI\Services\Conversations\AIConversationCompactionService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\ConversationCompactionNodesService;
use DDD\Infrastructure\Services\DDDService;

/**
 * The compaction consistency report: {@see AIConversationCompactionService::validateCompactionConsistency()} and
 * {@see AIConversationCompactionConsistencyReport} (`toArray()`, `isAllPass()`, `getEfficiencyCounts()`), the shape an
 * application's compaction validation and evaluation commands print. Small synthetic trees — valid; a gap in the
 * leaves; two overlapping nodes of one level; a partial overlap across levels; a roll-up over a hole; an invalid range;
 * an empty tree — and bounded reads that disagree with the full scan each produce the eleven checks, compared whole.
 *
 * The five reads go to doubles in the test container: the three node reads
 * ({@see CompactionConsistencyNodesServiceDouble}) and the two message reads
 * ({@see CompactionConsistencyMessagesServiceDouble}). No database.
 */
class CompactionConsistencyReportTest extends CompactionContainerTestCase
{
    protected const int CONVERSATION_ID = 51;

    protected CompactionConsistencyNodesServiceDouble $nodesServiceDouble;

    protected CompactionConsistencyMessagesServiceDouble $messagesServiceDouble;

    protected function setUp(): void
    {
        parent::setUp();
        $this->nodesServiceDouble = new CompactionConsistencyNodesServiceDouble();
        $this->replaceService(ConversationCompactionNodesService::class, $this->nodesServiceDouble);
        $this->messagesServiceDouble = new CompactionConsistencyMessagesServiceDouble();
        $this->replaceService(AIConversationMessagesService::class, $this->messagesServiceDouble);
    }

    protected function compactionService(): AIConversationCompactionService
    {
        /** @var AIConversationCompactionService $aIConversationCompactionService */
        $aIConversationCompactionService = DDDService::instance()->getService(AIConversationCompactionService::class);
        return $aIConversationCompactionService;
    }

    /**
     * The full tree and the conversation's rows 0 … $lastMessageIndex (minus $missingMessageIndexes).
     *
     * @param AIConversationCompactionNode[] $nodes
     * @param int[] $missingMessageIndexes
     */
    protected function givenTreeAndMessages(array $nodes, int $lastMessageIndex, array $missingMessageIndexes = []): void
    {
        $this->nodesServiceDouble->allNodes = self::fixtureNodeSet($nodes);
        $messages = [];
        for ($messageIndex = 0; $messageIndex <= $lastMessageIndex; $messageIndex++) {
            if (!in_array($messageIndex, $missingMessageIndexes, true)) {
                $messages[] = self::fixtureIndexedMessage($messageIndex);
            }
        }
        $this->messagesServiceDouble->allMessages = $messages;
    }

    /**
     * The report as an array, plus what every report must show: each of the five reads ran exactly once, in this
     * order, with the conversation's id, an uncached whole-conversation read and the tail read after the full-scan edge;
     * the value object's own answers equal its array; the conversation's node set is left alone.
     *
     * @return array{conversationId: int, allPass: bool, checks: array<string, array<string, mixed>>, efficiency: array<string, int>}
     */
    protected function validate(?int $expectedTailAfterIndex, int $conversationId = self::CONVERSATION_ID): array
    {
        $conversation = self::fixtureConversation($conversationId === 0 ? null : $conversationId);

        $consistencyReport = $this->compactionService()->validateCompactionConsistency($conversation);
        $report = $consistencyReport->toArray();
        self::assertSame($report['allPass'], $consistencyReport->isAllPass());
        self::assertSame($report['efficiency'], $consistencyReport->getEfficiencyCounts());
        self::assertSame(array_keys($report['checks']), array_map(
            static fn (AIConversationCompactionConsistencyCheck $check): string => $check->getCheckName(),
            $consistencyReport->getChecks()
        ));

        self::assertSame(
            [
                ['method' => 'findAllNodesForConversation', 'aiConversationId' => $conversationId],
                ['method' => 'findCoverNodesForConversation', 'aiConversationId' => $conversationId],
                ['method' => 'findExposedNodesForConversation', 'aiConversationId' => $conversationId],
            ],
            $this->nodesServiceDouble->calls,
            'each node read ran once, in this order'
        );
        self::assertSame(
            [
                ['method' => 'findAllMessagesForConversationInOrder', 'arguments' => ['offset' => null, 'limit' => null, 'useEntityRegistrCache' => false]],
                // the full-scan edge, or -1 when nothing is covered
                ['method' => 'findRawTailMessagesAfterIndex', 'arguments' => ['afterIndex' => $expectedTailAfterIndex ?? -1, 'useEntityRegistrCache' => false]],
            ],
            $this->messagesServiceDouble->calls,
            'each message read ran once'
        );
        self::assertFalse(isset($conversation->compactionNodes), 'the report reads, it never installs a node set on the conversation');
        return $report;
    }

    /**
     * The eleven checks in their output order, every one passing, with $overrides applied per check name.
     *
     * @param array<string, array<string, mixed>> $overrides
     * @return array<string, array<string, mixed>>
     */
    protected static function expectedChecks(?int $edge, int $tailCount, int $leafCount, array $overrides = []): array
    {
        $checks = [
            'coverDbMatchesFullScan' => ['pass' => true],
            'frontierDbMatchesFullScan' => ['pass' => true],
            'coverEdgeConsistent' => ['pass' => true, 'edge' => $edge],
            'coverTilesContiguously' => ['pass' => true],
            'tailMatchesEdge' => ['pass' => true, 'tailCount' => $tailCount],
            'nothingLostBetweenCoverAndTail' => ['pass' => true],
            'everyNodeRangeAndLevelValid' => ['pass' => true],
            'eachLevelIsDisjoint' => ['pass' => true],
            'leavesTileContiguouslyFromZero' => ['pass' => true, 'leafCount' => $leafCount],
            'treeIsLaminar' => ['pass' => true],
            'rollUpsSpanContiguousChildren' => ['pass' => true],
        ];
        foreach ($overrides as $checkName => $checkOverride) {
            $checks[$checkName] = array_merge($checks[$checkName], $checkOverride);
        }
        return $checks;
    }

    /** @return AIConversationCompactionNode[] six leaves of five messages over [0 … 29] and one roll-up over the first four */
    protected static function validTree(): array
    {
        $nodes = [];
        for ($leafIndex = 0; $leafIndex < 6; $leafIndex++) {
            $nodes[] = self::fixtureNode(0, $leafIndex * 5, $leafIndex * 5 + 4, self::CONVERSATION_ID);
        }
        $nodes[] = self::fixtureNode(1, 0, 19, self::CONVERSATION_ID);
        return $nodes;
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Trees whose bounded reads agree with the full scan
    // ---------------------------------------------------------------------------------------------------------------

    public function testAValidTreePassesEveryCheck(): void
    {
        $this->givenTreeAndMessages(self::validTree(), 34);

        $report = $this->validate(29);

        self::assertSame([
            'conversationId' => self::CONVERSATION_ID,
            'allPass' => true,
            'checks' => self::expectedChecks(29, 5, 6),
            'efficiency' => ['totalNodes' => 7, 'coverNodesLoaded' => 3, 'frontierNodesLoaded' => 3, 'totalMessages' => 35, 'tailMessagesLoaded' => 5],
        ], $report);
        // the bytes the commands print from
        self::assertSame(
            '{"conversationId":51,"allPass":true,"checks":{"coverDbMatchesFullScan":{"pass":true},"frontierDbMatchesFullScan":{"pass":true},'
            . '"coverEdgeConsistent":{"pass":true,"edge":29},"coverTilesContiguously":{"pass":true},"tailMatchesEdge":{"pass":true,"tailCount":5},'
            . '"nothingLostBetweenCoverAndTail":{"pass":true},"everyNodeRangeAndLevelValid":{"pass":true},"eachLevelIsDisjoint":{"pass":true},'
            . '"leavesTileContiguouslyFromZero":{"pass":true,"leafCount":6},"treeIsLaminar":{"pass":true},"rollUpsSpanContiguousChildren":{"pass":true}},'
            . '"efficiency":{"totalNodes":7,"coverNodesLoaded":3,"frontierNodesLoaded":3,"totalMessages":35,"tailMessagesLoaded":5}}',
            json_encode($report)
        );
    }

    public function testAGapInTheLeavesFailsOnlyTheLeafTiling(): void
    {
        // leaves [0-9] and [20-29]: the cover stops at the hole (edge 9), so cover, edge and tail stay consistent —
        // the uncovered leaf [20-29] sits INSIDE the raw tail and no cover or tail check notices it
        $this->givenTreeAndMessages([
            self::fixtureNode(0, 0, 9, self::CONVERSATION_ID),
            self::fixtureNode(0, 20, 29, self::CONVERSATION_ID),
        ], 34);

        $report = $this->validate(9);

        self::assertFalse($report['allPass']);
        self::assertSame(self::expectedChecks(9, 25, 2, [
            'leavesTileContiguouslyFromZero' => ['pass' => false],
        ]), $report['checks']);
        self::assertSame(['totalNodes' => 2, 'coverNodesLoaded' => 1, 'frontierNodesLoaded' => 2, 'totalMessages' => 35, 'tailMessagesLoaded' => 25], $report['efficiency']);
    }

    public function testTwoOverlappingNodesOfOneLevel(): void
    {
        // leaves [0-9] and [5-14]: the cover takes [5-14] at position 10 → the cover overlaps itself
        $this->givenTreeAndMessages([
            self::fixtureNode(0, 0, 9, self::CONVERSATION_ID),
            self::fixtureNode(0, 5, 14, self::CONVERSATION_ID),
        ], 19);

        $report = $this->validate(14);

        self::assertFalse($report['allPass']);
        self::assertSame(self::expectedChecks(14, 5, 2, [
            'coverTilesContiguously' => ['pass' => false],
            'eachLevelIsDisjoint' => ['pass' => false],
            'leavesTileContiguouslyFromZero' => ['pass' => false],
            'treeIsLaminar' => ['pass' => false],
        ]), $report['checks']);
        self::assertSame(['totalNodes' => 2, 'coverNodesLoaded' => 2, 'frontierNodesLoaded' => 2, 'totalMessages' => 20, 'tailMessagesLoaded' => 5], $report['efficiency']);
    }

    public function testAPartialOverlapAcrossLevels(): void
    {
        // L1 [0-14] half-covers the leaf [10-19]: the cover is L1 [0-14], L0 [10-19], L0 [20-29]
        $this->givenTreeAndMessages([
            self::fixtureNode(0, 0, 9, self::CONVERSATION_ID),
            self::fixtureNode(0, 10, 19, self::CONVERSATION_ID),
            self::fixtureNode(0, 20, 29, self::CONVERSATION_ID),
            self::fixtureNode(1, 0, 14, self::CONVERSATION_ID),
        ], 34);

        $report = $this->validate(29);

        self::assertFalse($report['allPass']);
        self::assertSame(self::expectedChecks(29, 5, 3, [
            'coverTilesContiguously' => ['pass' => false],
            'treeIsLaminar' => ['pass' => false],
            'rollUpsSpanContiguousChildren' => ['pass' => false],
        ]), $report['checks']);
        self::assertSame(['totalNodes' => 4, 'coverNodesLoaded' => 3, 'frontierNodesLoaded' => 3, 'totalMessages' => 35, 'tailMessagesLoaded' => 5], $report['efficiency']);
    }

    public function testARollUpOverAHole(): void
    {
        // L1 [0-29] over the leaves [0-9] and [20-29]: the roll-up claims [10-19] too; the cover is the roll-up alone
        $this->givenTreeAndMessages([
            self::fixtureNode(0, 0, 9, self::CONVERSATION_ID),
            self::fixtureNode(0, 20, 29, self::CONVERSATION_ID),
            self::fixtureNode(1, 0, 29, self::CONVERSATION_ID),
        ], 34);

        $report = $this->validate(29);

        self::assertFalse($report['allPass']);
        self::assertSame(self::expectedChecks(29, 5, 2, [
            'leavesTileContiguouslyFromZero' => ['pass' => false],
            'rollUpsSpanContiguousChildren' => ['pass' => false],
        ]), $report['checks']);
        self::assertSame(['totalNodes' => 3, 'coverNodesLoaded' => 1, 'frontierNodesLoaded' => 1, 'totalMessages' => 35, 'tailMessagesLoaded' => 5], $report['efficiency']);
    }

    public function testAnEmptyTreePassesWithANullEdge(): void
    {
        $this->givenTreeAndMessages([], 4);

        $report = $this->validate(null);

        self::assertSame([
            'conversationId' => self::CONVERSATION_ID,
            'allPass' => true,
            // the edge is null, the tail is the whole conversation (read after -1)
            'checks' => self::expectedChecks(null, 5, 0),
            'efficiency' => ['totalNodes' => 0, 'coverNodesLoaded' => 0, 'frontierNodesLoaded' => 0, 'totalMessages' => 5, 'tailMessagesLoaded' => 5],
        ], $report);
        self::assertSame('{"pass":true,"edge":null}', json_encode($report['checks']['coverEdgeConsistent']), 'a null edge is serialized, not dropped');
    }

    public function testALeafMarkedAsARollUpFailsTheRangeAndLevelCheck(): void
    {
        $leafMarkedAsRollUp = self::fixtureNode(0, 0, 9, self::CONVERSATION_ID);
        $leafMarkedAsRollUp->fromRawMessages = false;
        $this->givenTreeAndMessages([$leafMarkedAsRollUp, self::fixtureNode(0, 10, 19, self::CONVERSATION_ID)], 24);

        $report = $this->validate(19);

        self::assertFalse($report['allPass']);
        self::assertSame(self::expectedChecks(19, 5, 2, ['everyNodeRangeAndLevelValid' => ['pass' => false]]), $report['checks']);
    }

    public function testAnInvertedRangeIsInvalidAndSkippedByTheCover(): void
    {
        // [12-10] covers no position: the cover never picks it; the range check fails on it
        $this->givenTreeAndMessages([
            self::fixtureNode(0, 0, 9, self::CONVERSATION_ID),
            self::fixtureNode(0, 12, 10, self::CONVERSATION_ID),
        ], 14);

        $report = $this->validate(9);

        self::assertFalse($report['allPass']);
        self::assertSame(self::expectedChecks(9, 5, 2, [
            'everyNodeRangeAndLevelValid' => ['pass' => false],
            'leavesTileContiguouslyFromZero' => ['pass' => false],
        ]), $report['checks']);
    }

    public function testANegativeLevelIsInvalid(): void
    {
        $nodeBelowTheLeaves = self::fixtureNode(-1, 10, 19, self::CONVERSATION_ID);
        $this->givenTreeAndMessages([self::fixtureNode(0, 0, 9, self::CONVERSATION_ID), $nodeBelowTheLeaves], 24);

        $report = $this->validate(19);

        self::assertFalse($report['allPass']);
        self::assertSame(self::expectedChecks(19, 5, 1, ['everyNodeRangeAndLevelValid' => ['pass' => false]]), $report['checks']);
    }

    public function testLeavesThatDoNotStartAtZeroCoverNothing(): void
    {
        // the cover starts at index 0: leaves from 5 leave it empty (no edge, the whole conversation is the tail)
        $this->givenTreeAndMessages([
            self::fixtureNode(0, 5, 9, self::CONVERSATION_ID),
            self::fixtureNode(0, 10, 19, self::CONVERSATION_ID),
        ], 24);

        $report = $this->validate(null);

        self::assertFalse($report['allPass']);
        self::assertSame(self::expectedChecks(null, 25, 2, ['leavesTileContiguouslyFromZero' => ['pass' => false]]), $report['checks']);
        self::assertSame(['totalNodes' => 2, 'coverNodesLoaded' => 0, 'frontierNodesLoaded' => 2, 'totalMessages' => 25, 'tailMessagesLoaded' => 25], $report['efficiency']);
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Bounded reads that DISAGREE with the full scan (a valid tree)
    // ---------------------------------------------------------------------------------------------------------------

    public function testACoverReadThatMissesTheNewestCoverNode(): void
    {
        $this->givenTreeAndMessages(self::validTree(), 34);
        $fullCover = self::fixtureNodeSet(self::validTree())->buildCover()->getCoverNodes();
        $this->nodesServiceDouble->coverNodes = self::fixtureNodeSet(array_slice($fullCover, 0, 2));

        $report = $this->validate(29);

        self::assertFalse($report['allPass']);
        // the reported edge is the FULL-SCAN edge, not the bounded read's (24)
        self::assertSame(self::expectedChecks(29, 5, 6, [
            'coverDbMatchesFullScan' => ['pass' => false],
            'coverEdgeConsistent' => ['pass' => false],
        ]), $report['checks']);
        self::assertSame(2, $report['efficiency']['coverNodesLoaded']);
    }

    public function testACoverReadInAnotherOrderDoesNotMatch(): void
    {
        $this->givenTreeAndMessages(self::validTree(), 34);
        $fullCover = self::fixtureNodeSet(self::validTree())->buildCover()->getCoverNodes();
        $this->nodesServiceDouble->coverNodes = self::fixtureNodeSet(array_reverse($fullCover));

        $report = $this->validate(29);

        // the cover is compared in its order (unlike the frontier); its last node still carries the edge
        self::assertSame(self::expectedChecks(29, 5, 6, ['coverDbMatchesFullScan' => ['pass' => false]]), $report['checks']);
    }

    public function testTheFrontierComparisonIgnoresOrderButNotAMissingNode(): void
    {
        $this->givenTreeAndMessages(self::validTree(), 34);
        $frontierNewestFirst = [
            self::fixtureNode(0, 25, 29, self::CONVERSATION_ID),
            self::fixtureNode(0, 20, 24, self::CONVERSATION_ID),
            self::fixtureNode(1, 0, 19, self::CONVERSATION_ID),
        ];
        $this->nodesServiceDouble->exposedNodes = self::fixtureNodeSet($frontierNewestFirst);

        self::assertTrue($this->validate(29)['checks']['frontierDbMatchesFullScan']['pass'], 'the frontier is compared sorted by level and range — other objects of the same shape pass');

        $this->nodesServiceDouble->calls = [];
        $this->messagesServiceDouble->calls = [];
        $this->nodesServiceDouble->exposedNodes = self::fixtureNodeSet(array_slice($frontierNewestFirst, 1));
        $report = $this->validate(29);

        self::assertSame(self::expectedChecks(29, 5, 6, ['frontierDbMatchesFullScan' => ['pass' => false]]), $report['checks']);
        self::assertSame(2, $report['efficiency']['frontierNodesLoaded']);
    }

    public function testATailReadThatLosesARow(): void
    {
        $this->givenTreeAndMessages(self::validTree(), 34);
        $this->messagesServiceDouble->tailMessages = [
            self::fixtureIndexedMessage(30),
            self::fixtureIndexedMessage(31),
            self::fixtureIndexedMessage(33),
            self::fixtureIndexedMessage(34),
        ];

        $report = $this->validate(29);

        self::assertSame(self::expectedChecks(29, 4, 6, ['tailMatchesEdge' => ['pass' => false]]), $report['checks']);
        self::assertSame(['totalNodes' => 7, 'coverNodesLoaded' => 3, 'frontierNodesLoaded' => 3, 'totalMessages' => 35, 'tailMessagesLoaded' => 4], $report['efficiency']);
    }

    public function testATailReadInAnotherOrderIsSortedFirst(): void
    {
        $this->givenTreeAndMessages(self::validTree(), 34);
        $this->messagesServiceDouble->tailMessages = [
            self::fixtureIndexedMessage(34),
            self::fixtureIndexedMessage(30),
            self::fixtureIndexedMessage(32),
            self::fixtureIndexedMessage(31),
            self::fixtureIndexedMessage(33),
        ];

        self::assertTrue($this->validate(29)['allPass'], 'the indexes of both reads are sorted before they are compared');
    }

    public function testARowMissingRightAfterTheEdgeIsALossBetweenCoverAndTail(): void
    {
        // index 30 is absent from BOTH reads: the tail equals "every row past the edge", but it starts at 31
        $this->givenTreeAndMessages(self::validTree(), 34, missingMessageIndexes: [30]);

        $report = $this->validate(29);

        self::assertSame(self::expectedChecks(29, 4, 6, ['nothingLostBetweenCoverAndTail' => ['pass' => false]]), $report['checks']);
        self::assertSame(34, $report['efficiency']['totalMessages']);
    }

    public function testRowsWithoutAnIndexAreNotCounted(): void
    {
        $this->givenTreeAndMessages(self::validTree(), 34);
        $rowWithoutIndex = self::fixtureIndexedMessage(0);
        $rowWithoutIndex->indexInConversation = null;
        $this->messagesServiceDouble->allMessages[] = $rowWithoutIndex;

        $report = $this->validate(29);

        self::assertTrue($report['allPass']);
        self::assertSame(35, $report['efficiency']['totalMessages'], 'the row without an index is skipped');
        self::assertSame(5, $report['efficiency']['tailMessagesLoaded']);
    }

    public function testATransientConversationAndANullFullSet(): void
    {
        // no id → every read is asked for conversation 0; a null full set becomes a new empty set, never installed on
        // the conversation (see validate())
        $this->nodesServiceDouble->allNodes = null;
        $this->messagesServiceDouble->allMessages = [self::fixtureIndexedMessage(0), self::fixtureIndexedMessage(1)];

        $report = $this->validate(null, conversationId: 0);

        self::assertSame([
            'conversationId' => 0,
            'allPass' => true,
            'checks' => self::expectedChecks(null, 2, 0),
            'efficiency' => ['totalNodes' => 0, 'coverNodesLoaded' => 0, 'frontierNodesLoaded' => 0, 'totalMessages' => 2, 'tailMessagesLoaded' => 2],
        ], $report);
    }

    public function testTheLoadedConversationKeepsTheNodeSetItHad(): void
    {
        $this->givenTreeAndMessages(self::validTree(), 34);
        $conversation = self::fixtureConversation(self::CONVERSATION_ID);
        $coverOnlyNodeSet = self::fixtureNodeSet([self::fixtureNode(1, 0, 19, self::CONVERSATION_ID)]);
        $conversation->compactionNodes = $coverOnlyNodeSet;

        $this->compactionService()->validateCompactionConsistency($conversation);

        self::assertSame($coverOnlyNodeSet, $conversation->compactionNodes, 'the cover-only set the turn left stays');
        self::assertCount(1, $conversation->compactionNodes->getElements(), 'with its own node, not the full tree');
        self::assertSame(['findAllNodesForConversation', 'findCoverNodesForConversation', 'findExposedNodesForConversation'], $this->nodesServiceDouble->calledMethodNames());
        self::assertSame(['findAllMessagesForConversationInOrder', 'findRawTailMessagesAfterIndex'], $this->messagesServiceDouble->calledMethodNames());
    }

    /** The container answers both reads with this test's doubles (the node set and the conversation reach them through it). */
    public function testTheContainerAnswersTheDoubles(): void
    {
        self::assertSame($this->nodesServiceDouble, AIConversationCompactionNodes::getService());
        self::assertSame($this->messagesServiceDouble, DDDService::instance()->getService(AIConversationMessagesService::class));
        self::assertSame([], $this->nodesServiceDouble->calls, 'resolving the double is not a read');
    }
}
