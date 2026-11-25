<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeKeys;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeStep;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\TraceEvents\DBAIConversationTraceEventModel;
use DDD\Domain\AI\Repo\DB\Voice\Calls\DBAgentVoiceCallModel;
use DDD\Domain\AI\Services\Eval\AIConversationEvalPurgeService;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Tests\Agents\Voice\Support\AgentVoiceTestConfiguration;

/**
 * The evaluation purge and the voice call table. The table is optional: it exists only where voice is switched on and
 * calls are kept in it. So the two conversation pointers of a call are purge steps exactly while the table is in use,
 * and listed as deliberately no step otherwise — a statement on a table that need not exist would fail the purge of
 * every application without voice.
 */
class AIConversationEvalPurgeVoiceCallTableTest extends UnitTestCase
{
    /** @var array<string, mixed>|null The config values before a test declared a voice configuration */
    protected ?array $configValuesBefore = null;

    protected function tearDown(): void
    {
        if ($this->configValuesBefore !== null) {
            AgentVoiceTestConfiguration::restoreConfigValues($this->configValuesBefore);
            $this->configValuesBefore = null;
        }
        parent::tearDown();
    }

    public function testWithVoiceSwitchedOffNoStatementTouchesTheCallTableAndBothPointersAreListedWithTheReason(): void
    {
        $aiConversationEvalPurgeService = new AIConversationEvalPurgeService();

        self::assertSame([], $this->findStepKeysOfTheCallTable($aiConversationEvalPurgeService));
        $columnsNotPurged = $aiConversationEvalPurgeService->getColumnsNotPurged();
        foreach (['aiConversationId', 'startedByAgentInConversationId'] as $pointerColumnName) {
            $tableColumn = DBAgentVoiceCallModel::getTableName() . '.' . $pointerColumnName;
            self::assertArrayHasKey($tableColumn, $columnsNotPurged);
            self::assertStringContainsString('the call table is not in use', $columnsNotPurged[$tableColumn]);
        }
    }

    public function testWithCallsKeptInTheCallTableTheCallsOfAConversationGoAndTheStartPointerIsEmptied(): void
    {
        $this->configValuesBefore = AgentVoiceTestConfiguration::declareAgentVoiceConfiguration(AgentVoiceTestConfiguration::buildSwitchedOnConfiguration());
        $aiConversationEvalPurgeService = new AIConversationEvalPurgeService();
        $callTableName = (string)DBAgentVoiceCallModel::getTableName();

        self::assertSame(
            [
                AIConversationEvalPurgeStep::buildStepKey(AIConversationEvalPurgeStep::ACTION_DELETE, $callTableName, 'aiConversationId'),
                AIConversationEvalPurgeStep::buildStepKey(AIConversationEvalPurgeStep::ACTION_NULLIFY, $callTableName, 'startedByAgentInConversationId'),
            ],
            $this->findStepKeysOfTheCallTable($aiConversationEvalPurgeService)
        );
        foreach ($aiConversationEvalPurgeService->buildPurgeSteps()->getElements() as $purgeStep) {
            if ($purgeStep->tableName === $callTableName) {
                self::assertSame(AIConversationEvalPurgeKeys::KIND_CONVERSATION, $purgeStep->keyKind);
            }
        }
        $columnsNotPurged = $aiConversationEvalPurgeService->getColumnsNotPurged();
        self::assertArrayNotHasKey($callTableName . '.aiConversationId', $columnsNotPurged);
        self::assertArrayNotHasKey($callTableName . '.startedByAgentInConversationId', $columnsNotPurged);
    }

    public function testTheCallsGoBeforeTheConversationsAndAfterTheirTraceEvents(): void
    {
        $this->configValuesBefore = AgentVoiceTestConfiguration::declareAgentVoiceConfiguration(AgentVoiceTestConfiguration::buildSwitchedOnConfiguration());
        $stepKeysInOrder = (new AIConversationEvalPurgeService())->buildPurgeSteps()->getStepKeys();
        $delete = AIConversationEvalPurgeStep::ACTION_DELETE;

        $positionOfTheCalls = array_search(AIConversationEvalPurgeStep::buildStepKey($delete, (string)DBAgentVoiceCallModel::getTableName(), 'aiConversationId'), $stepKeysInOrder, true);
        $positionOfTheTraceEvents = array_search(AIConversationEvalPurgeStep::buildStepKey($delete, (string)DBAIConversationTraceEventModel::getTableName(), 'aiConversationId'), $stepKeysInOrder, true);
        $positionOfTheConversations = array_search(AIConversationEvalPurgeStep::buildStepKey($delete, (string)DBAIConversationModel::getTableName(), 'id'), $stepKeysInOrder, true);

        self::assertIsInt($positionOfTheCalls);
        self::assertGreaterThan($positionOfTheTraceEvents, $positionOfTheCalls);
        self::assertLessThan($positionOfTheConversations, $positionOfTheCalls);
        self::assertSame(count($stepKeysInOrder) - 1, $positionOfTheConversations, 'the conversations stay the last step');
    }

    public function testWithCallsKeptInAStoreOfTheApplicationTheCallTableIsNotTouched(): void
    {
        $agentVoiceConfiguration = AgentVoiceTestConfiguration::buildSwitchedOnConfiguration();
        $agentVoiceConfiguration['callStore'] = 'storeOfTheApplication';
        $this->configValuesBefore = AgentVoiceTestConfiguration::declareAgentVoiceConfiguration($agentVoiceConfiguration);
        $aiConversationEvalPurgeService = new AIConversationEvalPurgeService();

        self::assertSame([], $this->findStepKeysOfTheCallTable($aiConversationEvalPurgeService));
        self::assertArrayHasKey(DBAgentVoiceCallModel::getTableName() . '.aiConversationId', $aiConversationEvalPurgeService->getColumnsNotPurged());
    }

    /** @return string[] the keys of the steps on the call table, in step order */
    protected function findStepKeysOfTheCallTable(AIConversationEvalPurgeService $aiConversationEvalPurgeService): array
    {
        $stepKeysOfTheCallTable = [];
        foreach ($aiConversationEvalPurgeService->buildPurgeSteps()->getElements() as $purgeStep) {
            if ($purgeStep->tableName === (string)DBAgentVoiceCallModel::getTableName()) {
                $stepKeysOfTheCallTable[] = $purgeStep->getStepKey();
            }
        }
        return $stepKeysOfTheCallTable;
    }
}
