<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Admission;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallAdmissionService;
use DDD\Domain\Common\Entities\Accounts\Account;

/** The admission of an application in which one operator account (id 77) pays for every conversation's calls. */
class OperatorPaysAgentVoiceCallAdmissionService extends AgentVoiceCallAdmissionService
{
    protected function findBilledAccountOfConversation(AIConversation $conversation): ?Account
    {
        $operatorAccount = Account::newInstance();
        $operatorAccount->id = 77;

        return $operatorAccount;
    }
}
