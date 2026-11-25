<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Voice\Calls;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The answer of a task start on the transcript of a call
 * ({@see \DDD\Domain\AI\Services\Voice\AgentCallTasksService::startAgentTaskOnTranscript()}): the task was started and
 * the agent's work is everything after {@see $taskUserMessageIndex}; or it was not started, because a turn already
 * runs on the conversation ({@see self::STATUS_BUSY}) or for the reason {@see $error} names.
 */
class AgentCallTaskStart extends ValueObject
{
    /** @var string The task was started: the turn is dispatched */
    public const string STATUS_WORKING = 'working';

    /** @var string A turn still runs on the conversation: the task was NOT queued */
    public const string STATUS_BUSY = 'busy';

    /** @var string The conversation of the call is gone */
    public const string ERROR_CONVERSATION_NOT_FOUND = 'conversation_not_found';

    /** @var bool Whether the task was started */
    public bool $started = false;

    /** @var string|null {@see self::STATUS_WORKING} or {@see self::STATUS_BUSY}; null for a start that failed with an error */
    public ?string $status = null;

    /** @var string|null Why the task could not be started at all; null for a started task and for a busy conversation */
    public ?string $error = null;

    /** @var int|null The index of the signal that starts the task; null when the task was not started */
    public ?int $taskUserMessageIndex = null;

    /** The task was started by the signal at `$taskUserMessageIndex`. */
    public static function forStarted(int $taskUserMessageIndex): static
    {
        $agentCallTaskStart = new static();
        $agentCallTaskStart->started = true;
        $agentCallTaskStart->status = static::STATUS_WORKING;
        $agentCallTaskStart->taskUserMessageIndex = $taskUserMessageIndex;

        return $agentCallTaskStart;
    }

    /** A turn already runs on the conversation: nothing was queued. */
    public static function forBusy(): static
    {
        $agentCallTaskStart = new static();
        $agentCallTaskStart->status = static::STATUS_BUSY;

        return $agentCallTaskStart;
    }

    /** The task could not be started: `$error` says why. */
    public static function forError(string $error): static
    {
        $agentCallTaskStart = new static();
        $agentCallTaskStart->error = $error;

        return $agentCallTaskStart;
    }

    /** Whether the start was refused because a turn already runs on the conversation. */
    public function isBusy(): bool
    {
        return !$this->started && $this->status === static::STATUS_BUSY;
    }
}
