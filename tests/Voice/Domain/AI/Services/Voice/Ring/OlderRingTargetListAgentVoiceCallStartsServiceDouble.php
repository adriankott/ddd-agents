<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Ring;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallStartsService;
use DDD\Domain\Common\Entities\Accounts\Account;

/**
 * An application's call start written before the reachability question with the agent space existed: it answers the
 * whole list of reachable targets itself ({@see findReachableRingTargetNames()}) and never knew the newer question.
 */
class OlderRingTargetListAgentVoiceCallStartsServiceDouble extends AgentVoiceCallStartsService
{
    /** @var string[] */
    public array $reachableRingTargetNames = [];

    public int $listReads = 0;

    /** @return string[] */
    public function callFindRingTargetNamesToRing(Account $ownerAccount, ?AgentSpace $agentSpace, ?AIConversation $ringConversation): array
    {
        return $this->findRingTargetNamesToRing($ownerAccount, $agentSpace, $ringConversation);
    }

    public function callIsAppRingableInAgentSpace(Account $ownerAccount, ?AgentSpace $agentSpace, ?AIConversation $ringConversation): bool
    {
        return $this->isAppRingableInAgentSpace($ownerAccount, $agentSpace, $ringConversation);
    }

    /** @return string[] */
    protected function findReachableRingTargetNames(Account $ownerAccount, ?AIConversation $ringConversation): array
    {
        $this->listReads++;
        return $this->reachableRingTargetNames;
    }
}
