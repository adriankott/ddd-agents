<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\SignalSources\ConversationSignalSources;
use DDD\Domain\AI\Services\Conversations\AIConversationAuditRenderService;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Services\DDDService;

/**
 * The machine counts of a judged run, read off the conversation's loaded rows: what the judge's trace prints as its
 * COUNTS section ({@see toJudgeText()}) — authoritative, the judge never recounts — and what a caller stores beside the
 * verdict ({@see toArray()}).
 *
 * The caller adds the rows one by one ({@see addMessage()}). The first rule that matches a row decides where it counts:
 * - an owner text that is not blank: {@see $ownerReplies};
 * - a SIGNAL row the caller declares an owner reply (an application's widget whose submit stands for a typed answer):
 *   {@see $ownerReplies};
 * - an assistant text that is not blank: {@see $statusOnlyMessages} when it only announces work,
 *   {@see $agentMessages} otherwise;
 * - a TOOL_MESSAGE row (a widget a tool or the platform wrote): {@see $toolWidgets};
 * - any other SIGNAL row: {@see $repeatedTextsSuppressed}, {@see $loopCorrectives} (a loop corrective or a work
 *   continuation) or {@see $supervisorSteers} by its source;
 * - a tool call: a task-plan call that failed ({@see $failedTicks}) or was refused ({@see $rejectedTicks}); any other
 *   completed call that was not refused as a duplicate counts in {@see $mandatesGranted} when it grants a mandate and
 *   in {@see $outwardWrites} when its tool acts outward (a mandate grant does both).
 *
 * Reads no database and calls no model; it asks the audit render service (resolved through the container) whether a
 * tool acts outward.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalRunCounts extends ValueObject
{
    /**
     * @var string The tool that grants the owner's standing mandate. An application whose mandate tool has another name
     * redeclares it on its same-path subclass; the counts read it with `static::`.
     */
    public const string GRANT_MANDATE_TOOL_NAME = 'grantMandate';

    /** @var string[] The texts of a task-plan call result that mark the call as refused */
    public const array REJECTED_TICK_RESULT_MARKERS = ['REJECTED', 'No item updated'];

    /** @var string The header of the COUNTS section of the judge's trace */
    public const string JUDGE_TEXT_HEADER = '[COUNTS — machine-counted and AUTHORITATIVE, never recount]';

    /** @var int Owner texts, plus the SIGNAL rows the caller declared owner replies */
    public int $ownerReplies = 0;

    /** @var int Assistant texts that are not status-only announcements */
    public int $agentMessages = 0;

    /** @var int Assistant texts that only announce work */
    public int $statusOnlyMessages = 0;

    /** @var int Widget rows a tool or the platform wrote */
    public int $toolWidgets = 0;

    /** @var int Task-plan calls that failed */
    public int $failedTicks = 0;

    /** @var int Task-plan calls whose result refused the change */
    public int $rejectedTicks = 0;

    /** @var int Completed calls of a tool that acts outward */
    public int $outwardWrites = 0;

    /** @var int Completed mandate grants */
    public int $mandatesGranted = 0;

    /** @var int Signals of a repeated text the loop suppressed */
    public int $repeatedTextsSuppressed = 0;

    /** @var int Signals of a loop corrective or a work continuation */
    public int $loopCorrectives = 0;

    /** @var int Signals of the supervisor steering the agent */
    public int $supervisorSteers = 0;

    /**
     * Counts one loaded row.
     *
     * @param AIConversationMessage $message the row
     * @param bool $isHostOwnerReplyRow true = the caller declares this row an owner reply; read for a SIGNAL row only
     * @return void
     */
    public function addMessage(AIConversationMessage $message, bool $isHostOwnerReplyRow = false): void
    {
        $messageType = $message->messageType ?? null;
        if ($message->isOwnerOrLegacyUserRoleText() && trim((string)($message->message ?? '')) !== '') {
            $this->ownerReplies++;
        } elseif ($messageType === AIConversationMessage::MESSAGE_SIGNAL && $isHostOwnerReplyRow) {
            $this->ownerReplies++;
        } elseif ($message->isAssistantText() && trim((string)($message->message ?? '')) !== '') {
            if ($message->isStatusOnlyAnnouncementLine()) {
                $this->statusOnlyMessages++;
            } else {
                $this->agentMessages++;
            }
        } elseif ($messageType === AIConversationMessage::MESSAGE_TOOL_MESSAGE) {
            $this->toolWidgets++;
        } elseif ($messageType === AIConversationMessage::MESSAGE_SIGNAL) {
            $this->addSignalMessage($message);
        } elseif ($messageType === AIConversationMessage::MESSAGE_TOOL_CALL && isset($message->toolCall)) {
            $this->addToolCallMessage($message);
        }
    }

    /** Counts a SIGNAL row that is no owner reply, by its source. */
    protected function addSignalMessage(AIConversationMessage $message): void
    {
        $signalSource = (string)($message->signalSource ?? '');
        if ($signalSource === AIConversationMessage::SIGNAL_SOURCE_REPEATED_TEXT_SUPPRESSED) {
            $this->repeatedTextsSuppressed++;
        } elseif (ConversationSignalSources::tryFrom($signalSource)?->isLoopCorrectiveOrWorkContinuation() === true) {
            $this->loopCorrectives++;
        } elseif ($signalSource === AIConversationMessage::SIGNAL_SOURCE_SUPERVISOR_STEERING) {
            $this->supervisorSteers++;
        }
    }

    /** Counts a tool-call row: a task-plan tick that failed or was refused, a mandate grant, an outward write. */
    protected function addToolCallMessage(AIConversationMessage $message): void
    {
        $toolCall = $message->toolCall;
        $toolName = (string)($toolCall->name ?? '');
        if (in_array($toolName, TaskPlan::PLAN_TOOL_NAMES, true)) {
            if ($toolCall->status === AIConversationMessage::STATUS_FAILED) {
                $this->failedTicks++;
            } elseif ($this->isRejectedTickResult((string)($toolCall->result ?? ''))) {
                $this->rejectedTicks++;
            }
            return;
        }
        if ($toolCall->status !== AIConversationMessage::STATUS_COMPLETED || $toolCall->wasRefusedAsDuplicate) {
            return;
        }
        if ($toolName === static::GRANT_MANDATE_TOOL_NAME) {
            $this->mandatesGranted++;
        }
        /** @var AIConversationAuditRenderService $aIConversationAuditRenderService */
        $aIConversationAuditRenderService = DDDService::instance()->getService(AIConversationAuditRenderService::class);
        if ($aIConversationAuditRenderService::isOutwardActionToolName($toolName)) {
            $this->outwardWrites++;
        }
    }

    /** Whether the result of a task-plan call says the change was refused. */
    protected function isRejectedTickResult(string $toolCallResult): bool
    {
        foreach (static::REJECTED_TICK_RESULT_MARKERS as $rejectedTickResultMarker) {
            if (str_contains($toolCallResult, $rejectedTickResultMarker)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The counts under the keys of a stored result row, in this order; {@see buildCountsByJudgeLabel()} gives the labels
     * the judge reads. The widget rows are stored as `toolWidgets` (no reader of an older key exists).
     *
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'ownerReplies' => $this->ownerReplies,
            'agentMsgs' => $this->agentMessages,
            'statusOnlyMsgs' => $this->statusOnlyMessages,
            'toolWidgets' => $this->toolWidgets,
            'failedTicks' => $this->failedTicks,
            'rejectedTicks' => $this->rejectedTicks,
            'outwardWrites' => $this->outwardWrites,
            'mandatesGranted' => $this->mandatesGranted,
            'repeatedTextsSuppressed' => $this->repeatedTextsSuppressed,
            'loopCorrectives' => $this->loopCorrectives,
            'supervisorSteers' => $this->supervisorSteers,
        ];
    }

    /**
     * The counts under the labels the judge's trace prints, in the order of {@see toArray()}.
     *
     * @return array<string, int>
     */
    public function buildCountsByJudgeLabel(): array
    {
        return [
            'ownerReplies' => $this->ownerReplies,
            'agentMessages' => $this->agentMessages,
            'statusOnlyMessages' => $this->statusOnlyMessages,
            'toolWidgets' => $this->toolWidgets,
            'failedTicks' => $this->failedTicks,
            'rejectedTicks' => $this->rejectedTicks,
            'outwardWrites' => $this->outwardWrites,
            'mandatesGranted' => $this->mandatesGranted,
            'repeatedTextsSuppressed' => $this->repeatedTextsSuppressed,
            'loopCorrectives' => $this->loopCorrectives,
            'supervisorSteers' => $this->supervisorSteers,
        ];
    }

    /** The COUNTS section of the judge's trace: a leading line break, the header, then one `- label: count` line each. */
    public function toJudgeText(): string
    {
        $countLines = [];
        foreach ($this->buildCountsByJudgeLabel() as $judgeLabel => $count) {
            $countLines[] = sprintf('- %s: %d', $judgeLabel, $count);
        }
        return "\n" . static::JUDGE_TEXT_HEADER . "\n" . implode("\n", $countLines);
    }
}
