<?php

declare(strict_types=1);

namespace DDD\Domain\AI\MessageHandlers\Voice\SessionWorker;

use DDD\Domain\AI\Entities\Voice\AgentVoiceConfiguration;
use DDD\Domain\AI\Exceptions\Voice\LiveSessionGoneException;
use DDD\Domain\AI\MessageHandlers\AIDomainMessageHandler;
use DDD\Domain\AI\MessageHandlers\Voice\AgentVoiceMessengerTransports;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallStoreService;
use DDD\Domain\AI\Services\Voice\SessionWorker\AgentLiveVoiceSessionsService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Worker that accompanies ONE call for its whole duration. Thin per the message-handler convention: restore
 * auth → find the call → hand over to the ONE service method
 * {@see AgentLiveVoiceSessionsService::attachToCallSession()} with `async: false`.
 *
 * A call is minutes long — this deliberately occupies its worker for the call's lifetime. A call is ended at
 * `AI.agentVoice.limits.maximumCallSeconds` by the running-call check the session worker asks
 * ({@see \DDD\Domain\AI\Services\Voice\AgentVoiceCallAdmissionService::findRefusalOfRunningCall()}), not by the
 * time limit set here: the time a process waits on its socket does not count against it, so it stops only a worker
 * that computes without end. The clean-up of stale calls
 * ({@see AgentVoiceCallStoreService::findRunningCallsStartedBefore()}) is behind both.
 *
 * The message is acknowledged only when the handler returns, so a worker that dies mid-call (a container restart, a
 * fatal) leaves it unacknowledged and the transport redelivers it: the SUCCESSOR re-attaches to the still-running
 * session and carries the call to its end. The service's attach lease keeps two live workers off one session; a
 * session that is already gone ends the message for good instead of being retried.
 */
#[AsMessageHandler(fromTransport: AgentVoiceMessengerTransports::LIVE_SESSION)]
class VoiceLiveSessionAttachHandler extends AIDomainMessageHandler
{
    /** @var int How often the call is looked up before the handler gives up ({@see FIND_CALL_WAIT_MICROSECONDS} apart). */
    protected const int FIND_CALL_ATTEMPTS = 10;

    /** @var int Microseconds between two lookups of the call. */
    protected const int FIND_CALL_WAIT_MICROSECONDS = 500_000;

    public function __invoke(VoiceLiveSessionAttachMessage $message): void
    {
        // the limit of the worker's own computing time: waiting on the socket does not count against it, so the
        // length of a call is ended by the running-call check and a lost call by the clean-up of stale calls
        set_time_limit(AgentVoiceConfiguration::current()->maximumCallSeconds);
        DDDService::instance()->raiseMemoryLimitToAtLeast('512M');

        $this->setAuthAccountFromMessage($message);

        // The call belongs to the owner's account, but the worker reads the agent's configuration and books usage for
        // the billed account — a span across accounts, so it runs with the rights restrictions off; the finally
        // restores them on any exit.
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $agentVoiceCallStoreService = $this->getAgentVoiceCallStoreService();
            // The route that opens the call dispatches this message BEFORE its request transaction commits, and the
            // consumer regularly outraces the write by milliseconds. Poll briefly instead of giving up.
            $agentVoiceCall = $agentVoiceCallStoreService->findCallByProviderCallId($message->providerSessionId);
            $findAttempts = 0;
            while (!$agentVoiceCall && $findAttempts < static::FIND_CALL_ATTEMPTS) {
                usleep(static::FIND_CALL_WAIT_MICROSECONDS);
                $findAttempts++;
                $agentVoiceCall = $agentVoiceCallStoreService->findCallByProviderCallId($message->providerSessionId);
            }
            if (!$agentVoiceCall) {
                $this->getLogger()->warning(
                    "VoiceLiveSessionAttachHandler: no call for {$message->providerSessionId} after "
                    . intdiv($findAttempts * static::FIND_CALL_WAIT_MICROSECONDS, 1000) . 'ms — nothing to attach'
                );
                return;
            }

            try {
                $this->getAgentLiveVoiceSessionsService()->attachToCallSession($agentVoiceCall, async: false);
            } catch (LiveSessionGoneException $liveSessionGoneException) {
                // The session does not exist at the provider any more — a retry can only fail the same way.
                throw new UnrecoverableMessageHandlingException(
                    $liveSessionGoneException->getMessage(),
                    previous: $liveSessionGoneException
                );
            }
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    /** The container's {@see AgentVoiceCallStoreService}; a test replaces it here. */
    protected function getAgentVoiceCallStoreService(): AgentVoiceCallStoreService
    {
        /** @var AgentVoiceCallStoreService $agentVoiceCallStoreService */
        $agentVoiceCallStoreService = DDDService::instance()->getService(AgentVoiceCallStoreService::class);

        return $agentVoiceCallStoreService;
    }

    /** The container's {@see AgentLiveVoiceSessionsService}; a test replaces it here. */
    protected function getAgentLiveVoiceSessionsService(): AgentLiveVoiceSessionsService
    {
        /** @var AgentLiveVoiceSessionsService $agentLiveVoiceSessionsService */
        $agentLiveVoiceSessionsService = DDDService::instance()->getService(AgentLiveVoiceSessionsService::class);

        return $agentLiveVoiceSessionsService;
    }
}
