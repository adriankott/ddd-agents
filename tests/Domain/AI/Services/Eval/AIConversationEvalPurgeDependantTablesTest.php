<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Repo\DB\Conversations\A2A\DBAIConversationTaskPushNotificationConfigModel;
use DDD\Domain\AI\Repo\DB\Conversations\Agent\DBAIConversationDelegationBatchModel;
use DDD\Domain\AI\Repo\DB\Conversations\Compaction\DBAIConversationCompactionNodeModel;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\Embeddings\DBAIConversationMessageEmbeddingModel;
use DDD\Domain\AI\Repo\DB\Mandates\DBAgentMandateModel;
use DDD\Domain\AI\Services\Eval\AIConversationEvalPurgeService;
use DDD\Tests\Agents\Support\AgentPackageSourceRoots;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * Every column of a generated model of the module that points at a conversation or at one of its rows (messages,
 * delegation batches, tool-call batches, compaction nodes, voice calls, assessments) is covered by the evaluation
 * purge: it is a purge step, or {@see AIConversationEvalPurgeService::getColumnsNotPurged()} names it with the reason.
 * A new table with such a column fails here until it is mapped — a forgotten table would leave rows that point at
 * deleted conversations. An application holds its own models against its own step list the same way.
 */
class AIConversationEvalPurgeDependantTablesTest extends UnitTestCase
{
    /** @var string A model property whose name ends like this points at a conversation or one of its rows. */
    public const string POINTER_PROPERTY_PATTERN = '/public\s+\??int\s+\$([a-zA-Z]*(?:Conversation|Message|CompactionNode|Assessment|VoiceCall|DelegationBatch|ToolCallBatch)Id)\b/';

    public function testEveryConversationPointingColumnIsAPurgeStepOrListedWithAReason(): void
    {
        $coveredColumns = $this->buildColumnsCoveredByPurgeSteps();
        $columnsNotPurged = (new AIConversationEvalPurgeService())->getColumnsNotPurged();
        $foundColumns = $this->findConversationPointingModelColumns();
        $uncoveredColumns = [];
        foreach ($foundColumns as $tableColumn) {
            if (!isset($coveredColumns[$tableColumn]) && !isset($columnsNotPurged[$tableColumn])) {
                $uncoveredColumns[] = $tableColumn;
            }
        }
        self::assertGreaterThan(15, count($foundColumns), 'the model scan found the conversation-pointing columns');
        self::assertSame([], $uncoveredColumns, 'add a purge step to AIConversationEvalPurgeService::buildModulePurgeSteps() or name the column in getColumnsNotPurged() with its reason');
    }

    public function testTheKnownDependantTablesAreSteps(): void
    {
        $coveredColumns = $this->buildColumnsCoveredByPurgeSteps();
        foreach ([
            DBAIConversationCompactionNodeModel::getTableName() . '.aiConversationId',
            DBAIConversationMessageEmbeddingModel::getTableName() . '.aiConversationMessageId',
            DBAIConversationDelegationBatchModel::getTableName() . '.parentConversationId',
            DBAIConversationTaskPushNotificationConfigModel::getTableName() . '.aiConversationId',
            DBAgentMandateModel::getTableName() . '.sourceConversationId',
            DBAIConversationModel::getTableName() . '.id',
        ] as $tableColumn) {
            self::assertArrayHasKey($tableColumn, $coveredColumns, $tableColumn);
        }
    }

    public function testEveryListedExceptionIsStillAColumnOfAModel(): void
    {
        $columnsNotPurged = (new AIConversationEvalPurgeService())->getColumnsNotPurged();
        self::assertSame([], array_values(array_diff(array_keys($columnsNotPurged), $this->findConversationPointingModelColumns())), 'getColumnsNotPurged() names columns no model has any more');
        foreach ($columnsNotPurged as $tableColumn => $reason) {
            self::assertNotSame('', trim($reason), $tableColumn);
        }
    }

    /** @return array<string, true> `table.column` of every purge step */
    protected function buildColumnsCoveredByPurgeSteps(): array
    {
        $coveredColumns = [];
        foreach ((new AIConversationEvalPurgeService())->buildPurgeSteps()->getElements() as $purgeStep) {
            $coveredColumns[$purgeStep->tableName . '.' . $purgeStep->columnName] = true;
        }
        return $coveredColumns;
    }

    /** @return string[] `table.column` of every conversation-pointing property of every generated model of the module */
    protected function findConversationPointingModelColumns(): array
    {
        $tableColumns = [];
        foreach (AgentPackageSourceRoots::findPhpFiles() as $modelFilePath) {
            if (!preg_match('/^DB\w+Model\.php$/', basename($modelFilePath))) {
                continue;
            }
            $modelSource = (string)file_get_contents($modelFilePath);
            if (!preg_match("/TABLE_NAME = '(\\w+)'/", $modelSource, $tableNameMatch)) {
                continue;
            }
            preg_match_all(static::POINTER_PROPERTY_PATTERN, $modelSource, $pointerMatches);
            foreach ($pointerMatches[1] as $pointerColumnName) {
                $tableColumns[] = $tableNameMatch[1] . '.' . $pointerColumnName;
            }
        }
        $tableColumns = array_values(array_unique($tableColumns));
        sort($tableColumns);
        return $tableColumns;
    }
}
