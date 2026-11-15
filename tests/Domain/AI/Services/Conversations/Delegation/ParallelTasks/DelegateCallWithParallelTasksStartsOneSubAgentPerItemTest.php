<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Delegation\ParallelTasks;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentDelegationAgentTask;
use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentDelegationToolRunTask;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\DelegateTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\DelegateToolArguments;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentTransientMessageIds;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Skills\AISkills;
use DDD\Domain\AI\Services\Conversations\Agent\AgentChildTurnRunnerInterface;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A `delegate` call whose `parallelTasks` holds several items starts one sub-agent per item, one row per sub-agent: the
 * call's own row carries the first item, every further item a `delegate` row of its own right after it, in the call's
 * emission batch. Each item passes the model's guards on its own — a refused item fails its own row only. A single item
 * is the call itself; the old `tasks` name is never read as parallel tasks.
 */
class DelegateCallWithParallelTasksStartsOneSubAgentPerItemTest extends UnitTestCase
{
    public function testTwoOrMoreItemsAreReadOneTaskPerItemAndTheTopLevelTaskIsNotRead(): void
    {
        $delegateToolArguments = DelegateToolArguments::fromToolCallArguments([
            'task' => 'top-level task',
            DelegateToolArguments::PARALLEL_TASKS_ARGUMENT => [
                ['label' => 'Site A', 'task' => 'Research site A', 'skill_names' => ['Web.Search']],
                ['toolName' => 'readPage', 'toolArguments' => '{"url": "https://example.com"}'],
                'not an object',
            ],
        ]);

        self::assertTrue($delegateToolArguments->hasParallelTasks());
        self::assertNull($delegateToolArguments->task);
        $items = array_values($delegateToolArguments->parallelTaskArguments->getElements());
        self::assertCount(3, $items);
        self::assertSame('Site A', $items[0]->label);
        self::assertInstanceOf(AgentDelegationAgentTask::class, $items[0]->task);
        self::assertInstanceOf(AgentDelegationToolRunTask::class, $items[1]->task);
        self::assertNull($items[2]->task, 'an item that is no object names no task');
    }

    public function testASingleItemIsTheCallItselfAndTheOldTasksNameIsNotParallelTasks(): void
    {
        $singleItem = DelegateToolArguments::fromToolCallArguments([DelegateToolArguments::PARALLEL_TASKS_ARGUMENT => [['label' => 'Only', 'task' => 'Research it']]]);
        self::assertFalse($singleItem->hasParallelTasks());
        self::assertSame('Only', $singleItem->label);
        self::assertInstanceOf(AgentDelegationAgentTask::class, $singleItem->task);

        $oldTasksName = DelegateToolArguments::fromToolCallArguments(['tasks' => [['task' => 'a'], ['task' => 'b']]]);
        self::assertFalse($oldTasksName->hasParallelTasks());
        self::assertNull($oldTasksName->task);
    }

    public function testTheSchemaOffersParallelTasksWhoseItemsTakeTheTaskPropertiesOfACall(): void
    {
        $parameters = DelegateTool::forCatalog(new AISkills())->toOpenAiToolSchema()['function']['parameters'];

        $parallelTasks = $parameters['properties'][DelegateToolArguments::PARALLEL_TASKS_ARGUMENT];
        self::assertSame('array', $parallelTasks['type']);
        $topLevelTaskProperties = $parameters['properties'];
        unset($topLevelTaskProperties[DelegateToolArguments::PARALLEL_TASKS_ARGUMENT]);
        self::assertSame($topLevelTaskProperties, $parallelTasks['items']['properties']);
        self::assertSame(['label', 'task', 'skill_names', 'toolName', 'toolArguments'], array_keys($topLevelTaskProperties));
    }

    public function testEachItemGetsItsOwnRowAndRecordAndTheCallsRowNamesTheOthers(): void
    {
        [$spawnService, $parentConversation, $delegateCallMessage, $answer] = $this->dispatchDelegateCall([
            ['label' => 'Site A', 'task' => 'Research site A'],
            ['label' => 'Site B', 'task' => 'Research site B'],
            ['label' => 'Site C', 'task' => 'Research site C'],
        ]);

        self::assertSame(['Site A', 'Site B', 'Site C'], $spawnService->spawnedLabels);
        $delegateCallRows = $parentConversation->messages->buildToolCallHistory()->getDelegateCallRows();
        self::assertCount(3, $delegateCallRows);
        self::assertSame($delegateCallMessage, $delegateCallRows[0]);
        $recordNames = [];
        foreach ($delegateCallRows as $rowIndex => $delegateCallRow) {
            $recordNames[] = $delegateCallRow->delegationResults->subAgent->buildName();
            self::assertSame(AIConversationMessage::STATUS_COMPLETED, $delegateCallRow->toolCall->status);
            self::assertSame('call_model', $delegateCallRow->toolCall->emittedBatchId, 'the items are one emission');
            if ($rowIndex > 0) {
                self::assertStringStartsWith('call_fanout_', $delegateCallRow->toolCall->toolCallId);
                self::assertSame('Research site ' . ['A', 'B', 'C'][$rowIndex], $delegateCallRow->toolCall->arguments['task'], 'the item in the single-task shape');
                self::assertSame($delegateCallRow->delegationResults->subAgent->buildSpawnAnswerText(null), $delegateCallRow->toolCall->result);
            }
        }
        self::assertSame(['#1 «Site A»', '#2 «Site B»', '#3 «Site C»'], $recordNames);
        self::assertSame(
            'Sub-agent #1 «Site A» started (running). Its result arrives as a notification when it ends.'
                . "\nThis call also started #2 (Site B), #3 (Site C) — one sub-agent per further item of `parallelTasks`; their "
                . 'rows follow. Each ends with its own notification; none of their results has arrived yet.',
            $answer
        );
    }

    public function testARefusedItemFailsItsOwnRowOnlyAndTheOtherItemsRun(): void
    {
        [$spawnService, $parentConversation, $delegateCallMessage, $answer] = $this->dispatchDelegateCall([
            ['label' => 'Refused', 'task' => SpawnServiceThatStartsNoChild::REFUSED_TASK_TEXT],
            ['label' => 'Site B', 'task' => 'Research site B'],
            ['label' => 'No task'],
        ]);

        self::assertSame(['Site B'], $spawnService->spawnedLabels);
        $delegateCallRows = $parentConversation->messages->buildToolCallHistory()->getDelegateCallRows();
        self::assertCount(3, $delegateCallRows);
        self::assertSame(AIConversationMessage::STATUS_FAILED, $delegateCallMessage->toolCall->status);
        self::assertTrue($delegateCallMessage->delegationResults->subAgent->isNotRun());
        self::assertStringStartsWith(SpawnServiceThatStartsNoChild::REFUSAL_TEXT, $answer);
        self::assertSame(AIConversationMessage::STATUS_COMPLETED, $delegateCallRows[1]->toolCall->status);
        self::assertSame(AIConversationMessage::STATUS_FAILED, $delegateCallRows[2]->toolCall->status);
        self::assertTrue($delegateCallRows[2]->delegationResults->subAgent->isNotRun());
        self::assertSame(
            'delegate REJECTED — item 3 of `parallelTasks` names no task: give it `task` (with `skill_names`) for an agent '
                . 'task, or `toolName` + `toolArguments` for one tool call. This item was not started; the other items of the '
                . 'call are not affected.',
            $delegateCallRows[2]->toolCall->result
        );
        self::assertSame(['label' => 'No task'], $delegateCallRows[2]->toolCall->arguments);
        self::assertSame(
            SpawnServiceThatStartsNoChild::REFUSAL_TEXT
                . "\nThis call also started #2 (Site B) — one sub-agent per further item of `parallelTasks`; their rows follow. "
                . 'Each ends with its own notification; none of their results has arrived yet. NOT started: item 3 (No task) — '
                . 'its row carries the reason.',
            $answer
        );
    }

    public function testACallWhoseItemsNameNoTaskIsRejectedAsAWholeOnItsOwnRow(): void
    {
        [$spawnService, $parentConversation, $delegateCallMessage, $answer] = $this->dispatchDelegateCall([['label' => 'A'], ['label' => 'B']]);

        self::assertSame([], $spawnService->spawnedLabels);
        self::assertCount(1, $parentConversation->messages->buildToolCallHistory()->getDelegateCallRows());
        self::assertSame(AIConversationMessage::STATUS_FAILED, $delegateCallMessage->toolCall->status);
        self::assertSame(
            'delegate REJECTED — no item of `parallelTasks` names a task: give each item `task` (with `skill_names`) for an '
                . 'agent task, or `toolName` + `toolArguments` for one tool call. Nothing was started. '
                . DelegateToolArguments::DIRECT_WIDGET_TAIL,
            $answer
        );
    }

    public function testTheDescriptionsAreTheAgreedTexts(): void
    {
        $function = DelegateTool::forCatalog(new AISkills())->toOpenAiToolSchema()['function'];

        self::assertStringStartsWith('Start a sub-agent for ONE self-contained task — or several sub-agents together with `parallelTasks`.', $function['description']);
        self::assertStringContainsString('— stated at the top level for one sub-agent, or per item of `parallelTasks`.', $function['description']);
        self::assertStringStartsWith(
            'Several sub-agents started together by this ONE call:',
            $function['parameters']['properties'][DelegateToolArguments::PARALLEL_TASKS_ARGUMENT]['description']
        );
    }

    /**
     * The model's `delegate` call with these `parallelTasks`, dispatched sync on a conversation that is not stored.
     *
     * @param array<int, array<string, mixed>> $parallelTasks
     * @return array{0: SpawnServiceThatStartsNoChild, 1: AIConversation, 2: AIConversationMessage, 3: string}
     */
    protected function dispatchDelegateCall(array $parallelTasks): array
    {
        $parentConversation = new AIConversation();
        $parentConversation->messages = new AIConversationMessages();
        $transientMessageIds = AgentTransientMessageIds::startingAt(0);
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        $delegateCallMessage = $aIConversationMessagesService->appendNewMessage($parentConversation, $transientMessageIds, AIConversationMessage::MESSAGE_TOOL_CALL, '', authorType: AIConversationMessage::AUTHOR_ASSISTANT);
        $delegateToolCall = new ToolCall();
        $delegateToolCall->toolCallId = 'call_model';
        $delegateToolCall->name = DelegateTool::NAME;
        $delegateToolCall->arguments = [DelegateToolArguments::PARALLEL_TASKS_ARGUMENT => $parallelTasks];
        $delegateToolCall->status = AIConversationMessage::STATUS_PENDING;
        $delegateCallMessage->toolCall = $delegateToolCall;
        $spawnService = new SpawnServiceThatStartsNoChild();
        $answer = $spawnService->spawnSubAgentOfModelCall(
            AgentRun::forTurnEntry(),
            $this->createStub(AgentChildTurnRunnerInterface::class),
            $parentConversation,
            $delegateToolCall,
            $delegateCallMessage,
            $transientMessageIds,
            null,
            null,
            false
        );
        return [$spawnService, $parentConversation, $delegateCallMessage, $answer];
    }
}
