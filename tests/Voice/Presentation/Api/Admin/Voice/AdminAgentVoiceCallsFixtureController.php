<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Presentation\Api\Admin\Voice;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Services\AISessionUsageService;
use DDD\Domain\Common\Entities\Money\MoneyAmount;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallAdmissionService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallStoreService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Presentation\Api\Admin\Voice\AdminAgentVoiceCallsTrait;

/**
 * An application's admin controller that mounts the call endpoints: the store, the admission and the pricing service
 * are the doubles the test sets (the trait's override points). The access hook records each call and refuses with
 * `admin_refused` while {@see $accessRefused} is set. While {@see $overridesListableAccountIds} is set, the account
 * hook answers {@see $listableAccountIds} (null = every account) instead of the trait's default; either way the
 * accounts it is asked for are recorded. While {@see $ledgerCostsByCallId} is set, the cost hook answers from it, as
 * an application with a cost ledger would.
 */
class AdminAgentVoiceCallsFixtureController
{
    use AdminAgentVoiceCallsTrait {
        findAccountIdsOfListableVoiceCalls as protected findAccountIdsOfListableVoiceCallsOfTrait;
        findBookedCostsOfVoiceCall as protected findBookedCostsOfVoiceCallOfTrait;
    }

    /** @var array<int, MoneyAmount|null>|null what a cost ledger booked per call id; null = the trait prices the call */
    public ?array $ledgerCostsByCallId = null;

    public ?AgentVoiceCallStoreService $agentVoiceCallStoreService = null;

    public ?AgentVoiceCallAdmissionService $agentVoiceCallAdmissionService = null;

    public ?AISessionUsageService $aISessionUsageService = null;

    public bool $accessRefused = false;

    public int $accessHookCallCount = 0;

    public bool $overridesListableAccountIds = false;

    /** @var int[]|null */
    public ?array $listableAccountIds = null;

    /** @var int[] the ids of the accounts the account hook was asked for */
    public array $accountIdsAskedForListableCalls = [];

    protected function throwForbiddenUnlessVoiceCallsAccessAllowed(): void
    {
        $this->accessHookCallCount++;
        if ($this->accessRefused) {
            throw new ForbiddenException('admin_refused');
        }
    }

    protected function findAccountIdsOfListableVoiceCalls(Account $account): ?array
    {
        $this->accountIdsAskedForListableCalls[] = (int)$account->id;
        if ($this->overridesListableAccountIds) {
            return $this->listableAccountIds;
        }

        return $this->findAccountIdsOfListableVoiceCallsOfTrait($account);
    }

    protected function findBookedCostsOfVoiceCall(AgentVoiceCall $agentVoiceCall): ?MoneyAmount
    {
        if ($this->ledgerCostsByCallId !== null) {
            return $this->ledgerCostsByCallId[(int)$agentVoiceCall->id] ?? null;
        }

        return $this->findBookedCostsOfVoiceCallOfTrait($agentVoiceCall);
    }

    protected function getVoiceCallStoreOfAdminEndpoints(): AgentVoiceCallStoreService
    {
        return $this->agentVoiceCallStoreService;
    }

    protected function getVoiceCallAdmissionServiceOfAdminEndpoints(): AgentVoiceCallAdmissionService
    {
        return $this->agentVoiceCallAdmissionService;
    }

    protected function getSessionUsageServiceOfAdminEndpoints(): AISessionUsageService
    {
        return $this->aISessionUsageService;
    }
}
