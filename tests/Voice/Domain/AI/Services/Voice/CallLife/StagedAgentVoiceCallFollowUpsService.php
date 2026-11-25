<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\CallLife;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallReachableChannels;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallFollowUpsService;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Tests\Agents\Voice\Doubles\RecordingAgentVoiceCallReachableChannels;

/**
 * The follow-up service with its reads staged: the conversation the agent started the call from, the owner account,
 * the automation gate and what reaches the owner. Each read records whether the entity rights applied at that moment.
 */
class StagedAgentVoiceCallFollowUpsService extends AgentVoiceCallFollowUpsService
{
    public ?AIConversation $followUpConversation = null;

    public ?Account $ownerAccount = null;

    public bool $refusesAutomation = false;

    public RecordingAgentVoiceCallReachableChannels $reachableChannels;

    /** @var array<int, array{read: string, rightsApplied: bool}> */
    public array $recordedReads = [];

    public function __construct()
    {
        $this->reachableChannels = new RecordingAgentVoiceCallReachableChannels();
    }

    protected function findFollowUpConversation(AgentVoiceCall $agentVoiceCall): ?AIConversation
    {
        $this->recordedReads[] = ['read' => 'conversation:' . $agentVoiceCall->startedByAgentInConversationId, 'rightsApplied' => DBEntity::getApplyRightsRestrictions()];

        return $this->followUpConversation;
    }

    protected function findAccount(int $accountId): ?Account
    {
        $this->recordedReads[] = ['read' => 'account:' . $accountId, 'rightsApplied' => DBEntity::getApplyRightsRestrictions()];

        return $this->ownerAccount;
    }

    protected function isScopeRefusedForAutomation(AIConversation $followUpConversation): bool
    {
        $this->recordedReads[] = ['read' => 'automation:' . $followUpConversation->id . ':' . $this->getAutomationOperationName(), 'rightsApplied' => DBEntity::getApplyRightsRestrictions()];

        return $this->refusesAutomation;
    }

    protected function findReachableChannelsForOwner(
        Account $ownerAccount,
        ?AgentSpace $agentSpace,
        AIConversation $followUpConversation
    ): AgentVoiceCallReachableChannels {
        $this->recordedReads[] = ['read' => 'reachable:' . $ownerAccount->id . ':' . ($agentSpace?->id ?? 'none'), 'rightsApplied' => DBEntity::getApplyRightsRestrictions()];

        return $this->reachableChannels;
    }
}
