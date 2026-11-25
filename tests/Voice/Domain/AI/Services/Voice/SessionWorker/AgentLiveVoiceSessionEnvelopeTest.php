<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\SessionWorker;

use DDD\Domain\AI\Entities\UsageLogs\Enums\AIUsageInitiator;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallAdmissionService;
use DDD\Infrastructure\Exceptions\InternalErrorException;

/**
 * The usage envelope of a session: the worker opens ONE per call for the call's account, under the admission's
 * envelope scope and as a Messenger job, and closes it when the call's loop has ended; it opens none when the worker
 * already runs inside one (an application's bus middleware), and a call whose envelope cannot be opened still runs.
 */
class AgentLiveVoiceSessionEnvelopeTest extends AgentLiveVoiceSessionWorkerTestCase
{
    public function testOneEnvelopeIsOpenedForTheCallAndClosedAfterTheLoop(): void
    {
        $worker = $this->buildWorkerWithConversation();

        $this->attachExpectingTheConnectToFail($worker, static::buildRunningCall());

        static::assertSame([[
            'accountId' => static::ACCOUNT_ID,
            'scope' => $worker->agentVoiceCallAdmissionService->getSessionEnvelopeScope(),
            'initiatedBy' => AIUsageInitiator::MESSENGER,
        ]], $worker->aIUsageEnvelopesService->openedEnvelopes);
        static::assertSame(AgentVoiceCallAdmissionService::ENVELOPE_SCOPE_SESSION, $worker->agentVoiceCallAdmissionService->getSessionEnvelopeScope());
        static::assertCount(1, $worker->aIUsageEnvelopesService->closedEnvelopes);
        static::assertSame(1, $worker->aIUsageEnvelopesService->closedEnvelopes[0]?->envelopeId, 'the envelope it opened is the one closed');
    }

    public function testACallWithoutAnAccountGetsAnEnvelopeWithoutOne(): void
    {
        $worker = $this->buildWorkerWithConversation();

        $this->attachExpectingTheConnectToFail($worker, static::buildRunningCall(accountId: null));

        static::assertNull($worker->aIUsageEnvelopesService->openedEnvelopes[0]['accountId']);
    }

    public function testNoEnvelopeIsOpenedInsideAnOpenOne(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $worker->aIUsageEnvelopesService->envelopeIsOpenAlready = true;

        $this->attachExpectingTheConnectToFail($worker, static::buildRunningCall());

        static::assertSame([], $worker->aIUsageEnvelopesService->openedEnvelopes);
        static::assertSame([], $worker->aIUsageEnvelopesService->closedEnvelopes, 'the open envelope is not the worker\'s to close');
    }

    public function testACallWhoseEnvelopeCannotBeOpenedStillRuns(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $worker->aIUsageEnvelopesService->openingFails = true;

        $this->attachExpectingTheConnectToFail($worker, static::buildRunningCall());

        static::assertSame(['LIVE live_session_seven usage envelope not opened: ' . RecordingAIUsageEnvelopesService::OPENING_REFUSAL_MESSAGE], $worker->findVoiceLogLinesContaining('usage envelope'));
        static::assertSame(1, $this->scriptedEventLoop->runCount, 'the loop ran');
        static::assertSame([], $worker->aIUsageEnvelopesService->closedEnvelopes);
    }

    public function testASkippedAttachOpensNoEnvelope(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $this->leaseAndCounterCache->lockTokensByKey['voice-live-attach-lease:' . static::PROVIDER_SESSION_ID] = 'token-of-the-living-holder';

        $worker->attachToCallSession(static::buildRunningCall(), async: false);

        static::assertSame([], $worker->aIUsageEnvelopesService->openedEnvelopes);
    }

    protected function attachExpectingTheConnectToFail(StagedAgentLiveVoiceSessionsService $worker, AgentVoiceCall $agentVoiceCall): void
    {
        try {
            $worker->attachToCallSession($agentVoiceCall, async: false);
            static::fail('The attach was expected to throw: no sideband can be reached on the scripted adapter.');
        } catch (InternalErrorException $connectFailure) {
            static::assertSame(
                'The sideband connect to the provider scriptedLive failed for session live_session_seven after 3 attempts: Cannot connect to invalid URL (invalid-scheme://sideband-attempt)',
                $connectFailure->getMessage()
            );
        }
    }
}
