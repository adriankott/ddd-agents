<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Voice;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Voice\AgentVoiceConfiguration;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCalls;
use DDD\Domain\AI\Services\Voice\Calls\AgentVoiceCallEntityStoreService;
use DDD\Domain\AI\Services\Voice\Calls\AgentVoiceCallStore;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * The port through which the voice channel reads and writes calls. Where a call is kept is named by the application
 * (`AI.agentVoice.callStore`); the package ships one store, `entity`, which keeps a call as a row of the call table.
 * Routes, tools, the session worker and the admin list ask this service and never a store, so all of them work on
 * whichever store is bound.
 *
 * An application with a store of its own maps a further name in {@see getCallStoreClassNames()} through a subclass at
 * the same path. A configured name no store answers to is refused on every read and write.
 */
class AgentVoiceCallStoreService extends Service implements AgentVoiceCallStore
{
    public function createCall(AgentVoiceCall $agentVoiceCall): AgentVoiceCall
    {
        return $this->getBoundCallStore()->createCall($agentVoiceCall);
    }

    public function findCall(int $callId): ?AgentVoiceCall
    {
        return $this->getBoundCallStore()->findCall($callId);
    }

    public function findCallOfAccount(int $callId, Account $account): ?AgentVoiceCall
    {
        return $this->getBoundCallStore()->findCallOfAccount($callId, $account);
    }

    public function findCallByProviderCallId(string $providerCallId): ?AgentVoiceCall
    {
        return $this->getBoundCallStore()->findCallByProviderCallId($providerCallId);
    }

    public function findOpenCallOfAccount(Account $account): ?AgentVoiceCall
    {
        return $this->getBoundCallStore()->findOpenCallOfAccount($account);
    }

    public function findCallsOfConversation(AIConversation $conversation): AgentVoiceCalls
    {
        return $this->getBoundCallStore()->findCallsOfConversation($conversation);
    }

    public function findRunningCallOfConversation(AIConversation $conversation): ?AgentVoiceCall
    {
        return $this->getBoundCallStore()->findRunningCallOfConversation($conversation);
    }

    public function findCallsOfAccount(Account $account, bool $onlyOpenCalls): AgentVoiceCalls
    {
        return $this->getBoundCallStore()->findCallsOfAccount($account, $onlyOpenCalls);
    }

    public function claimRingingCallAnswer(AgentVoiceCall $agentVoiceCall): bool
    {
        return $this->getBoundCallStore()->claimRingingCallAnswer($agentVoiceCall);
    }

    public function claimUnansweredCallEnd(AgentVoiceCall $agentVoiceCall, string $endReason): bool
    {
        return $this->getBoundCallStore()->claimUnansweredCallEnd($agentVoiceCall, $endReason);
    }

    public function markCallStarted(AgentVoiceCall $agentVoiceCall): void
    {
        $this->getBoundCallStore()->markCallStarted($agentVoiceCall);
    }

    public function markCallEnded(AgentVoiceCall $agentVoiceCall, string $endReason): void
    {
        $this->getBoundCallStore()->markCallEnded($agentVoiceCall, $endReason);
    }

    public function setProviderCallIdOfCall(AgentVoiceCall $agentVoiceCall, string $providerCallId): void
    {
        $this->getBoundCallStore()->setProviderCallIdOfCall($agentVoiceCall, $providerCallId);
    }

    public function setVoiceProviderOfCall(AgentVoiceCall $agentVoiceCall, string $voiceProviderName): void
    {
        $this->getBoundCallStore()->setVoiceProviderOfCall($agentVoiceCall, $voiceProviderName);
    }

    public function markCallStartedByAgentInConversation(AgentVoiceCall $agentVoiceCall, AIConversation $conversation): void
    {
        $this->getBoundCallStore()->markCallStartedByAgentInConversation($agentVoiceCall, $conversation);
    }

    public function addBookedSeconds(AgentVoiceCall $agentVoiceCall, int $seconds): void
    {
        $this->getBoundCallStore()->addBookedSeconds($agentVoiceCall, $seconds);
    }

    public function findBookedSecondsOfAccountOnDay(Account $account, int $dayStartUnixTime): int
    {
        return $this->getBoundCallStore()->findBookedSecondsOfAccountOnDay($account, $dayStartUnixTime);
    }

    public function findRunningCallsStartedBefore(int $olderThanMinutes): AgentVoiceCalls
    {
        return $this->getBoundCallStore()->findRunningCallsStartedBefore($olderThanMinutes);
    }

    public function findRunningCallOfConversationId(int $conversationId): ?AgentVoiceCall
    {
        return $this->getBoundCallStore()->findRunningCallOfConversationId($conversationId);
    }

    public function findCallsOfAccountIds(?array $accountIds): AgentVoiceCalls
    {
        return $this->getBoundCallStore()->findCallsOfAccountIds($accountIds);
    }

    public function findCallOfAccountIds(int $callId, ?array $accountIds): ?AgentVoiceCall
    {
        return $this->getBoundCallStore()->findCallOfAccountIds($callId, $accountIds);
    }

    public function findUnansweredCallsOfAccountPastRingDeadline(Account $account, int $nowUnixTime): AgentVoiceCalls
    {
        return $this->getBoundCallStore()->findUnansweredCallsOfAccountPastRingDeadline($account, $nowUnixTime);
    }

    /**
     * The binding the application configured.
     *
     * @throws InternalErrorException the configured name maps to no store, or to a class that is no store
     */
    public function getBoundCallStore(): AgentVoiceCallStore
    {
        $callStoreName = AgentVoiceConfiguration::current()->callStore;
        $callStoreClassName = $this->getCallStoreClassNames()[$callStoreName] ?? null;
        if ($callStoreClassName === null || !class_exists($callStoreClassName)) {
            throw new InternalErrorException(
                'AI.agentVoice.callStore "' . $callStoreName . '" names no call store of this installation (known: '
                . implode(', ', array_keys($this->getCallStoreClassNames())) . ').'
            );
        }
        $callStore = DDDService::instance()->getService($callStoreClassName);
        if (!$callStore instanceof AgentVoiceCallStore) {
            throw new InternalErrorException('The call store "' . $callStoreName . '" (' . $callStoreClassName . ') does not implement ' . AgentVoiceCallStore::class . '.');
        }

        return $callStore;
    }

    /**
     * The stores an application can configure: the value of `AI.agentVoice.callStore` => the service that keeps calls.
     *
     * @return array<string, class-string>
     */
    protected function getCallStoreClassNames(): array
    {
        return [
            AgentVoiceConfiguration::CALL_STORE_ENTITY => AgentVoiceCallEntityStoreService::class,
        ];
    }
}
