<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunFields;
use DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunRecording;
use DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunScope;
use DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunSession;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\AI\Services\Eval\AgentRecordedRunsService;
use DDD\Domain\Common\Repo\DB\Accounts\DBAccountModel;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\DatabaseTestCase;

/**
 * A recorded run on the database, on MariaDB and on PostgreSQL: the session reads the high-water marks of every table,
 * the rows the run creates are read back in its scope (the tree, then the space and the account), the recording holds
 * the normalized snapshot, and a replay that creates the same rows under other ids is identical. A replay whose tools
 * run live reads the ids it created so far through the tree of its root conversation. Every row of a run gets its id
 * from the database (an explicit id moves no PostgreSQL sequence, and the marks are read from the next ids).
 */
class AgentRecordedRunSessionDatabaseTest extends DatabaseTestCase
{
    public const int ACCOUNT_ID = 3;

    public const int AGENT_SPACE_ID = 7;


    protected function setUp(): void
    {
        parent::setUp();
        $this->insertRow(DBAccountModel::getTableName(), ['id' => self::ACCOUNT_ID, 'email' => 'owner@example.test']);
        $this->insertAgentSpaceRow(['id' => self::AGENT_SPACE_ID, 'accountId' => self::ACCOUNT_ID]);
    }

    protected function getAgentRecordedRunsService(): AgentRecordedRunsService
    {
        /** @var AgentRecordedRunsService $agentRecordedRunsService */
        $agentRecordedRunsService = DDDService::instance()->getService(AgentRecordedRunsService::class);
        return $agentRecordedRunsService;
    }

    public function testARunRecordedAndReplayedOnOtherIdsIsIdentical(): void
    {
        $recordingSession = $this->getAgentRecordedRunsService()->startSession(null);
        $recordedRootConversation = $this->driveRun($recordingSession);
        $agentRecordedRunRecording = $this->buildRecordingOfRun($recordingSession, $recordedRootConversation);

        self::assertSame(['agentSpace' => self::AGENT_SPACE_ID, 'rootConversation' => (int)$recordedRootConversation->id], $agentRecordedRunRecording->getScopeIds());
        $createdIdsByTable = $agentRecordedRunRecording->getCreatedIdRead()->getIdsByTable();
        self::assertSame([(int)$recordedRootConversation->id], array_map('intval', $createdIdsByTable[DBAIConversationModel::getTableName()] ?? []));
        self::assertSame($this->findMessageIdsOfConversation((int)$recordedRootConversation->id), array_map('intval', $createdIdsByTable[DBAIConversationMessageModel::getTableName()] ?? []));
        self::assertSame(['conversations' => 1, 'messages' => 2], array_intersect_key($agentRecordedRunRecording->getSnapshot()->countRowsBySection(), ['conversations' => 0, 'messages' => 0]));

        $replaySession = $this->getAgentRecordedRunsService()->startSession(AgentRecordedRunRecording::fromStoredRecording($agentRecordedRunRecording->toArray()));
        $replayRootConversation = $this->driveRun($replaySession);
        $agentRecordedRunComparison = $replaySession->compareWithRecording($replayRootConversation, ['agentSpace' => self::AGENT_SPACE_ID, 'rootConversation' => (int)$replayRootConversation->id]);

        self::assertTrue($agentRecordedRunComparison->isIdentical(), implode("\n", $agentRecordedRunComparison->differences));
    }

    public function testAReplayThatWritesAnotherRowDiffers(): void
    {
        $recordingSession = $this->getAgentRecordedRunsService()->startSession(null);
        $agentRecordedRunRecording = $this->buildRecordingOfRun($recordingSession, $this->driveRun($recordingSession));

        $replaySession = $this->getAgentRecordedRunsService()->startSession($agentRecordedRunRecording);
        $replayRootConversation = $this->driveRun($replaySession, 'Another answer');
        $agentRecordedRunComparison = $replaySession->compareWithRecording($replayRootConversation, ['agentSpace' => self::AGENT_SPACE_ID, 'rootConversation' => (int)$replayRootConversation->id]);

        self::assertFalse($agentRecordedRunComparison->isIdentical());
    }

    public function testALiveToolsReplayReadsTheIdsItCreatedSoFarThroughItsTree(): void
    {
        $recordingSession = $this->getAgentRecordedRunsService()->startSession(null, toolsRunLive: true);
        $agentRecordedRunRecording = $this->buildRecordingOfRun($recordingSession, $this->driveRun($recordingSession));
        $replaySession = $this->getAgentRecordedRunsService()->startSession($agentRecordedRunRecording);
        $replaySession->setScope(AgentRecordedRunScope::forScopeIds(['agentSpace' => self::AGENT_SPACE_ID, 'account' => self::ACCOUNT_ID], ['account', 'agentSpace']));
        $replaySession->start();
        try {
            $replayRootConversationId = $this->insertConversationRow();
            $this->insertMessageRow($replayRootConversationId, 0, AIConversationMessage::ROLE_USER, 'Hello');
            $replaySession->setRootConversation($this->buildConversation($replayRootConversationId));

            $replayCreatedIdsByTable = $replaySession->findReplayCreatedIdsByTable();
        } finally {
            $replaySession->stop(runRemainingMessages: false);
        }

        self::assertSame($this->findMessageIdsOfConversation($replayRootConversationId), array_map('intval', $replayCreatedIdsByTable[DBAIConversationMessageModel::getTableName()] ?? []));
        self::assertSame([$replayRootConversationId], array_map('intval', $replayCreatedIdsByTable[DBAIConversationModel::getTableName()] ?? []));
    }

    protected function buildRecordingOfRun(AgentRecordedRunSession $agentRecordedRunSession, AIConversation $rootConversation): AgentRecordedRunRecording
    {
        return $agentRecordedRunSession->buildRecording(
            $rootConversation,
            ['agentSpace' => self::AGENT_SPACE_ID, 'rootConversation' => (int)$rootConversation->id],
            AgentRecordedRunFields::fromValuesByField(['harness' => 'app:test:recorded-run', 'turns' => ['Hello']])
        );
    }

    /** One run in the space: the root conversation and two messages, created between start and stop. */
    protected function driveRun(AgentRecordedRunSession $agentRecordedRunSession, string $answer = 'Hello back'): AIConversation
    {
        $agentRecordedRunSession->setScope(AgentRecordedRunScope::forScopeIds(['agentSpace' => self::AGENT_SPACE_ID, 'account' => self::ACCOUNT_ID], ['account', 'agentSpace']));
        $agentRecordedRunSession->start();
        try {
            $agentRecordedRunSession->beginTurn(1);
            $rootConversation = $this->buildConversation($this->insertConversationRow());
            $agentRecordedRunSession->setRootConversation($rootConversation);
            $this->insertMessageRow((int)$rootConversation->id, 0, AIConversationMessage::ROLE_USER, 'Hello');
            $this->insertMessageRow((int)$rootConversation->id, 1, AIConversationMessage::ROLE_ASSISTANT, $answer);
            $agentRecordedRunSession->runQueuedMessages();
        } finally {
            $agentRecordedRunSession->stop(runRemainingMessages: false);
        }
        return $rootConversation;
    }

    /** @return int the id the database gave the new root conversation of the space */
    protected function insertConversationRow(): int
    {
        $this->insertRow(DBAIConversationModel::getTableName(), ['agentSpaceId' => self::AGENT_SPACE_ID, 'accountId' => self::ACCOUNT_ID]);
        $sqlDialect = $this->getSqlDialect();
        return (int)$this->getDatabaseConnection()->fetchOne('SELECT MAX(' . $sqlDialect->quote('id') . ') FROM ' . $sqlDialect->quote(DBAIConversationModel::getTableName()));
    }

    /** @return int[] the ids of the messages of the conversation, ascending */
    protected function findMessageIdsOfConversation(int $conversationId): array
    {
        $sqlDialect = $this->getSqlDialect();
        return array_map('intval', $this->getDatabaseConnection()->fetchFirstColumn(
            'SELECT ' . $sqlDialect->quote('id') . ' FROM ' . $sqlDialect->quote(DBAIConversationMessageModel::getTableName())
            . ' WHERE ' . $sqlDialect->quote('aiConversationId') . ' = ? ORDER BY ' . $sqlDialect->quote('id'),
            [$conversationId]
        ));
    }

    protected function insertMessageRow(int $conversationId, int $indexInConversation, string $role, string $text): void
    {
        $this->insertRow(DBAIConversationMessageModel::getTableName(), [
            'aiConversationId' => $conversationId,
            'indexInConversation' => $indexInConversation,
            'messageType' => AIConversationMessage::MESSAGE_TEXT,
            'role' => $role,
            'message' => $text,
        ]);
    }

    protected function buildConversation(int $conversationId): AIConversation
    {
        $conversation = AIConversation::newInstance();
        $conversation->id = $conversationId;
        return $conversation;
    }
}
