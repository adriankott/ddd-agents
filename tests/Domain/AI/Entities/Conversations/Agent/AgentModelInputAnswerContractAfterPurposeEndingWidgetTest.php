<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentModelInput;
use DDD\Domain\AI\Entities\Conversations\Agent\Context\AgentModelInputTailEntry;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AskOwnerTool;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Probes\PurposeStubbingConversation;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Probes\SummaryEndedPurpose;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * No answer contract once a tool of the turn made the platform write the widget that ends the purpose: that widget
 * reports the turn, and the tool's result asks for one closing line. Every other turn keeps the contract. The
 * `[SIGNAL:answer-step]` entry of that text-only step asks for the closing text, not for the owed plan item's result.
 */
class AgentModelInputAnswerContractAfterPurposeEndingWidgetTest extends UnitTestCase
{
    protected function buildOwnerTextMessage(string $text): AIConversationMessage
    {
        /** @var AIConversationMessage $ownerTextMessage */
        $ownerTextMessage = static::instantiateWithoutConstructor(AIConversationMessage::class);
        $ownerTextMessage->role = AIConversationMessage::ROLE_USER;
        $ownerTextMessage->messageType = AIConversationMessage::MESSAGE_TEXT;
        $ownerTextMessage->authorType = AIConversationMessage::AUTHOR_USER;
        $ownerTextMessage->message = $text;
        return $ownerTextMessage;
    }

    protected function buildCompletedToolCallMessage(string $toolName): AIConversationMessage
    {
        /** @var AIConversationMessage $toolCallMessage */
        $toolCallMessage = static::instantiateWithoutConstructor(AIConversationMessage::class);
        $toolCallMessage->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        /** @var ToolCall $toolCall */
        $toolCall = static::instantiateWithoutConstructor(ToolCall::class);
        $toolCall->name = $toolName;
        $toolCall->status = AIConversationMessage::STATUS_COMPLETED;
        $toolCallMessage->toolCall = $toolCall;
        return $toolCallMessage;
    }

    protected function buildWidgetRow(string $widgetType, string $messageType, string $authorType): AIConversationMessage
    {
        /** @var AIConversationMessage $widgetRow */
        $widgetRow = static::instantiateWithoutConstructor(AIConversationMessage::class);
        $widgetRow->role = AIConversationMessage::ROLE_ASSISTANT;
        $widgetRow->messageType = $messageType;
        $widgetRow->authorType = $authorType;
        $widgetRow->message = "```widget\n" . json_encode(['type' => $widgetType]) . "\n```";
        $widgetRow->toolCall = null;
        return $widgetRow;
    }

    protected function buildPlatformWrittenWidgetRow(string $widgetType): AIConversationMessage
    {
        return $this->buildWidgetRow($widgetType, AIConversationMessage::MESSAGE_TOOL_MESSAGE, AIConversationMessage::AUTHOR_TOOL);
    }

    /** @param AIConversationMessage[] $messages */
    protected function buildModelInput(array $messages, array $widgetTypesThatEndThePurpose = ['Summary']): AgentModelInput
    {
        $agentModelInput = new AgentModelInput();
        $agentModelInput->widgetTypesThatEndThePurpose = $widgetTypesThatEndThePurpose;
        $agentModelInput->messages = $messages;
        return $agentModelInput;
    }

    public function testThePlatformWrittenWidgetThatEndsThePurposeLeavesNoContract(): void
    {
        $agentModelInput = $this->buildModelInput([
            $this->buildOwnerTextMessage('Hand it over.'),
            $this->buildCompletedToolCallMessage('recordAim'),
            $this->buildCompletedToolCallMessage('deliverSummary'),
            $this->buildPlatformWrittenWidgetRow('Summary'),
        ]);

        self::assertNull($agentModelInput->completedWritesAnswerContractHint());
    }

    public function testWithoutThatWidgetRowTheContractNamesBothCalls(): void
    {
        $calls = [$this->buildOwnerTextMessage('Hand it over.'), $this->buildCompletedToolCallMessage('recordAim'), $this->buildCompletedToolCallMessage('deliverSummary')];
        $otherWidgetRow = $this->buildPlatformWrittenWidgetRow('Selection');
        $agentsOwnBlock = $this->buildWidgetRow('Summary', AIConversationMessage::MESSAGE_TEXT, AIConversationMessage::AUTHOR_ASSISTANT);

        foreach ([[], [$otherWidgetRow], [$agentsOwnBlock]] as $rowsAfterTheCalls) {
            $answerContractHint = (string)$this->buildModelInput([...$calls, ...$rowsAfterTheCalls])->completedWritesAnswerContractHint();
            self::assertStringContainsString('Completed so far in this turn: recordAim, deliverSummary.', $answerContractHint);
        }
    }

    public function testWithoutEndingWidgetTypesTheContractStays(): void
    {
        $agentModelInput = $this->buildModelInput([
            $this->buildOwnerTextMessage('Hand it over.'),
            $this->buildCompletedToolCallMessage('deliverSummary'),
            $this->buildPlatformWrittenWidgetRow('Summary'),
        ], []);

        self::assertStringContainsString('deliverSummary', (string)$agentModelInput->completedWritesAnswerContractHint());
    }

    public function testAWidgetRowBeforeTheOwnersNextTextKeepsTheContractForTheNewWrite(): void
    {
        $answerContractHint = (string)$this->buildModelInput([
            $this->buildOwnerTextMessage('Hand it over.'),
            $this->buildCompletedToolCallMessage('deliverSummary'),
            $this->buildPlatformWrittenWidgetRow('Summary'),
            $this->buildOwnerTextMessage('One more note, please.'),
            $this->buildCompletedToolCallMessage('recordNote'),
        ])->completedWritesAnswerContractHint();

        self::assertStringContainsString('Completed so far in this turn: recordNote.', $answerContractHint);
        self::assertStringNotContainsString('deliverSummary', $answerContractHint);
    }

    public function testTheDirectivePhasePutsThePurposesEndingWidgetTypesOntoTheModelInput(): void
    {
        /** @var PurposeStubbingConversation $conversation */
        $conversation = static::instantiateWithoutConstructor(PurposeStubbingConversation::class);
        /** @var SummaryEndedPurpose $summaryEndedPurpose */
        $summaryEndedPurpose = static::instantiateWithoutConstructor(SummaryEndedPurpose::class);
        $summaryEndedPurpose->name = 'Summary.Handover';
        $summaryEndedPurpose->directive = 'Hand the summary over.';
        $conversation->stubbedPurpose = $summaryEndedPurpose;
        $conversation->purposeName = 'Summary.Handover';

        $rootModelInput = new AgentModelInput();
        $conversation->putDirectivesIntoModelInput($rootModelInput);
        self::assertSame(['Summary'], $rootModelInput->widgetTypesThatEndThePurpose);

        $delegationModelInput = new AgentModelInput();
        $delegationModelInput->isDelegation = true;
        $conversation->putDirectivesIntoModelInput($delegationModelInput);
        self::assertSame([], $delegationModelInput->widgetTypesThatEndThePurpose);

        $conversation->purposeName = null;
        $withoutPurposeModelInput = new AgentModelInput();
        $conversation->putDirectivesIntoModelInput($withoutPurposeModelInput);
        self::assertSame([], $withoutPurposeModelInput->widgetTypesThatEndThePurpose);
    }
    protected const string DEFAULT_ANSWER_STEP_TEXT = '[SIGNAL:answer-step] Tools are intentionally paused for '
        . 'this ONE step: the next move is owner-visible TEXT — what the previous step asks for (a rejection\'s '
        . 'instruction, a delivery, the one open question): the RESULT the owed plan item promises, or its ONE question. '
        . 'A report of what is done or what happens next is not an answer — never end on it. '
        . 'Nothing is unavailable and you never lack access — NEVER tell the user so. Write it in the '
        . 'owner\'s confirmed language.';

    protected const string BUDGET_CLOSING_ANSWER_STEP_TEXT = '[SIGNAL:answer-step] Tools are intentionally paused for '
        . 'this ONE step: the next move is owner-visible TEXT — what the previous step asks for (a rejection\'s '
        . 'instruction, a delivery, the one open question), or a plain report of what IS done and what happens next. '
        . 'Nothing is unavailable and you never lack access — NEVER tell the user so. Write it in the '
        . 'owner\'s confirmed language.';

    /** The text of the `[SIGNAL:answer-step]` entry; null when the tail holds none */
    protected function findAnswerStepText(AgentModelInput $agentModelInput, string|array|null $forceToolChoice, bool $isBudgetClosingStep = false): ?string
    {
        foreach ($agentModelInput->buildTailEntries($forceToolChoice, $isBudgetClosingStep)->getElements() as $agentModelInputTailEntry) {
            /** @var AgentModelInputTailEntry $agentModelInputTailEntry */
            if ($agentModelInputTailEntry->kind === AgentModelInputTailEntry::KIND_ANSWER_STEP) {
                return $agentModelInputTailEntry->text;
            }
        }
        return null;
    }

    public function testTheTextOnlyStepAfterThePurposeEndingWidgetAsksForTheClosingText(): void
    {
        $answerStepText = (string)$this->findAnswerStepText($this->buildModelInput([
            $this->buildOwnerTextMessage('Hand it over.'),
            $this->buildCompletedToolCallMessage('recordAim'),
            $this->buildCompletedToolCallMessage('deliverSummary'),
            $this->buildPlatformWrittenWidgetRow('Summary'),
        ]), 'none');

        self::assertStringContainsString(': here the text the last tool result asks for — every part it lists, in its order. Beyond those '
            . 'parts: no recap of what the widget shows and no list of completed steps. ', $answerStepText);
        self::assertStringNotContainsString('never end on it', $answerStepText);
    }

    public function testTheTextOnlyStepAfterAQuestionRecordedInThisTurnAsksForTheClosedItemsResultsNotTheQuestion(): void
    {
        $answerStepText = (string)$this->findAnswerStepText($this->buildModelInput([
            $this->buildOwnerTextMessage('Do items 1 and 2, then ask me whether to go on.'),
            $this->buildCompletedToolCallMessage('recordAim'),
            $this->buildCompletedToolCallMessage(AskOwnerTool::NAME),
        ], []), 'none');

        self::assertStringContainsString(': your question is recorded and shows as a widget under your text — do not repeat '
            . 'it. Write what the owner has not seen yet: first what the question\'s own tool result asks to stand in this '
            . 'text (a draft the question approves: every line of it), then the result of every plan item you closed in '
            . 'this turn, in the order of the plan; with neither, ONE short sentence that leads to the question. ', $answerStepText);
        self::assertStringNotContainsString('or its ONE question', $answerStepText);

        $askedBeforeTheOwnersNewestText = (string)$this->findAnswerStepText($this->buildModelInput([
            $this->buildCompletedToolCallMessage(AskOwnerTool::NAME),
            $this->buildOwnerTextMessage('Yes, go on.'),
            $this->buildCompletedToolCallMessage('recordAim'),
        ], []), 'none');
        self::assertSame(self::DEFAULT_ANSWER_STEP_TEXT, $askedBeforeTheOwnersNewestText, 'a question of an earlier turn was answered by the owner');
    }

    public function testWithoutThatWidgetRowTheAnswerStepKeepsTheDefaultWording(): void
    {
        $calls = [$this->buildOwnerTextMessage('Hand it over.'), $this->buildCompletedToolCallMessage('recordAim'), $this->buildCompletedToolCallMessage('deliverSummary')];
        $otherWidgetRow = $this->buildPlatformWrittenWidgetRow('Selection');
        $agentsOwnBlock = $this->buildWidgetRow('Summary', AIConversationMessage::MESSAGE_TEXT, AIConversationMessage::AUTHOR_ASSISTANT);

        foreach ([[], [$otherWidgetRow], [$agentsOwnBlock]] as $rowsAfterTheCalls) {
            self::assertSame(self::DEFAULT_ANSWER_STEP_TEXT, $this->findAnswerStepText($this->buildModelInput([...$calls, ...$rowsAfterTheCalls]), 'none'));
        }
    }

    public function testTheBudgetClosingStepKeepsItsWordingBesideThePurposeEndingWidget(): void
    {
        $agentModelInput = $this->buildModelInput([
            $this->buildOwnerTextMessage('Hand it over.'),
            $this->buildCompletedToolCallMessage('deliverSummary'),
            $this->buildPlatformWrittenWidgetRow('Summary'),
        ]);

        self::assertSame(self::BUDGET_CLOSING_ANSWER_STEP_TEXT, $this->findAnswerStepText($agentModelInput, 'none', true));
    }

    public function testAStepWithToolsHasNoAnswerStepEntry(): void
    {
        $agentModelInput = $this->buildModelInput([
            $this->buildOwnerTextMessage('Hand it over.'),
            $this->buildCompletedToolCallMessage('deliverSummary'),
            $this->buildPlatformWrittenWidgetRow('Summary'),
        ]);

        self::assertNull($this->findAnswerStepText($agentModelInput, null));
    }
}
