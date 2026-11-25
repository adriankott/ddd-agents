<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\RunRelease;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationSignalSource;
use InvalidArgumentException;

/**
 * The rows a scripted drive (or another writer) appends to the conversation of the release stage, by name. The work
 * signal is an orchestrator's message: a signal a running turn picks up, not written by the owner, so a copy of it that
 * landed after the turn's last model call is replayed by the release path.
 */
class RunReleaseScriptedRows
{
    public const string OWNER_MESSAGE = 'ownerMessage';

    public const string ASSISTANT_TEXT = 'assistantText';

    public const string LOOP_NUDGE = 'loopNudge';

    public const string TURN_CONTINUATION = 'turnContinuation';

    public const string WORK_SIGNAL = 'workSignal';

    public const string REPEATED_TEXT_SUPPRESSED = 'repeatedTextSuppressed';

    /** An orchestrator's message no model call has read yet (status PENDING): it owes the sub-agent a turn wherever it sits. */
    public const string UNREAD_ORCHESTRATOR_MESSAGE = 'unreadOrchestratorMessage';

    /** The text of the work signal — its replay row carries it unchanged. */
    public const string WORK_SIGNAL_TEXT = 'The orchestrator adds: the second document arrived.';

    public static function append(AIConversation $conversation, string $scriptedRowName): AIConversationMessage
    {
        return match ($scriptedRowName) {
            self::OWNER_MESSAGE => $conversation->appendMessage(AIConversationMessage::newInstance(), AIConversationMessage::MESSAGE_TEXT, 'And one more thing.', AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER),
            self::ASSISTANT_TEXT => $conversation->appendMessage(AIConversationMessage::newInstance(), AIConversationMessage::MESSAGE_TEXT, 'Here is your answer.', AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT),
            self::LOOP_NUDGE => $conversation->appendMessage(AIConversationMessage::newInstance(), AIConversationMessage::MESSAGE_SIGNAL, 'You produced no output.', AIConversationMessage::ROLE_SYSTEM, AIConversationMessage::AUTHOR_AGENT, signalSource: AIConversationMessage::SIGNAL_SOURCE_LOOP_NUDGE),
            self::TURN_CONTINUATION => $conversation->appendMessage(AIConversationMessage::newInstance(), AIConversationMessage::MESSAGE_SIGNAL, 'Checkpoint: continue working.', AIConversationMessage::ROLE_SYSTEM, AIConversationMessage::AUTHOR_AGENT, signalSource: AIConversationMessage::SIGNAL_SOURCE_TURN_CONTINUATION),
            self::WORK_SIGNAL => $conversation->appendMessage(AIConversationMessage::newInstance(), AIConversationMessage::MESSAGE_SIGNAL, self::WORK_SIGNAL_TEXT, AIConversationMessage::ROLE_SYSTEM, AIConversationMessage::AUTHOR_AGENT, signalSource: AIConversationSignalSource::ORCHESTRATOR_MESSAGE->value),
            self::REPEATED_TEXT_SUPPRESSED => $conversation->appendMessage(AIConversationMessage::newInstance(), AIConversationMessage::MESSAGE_SIGNAL, 'Your last text repeated an earlier one; it was not delivered.', AIConversationMessage::ROLE_SYSTEM, AIConversationMessage::AUTHOR_AGENT, signalSource: AIConversationMessage::SIGNAL_SOURCE_REPEATED_TEXT_SUPPRESSED),
            self::UNREAD_ORCHESTRATOR_MESSAGE => self::markPending($conversation->appendMessage(AIConversationMessage::newInstance(), AIConversationMessage::MESSAGE_SIGNAL, self::WORK_SIGNAL_TEXT, AIConversationMessage::ROLE_SYSTEM, AIConversationMessage::AUTHOR_AGENT, signalSource: AIConversationSignalSource::ORCHESTRATOR_MESSAGE->value)),
            default => throw new InvalidArgumentException('unknown scripted row ' . $scriptedRowName),
        };
    }

    protected static function markPending(AIConversationMessage $message): AIConversationMessage
    {
        $message->status = AIConversationMessage::STATUS_PENDING;
        return $message;
    }
}
