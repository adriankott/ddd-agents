<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentModelInput;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Probes\PurposeStubbingConversation;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Probes\SummaryEndedPurpose;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * No answer contract once a tool of the turn made the platform write the widget that ends the purpose: that widget
 * reports the turn, and the tool's result asks for one closing line. Every other turn keeps the contract.
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
}
