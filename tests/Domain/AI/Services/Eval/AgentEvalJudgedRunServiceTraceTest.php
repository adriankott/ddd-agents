<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use ReflectionClass;

/**
 * The full trace of a judged run: the agent's system prompt above the messages, then the deterministic end sections
 * the judge reads instead of recounting or inferring persistence from prose — the task plan with its provenance, the
 * write ledger, the outward actions, the widgets the owner saw, the counts (an application's owner-reply signal row
 * counted as an owner reply) — and the application's end state when it renders one.
 */
class AgentEvalJudgedRunServiceTraceTest extends IntegrationTestCase
{
    protected function buildMessage(int $index, string $messageType, ?string $role, ?string $authorType, string $text = '', ?string $signalSource = null): AIConversationMessage
    {
        /** @var AIConversationMessage $message */
        $message = (new ReflectionClass(AIConversationMessage::class))->newInstanceWithoutConstructor();
        $message->indexInConversation = $index;
        $message->messageType = $messageType;
        $message->role = $role;
        $message->authorType = $authorType;
        $message->message = $text;
        $message->signalSource = $signalSource;
        $message->toolCall = null;
        return $message;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function buildToolCallMessage(int $index, string $toolName, array $arguments, string $status = AIConversationMessage::STATUS_COMPLETED, string $result = 'ok'): AIConversationMessage
    {
        $message = $this->buildMessage($index, AIConversationMessage::MESSAGE_TOOL_CALL, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_AGENT);
        $toolCall = new ToolCall();
        $toolCall->name = $toolName;
        $toolCall->arguments = $arguments;
        $toolCall->status = $status;
        $toolCall->result = $result;
        $message->toolCall = $toolCall;
        return $message;
    }

    protected function buildConversation(AIConversationMessage ...$orderedMessages): AIConversation
    {
        /** @var AIConversation $conversation */
        $conversation = (new ReflectionClass(AIConversation::class))->newInstanceWithoutConstructor();
        $conversation->messages = new AIConversationMessages();
        foreach ($orderedMessages as $message) {
            $conversation->messages->add($message);
        }
        return $conversation;
    }

    public function testTheSystemPromptLeadsTheTraceAndAMissingOneLeavesNoHeadline(): void
    {
        $agentEvalJudgedRunService = new AgentEvalJudgedRunServiceScriptedModelsDouble();
        $conversation = $this->buildConversation($this->buildMessage(1, AIConversationMessage::MESSAGE_TEXT, AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'ok'));

        self::assertStringStartsWith('[1] TEXT USER (owner reply 1): ok', $agentEvalJudgedRunService->getFullTraceText($conversation));

        $agentEvalJudgedRunService->systemPromptForTrace = 'You work for the owner.';
        self::assertStringStartsWith(
            "[SYSTEM PROMPT — the base data it carries (in particular in its 'Work context' block) counts as GROUNDED, also without a tool call]\nYou work for the owner.\n\n[1] TEXT USER",
            $agentEvalJudgedRunService->getFullTraceText($conversation)
        );
    }

    public function testTheLedgerListsOnlyWritesTheOutwardActionsTheGrantsAndTheCountsAreMachineCounted(): void
    {
        $agentEvalJudgedRunService = new AgentEvalJudgedRunServiceScriptedModelsDouble();

        $traceText = $agentEvalJudgedRunService->getFullTraceText($this->buildConversation(
            $this->buildMessage(1, AIConversationMessage::MESSAGE_TEXT, AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'ok'),
            $this->buildToolCallMessage(2, 'proposeEntries', ['maxEntries' => 12]),
            $this->buildToolCallMessage(3, 'searchAreas', ['searchInput' => 'North']),
            $this->buildToolCallMessage(4, 'addEntries', ['entries' => ['north street']]),
            $this->buildToolCallMessage(5, 'set_task_status', ['updates' => []], AIConversationMessage::STATUS_FAILED, 'No item updated: REJECTED'),
            $this->buildToolCallMessage(6, 'grantMandate', ['actionClass' => 'ENTRY_SYNC']),
        ));

        self::assertStringContainsString("\n\n[WRITE LEDGER", $traceText);
        self::assertStringContainsString('- [4] addEntries(', $traceText);
        self::assertStringNotContainsString('- [2] proposeEntries', $traceText);
        self::assertStringNotContainsString('- [5] set_task_status', $traceText);
        self::assertStringContainsString("\n\n[OUTWARD ACTIONS", $traceText);
        self::assertStringContainsString('- [6] grantMandate(', $traceText);
        self::assertStringContainsString("\n\n[WIDGETS THE OWNER SAW", $traceText);
        self::assertStringContainsString("\n\n[COUNTS — machine-counted and AUTHORITATIVE, never recount]", $traceText);
        self::assertStringContainsString('- ownerReplies: 1', $traceText);
        self::assertStringContainsString('- failedTicks: 1', $traceText);
        self::assertStringContainsString('- outwardWrites: 1', $traceText);
        self::assertStringContainsString('- mandatesGranted: 1', $traceText);
        self::assertStringNotContainsString('[TASK PLAN', $traceText, 'no plan, no plan section');
        self::assertStringEndsWith('- supervisorSteers: 0', $traceText, 'without an end state of the application the counts close the trace');
    }

    public function testASignalRowTheApplicationDeclaresAnOwnerReplyCountsAsOneAndAnotherClickDoesNot(): void
    {
        $agentEvalJudgedRunService = new AgentEvalJudgedRunServiceScriptedModelsDouble();
        $agentEvalJudgedRunService->hostOwnerReplySignalSource = 'FORM_SUBMIT';
        $conversation = $this->buildConversation(
            $this->buildMessage(1, AIConversationMessage::MESSAGE_TEXT, AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'We are new.'),
            $this->buildMessage(2, AIConversationMessage::MESSAGE_SIGNAL, AIConversationMessage::ROLE_SYSTEM, AIConversationMessage::AUTHOR_AGENT, 'The owner submitted the form.', 'FORM_SUBMIT'),
            $this->buildMessage(3, AIConversationMessage::MESSAGE_SIGNAL, AIConversationMessage::ROLE_SYSTEM, AIConversationMessage::AUTHOR_AGENT, 'The owner confirmed the pin.', AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_CLICK),
        );

        self::assertSame(2, $agentEvalJudgedRunService->buildRunCountsOfConversation($conversation)->ownerReplies);
        self::assertStringContainsString('- ownerReplies: 2', $agentEvalJudgedRunService->getFullTraceText($conversation));
        $agentEvalJudgedRunService->hostOwnerReplySignalSource = null;
        self::assertSame(1, $agentEvalJudgedRunService->buildRunCountsOfConversation($conversation)->ownerReplies, 'by default no signal row is an owner reply');
    }

    public function testTheTaskPlanRendersThePlatformProvenanceAndTheEvidenceForTheJudgeOnly(): void
    {
        $platformDone = new TaskPlanItem();
        $platformDone->text = 'Entry list';
        $platformDone->status = TaskPlanItem::STATUS_DONE;
        $platformDone->statusNote = 'already satisfied: 3 entries';
        $platformDone->preResolvedAtSeed = true;
        $platformDone->platformResolvedAtMessageIndex = 31;
        $evidenced = new TaskPlanItem();
        $evidenced->text = 'Baseline';
        $evidenced->status = TaskPlanItem::STATUS_DONE;
        $evidenced->statusNote = 'numbers delivered';
        $evidenced->evidenceMessageIndex = 57;
        $agentNotApplicable = new TaskPlanItem();
        $agentNotApplicable->text = 'Notifications';
        $agentNotApplicable->status = TaskPlanItem::STATUS_NOT_APPLICABLE;
        $agentNotApplicable->statusNote = 'owner: no notifications wanted (system)';
        $taskPlan = new TaskPlan();
        $taskPlan->items = new TaskPlanItems();
        foreach ([$platformDone, $evidenced, $agentNotApplicable] as $taskPlanItem) {
            $taskPlan->items->add($taskPlanItem);
        }
        $conversation = $this->buildConversation($this->buildMessage(1, AIConversationMessage::MESSAGE_TEXT, AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'ok'));
        $agentWorkingState = new AgentWorkingState();
        $agentWorkingState->taskPlan = $taskPlan;
        $conversation->agentWorkingState = $agentWorkingState;

        $traceText = (new AgentEvalJudgedRunServiceScriptedModelsDouble())->getFullTraceText($conversation);

        self::assertStringContainsString("\n\n[TASK PLAN — status + statusNote per item", $traceText);
        self::assertStringContainsString('[✓ (platform-resolved @31)] 1 Entry list — already satisfied', $traceText);
        self::assertStringContainsString('[✓ (evidence @57)] 2 Baseline — numbers delivered', $traceText);
        // the agent's own n/a carries NO platform suffix — a "(system)" typed into the note is not provenance
        self::assertStringContainsString('[– n/a: owner: no notifications wanted (system)] 3 Notifications', $traceText);
        self::assertStringNotContainsString('[– n/a (platform-resolved', $traceText);
        self::assertStringNotContainsString('platform-resolved', $taskPlan->toTailHintText(), 'the agent\'s own render never carries the suffixes');
    }

    public function testTheInventoryListsModelFencesAndWidgetRowsWithTheirControls(): void
    {
        $traceText = (new AgentEvalJudgedRunServiceScriptedModelsDouble())->getFullTraceText($this->buildConversation(
            $this->buildMessage(3, AIConversationMessage::MESSAGE_TEXT, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, "Here are the options.\n```widget\n{\"type\":\"QuickReplies\",\"options\":[\"A\",\"B\"]}\n```"),
            $this->buildMessage(4, AIConversationMessage::MESSAGE_TOOL_MESSAGE, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_AGENT, "```widget\n{\"type\":\"QuickReplies\",\"options\":[\"Yes\",\"No\"]}\n```"),
            $this->buildMessage(5, AIConversationMessage::MESSAGE_TEXT, AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, "```widget\n{\"type\":\"QuickReplies\"}\n```"),
            $this->buildMessage(6, AIConversationMessage::MESSAGE_TEXT, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, 'The options are A, B and C.'),
        ));
        $inventoryText = substr($traceText, (int)strpos($traceText, '[WIDGETS THE OWNER SAW'));

        self::assertStringContainsString('- [3] QuickReplies — written by the MODEL (fence in its text) — owner control: answer pills', $inventoryText);
        self::assertStringContainsString('- [4] QuickReplies — written by a TOOL or the PLATFORM (a widget row stored without its origin) — owner control: answer pills', $inventoryText);
        self::assertStringNotContainsString('[5]', $inventoryText, 'an owner-typed fence is not a rendered widget');
        self::assertStringNotContainsString('[6]', $inventoryText, 'names in prose are not a widget');
        self::assertStringContainsString('(no widgets at all)', (new AgentEvalJudgedRunServiceScriptedModelsDouble())->getFullTraceText($this->buildConversation(
            $this->buildMessage(1, AIConversationMessage::MESSAGE_TEXT, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, 'Prose only.')
        )));
    }

    public function testTheApplicationsEndStateClosesTheTrace(): void
    {
        $agentEvalJudgedRunService = new AgentEvalJudgedRunServiceScriptedModelsDouble();
        $agentEvalJudgedRunService->hostEndStateText = "\n[END STATE — read after the run]\n## getEntries\n3 entries";

        $traceText = $agentEvalJudgedRunService->getFullTraceText($this->buildConversation(
            $this->buildMessage(1, AIConversationMessage::MESSAGE_TEXT, AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'ok')
        ));

        self::assertStringEndsWith("- supervisorSteers: 0\n\n[END STATE — read after the run]\n## getEntries\n3 entries", $traceText);
    }
}
