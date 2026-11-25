<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Ring;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallsService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Base\Repo\DB\DBEntity;

/**
 * The calls service with its own logic, and with the writes into a conversation and the account read recorded instead
 * of reaching the database: the call end marker ({@see persistCallLifecycleSignalMessage()}), the follow-up turn of an
 * unanswered call, the account lookup (answered from {@see $accountsById}) and the conversation a ring creates
 * (answered by {@see $conversationCreatedForRing}).
 */
class RecordingAgentVoiceCallsServiceDouble extends AgentVoiceCallsService
{
    /** @var array<int, array{callId: int, callEnded: bool, endReason: ?string, rightsApplied: bool}> */
    public array $recordedLifecycleMarkers = [];

    /** @var int[] The call ids a follow-up turn was asked for */
    public array $recordedFollowUpCallIds = [];

    /** @var array<int, Account> */
    public array $accountsById = [];

    public ?AIConversation $conversationCreatedForRing = null;

    /** @var array<int, array{accountId: int, agentSpace: ?AgentSpace, callId: ?int}> */
    public array $recordedConversationCreations = [];

    public function __construct()
    {
    }

    protected function persistCallLifecycleSignalMessage(AgentVoiceCall $agentVoiceCall, bool $callEnded): void
    {
        $this->recordedLifecycleMarkers[] = [
            'callId' => (int)$agentVoiceCall->id,
            'callEnded' => $callEnded,
            'endReason' => $agentVoiceCall->endReason,
            'rightsApplied' => DBEntity::getApplyRightsRestrictions(),
        ];
    }

    protected function startFollowUpTurnIfAgentCallWentUnanswered(AgentVoiceCall $agentVoiceCall): void
    {
        $this->recordedFollowUpCallIds[] = (int)$agentVoiceCall->id;
    }

    protected function findAccountById(int $accountId): ?Account
    {
        return $this->accountsById[$accountId] ?? null;
    }

    protected function createCallConversation(?AgentVoiceCall $agentVoiceCall, Account $account, ?AgentSpace $agentSpace): ?AIConversation
    {
        $this->recordedConversationCreations[] = [
            'accountId' => (int)$account->id,
            'agentSpace' => $agentSpace,
            'callId' => $agentVoiceCall?->id !== null ? (int)$agentVoiceCall->id : null,
        ];
        return $this->conversationCreatedForRing;
    }
}
