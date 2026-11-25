<?php

declare(strict_types=1);

namespace DDD\Domain\AI\MessageHandlers\Voice;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\MessageHandlers\AIDomainMessageHandler;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallStoreService;
use DDD\Domain\AI\Services\Voice\AgentVoiceRingService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * Ends ONE ring nobody answered, once its deadline has passed ({@see AgentVoiceRingDeadlineMessage}). The rights are
 * lifted in the worker (the call and the conversation its follow-up runs in need not belong to the account of the
 * message); the expiry itself decides, by the call's state and its exactly-once end claim, so a ring that ended in any
 * other way stays untouched. Never throws: a retry could only repeat a look the next turn start of the call's
 * conversation makes anyway.
 */
#[AsMessageHandler(fromTransport: AgentVoiceRingDeadlineMessage::TRANSPORT)]
class AgentVoiceRingDeadlineHandler extends AIDomainMessageHandler
{
    public function __invoke(AgentVoiceRingDeadlineMessage $message): void
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $this->setAuthAccountFromMessage($message);
            /** @var AgentVoiceCallStoreService $agentVoiceCallStoreService */
            $agentVoiceCallStoreService = DDDService::instance()->getService(AgentVoiceCallStoreService::class);
            $agentVoiceCall = $agentVoiceCallStoreService->findCall($message->agentVoiceCallId);
            if (!$agentVoiceCall instanceof AgentVoiceCall || $agentVoiceCall->endedAt !== null) {
                return;
            }
            /** @var AgentVoiceRingService $agentVoiceRingService */
            $agentVoiceRingService = DDDService::instance()->getService(AgentVoiceRingService::class);
            $agentVoiceRingService->expireOverdueRingOfCall($agentVoiceCall);
        } catch (Throwable $deadlineCheckFailure) {
            $this->logShortException($this->getLogger(), "Ring deadline check of call {$message->agentVoiceCallId}", $deadlineCheckFailure);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }
}
