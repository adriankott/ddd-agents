<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\RunRelease;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationTurnSignalsService;
use Throwable;

/**
 * The turn signals of the release stage: the probe after a release ("a signal waits above the index") is recorded and
 * answers as scripted; the read mark of the replay answers or throws as scripted. The replay itself and the mid-turn
 * owner note run for real and write through the stage's messages service.
 */
class RunReleaseTurnSignalsServiceDouble extends AIConversationTurnSignalsService
{
    public RunReleaseEventLog $eventLog;

    /** The answer of the probe. */
    public bool $signalWaitingAnswer = false;

    /** The answer of the read mark (false: a row whose text an injection replaced stays waiting). */
    public bool $waitingSignalsMarkedReadAnswer = true;

    /** Thrown by the read mark (a lost claim, or any other write error). */
    public ?Throwable $waitingSignalsMarkReadFailure = null;

    public function __construct(RunReleaseEventLog $eventLog)
    {
        // The parent service is container-managed and holds no constructor state.
        $this->eventLog = $eventLog;
    }

    public function hasSignalWaitingForRunningTurnAboveIndex(AIConversation $conversation, int $aboveIndex): bool
    {
        $this->eventLog->record('probe signalWaitingAbove=' . $aboveIndex . ' answer=' . ($this->signalWaitingAnswer ? 'yes' : 'no'));
        return $this->signalWaitingAnswer;
    }

    public function markLoadedWaitingSignalsRead(AIConversation $conversation, int $aboveIndex): bool
    {
        if ($this->waitingSignalsMarkReadFailure !== null) {
            throw $this->waitingSignalsMarkReadFailure;
        }
        return $this->waitingSignalsMarkedReadAnswer;
    }
}
