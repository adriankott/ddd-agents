<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\RecordedRuns;

use DDD\Domain\AI\Entities\UsageLogs\AIBudgetBypassGrant;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Domain\Base\Entities\MessageHandlers\RecordedRunMessageQueueHostInterface;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The host of a recorded run's message queue for ONE call of {@see AgentRecordedRunSession::runQueuedMessages()}: a
 * consumed job starts on fresh state, so after each queued message the harness's account and its process budget grant
 * — read right before the call — come back; the switches and the phase are the session's.
 *
 * A plain object of one call; no container service.
 *
 * @phpstan-consistent-constructor
 */
#[Exclude]
class AgentRecordedRunQueuedMessagesHost implements RecordedRunMessageQueueHostInterface
{
    /** The session that owns the run's switches. */
    public ?AgentRecordedRunSession $agentRecordedRunSession = null;

    /** The harness's account before the queued messages. */
    public ?Account $harnessAccount = null;

    /** The harness's process grant before the queued messages. */
    public ?AIBudgetBypassGrant $processBudgetBypassGrant = null;

    /**
     * @param AgentRecordedRunSession $agentRecordedRunSession
     * @param Account|null $harnessAccount
     * @param AIBudgetBypassGrant|null $processBudgetBypassGrant
     * @return static
     */
    public static function forSession(AgentRecordedRunSession $agentRecordedRunSession, ?Account $harnessAccount, ?AIBudgetBypassGrant $processBudgetBypassGrant): static
    {
        $agentRecordedRunQueuedMessagesHost = new static();
        $agentRecordedRunQueuedMessagesHost->agentRecordedRunSession = $agentRecordedRunSession;
        $agentRecordedRunQueuedMessagesHost->harnessAccount = $harnessAccount;
        $agentRecordedRunQueuedMessagesHost->processBudgetBypassGrant = $processBudgetBypassGrant;
        return $agentRecordedRunQueuedMessagesHost;
    }

    /** @return void */
    public function reinstallSwitchesAfterWorkerStateReset(): void
    {
        $this->agentRecordedRunSession?->reinstallSwitchesAfterWorkerStateReset();
    }

    /**
     * @param int|null $phaseOrdinal
     * @return void
     */
    public function applyPhaseOrdinal(?int $phaseOrdinal): void
    {
        $this->agentRecordedRunSession?->applyPhaseOrdinal($phaseOrdinal);
    }

    /** @return void */
    public function restoreStateAfterQueuedMessage(): void
    {
        $harnessAccount = $this->harnessAccount;
        AuthService::instance()->setAccount($harnessAccount);
        if ($this->processBudgetBypassGrant !== null) {
            /** @var AIUsageEnvelopesService $aIUsageEnvelopesService */
            $aIUsageEnvelopesService = DDDService::instance()->getService(AIUsageEnvelopesService::class);
            $aIUsageEnvelopesService->grantBudgetBypassForTheProcess($this->processBudgetBypassGrant);
        }
    }
}
