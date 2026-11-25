<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Ring;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallStart;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallStartsService;
use DDD\Domain\Common\Entities\Accounts\Account;

/**
 * The call start with the purpose and conversation points a test replaces: the callable purposes come from
 * {@see $callablePurposesByName} instead of the application's registry, the purposed conversation of a ring is
 * {@see $purposedConversation} (recorded instead of created), and the call's own conversation is found in
 * {@see $callConversationsById} instead of the database. Everything else is the package's.
 */
class PurposeStagingAgentVoiceCallStartsServiceDouble extends AgentVoiceCallStartsService
{
    /** @var array<string, AIConversationPurpose> */
    public array $callablePurposesByName = [];

    public ?AIConversation $purposedConversation = null;

    /** @var array<int, array{accountId: int, purposeName: string}> */
    public array $recordedPurposedConversationRequests = [];

    protected function findCallablePurpose(string $purposeName): ?AIConversationPurpose
    {
        return $this->callablePurposesByName[$purposeName] ?? null;
    }

    /** @return string[] */
    protected function findCallablePurposeNames(): array
    {
        return array_keys($this->callablePurposesByName);
    }

    /** @var array<int, AIConversation> The conversations {@see findCallConversation()} finds, by id */
    public array $callConversationsById = [];

    protected function findCallConversation(AgentVoiceCallStart $agentVoiceCallStart): ?AIConversation
    {
        return $this->callConversationsById[(int)$agentVoiceCallStart->callConversationId] ?? null;
    }

    protected function createPurposedConversationForAppRing(Account $ownerAccount, ?AgentSpace $agentSpace, AIConversationPurpose $callPurpose): ?AIConversation
    {
        $this->recordedPurposedConversationRequests[] = ['accountId' => (int)$ownerAccount->id, 'purposeName' => $callPurpose->name];
        return $this->purposedConversation;
    }
}
