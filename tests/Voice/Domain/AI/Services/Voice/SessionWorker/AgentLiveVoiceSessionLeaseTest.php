<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\SessionWorker;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Infrastructure\Exceptions\InternalErrorException;

/**
 * The attach lease and the successor: a lease in the shared cache keeps two workers off one session; a worker finding
 * it held waits up to 20 s for it to expire (the holder died) and takes the session over, or gives up when the holder
 * keeps it; a successor books from the seconds its predecessor already booked; without a cache the call is attached
 * anyway. The connect after the lease fails at once in these cases (no sideband exists): the call then ends FAILED and
 * the attach throws, which these cases assert only where they rely on it.
 */
class AgentLiveVoiceSessionLeaseTest extends AgentLiveVoiceSessionWorkerTestCase
{
    public const string LEASE_KEY = 'voice-live-attach-lease:' . self::PROVIDER_SESSION_ID;

    public const string BASELINE_KEY = 'voice-live-billed-seconds:' . self::PROVIDER_SESSION_ID;

    public function testASecondWorkerSkipsTheSessionWhoseLeaseIsStillRefreshed(): void
    {
        $worker = $this->buildWorker();
        $this->leaseAndCounterCache->lockTokensByKey[static::LEASE_KEY] = 'token-of-the-living-holder';

        $worker->attachToCallSession(static::buildRunningCall(), async: false);

        static::assertCount(21, $this->leaseAndCounterCache->lockAcquireRequests, 'one try a second for 20 s, then the last one');
        static::assertSame(['key' => static::LEASE_KEY, 'ttlSeconds' => 15], $this->leaseAndCounterCache->lockAcquireRequests[0]);
        static::assertSame(array_fill(0, 20, 1), RecordedSessionWorkerSleeps::$sleptSeconds);
        static::assertSame([
            'LIVE ' . static::PROVIDER_SESSION_ID . ' attach lease held — waiting up to 20s for it to expire',
            'LIVE ' . static::PROVIDER_SESSION_ID . ' attach skipped — another worker holds this session\'s lease',
        ], $worker->voiceLogLines);
        static::assertSame([], $worker->voiceProviderAdapter->sidebandWebSocketUrlRequests, 'no connect');
        static::assertSame([], $worker->aIUsageEnvelopesService->openedEnvelopes, 'no envelope');
        static::assertSame([], $worker->agentVoiceCallsService->persistedCallEnds, 'the call is the holder\'s, it is not ended');
        static::assertSame([], $this->leaseAndCounterCache->lockReleaseRequests, 'the holder\'s lease is never released');
        static::assertSame(0, $this->scriptedEventLoop->runCount);
    }

    public function testAStaleLeaseIsTakenOverOnceItExpired(): void
    {
        $worker = $this->buildWorker();
        // the dead holder's lease expires after its TTL of 15 s: 15 refused tries, then the successor gets it
        $this->leaseAndCounterCache->lockAnswers = [...array_fill(0, 15, null), 'token-of-the-successor'];

        $connectFailure = $this->attachExpectingTheConnectToFail($worker);

        static::assertSame(array_fill(0, 15, 1), RecordedSessionWorkerSleeps::$sleptSeconds);
        static::assertCount(16, $this->leaseAndCounterCache->lockAcquireRequests);
        static::assertSame([static::PROVIDER_SESSION_ID, static::PROVIDER_SESSION_ID, static::PROVIDER_SESSION_ID], $worker->voiceProviderAdapter->sidebandWebSocketUrlRequests, 'the successor attaches');
        static::assertSame([['key' => static::LEASE_KEY, 'token' => 'token-of-the-successor']], $this->leaseAndCounterCache->lockReleaseRequests, 'its own lease is given back at the end');
        static::assertNull($worker->getWorkerState('attachLeaseToken'));
        static::assertInstanceOf(InternalErrorException::class, $connectFailure);
    }

    public function testTheLastTryAtTwentySecondsStillTakesTheLease(): void
    {
        $worker = $this->buildWorker();
        $this->leaseAndCounterCache->lockAnswers = [...array_fill(0, 20, null), 'token-at-the-last-try'];

        $this->attachExpectingTheConnectToFail($worker);

        static::assertCount(20, RecordedSessionWorkerSleeps::$sleptSeconds);
        static::assertSame([['key' => static::LEASE_KEY, 'token' => 'token-at-the-last-try']], $this->leaseAndCounterCache->lockReleaseRequests);
    }

    public function testASuccessorBooksFromTheStoredBaselineNotFromZero(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $this->leaseAndCounterCache->countersByKey[static::BASELINE_KEY] = 120;
        $agentVoiceCall = static::buildRunningCall();

        $this->attachExpectingTheConnectToFail($worker, $agentVoiceCall);
        static::assertSame(120, $worker->getWorkerState('billedSessionSeconds'));
        static::assertSame(['LIVE ' . static::PROVIDER_SESSION_ID . ' re-attaching — 120s already billed by a predecessor worker'], $worker->findVoiceLogLinesContaining('re-attaching'));

        $worker->receiveSessionEvent($agentVoiceCall, new RecordingSidebandWebSocket(), ['type' => 'session.usage.updated', 'usage' => ['seconds' => 150]]);

        static::assertSame([30.0], $worker->aISessionUsageService->getRecordedSeconds(), 'the predecessor\'s 120 s are not booked again');
        static::assertSame([['callId' => static::CALL_ID, 'seconds' => 30]], $worker->agentVoiceCallStoreService->bookedSeconds);
        static::assertSame(150, $worker->getWorkerState('billedSessionSeconds'));
        static::assertSame(150, $this->leaseAndCounterCache->countersByKey[static::BASELINE_KEY]);
        static::assertSame([['key' => static::BASELINE_KEY, 'by' => 30, 'ttlSeconds' => 4 * 3600]], $this->leaseAndCounterCache->counterIncrements);
    }

    public function testAFirstWorkerStartsFromZero(): void
    {
        $worker = $this->buildWorker();

        $this->attachExpectingTheConnectToFail($worker);

        static::assertSame(0, $worker->getWorkerState('billedSessionSeconds'));
        static::assertSame([], $worker->findVoiceLogLinesContaining('re-attaching'));
        static::assertSame([], RecordedSessionWorkerSleeps::$sleptSeconds);
    }

    public function testWithoutTheSharedCacheTheCallIsAttachedWithoutALease(): void
    {
        $worker = $this->buildWorker();
        $this->leaseAndCounterCache->failsEveryCall = true;

        $this->attachExpectingTheConnectToFail($worker);

        static::assertSame(['LIVE ' . static::PROVIDER_SESSION_ID . ' attach lease unavailable (shared cache unreachable) — attaching without it'], $worker->findVoiceLogLinesContaining('attach lease'));
        static::assertCount(3, $worker->voiceProviderAdapter->sidebandWebSocketUrlRequests, 'the call is attached');
        static::assertSame(0, $worker->getWorkerState('billedSessionSeconds'), 'no baseline without the cache');
        static::assertSame([], $this->leaseAndCounterCache->lockReleaseRequests, 'no lease, nothing to give back');
    }

    public function testTheWatcherRefreshesTheLeaseWithItsTokenAndLogsALossOnce(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $worker->setWorkerState('attachLeaseToken', 'token-of-this-worker');
        $this->leaseAndCounterCache->refreshKeepsTheLock = false;
        $agentVoiceCall = static::buildRunningCall();
        $sidebandWebSocket = new RecordingSidebandWebSocket();

        $worker->runWatcherTickOn($agentVoiceCall, $sidebandWebSocket);
        $worker->runWatcherTickOn($agentVoiceCall, $sidebandWebSocket);

        static::assertSame([
            ['key' => static::LEASE_KEY, 'token' => 'token-of-this-worker', 'ttlSeconds' => 15],
            ['key' => static::LEASE_KEY, 'token' => 'token-of-this-worker', 'ttlSeconds' => 15],
        ], $this->leaseAndCounterCache->lockRefreshRequests);
        static::assertSame(['LIVE ' . static::PROVIDER_SESSION_ID . ' attach lease lost — expired or taken over'], $worker->findVoiceLogLinesContaining('attach lease lost'));
        static::assertSame([], $worker->agentVoiceCallsService->persistedCallEnds, 'the worker keeps the call');
    }

    public function testAWorkerWithoutALeaseRefreshesNothing(): void
    {
        $worker = $this->buildWorkerWithConversation();

        $worker->runWatcherTickOn(static::buildRunningCall(), new RecordingSidebandWebSocket());

        static::assertSame([], $this->leaseAndCounterCache->lockRefreshRequests);
    }

    /** Runs the attach; the connect fails at once on the scripted adapter, so the attach throws. */
    protected function attachExpectingTheConnectToFail(
        StagedAgentLiveVoiceSessionsService $worker,
        ?AgentVoiceCall $agentVoiceCall = null
    ): InternalErrorException {
        try {
            $worker->attachToCallSession($agentVoiceCall ?? static::buildRunningCall(), async: false);
        } catch (InternalErrorException $connectFailure) {
            static::assertSame(
                'The sideband connect to the provider scriptedLive failed for session ' . static::PROVIDER_SESSION_ID
                . ' after 3 attempts: Cannot connect to invalid URL (invalid-scheme://sideband-attempt)',
                $connectFailure->getMessage()
            );

            return $connectFailure;
        }
        static::fail('The attach was expected to throw: no sideband can be reached on the scripted adapter.');
    }
}
