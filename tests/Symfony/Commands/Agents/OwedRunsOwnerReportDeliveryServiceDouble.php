<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentAutomationEligibility;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationOwnerReportDeliveryService;

/**
 * The owner report delivery: the obligation of every conversation the test listed belongs to an ended scope and is
 * closed with a reason; a deferral is recorded in the pass log.
 */
class OwedRunsOwnerReportDeliveryServiceDouble extends AIConversationOwnerReportDeliveryService
{
    public const string SUPPRESSION_REASON = 'SCOPE_ENDED';

    /** @var int[] */
    public array $conversationIdsOfIneligibleScopes = [];

    /**
     * @param RecoveryPassLog $recoveryPassLog
     */
    public function __construct(public RecoveryPassLog $recoveryPassLog)
    {
    }

    public function handleReportObligationForIneligibleScope(AIConversation $conversation, bool $claimHeld, ?AgentAutomationEligibility $eligibility = null): bool
    {
        if (!in_array((int)$conversation->id, $this->conversationIdsOfIneligibleScopes, true)) {
            return false;
        }
        $conversation->suppressionReason = self::SUPPRESSION_REASON;
        return true;
    }

    public function deferOwedReport(AIConversation $conversation): void
    {
        $this->recoveryPassLog->record("deferReport:{$conversation->id}");
    }
}
