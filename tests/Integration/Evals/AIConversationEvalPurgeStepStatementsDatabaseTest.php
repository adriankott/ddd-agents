<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Evals;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeKeys;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeStep;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Tests\Agents\Support\DatabaseTestCase;
use Doctrine\DBAL\ArrayParameterType;

/**
 * The statements of a purge step against the database, on both engines: a write touches at most its row limit of the
 * rows whose column holds one of the ids, never a row outside the id list, and repeating it finishes the step; the
 * dry-run count counts exactly those rows.
 */
class AIConversationEvalPurgeStepStatementsDatabaseTest extends DatabaseTestCase
{
    protected const int ROOT_CONVERSATION_ID = 100;

    protected const int OTHER_CONVERSATION_ID = 200;

    protected function setUp(): void
    {
        parent::setUp();
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => self::ROOT_CONVERSATION_ID]);
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => self::OTHER_CONVERSATION_ID]);
        foreach ([101, 102, 103] as $childConversationId) {
            $this->insertRow(DBAIConversationModel::getTableName(), ['id' => $childConversationId, 'rootConversationId' => self::ROOT_CONVERSATION_ID]);
        }
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => 201, 'rootConversationId' => self::OTHER_CONVERSATION_ID]);
        foreach ([[901, self::ROOT_CONVERSATION_ID, 0], [902, self::ROOT_CONVERSATION_ID, 1], [903, self::ROOT_CONVERSATION_ID, 2], [904, self::OTHER_CONVERSATION_ID, 0]] as [$messageId, $conversationId, $indexInConversation]) {
            $this->insertRow(DBAIConversationMessageModel::getTableName(), [
                'id' => $messageId,
                'aiConversationId' => $conversationId,
                'indexInConversation' => $indexInConversation,
                'messageType' => AIConversationMessage::MESSAGE_TEXT,
                'role' => AIConversationMessage::ROLE_ASSISTANT,
                'message' => 'an answer',
                'created' => '2026-03-01 08:00:00',
            ]);
        }
    }

    public function testADeleteWritesAtMostItsLimitInsideTheIdListAndTheCountMatches(): void
    {
        $messagesStep = AIConversationEvalPurgeStep::deleteRows(DBAIConversationMessageModel::getTableName(), 'aiConversationId', AIConversationEvalPurgeKeys::KIND_CONVERSATION, hasHeavyRows: true);

        self::assertSame(3, $this->countStepRows($messagesStep, [self::ROOT_CONVERSATION_ID]));
        self::assertSame(2, $this->executeStepWrite($messagesStep, [self::ROOT_CONVERSATION_ID], 2));
        self::assertSame(1, $this->executeStepWrite($messagesStep, [self::ROOT_CONVERSATION_ID], 2), 'the repeat finishes the step');
        self::assertSame(0, $this->executeStepWrite($messagesStep, [self::ROOT_CONVERSATION_ID], 2));
        self::assertNotNull($this->findStoredRow(DBAIConversationMessageModel::getTableName(), 904), 'a row outside the id list stays');
        self::assertNull($this->findStoredRow(DBAIConversationMessageModel::getTableName(), 901));
    }

    public function testANullifyClearsAtMostItsLimitOfPointersAndKeepsTheRows(): void
    {
        $rootPointerStep = AIConversationEvalPurgeStep::nullifyColumn(DBAIConversationModel::getTableName(), 'rootConversationId', AIConversationEvalPurgeKeys::KIND_CONVERSATION);

        self::assertSame(3, $this->countStepRows($rootPointerStep, [self::ROOT_CONVERSATION_ID]));
        self::assertSame(2, $this->executeStepWrite($rootPointerStep, [self::ROOT_CONVERSATION_ID], 2));
        self::assertSame(1, $this->executeStepWrite($rootPointerStep, [self::ROOT_CONVERSATION_ID], 2));
        self::assertSame(0, $this->countStepRows($rootPointerStep, [self::ROOT_CONVERSATION_ID]));
        foreach ([101, 102, 103] as $childConversationId) {
            $storedChild = $this->findStoredRow(DBAIConversationModel::getTableName(), $childConversationId);
            self::assertNotNull($storedChild, 'a nullify keeps the row');
            self::assertNull($storedChild['rootConversationId']);
        }
        self::assertSame(self::OTHER_CONVERSATION_ID, (int)$this->findStoredRow(DBAIConversationModel::getTableName(), 201)['rootConversationId']);
    }

    /** @param int[] $ids */
    protected function executeStepWrite(AIConversationEvalPurgeStep $step, array $ids, int $rowLimit): int
    {
        return (int)$this->getDatabaseConnection()->executeStatement(
            $step->buildWriteSql($rowLimit, $this->getSqlDialect()),
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER]
        );
    }

    /** @param int[] $ids */
    protected function countStepRows(AIConversationEvalPurgeStep $step, array $ids): int
    {
        return (int)$this->getDatabaseConnection()->fetchOne(
            $step->buildCountSql($this->getSqlDialect()),
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER]
        );
    }
}
