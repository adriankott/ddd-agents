<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\RunRelease;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationToolRunService;

/** The tool-run child's turn without a model: recorded, answered with a scripted terminal state. */
class RunReleaseDelegationToolRunServiceDouble extends AIConversationDelegationToolRunService
{
    public RunReleaseEventLog $eventLog;

    public string $terminalRunStateAnswer = AIConversationRunState::COMPLETED;

    public function __construct(RunReleaseEventLog $eventLog)
    {
        // The parent service is container-managed and holds no constructor state.
        $this->eventLog = $eventLog;
    }

    public function runToolRunChildTurn(AgentRun $agentRun, AIConversation $conversation): string
    {
        $this->eventLog->record('runToolRunChildTurn answer=' . $this->terminalRunStateAnswer);
        return $this->terminalRunStateAnswer;
    }
}
