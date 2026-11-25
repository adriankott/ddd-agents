<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Admission;

use DDD\Domain\AI\Entities\ModelCalls\AIModelCall;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallAdmissionService;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Common\Entities\Accounts\Account;

/** The admission of an application whose voice session's model call enforces no budget (its override point). */
class UnenforcedBudgetAgentVoiceCallAdmissionService extends AgentVoiceCallAdmissionService
{
    public function buildSessionModelCall(Account $billedAccount, ?Entity $billedEntity, ?string $providerName = null): AIModelCall
    {
        $aiModelCall = parent::buildSessionModelCall($billedAccount, $billedEntity, $providerName);
        $aiModelCall->enforceBudget = false;

        return $aiModelCall;
    }
}
