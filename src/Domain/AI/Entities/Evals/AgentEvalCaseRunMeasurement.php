<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * What a case row measures of one driven conversation: the conversation, its messages with those of its delegated
 * conversations ({@see AIConversation::getAllMessagesIncludingDelegations()}), its run figures
 * ({@see AgentEvalRunFigures}), the wall milliseconds the caller measured around the run, and the message trace the
 * row stores (the caller builds it with the runner's trace builder; null = none).
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalCaseRunMeasurement extends ValueObject
{
    /** @var AIConversation The driven conversation */
    public AIConversation $conversation;

    /** @var AIConversationMessages The messages of the conversation and of its delegated conversations */
    public AIConversationMessages $messagesOfTree;

    /** @var AgentEvalRunFigures The run figures of the conversation */
    public AgentEvalRunFigures $runFigures;

    /** @var int The wall milliseconds of the run, measured by the caller around it */
    public int $durationMs = 0;

    /** @var mixed The message trace the row stores under `messages`; null = none */
    public mixed $messagesTrace = null;

    /**
     * The measurement of `$conversation`, run in `$durationMs` wall milliseconds; the caller sets
     * {@see $messagesTrace} from {@see $messagesOfTree}.
     */
    public static function forConversation(AIConversation $conversation, int $durationMs): static
    {
        $agentEvalCaseRunMeasurement = static::newInstance();
        $agentEvalCaseRunMeasurement->conversation = $conversation;
        $agentEvalCaseRunMeasurement->messagesOfTree = $conversation->getAllMessagesIncludingDelegations();
        $agentEvalCaseRunMeasurement->runFigures = AgentEvalRunFigures::fromConversation($conversation);
        $agentEvalCaseRunMeasurement->durationMs = $durationMs;
        return $agentEvalCaseRunMeasurement;
    }

    /** The wall milliseconds since `$startedAtMicrotime` (a `microtime(true)`), rounded. */
    public static function measureDurationMsSince(float $startedAtMicrotime): int
    {
        return (int)round((microtime(true) - $startedAtMicrotime) * 1000);
    }

    /** The stored id of the conversation; null for a conversation that was not stored. */
    public function getConversationId(): ?int
    {
        return isset($this->conversation->id) ? (int)$this->conversation->id : null;
    }
}
