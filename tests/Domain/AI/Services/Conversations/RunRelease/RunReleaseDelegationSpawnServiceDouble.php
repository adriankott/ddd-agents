<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\RunRelease;

use DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationSpawnService;

/** The delegation spawn of the release stage: the promotion of a tree's queued children is recorded, nothing starts. */
class RunReleaseDelegationSpawnServiceDouble extends AIConversationDelegationSpawnService
{
    public RunReleaseEventLog $eventLog;

    public function __construct(RunReleaseEventLog $eventLog)
    {
        // The parent service is container-managed and holds no constructor state.
        $this->eventLog = $eventLog;
    }

    public function promoteQueuedDelegations(int $rootConversationId): array
    {
        $this->eventLog->record('promoteQueuedDelegations root=' . $rootConversationId);
        return [];
    }
}
