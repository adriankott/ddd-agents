<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals\ConversationPurge;

use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeKeys;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeReport;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeSampleRoot;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeStep;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalTree;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalTreeConversation;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalTreeConversations;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalTrees;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The tallies of one purge run and the console output built from them. The expected lines, headers, summary names
 * and their order are the purge command's output as operators and log searches read it: the progress line after each
 * batch, the step table (label, rows, estimated MB, a total row), the summary table, the account table, the sample
 * table and the closing line.
 */
class AIConversationEvalPurgeReportTest extends UnitTestCase
{
    public function testThePurgedTreesAreCountedPerAccountAndTheFirstTenAreSampledWithTheirMarker(): void
    {
        $report = AIConversationEvalPurgeReport::forRun(true);
        for ($rootConversationId = 1; $rootConversationId <= 12; $rootConversationId++) {
            $report->recordPurgedTree(self::buildTreeOfTwo($rootConversationId, $rootConversationId % 2 === 0 ? 70 : 71), $rootConversationId === 3 ? 'throwawayAgentSpace' : 'evalRun');
        }

        self::assertSame([71 => 6, 70 => 6], $report->treesPurgedByAccountId);
        self::assertSame([71 => 12, 70 => 12], $report->conversationsPurgedByAccountId);
        self::assertSame(10, $report->sampleRootConversations->count());
        $thirdSample = $report->sampleRootConversations->getElements()[2];
        self::assertSame(3, $thirdSample->rootConversationId);
        self::assertSame(71, $thirdSample->accountId);
        self::assertSame(2, $thirdSample->conversations);
        self::assertSame('throwawayAgentSpace', $thirdSample->marker);
        self::assertSame(['3', '71', '2', 'throwawayAgentSpace'], $thirdSample->buildTableCells());
        self::assertSame(['sample root conversation', 'account', 'conversations in tree', 'eval marker'], AIConversationEvalPurgeSampleRoot::TABLE_HEADERS);
    }

    public function testASampleWithoutAccountOrMarkerShowsADash(): void
    {
        $tree = AIConversationEvalTree::fromConversations(5, AIConversationEvalTreeConversations::newInstance(), null);

        self::assertSame(['5', '-', '1', '-'], AIConversationEvalPurgeSampleRoot::forPurgedTree($tree, null)->buildTableCells());
    }

    public function testAFinishedBatchCountsItsTreesTheirConversationsAndTheirMarkers(): void
    {
        $report = AIConversationEvalPurgeReport::forRun(false);
        $trees = AIConversationEvalTrees::newInstance();
        $firstTree = self::buildTreeOfTwo(1, 70);
        $secondTree = self::buildTreeOfTwo(2, 70);
        $trees->add($firstTree, $secondTree);
        $purgeKeys = AIConversationEvalPurgeKeys::newInstance();
        $purgeKeys->setIdsOfKind(AIConversationEvalPurgeKeys::KIND_CONVERSATION, $trees->getConversationIds());

        $report->recordPurgedTrees($trees, $purgeKeys, [2 => 'evalSeededHistory']);

        self::assertSame(2, $report->treesPurged);
        self::assertSame(3, $report->conversationsInPurgedTrees, 'the distinct conversations of the batch: 1, 101 (shared) and 2');
        self::assertSame([70 => 2], $report->treesPurgedByAccountId);
        self::assertSame([null, 'evalSeededHistory'], [$report->sampleRootConversations->getElements()[0]->marker, $report->sampleRootConversations->getElements()[1]->marker]);
    }

    public function testVerdictsAreCountedByKind(): void
    {
        $report = AIConversationEvalPurgeReport::forRun(true);
        foreach ([AIConversationEvalTree::VERDICT_PURGE, AIConversationEvalTree::VERDICT_SKIP_RUN_IN_FLIGHT, AIConversationEvalTree::VERDICT_SKIP_FRESH_HEARTBEAT,
            AIConversationEvalTree::VERDICT_SKIP_RECENT_ACTIVITY, AIConversationEvalTree::VERDICT_SKIP_RECENT_ACTIVITY, AIConversationEvalTree::VERDICT_SKIP_NOT_EVAL_ACCOUNT] as $verdict) {
            $report->recordVerdict($verdict);
        }

        self::assertSame(6, $report->treesChecked);
        self::assertSame([1, 1, 2, 1], [$report->treesSkippedRunInFlight, $report->treesSkippedFreshHeartbeat, $report->treesSkippedRecentActivity, $report->treesSkippedNotEvalAccount]);
    }

    public function testNullifiedRowsCarryNoByteEstimate(): void
    {
        $report = AIConversationEvalPurgeReport::forRun(true);
        $report->addStepRows(AIConversationEvalPurgeStep::nullifyColumn('EntityAccountGoals', 'sourceMessageId', AIConversationEvalPurgeKeys::KIND_MESSAGE), 4, 900);

        self::assertSame(4, $report->getRowsTotal());
        self::assertSame(0, $report->getEstimatedBytesTotal());
        self::assertSame(['EntityAccountGoals.sourceMessageId = NULL', '4', '-'], $report->buildStepTableCells('EntityAccountGoals.sourceMessageId = NULL'));
    }

    public function testWritesKeepTheirAverageAndTheSlowest(): void
    {
        $report = AIConversationEvalPurgeReport::forRun(false);
        $report->recordWrite(100);
        $report->recordWrite(301);

        self::assertSame(2, $report->writes);
        self::assertSame(200, $report->averageWriteMs);
        self::assertSame(301, $report->slowestWriteMs);
        self::assertSame(401, $report->totalWriteMs);
    }

    public function testTheProgressLineAndTheClosingLineOfARun(): void
    {
        $report = self::buildReportOfOneBatch(true);

        self::assertSame(
            '[dry run] roots up to 42: 3 trees checked, 2 purged (5 conversations), 1550 rows, 1.5 MB, average write - ms',
            $report->buildProgressLine()
        );
        self::assertSame('dry run: 2 trees, 1550 rows, stopped because: no eval conversation tree left', $report->buildResultLine());

        $appliedReport = self::buildReportOfOneBatch(false);
        $appliedReport->recordWrite(12);
        $appliedReport->stoppedBecause = AIConversationEvalPurgeReport::STOPPED_TIME_BUDGET;
        self::assertSame('roots up to 42: 3 trees checked, 2 purged (5 conversations), 1550 rows, 1.5 MB, average write 12 ms', $appliedReport->buildProgressLine());
        self::assertSame('2 trees, 1550 rows, stopped because: time budget used up', $appliedReport->buildResultLine());
    }

    public function testTheTablesOfTheConsoleOutput(): void
    {
        $report = self::buildReportOfOneBatch(true);

        self::assertSame(['table / column', 'rows (would)', 'estimated MB'], $report->buildStepTableHeaders());
        self::assertSame(['EntityTempAIConversationMessages', '50', '1.5'], $report->buildStepTableCells('EntityTempAIConversationMessages'));
        self::assertSame(['EntityAIConversationTurnEvents', '1500', '0'], $report->buildStepTableCells('EntityAIConversationTurnEvents'));
        self::assertSame(['TOTAL', '1550', '1.5'], $report->buildStepTableTotalCells());
        self::assertSame(['account', 'trees (would)', 'conversations'], $report->buildAccountTableHeaders());
        self::assertSame(['70', '2', '4'], $report->buildAccountTableCells(70));
        self::assertSame(['', 'value'], $report->buildSummaryTableHeaders());
        self::assertSame([
            'activityCutoff (UTC)' => '2026-03-10 12:00:00',
            'treesChecked' => '3',
            'treesPurged' => '2',
            'conversationsInPurgedTrees' => '5',
            'treesSkippedRunInFlight' => '0',
            'treesSkippedFreshHeartbeat' => '0',
            'treesSkippedRecentActivity' => '1',
            'treesSkippedNotEvalAccount' => '0',
            'batchesLeftIncomplete' => '0',
            'chunksSkippedOnLock' => '0',
            'pausesForServerLoad' => '0',
            'writes' => '0',
            'averageWriteMs' => '-',
            'slowestWriteMs' => '-',
            'lastRootConversationId' => '42',
            'runSeconds' => '0',
            'stoppedBecause' => 'no eval conversation tree left',
        ], $report->buildSummaryValuesByName());

        $appliedReport = self::buildReportOfOneBatch(false);
        self::assertSame(['table / column', 'rows', 'estimated MB'], $appliedReport->buildStepTableHeaders());
        self::assertSame(['account', 'trees', 'conversations'], $appliedReport->buildAccountTableHeaders());
    }

    /** One checked batch: 3 trees, 2 purged with 5 conversations, 1 kept as recent; 50 messages of about 31 KB, 1500 turn events. */
    protected static function buildReportOfOneBatch(bool $dryRun): AIConversationEvalPurgeReport
    {
        $report = AIConversationEvalPurgeReport::forRun($dryRun);
        $report->activityCutoff = '2026-03-10 12:00:00';
        $report->recordVerdict(AIConversationEvalTree::VERDICT_PURGE);
        $report->recordVerdict(AIConversationEvalTree::VERDICT_PURGE);
        $report->recordVerdict(AIConversationEvalTree::VERDICT_SKIP_RECENT_ACTIVITY);
        $report->treesPurged = 2;
        $report->conversationsInPurgedTrees = 5;
        $report->treesPurgedByAccountId = [70 => 2];
        $report->conversationsPurgedByAccountId = [70 => 4];
        $report->lastRootConversationId = 42;
        $report->addStepRows(AIConversationEvalPurgeStep::deleteRows('EntityTempAIConversationMessages', 'id', AIConversationEvalPurgeKeys::KIND_MESSAGE, hasHeavyRows: true), 50, 31458);
        $report->addStepRows(AIConversationEvalPurgeStep::deleteRows('EntityAIConversationTurnEvents', 'aiConversationId', AIConversationEvalPurgeKeys::KIND_CONVERSATION), 1500, 0);
        return $report;
    }

    /** A tree of the root and one child (100 + root), the root on `$accountId`. */
    protected static function buildTreeOfTwo(int $rootConversationId, int $accountId): AIConversationEvalTree
    {
        $treeConversations = AIConversationEvalTreeConversations::newInstance();
        $root = AIConversationEvalTreeConversation::forConversation($rootConversationId, $accountId, null, false);
        $child = AIConversationEvalTreeConversation::forConversation($rootConversationId === 2 ? 101 : 100 + $rootConversationId, 70, null, false);
        $treeConversations->add($root, $child);
        return AIConversationEvalTree::fromConversations($rootConversationId, $treeConversations, null);
    }
}
