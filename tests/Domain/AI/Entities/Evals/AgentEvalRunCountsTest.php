<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SendOwnerReportTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SetTaskStatusTool;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationSignalSource;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Evals\AgentEvalRunCounts;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The machine counts of a judged run: each loaded row counts in at most one figure, a SIGNAL row counts as an owner
 * reply only when the caller declares it one, blank texts count nowhere, a task-plan call counts as a failed or refused
 * tick, and only a completed, not refused call counts as a mandate grant or an outward write (the mandate grant is an
 * outward action itself, so it counts in both). The stored keys and the judge's text keep their order.
 */
class AgentEvalRunCountsTest extends UnitTestCase
{
    public function testEachRowCountsInItsFigure(): void
    {
        $agentEvalRunCounts = AgentEvalRunCounts::newInstance();
        foreach ($this->buildRowsOfARun() as $message) {
            $agentEvalRunCounts->addMessage($message);
        }

        self::assertSame([
            'ownerReplies' => 2,
            'agentMsgs' => 1,
            'statusOnlyMsgs' => 1,
            'toolWidgets' => 1,
            'failedTicks' => 1,
            'rejectedTicks' => 2,
            'outwardWrites' => 2,
            'mandatesGranted' => 1,
            'repeatedTextsSuppressed' => 1,
            'loopCorrectives' => 1,
            'supervisorSteers' => 1,
        ], $agentEvalRunCounts->toArray());
    }

    public function testASignalRowTheCallerDeclaresAnOwnerReplyCountsAsOneAndNothingElse(): void
    {
        $agentEvalRunCounts = AgentEvalRunCounts::newInstance();
        $agentEvalRunCounts->addMessage($this->signal(AIConversationMessage::SIGNAL_SOURCE_SUPERVISOR_STEERING), true);
        $agentEvalRunCounts->addMessage($this->signal(AIConversationMessage::SIGNAL_SOURCE_SUPERVISOR_STEERING), false);

        self::assertSame(1, $agentEvalRunCounts->ownerReplies);
        self::assertSame(1, $agentEvalRunCounts->supervisorSteers);
    }

    public function testTheDeclarationIsReadForASignalRowOnly(): void
    {
        $agentEvalRunCounts = AgentEvalRunCounts::newInstance();
        $agentEvalRunCounts->addMessage($this->assistantText('Here are your numbers.'), true);
        $agentEvalRunCounts->addMessage($this->toolMessage(), true);

        self::assertSame(0, $agentEvalRunCounts->ownerReplies);
        self::assertSame(1, $agentEvalRunCounts->agentMessages);
        self::assertSame(1, $agentEvalRunCounts->toolWidgets);
    }

    public function testACallThatWasNotCompletedOrWasRefusedAsDuplicateCountsNowhere(): void
    {
        $agentEvalRunCounts = AgentEvalRunCounts::newInstance();
        $agentEvalRunCounts->addMessage($this->toolCall(SendOwnerReportTool::NAME, AIConversationMessage::STATUS_FAILED, ''));
        $refusedDuplicate = $this->toolCall(SendOwnerReportTool::NAME, AIConversationMessage::STATUS_COMPLETED, '');
        $refusedDuplicate->toolCall->wasRefusedAsDuplicate = true;
        $agentEvalRunCounts->addMessage($refusedDuplicate);

        self::assertSame(array_fill_keys(array_keys($agentEvalRunCounts->toArray()), 0), $agentEvalRunCounts->toArray());
    }

    public function testTheJudgeTextListsEveryCountUnderItsEnglishLabel(): void
    {
        $agentEvalRunCounts = AgentEvalRunCounts::newInstance();
        foreach ($this->buildRowsOfARun() as $message) {
            $agentEvalRunCounts->addMessage($message);
        }

        self::assertSame(
            "\n[COUNTS — machine-counted and AUTHORITATIVE, never recount]\n"
            . "- ownerReplies: 2\n- agentMessages: 1\n- statusOnlyMessages: 1\n- toolWidgets: 1\n- failedTicks: 1\n- rejectedTicks: 2\n"
            . "- outwardWrites: 2\n- mandatesGranted: 1\n- repeatedTextsSuppressed: 1\n- loopCorrectives: 1\n- supervisorSteers: 1",
            $agentEvalRunCounts->toJudgeText()
        );
        self::assertSame(array_values($agentEvalRunCounts->toArray()), array_values($agentEvalRunCounts->buildCountsByJudgeLabel()));
    }

    /**
     * One row of every kind, blank texts included.
     *
     * @return AIConversationMessage[]
     */
    protected function buildRowsOfARun(): array
    {
        return [
            $this->ownerText('Show me last week.'),
            $this->ownerText('   '),
            $this->ownerText('Yes, send it.'),
            $this->assistantText('Here are your numbers.'),
            $this->assistantText(AIConversationMessage::PRE_CALL_MESSAGE_MARKER . ' Fetching the numbers'),
            $this->assistantText(''),
            $this->toolMessage(),
            $this->signal(AIConversationMessage::SIGNAL_SOURCE_REPEATED_TEXT_SUPPRESSED),
            $this->signal(AIConversationSignalSource::LOOP_NUDGE->value),
            $this->signal(AIConversationMessage::SIGNAL_SOURCE_SUPERVISOR_STEERING),
            $this->signal('SOME_OTHER_SOURCE'),
            $this->toolCall(SetTaskStatusTool::NAME, AIConversationMessage::STATUS_FAILED, ''),
            $this->toolCall(SetTaskStatusTool::NAME, AIConversationMessage::STATUS_COMPLETED, 'REJECTED: the item is not done'),
            $this->toolCall(SetTaskStatusTool::NAME, AIConversationMessage::STATUS_COMPLETED, 'No item updated.'),
            $this->toolCall(SetTaskStatusTool::NAME, AIConversationMessage::STATUS_COMPLETED, 'Item 1 done.'),
            $this->toolCall(AgentEvalRunCounts::GRANT_MANDATE_TOOL_NAME, AIConversationMessage::STATUS_COMPLETED, '{}'),
            $this->toolCall(SendOwnerReportTool::NAME, AIConversationMessage::STATUS_COMPLETED, 'sent'),
            $this->toolCall('listEntries', AIConversationMessage::STATUS_COMPLETED, '[]'),
        ];
    }

    protected function ownerText(string $text): AIConversationMessage
    {
        $message = new AIConversationMessage();
        $message->role = AIConversationMessage::ROLE_USER;
        $message->authorType = AIConversationMessage::AUTHOR_USER;
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->message = $text;
        return $message;
    }

    protected function assistantText(string $text): AIConversationMessage
    {
        $message = new AIConversationMessage();
        $message->role = AIConversationMessage::ROLE_ASSISTANT;
        $message->authorType = AIConversationMessage::AUTHOR_ASSISTANT;
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->message = $text;
        return $message;
    }

    protected function toolMessage(): AIConversationMessage
    {
        $message = new AIConversationMessage();
        $message->role = AIConversationMessage::ROLE_ASSISTANT;
        $message->messageType = AIConversationMessage::MESSAGE_TOOL_MESSAGE;
        $message->message = '```widget\n{}\n```';
        return $message;
    }

    protected function signal(string $signalSource): AIConversationMessage
    {
        $message = new AIConversationMessage();
        $message->role = AIConversationMessage::ROLE_SYSTEM;
        $message->messageType = AIConversationMessage::MESSAGE_SIGNAL;
        $message->signalSource = $signalSource;
        $message->message = 'signal';
        return $message;
    }

    protected function toolCall(string $toolName, string $status, string $result): AIConversationMessage
    {
        $message = new AIConversationMessage();
        $message->role = AIConversationMessage::ROLE_ASSISTANT;
        $message->authorType = AIConversationMessage::AUTHOR_ASSISTANT;
        $message->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        $message->message = '';
        $toolCall = new ToolCall();
        $toolCall->name = $toolName;
        $toolCall->arguments = [];
        $toolCall->status = $status;
        $toolCall->result = $result;
        $message->toolCall = $toolCall;
        return $message;
    }
}
