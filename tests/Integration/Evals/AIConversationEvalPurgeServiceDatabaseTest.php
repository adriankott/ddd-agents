<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Evals;

use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeKeys;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeSettings;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeStep;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalTree;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\AI\Services\Eval\AIConversationEvalPurgeService;
use DDD\Domain\Common\Repo\DB\Accounts\DBAccountModel;
use DDD\Tests\Agents\Domain\AI\Services\Eval\AIConversationEvalPurgeServiceQueryProbe;
use DDD\Tests\Agents\Support\DatabaseTestCase;

/**
 * The evaluation purge's own reads and its chunk write against the database, on both engines: the candidate roots
 * (eval accounts, age, the evaluation markers, kept agent spaces), the marker of a root, the tree with its run facts
 * and newest message, the row statistics, one write chunk in its own transaction, and a read between the
 * statement-limit pair.
 */
class AIConversationEvalPurgeServiceDatabaseTest extends DatabaseTestCase
{
    protected const int EVAL_ACCOUNT_ID = 7001;

    protected const int OTHER_ACCOUNT_ID = 7002;

    protected const int KEPT_AGENT_SPACE_ID = 31;

    protected const int OTHER_AGENT_SPACE_ID = 32;

    protected const string ACTIVITY_CUTOFF = '2026-06-01 00:00:00';

    protected AIConversationEvalPurgeServiceQueryProbe $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AIConversationEvalPurgeServiceQueryProbe();
        $this->service->configuredEvalAccountIds = [self::EVAL_ACCOUNT_ID];
        $this->insertRow(DBAccountModel::getTableName(), ['id' => self::EVAL_ACCOUNT_ID, 'email' => 'eval@example.test']);
        $this->insertRow(DBAccountModel::getTableName(), ['id' => self::OTHER_ACCOUNT_ID, 'email' => 'other@example.test']);
        foreach ([self::KEPT_AGENT_SPACE_ID, self::OTHER_AGENT_SPACE_ID] as $agentSpaceId) {
            $this->insertAgentSpaceRow(['id' => $agentSpaceId, 'accountId' => self::EVAL_ACCOUNT_ID]);
        }
    }

    /** @param array<string, mixed> $columnValues */
    protected function insertConversation(int $conversationId, array $columnValues = []): void
    {
        $this->insertRow(DBAIConversationModel::getTableName(), $columnValues + [
            'id' => $conversationId,
            'accountId' => self::EVAL_ACCOUNT_ID,
            'runState' => AIConversationRunState::IDLE,
            'created' => '2026-01-01 00:00:00',
            // set by hand: a default of the schema would stamp the time of the test as activity
            'updated' => '2026-01-01 00:00:00',
        ]);
    }

    protected function insertMessage(int $messageId, int $conversationId, int $indexInConversation, string $created): void
    {
        $this->insertRow(DBAIConversationMessageModel::getTableName(), [
            'id' => $messageId,
            'aiConversationId' => $conversationId,
            'indexInConversation' => $indexInConversation,
            'messageType' => AIConversationMessage::MESSAGE_TEXT,
            'role' => AIConversationMessage::ROLE_ASSISTANT,
            'message' => 'an answer',
            'created' => $created,
        ]);
    }

    public function testTheCandidatesAreOldRootsOfTheEvalAccountsOutsideTheKeptSpaces(): void
    {
        $this->insertConversation(101);
        $this->insertConversation(102, ['agentSpaceId' => self::KEPT_AGENT_SPACE_ID]);
        $this->insertConversation(103, ['agentSpaceId' => self::OTHER_AGENT_SPACE_ID]);
        $this->insertConversation(104, ['accountId' => self::OTHER_ACCOUNT_ID]);
        $this->insertConversation(105, ['created' => '2026-07-01 00:00:00']);
        // a delegation child of a root that still exists, a supervisor tandem, and a child whose root row is gone
        $this->insertConversation(106, ['rootConversationId' => 101]);
        $this->insertConversation(107, ['supervisedConversationId' => 101]);
        $this->insertConversation(108, ['rootConversationId' => 999]);
        $settings = AIConversationEvalPurgeSettings::newInstance();
        $settings->keptAgentSpaceIds = [self::KEPT_AGENT_SPACE_ID];

        self::assertSame([101, 103, 108], $this->service->probeCandidateRootConversationIds([self::EVAL_ACCOUNT_ID], self::ACTIVITY_CUTOFF, $settings));

        $settings->onlyRootConversationId = 103;
        self::assertSame([103], $this->service->probeCandidateRootConversationIds([self::EVAL_ACCOUNT_ID], self::ACTIVITY_CUTOFF, $settings));
    }

    public function testAnEvalOnlyRunSelectsOnlyMarkedRootsAndNamesTheirMarker(): void
    {
        $this->insertConversation(201, ['runSettings' => '{"evalRun":true}']);
        $this->insertConversation(202, ['runSettings' => '{"evalSeededHistory":true,"evalRun":false}']);
        $this->insertConversation(203, ['runSettings' => '{"evalRun":false}']);
        $this->insertConversation(204);
        $settings = AIConversationEvalPurgeSettings::newInstance();
        $settings->evalOnly = true;

        self::assertSame([201, 202], $this->service->probeCandidateRootConversationIds([self::EVAL_ACCOUNT_ID], self::ACTIVITY_CUTOFF, $settings));
        $evalMarkersByRootConversationId = $this->service->probeEvalMarkersOfRootConversations([201, 202, 203, 204]);
        ksort($evalMarkersByRootConversationId);
        self::assertSame(
            [201 => AIConversationEvalPurgeService::EVAL_MARKER_EVAL_RUN, 202 => AIConversationEvalPurgeService::EVAL_MARKER_SEEDED_HISTORY, 203 => null, 204 => null],
            $evalMarkersByRootConversationId
        );
    }

    public function testTheTreeHoldsItsMembersTheirRunFactsAndTheNewestMessage(): void
    {
        $this->insertConversation(301, ['updated' => '2026-02-01 10:00:00']);
        $this->insertConversation(302, ['rootConversationId' => 301]);
        $this->insertConversation(303, ['supervisedConversationId' => 302, 'runHeartbeatAt' => '2026-02-03 09:00:00']);
        $this->insertConversation(304, ['rootConversationId' => 302, 'runState' => AIConversationRunState::RUNNING]);
        $this->insertConversation(399);
        $this->insertMessage(3901, 301, 0, '2026-02-04 08:00:00');
        $this->insertMessage(3902, 302, 0, '2026-02-05 08:00:00');

        $tree = $this->service->findEvalTree(301, AIConversationEvalPurgeSettings::newInstance());

        $conversationIds = $tree->conversationIds;
        sort($conversationIds);
        self::assertSame([301, 302, 303, 304], $conversationIds, 'the tree expands over roots and supervised conversations, never to another tree');
        self::assertSame(301, $tree->conversationIds[0]);
        self::assertSame(self::EVAL_ACCOUNT_ID, $tree->rootAccountId);
        self::assertTrue($tree->hasRunInFlight);
        self::assertSame('2026-02-03 09:00:00', $tree->newestHeartbeatAt);
        self::assertSame('2026-02-05 08:00:00', $tree->newestActivityAt);
        self::assertSame(AIConversationEvalTree::VERDICT_SKIP_RUN_IN_FLIGHT, $tree->decidePurgeVerdict([self::EVAL_ACCOUNT_ID], self::ACTIVITY_CUTOFF, '2026-06-01 00:00:00'));
    }

    public function testARunTokenCountsAsARunInFlight(): void
    {
        $this->insertConversation(401, ['runToken' => 'a-token']);

        self::assertTrue($this->service->findEvalTree(401, AIConversationEvalPurgeSettings::newInstance())->hasRunInFlight);
    }

    public function testTheRowStatisticsNameEveryStepTableTheCatalogKnows(): void
    {
        $averageRowBytesByTableName = $this->service->findAverageRowBytesByTableName($this->service->buildPurgeSteps());

        self::assertArrayHasKey(DBAIConversationModel::getTableName(), $averageRowBytesByTableName);
        foreach ($averageRowBytesByTableName as $averageRowBytes) {
            self::assertGreaterThanOrEqual(0, $averageRowBytes);
        }
    }

    public function testOneWriteChunkCommitsItsRowsAndLeavesNoTransactionOpen(): void
    {
        $this->insertConversation(501);
        foreach ([5001, 5002, 5003] as $messageIndex => $messageId) {
            $this->insertMessage($messageId, 501, $messageIndex, '2026-02-01 08:00:00');
        }
        $messagesStep = AIConversationEvalPurgeStep::deleteRows(DBAIConversationMessageModel::getTableName(), 'aiConversationId', AIConversationEvalPurgeKeys::KIND_CONVERSATION, hasHeavyRows: true);

        self::assertSame(2, $this->service->probeStepChunk($messagesStep, [501], 2));
        self::assertFalse($this->getDatabaseConnection()->isTransactionActive());
        self::assertSame(1, $this->service->probeStepChunk($messagesStep, [501], 2));
        self::assertNull($this->findStoredRow(DBAIConversationMessageModel::getTableName(), 5003));
    }

    public function testAReadBetweenTheStatementLimitPairWorksAndLeavesNoTransactionOpen(): void
    {
        $this->insertConversation(601);
        $connection = $this->getDatabaseConnection();
        $sqlDialect = $this->getSqlDialect();
        $countSql = 'SELECT COUNT(*) FROM ' . $sqlDialect->quote(DBAIConversationModel::getTableName()) . ' WHERE ' . $sqlDialect->quote('id') . ' = ?';

        $this->service->beginStatementLimitedRead();
        try {
            self::assertTrue($connection->isTransactionActive(), 'the read runs in a transaction of its own');
            $storedConversations = (int)$connection->fetchOne($countSql, [601]);
        } finally {
            $this->service->endStatementLimitedRead();
        }

        self::assertSame(1, $storedConversations);
        self::assertFalse($connection->isTransactionActive(), 'nothing stays open after the end');
        self::assertSame(1, (int)$connection->fetchOne($countSql, [601]), 'the connection reads on after the end');
    }

    public function testAFailedReadBetweenThePairLeavesNoTransactionOpenAndTheConnectionUsable(): void
    {
        $this->insertConversation(602);
        $connection = $this->getDatabaseConnection();
        $sqlDialect = $this->getSqlDialect();
        $readFailed = false;

        $this->service->beginStatementLimitedRead();
        try {
            $connection->fetchOne('SELECT ' . $sqlDialect->quote('noSuchColumn') . ' FROM ' . $sqlDialect->quote(DBAIConversationModel::getTableName()));
        } catch (\Doctrine\DBAL\Exception) {
            $readFailed = true;
        } finally {
            $this->service->endStatementLimitedRead();
        }

        self::assertTrue($readFailed);
        self::assertFalse($connection->isTransactionActive());
        self::assertSame(1, (int)$connection->fetchOne(
            'SELECT COUNT(*) FROM ' . $sqlDialect->quote(DBAIConversationModel::getTableName()) . ' WHERE ' . $sqlDialect->quote('id') . ' = ?',
            [602]
        ), 'an aborted read transaction was ended, the next statement runs');
    }

    public function testAWholeWritingRunDeletesAnOldTreeOfAnEvalAccountWithItsMessages(): void
    {
        $this->insertConversation(601);
        $this->insertConversation(602, ['rootConversationId' => 601]);
        $this->insertMessage(6001, 602, 0, '2026-02-01 08:00:00');
        $this->insertConversation(603, ['accountId' => self::OTHER_ACCOUNT_ID]);
        $settings = AIConversationEvalPurgeSettings::newInstance();
        $settings->dryRun = false;
        $settings->pauseMs = 0;

        $report = $this->service->purgeEvalConversations($settings);

        self::assertSame(1, $report->treesPurged);
        self::assertSame(2, $report->conversationsInPurgedTrees);
        self::assertNull($this->findStoredRow(DBAIConversationModel::getTableName(), 601));
        self::assertNull($this->findStoredRow(DBAIConversationModel::getTableName(), 602));
        self::assertNull($this->findStoredRow(DBAIConversationMessageModel::getTableName(), 6001));
        self::assertNotNull($this->findStoredRow(DBAIConversationModel::getTableName(), 603), 'a conversation of another account stays');
    }
}
