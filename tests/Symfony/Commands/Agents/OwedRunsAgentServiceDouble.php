<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkerJobReceipt;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use RuntimeException;

/** The agent service recording each late delivery and each resume in the pass log; throws for the listed conversations. */
class OwedRunsAgentServiceDouble extends AIConversationAgentService
{
    public const string FAILURE_MESSAGE = 'the worker queue is down';

    /** @var int[] */
    public array $failingConversationIds = [];

    /**
     * @param RecoveryPassLog $recoveryPassLog
     */
    public function __construct(public RecoveryPassLog $recoveryPassLog)
    {
    }

    public function deliverOwedReportLate(AIConversation $conversation): void
    {
        $this->recoveryPassLog->record("deliver:{$conversation->id}");
        $this->failWhenListed($conversation);
    }

    public function resumeConversationIfReady(AIConversation $conversation, bool $async = false, ?AgentWorkerJobReceipt $agentWorkerJobReceipt = null, ?float $ownerRowSavedWallUs = null): void
    {
        $this->recoveryPassLog->record("resume:{$conversation->id}" . ($async ? '' : ':inline'));
        $this->failWhenListed($conversation);
    }

    protected function failWhenListed(AIConversation $conversation): void
    {
        if (in_array((int)$conversation->id, $this->failingConversationIds, true)) {
            throw new RuntimeException(self::FAILURE_MESSAGE);
        }
    }
}
