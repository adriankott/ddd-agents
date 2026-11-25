<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Voice\Calls;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCalls;
use DDD\Domain\AI\Repo\DB\Voice\Calls\DBAgentVoiceCalls;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * The binding `entity` of the call store: a call is a row of the call table ({@see AgentVoiceCall}). Filter, sort and
 * pagination of a call list are typed columns, and nothing depends on how long trace events are kept.
 *
 * An application binds this onto its own table by a subclass of the entity at the same path (its own columns, its own
 * repository); nothing here names a column the base entity does not have.
 *
 * Every read and the creating write run without the entity rights: the scope of a read is its query filter (the
 * account, the conversation, the id), and the callers are routes that checked the account and workers without a
 * signed-in account.
 */
class AgentVoiceCallEntityStoreService extends Service implements AgentVoiceCallStore
{
    /** @var int How many calls a list read returns at most when the caller's query options name no number */
    public const int CALL_LIST_LIMIT = 50;

    public function createCall(AgentVoiceCall $agentVoiceCall): AgentVoiceCall
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $agentVoiceCall->update();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }

        return $agentVoiceCall;
    }

    public function findCall(int $callId): ?AgentVoiceCall
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            return $this->getCallsRepository()->findCallById($callId);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    public function findCallOfAccount(int $callId, Account $account): ?AgentVoiceCall
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            return $this->getCallsRepository()->findCallOfAccountById($callId, (int)$account->id);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    public function findCallByProviderCallId(string $providerCallId): ?AgentVoiceCall
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            return $this->getCallsRepository()->findCallByProviderCallId($providerCallId);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    public function findOpenCallOfAccount(Account $account): ?AgentVoiceCall
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            return $this->getCallsRepository()->findOpenCalls(time(), 1, (int)$account->id)?->first();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    public function findCallsOfConversation(AIConversation $conversation): AgentVoiceCalls
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            return $this->getCallsRepository()->findCallsByConversationId((int)$conversation->id) ?? AgentVoiceCalls::newInstance();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    public function findRunningCallOfConversation(AIConversation $conversation): ?AgentVoiceCall
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            return $this->getCallsRepository()->findOpenAnsweredCallByConversationId((int)$conversation->id);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    public function findCallsOfAccount(Account $account, bool $onlyOpenCalls): AgentVoiceCalls
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $agentVoiceCalls = $onlyOpenCalls
                ? $this->getCallsRepository()->findOpenCalls(time(), static::CALL_LIST_LIMIT, (int)$account->id)
                : $this->getCallsRepository()->findCallsOfAccount((int)$account->id);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }

        return $agentVoiceCalls ?? AgentVoiceCalls::newInstance();
    }

    public function claimRingingCallAnswer(AgentVoiceCall $agentVoiceCall): bool
    {
        if ($agentVoiceCall->id === null || $agentVoiceCall->startedAt !== null || $agentVoiceCall->endedAt !== null) {
            return false;
        }
        $nowUnixTime = time();
        if (!$this->getCallsRepository()->claimRingingCallAnswer((int)$agentVoiceCall->id, $nowUnixTime)) {
            return false;
        }
        $agentVoiceCall->startedAt = DateTime::fromTimestamp($nowUnixTime);

        return true;
    }

    public function claimUnansweredCallEnd(AgentVoiceCall $agentVoiceCall, string $endReason): bool
    {
        if ($agentVoiceCall->id === null || $agentVoiceCall->startedAt !== null || $agentVoiceCall->endedAt !== null) {
            return false;
        }
        $nowUnixTime = time();
        if (!$this->getCallsRepository()->claimUnansweredCallEnd((int)$agentVoiceCall->id, $endReason, $nowUnixTime)) {
            return false;
        }
        $agentVoiceCall->endedAt = DateTime::fromTimestamp($nowUnixTime);
        $agentVoiceCall->endReason = $endReason;

        return true;
    }

    public function markCallStarted(AgentVoiceCall $agentVoiceCall): void
    {
        $agentVoiceCall->markStarted();
        $agentVoiceCall->updatePartialIgnoringRights('startedAt');
    }

    public function markCallEnded(AgentVoiceCall $agentVoiceCall, string $endReason): void
    {
        $agentVoiceCall->markEnded($endReason);
        $agentVoiceCall->updatePartialIgnoringRights('endedAt', 'endReason');
    }

    public function setProviderCallIdOfCall(AgentVoiceCall $agentVoiceCall, string $providerCallId): void
    {
        $agentVoiceCall->providerCallId = $providerCallId;
        $agentVoiceCall->updatePartialIgnoringRights('providerCallId');
    }

    public function setVoiceProviderOfCall(AgentVoiceCall $agentVoiceCall, string $voiceProviderName): void
    {
        $agentVoiceCall->voiceProvider = $voiceProviderName;
        $agentVoiceCall->updatePartialIgnoringRights('voiceProvider');
    }

    public function markCallStartedByAgentInConversation(AgentVoiceCall $agentVoiceCall, AIConversation $conversation): void
    {
        $agentVoiceCall->startedByAgentInConversationId = (int)$conversation->id;
        $agentVoiceCall->updatePartialIgnoringRights('startedByAgentInConversationId');
    }

    public function addBookedSeconds(AgentVoiceCall $agentVoiceCall, int $seconds): void
    {
        if ($agentVoiceCall->id === null || $seconds <= 0) {
            return;
        }
        $this->getCallsRepository()->addBookedSeconds((int)$agentVoiceCall->id, $seconds);
        $agentVoiceCall->bookedSeconds += $seconds;
    }

    public function findBookedSecondsOfAccountOnDay(Account $account, int $dayStartUnixTime): int
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            return $this->getCallsRepository()->findBookedSecondsOfAccountOnDay((int)$account->id, $dayStartUnixTime);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    public function findRunningCallsStartedBefore(int $olderThanMinutes): AgentVoiceCalls
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            return $this->getCallsRepository()->findRunningCallsStartedBefore($olderThanMinutes) ?? AgentVoiceCalls::newInstance();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    public function findRunningCallOfConversationId(int $conversationId): ?AgentVoiceCall
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            return $this->getCallsRepository()->findOpenAnsweredCallByConversationId($conversationId);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    public function findCallsOfAccountIds(?array $accountIds): AgentVoiceCalls
    {
        if ($accountIds === []) {
            return AgentVoiceCalls::newInstance();
        }
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            return $this->getCallsRepository()->findCallsOfAccounts($accountIds) ?? AgentVoiceCalls::newInstance();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    public function findCallOfAccountIds(int $callId, ?array $accountIds): ?AgentVoiceCall
    {
        if ($accountIds === []) {
            return null;
        }
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            return $this->getCallsRepository()->findCallOfAccountsById($callId, $accountIds);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    public function findUnansweredCallsOfAccountPastRingDeadline(Account $account, int $nowUnixTime): AgentVoiceCalls
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            return $this->getCallsRepository()->findUnansweredCallsPastRingDeadline($nowUnixTime, static::CALL_LIST_LIMIT, (int)$account->id)
                ?? AgentVoiceCalls::newInstance();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    /** The repository of the call table, as the application has it (its subclass at the same path, when there is one). */
    protected function getCallsRepository(): DBAgentVoiceCalls
    {
        /** @var DBAgentVoiceCalls $callsRepository */
        $callsRepository = AgentVoiceCalls::getRepoClassInstance(LazyLoadRepo::DB);

        return $callsRepository;
    }
}
