<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeKeys;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeSettings;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeStep;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeSteps;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\AI\Repo\DB\Conversations\TurnEvents\DBAIConversationTurnEventModel;

/**
 * The purge as an application extends it: two tables of its own (a record of a conversation with its child rows and a
 * report that keeps a message pointer), a key kind of its own, a marker of its own, accounts whose marked trees only
 * may go, and an order that puts one of its steps between two of the module's.
 */
class AIConversationEvalPurgeServiceOfAnApplication extends AIConversationEvalPurgeServiceRecordingDouble
{
    /** @var string The key kind of the application's records */
    public const string KIND_RECORD = 'record';

    /** @var string The marker of a conversation only an evaluation creates in the application */
    public const string EVAL_MARKER_EVAL_WORKSPACE = 'evalWorkspace';

    public const string RECORD_TABLE_NAME = 'ApplicationRecords';

    public const string RECORD_CHILD_TABLE_NAME = 'ApplicationRecordChildren';

    public const string REPORT_TABLE_NAME = 'ApplicationReports';

    /** @var bool Whether the application interleaves its steps with the module's */
    public bool $interleavesItsSteps = false;

    protected function buildHostDependantPurgeSteps(): AIConversationEvalPurgeSteps
    {
        $hostSteps = AIConversationEvalPurgeSteps::newInstance();
        $this->addPurgeStep($hostSteps, AIConversationEvalPurgeStep::deleteRows(self::RECORD_CHILD_TABLE_NAME, 'recordId', self::KIND_RECORD));
        $this->addPurgeStep($hostSteps, AIConversationEvalPurgeStep::deleteRows(self::RECORD_TABLE_NAME, 'aiConversationId', AIConversationEvalPurgeKeys::KIND_CONVERSATION));
        $this->addPurgeStep($hostSteps, AIConversationEvalPurgeStep::nullifyColumn(self::REPORT_TABLE_NAME, 'sourceMessageId', AIConversationEvalPurgeKeys::KIND_MESSAGE));
        return $hostSteps;
    }

    public function getPurgeStepKeysInOrder(): array
    {
        if (!$this->interleavesItsSteps) {
            return parent::getPurgeStepKeysInOrder();
        }
        $turnEventsStepKey = AIConversationEvalPurgeStep::buildStepKey(AIConversationEvalPurgeStep::ACTION_DELETE, (string)DBAIConversationTurnEventModel::getTableName(), 'aiConversationId');
        // the module's turn events first, then the application's records, then the rest in the default order
        return array_merge([$turnEventsStepKey], $this->buildHostDependantPurgeSteps()->getStepKeys());
    }

    protected function collectHostPurgeKeys(AIConversationEvalPurgeKeys $purgeKeys, AIConversationEvalPurgeSettings $settings): void
    {
        $purgeKeys->setIdsOfKind(self::KIND_RECORD, $this->findIdsWhereColumnIn(self::RECORD_TABLE_NAME, 'aiConversationId', $purgeKeys->getConversationIds(), $settings));
    }

    protected function buildHostEvalMarkerConditionsByMarker(string $conversationAlias): array
    {
        return [
            self::EVAL_MARKER_EVAL_WORKSPACE => $this->quoteColumn($conversationAlias, 'agentSpaceId') . ' IN (SELECT ' . $this->quoteName('id')
                . ' FROM ' . $this->quoteName('ApplicationEvalWorkspaces') . ')',
            // a marker the module already checks keeps the module's condition
            self::EVAL_MARKER_EVAL_RUN => '1 = 0',
        ];
    }

    public function getColumnsNotPurged(): array
    {
        return array_merge(parent::getColumnsNotPurged(), [self::RECORD_CHILD_TABLE_NAME . '.recordId' => 'goes with its record']);
    }

    /** @return string the message table, for the assertions */
    public static function getMessageTableName(): string
    {
        return (string)DBAIConversationMessageModel::getTableName();
    }
}
