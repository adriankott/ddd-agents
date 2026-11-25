<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\CallLife;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallsService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Cache\Cache;
use RuntimeException;

/**
 * The calls service with the reaches outside the package staged at its documented override points: the provider's
 * hang-up, the ring cancel, the account-wide ring expiry, the account read, the environment, the shared cache and the
 * conversation of a new call. Each is recorded; each can be made to fail.
 */
class StagedAgentVoiceCallsService extends AgentVoiceCallsService
{
    /** @var string[] Every reach outside, in order: "hangUp:<providerCallId>", "cancelRing:<id>", "expireRings:<accountId>", "furtherLegs:<id>" */
    public array $recordedReaches = [];

    public ?string $hangUpFailureMessage = null;

    public ?string $ringCancelFailureMessage = null;

    public ?string $ringExpiryFailureMessage = null;

    public ?string $conversationValuesFailureMessage = null;

    public bool $runsInProduction = false;

    /** @var array<int, Account> */
    public array $accountsById = [];

    public ?Cache $sharedCache = null;

    /** @var array<int, array{callDirection: ?string, accountId: int, agentSpace: ?AgentSpace}> */
    public array $newCallValueQuestions = [];

    protected function hangUpProviderSession(AgentVoiceCall $agentVoiceCall): void
    {
        $this->recordedReaches[] = 'hangUp:' . $agentVoiceCall->providerCallId;
        if ($this->hangUpFailureMessage !== null) {
            throw new RuntimeException($this->hangUpFailureMessage);
        }
    }

    protected function cancelAppRingsOfCallEndedBeforeAnswer(AgentVoiceCall $agentVoiceCall): void
    {
        $this->recordedReaches[] = 'cancelRing:' . $agentVoiceCall->id;
        if ($this->ringCancelFailureMessage !== null) {
            throw new RuntimeException($this->ringCancelFailureMessage);
        }
    }

    protected function expireOverdueRingsOfAccount(Account $account): void
    {
        $this->recordedReaches[] = 'expireRings:' . $account->id;
        if ($this->ringExpiryFailureMessage !== null) {
            throw new RuntimeException($this->ringExpiryFailureMessage);
        }
    }

    protected function endFurtherLegsOfAnsweredCallBestEffort(AgentVoiceCall $agentVoiceCall): void
    {
        $this->recordedReaches[] = 'furtherLegs:' . $agentVoiceCall->id;
    }

    protected function applyApplicationValuesToNewCall(AgentVoiceCall $agentVoiceCall, Account $account, ?AgentSpace $agentSpace): void
    {
        $this->newCallValueQuestions[] = ['callDirection' => $agentVoiceCall->direction, 'accountId' => (int)$account->id, 'agentSpace' => $agentSpace];
    }

    protected function applyApplicationValuesToNewCallConversation(AIConversation $conversation, ?AgentVoiceCall $agentVoiceCall): void
    {
        $this->recordedReaches[] = 'conversationValues:' . $conversation->accountId;
        if ($this->conversationValuesFailureMessage !== null) {
            throw new RuntimeException($this->conversationValuesFailureMessage);
        }
    }

    protected function isProductionEnvironment(): bool
    {
        return $this->runsInProduction;
    }

    protected function findAccountById(int $accountId): ?Account
    {
        $this->recordedReaches[] = 'findAccount:' . $accountId;

        return $this->accountsById[$accountId] ?? null;
    }

    protected function getSharedCache(): ?Cache
    {
        return $this->sharedCache;
    }
}
