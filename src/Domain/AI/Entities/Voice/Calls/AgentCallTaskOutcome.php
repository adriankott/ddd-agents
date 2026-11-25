<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Voice\Calls;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Where a task started on the transcript of a call stands
 * ({@see \DDD\Domain\AI\Services\Voice\AgentCallTasksService::findAgentTaskOutcome()}): still working, completed with
 * the agent's answer, or failed (with the last answer text there is, if any).
 */
class AgentCallTaskOutcome extends ValueObject
{
    /** @var string The turn runs, or its dispatched resume has not started yet */
    public const string STATUS_WORKING = 'working';

    /** @var string The turn ended with an answer */
    public const string STATUS_COMPLETED = 'completed';

    /** @var string The turn failed, or the conversation is gone */
    public const string STATUS_FAILED = 'failed';

    /** @var string One of the STATUS_* constants */
    public string $status = self::STATUS_WORKING;

    /** @var string|null The agent's answer as the voice model reads it; null while working and for a failure without one */
    public ?string $answerText = null;

    /** The task is still worked on. */
    public static function forWorking(): static
    {
        return new static();
    }

    /** The task ended with the answer `$answerText`. */
    public static function forCompleted(string $answerText): static
    {
        $agentCallTaskOutcome = new static();
        $agentCallTaskOutcome->status = static::STATUS_COMPLETED;
        $agentCallTaskOutcome->answerText = $answerText;

        return $agentCallTaskOutcome;
    }

    /** The task failed; `$answerText` is what there is to say about it, if anything. */
    public static function forFailed(?string $answerText = null): static
    {
        $agentCallTaskOutcome = new static();
        $agentCallTaskOutcome->status = static::STATUS_FAILED;
        $agentCallTaskOutcome->answerText = $answerText;

        return $agentCallTaskOutcome;
    }

    public function isWorking(): bool
    {
        return $this->status === static::STATUS_WORKING;
    }

    public function isCompleted(): bool
    {
        return $this->status === static::STATUS_COMPLETED;
    }
}
