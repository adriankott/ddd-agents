<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\RunRelease;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoriesService;
use Throwable;

/** The memories of the release stage: the curation after a settled turn is recorded and throws when scripted. */
class RunReleaseMemoriesServiceDouble extends AgentMemoriesService
{
    public RunReleaseEventLog $eventLog;

    public ?Throwable $curationFailure = null;

    public function __construct(RunReleaseEventLog $eventLog)
    {
        // The parent service is container-managed and holds no constructor state.
        $this->eventLog = $eventLog;
    }

    public function curateConversationAfterTurn(AIConversation $conversation, bool $async): void
    {
        if ($this->curationFailure !== null) {
            $this->eventLog->record('curateMemory threw');
            throw $this->curationFailure;
        }
        $this->eventLog->record('curateMemory async=' . ($async ? 'yes' : 'no'));
    }
}
