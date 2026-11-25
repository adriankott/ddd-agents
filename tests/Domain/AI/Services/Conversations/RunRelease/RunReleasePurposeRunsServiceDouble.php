<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\RunRelease;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationPurposeRunsService;
use Throwable;

/** The purpose runs of the release stage: the purpose-goal close is recorded with whether it may close, and throws when scripted. */
class RunReleasePurposeRunsServiceDouble extends AIConversationPurposeRunsService
{
    public RunReleaseEventLog $eventLog;

    public ?Throwable $closeFailure = null;

    public function __construct(RunReleaseEventLog $eventLog)
    {
        // The parent service is container-managed and holds no constructor state.
        $this->eventLog = $eventLog;
    }

    public function closeConversationIfPurposeGoalReached(AgentRun $agentRun, AIConversation $conversation, bool $allowClose = true): void
    {
        $this->eventLog->record('closeConversationIfPurposeGoalReached allowClose=' . ($allowClose ? 'yes' : 'no'));
        if ($this->closeFailure !== null) {
            throw $this->closeFailure;
        }
    }
}
