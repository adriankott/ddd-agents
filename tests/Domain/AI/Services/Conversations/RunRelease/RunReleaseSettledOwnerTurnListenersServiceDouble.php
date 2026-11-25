<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\RunRelease;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\AIConversationSettledOwnerTurnListenersService;
use Throwable;

/**
 * The settled-owner-turn listeners of the release stage, standing in for whatever an application hangs on the end of
 * an answered owner turn: the notification is recorded and throws when scripted (a listener service that cannot run).
 */
class RunReleaseSettledOwnerTurnListenersServiceDouble extends AIConversationSettledOwnerTurnListenersService
{
    public RunReleaseEventLog $eventLog;

    public ?Throwable $notificationFailure = null;

    public function __construct(RunReleaseEventLog $eventLog)
    {
        // The parent service is container-managed and holds no constructor state.
        $this->eventLog = $eventLog;
    }

    public function notifyListenersOfSettledOwnerTurn(AIConversation $conversation): void
    {
        if ($this->notificationFailure !== null) {
            $this->eventLog->record('notifySettledOwnerTurnListeners threw');
            throw $this->notificationFailure;
        }
        $this->eventLog->record('notifySettledOwnerTurnListeners');
    }
}
