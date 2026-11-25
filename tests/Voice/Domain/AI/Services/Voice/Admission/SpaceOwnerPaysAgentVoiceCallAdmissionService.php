<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Admission;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallAdmissionService;
use DDD\Domain\Common\Entities\Accounts\Account;

/**
 * The admission of an application in which the owner of the agent space (the space's `accountId`) pays for a call
 * start, whoever starts it; without a space the package's default.
 */
class SpaceOwnerPaysAgentVoiceCallAdmissionService extends AgentVoiceCallAdmissionService
{
    protected function findBilledAccountOfCallStart(Account $account, ?AgentSpace $agentSpace, ?AIConversation $conversation): Account
    {
        if ($agentSpace?->accountId === null) {
            return parent::findBilledAccountOfCallStart($account, $agentSpace, $conversation);
        }
        $ownerAccount = Account::newInstance();
        $ownerAccount->id = $agentSpace->accountId;

        return $ownerAccount;
    }
}
