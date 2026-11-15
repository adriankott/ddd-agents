<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationNotificationsService;

/** The run-end notifier of the sub-agents: records the deadline it was asked for and reports a written notification. */
class ReaperDelegationNotificationsServiceDouble extends AIConversationDelegationNotificationsService
{
    /**
     * @param RecoveryPassLog $recoveryPassLog
     */
    public function __construct(public RecoveryPassLog $recoveryPassLog)
    {
    }

    public function notifyParentOfSubAgentRunEndOfDeadline(int $deadlineId): bool
    {
        $this->recoveryPassLog->record("notify:{$deadlineId}");
        return true;
    }
}
