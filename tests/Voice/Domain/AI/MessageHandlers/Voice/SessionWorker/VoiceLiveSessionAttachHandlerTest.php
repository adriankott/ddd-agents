<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\MessageHandlers\Voice\SessionWorker;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Exceptions\Voice\LiveSessionGoneException;
use DDD\Domain\AI\MessageHandlers\Voice\SessionWorker\VoiceLiveSessionAttachHandler;
use DDD\Domain\AI\MessageHandlers\Voice\SessionWorker\VoiceLiveSessionAttachMessage;
use DDD\Domain\Base\Repo\DatabaseRepoEntity;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Voice\Support\AgentVoiceCallFixtures;
use DDD\Tests\Agents\Voice\Support\AgentVoiceUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

require_once __DIR__ . '/SessionAttachSleep.functions.php';

/**
 * The job that attaches the session worker to a call: the call row may not be committed yet when the job arrives, so
 * the handler looks it up up to 11 times, 500 ms apart, and gives up with a warning; the worker runs with the rights
 * restrictions off for the whole call and they are restored on every exit; a session that is gone at the provider is
 * unrecoverable (a retry fails the same way), any other failure is left to the bus's retry.
 */
class VoiceLiveSessionAttachHandlerTest extends AgentVoiceUnitTestCase
{
    public const string PROVIDER_SESSION_ID = 'live_session_seven';

    protected string $maximumExecutionTimeBeforeTheTest = '0';

    protected function setUp(): void
    {
        parent::setUp();
        $this->maximumExecutionTimeBeforeTheTest = (string)ini_get('max_execution_time');
        RecordedAttachHandlerSleeps::startRecording();
    }

    protected function tearDown(): void
    {
        RecordedAttachHandlerSleeps::stopRecording();
        set_time_limit((int)$this->maximumExecutionTimeBeforeTheTest);
        parent::tearDown();
    }

    public function testTheMessageCarriesTheSessionAndNamesItsHandler(): void
    {
        $voiceLiveSessionAttachMessage = new VoiceLiveSessionAttachMessage(static::PROVIDER_SESSION_ID);

        static::assertSame(static::PROVIDER_SESSION_ID, $voiceLiveSessionAttachMessage->providerSessionId);
        static::assertSame(VoiceLiveSessionAttachHandler::class, VoiceLiveSessionAttachMessage::$messageHandler);
    }

    public function testAFoundCallIsAttachedSynchronouslyWithTheRightsRestrictionsOff(): void
    {
        $attachHandler = $this->buildHandlerWithCall(lookupsBeforeTheCallAppears: 0);
        $rightsRestrictionsAppliedBefore = DatabaseRepoEntity::getApplyRightsRestrictions();

        $attachHandler($this->buildMessage());

        static::assertSame([['callId' => 7, 'async' => false, 'rightsRestrictionsApplied' => false]], $attachHandler->attachingSessionsService->attachRequests);
        static::assertSame([static::PROVIDER_SESSION_ID], $attachHandler->callLookupStoreService->lookedUpProviderCallIds);
        static::assertSame([], RecordedAttachHandlerSleeps::$sleptMicroseconds);
        static::assertSame($rightsRestrictionsAppliedBefore, DatabaseRepoEntity::getApplyRightsRestrictions(), 'restored after the call');
        static::assertSame('3600', ini_get('max_execution_time'), 'the longest call is the time limit of the job');
    }

    /** @return array<string, array{0: int}> */
    public static function provideLateCommits(): array
    {
        return [
            'found on the second look' => [1],
            'found on the last look' => [10],
        ];
    }

    #[DataProvider('provideLateCommits')]
    public function testACallCommittedLateIsWaitedFor(int $lookupsBeforeTheCallAppears): void
    {
        $attachHandler = $this->buildHandlerWithCall($lookupsBeforeTheCallAppears);

        $attachHandler($this->buildMessage());

        static::assertCount($lookupsBeforeTheCallAppears + 1, $attachHandler->callLookupStoreService->lookedUpProviderCallIds);
        static::assertSame(array_fill(0, $lookupsBeforeTheCallAppears, 500_000), RecordedAttachHandlerSleeps::$sleptMicroseconds);
        static::assertCount(1, $attachHandler->attachingSessionsService->attachRequests);
    }

    public function testACallThatNeverAppearsIsGivenUpWithAWarning(): void
    {
        $attachHandler = $this->buildHandlerWithCall(lookupsBeforeTheCallAppears: null);
        $rightsRestrictionsAppliedBefore = DatabaseRepoEntity::getApplyRightsRestrictions();

        $attachHandler($this->buildMessage());

        static::assertCount(11, $attachHandler->callLookupStoreService->lookedUpProviderCallIds);
        static::assertSame(array_fill(0, 10, 500_000), RecordedAttachHandlerSleeps::$sleptMicroseconds);
        static::assertSame([], $attachHandler->attachingSessionsService->attachRequests);
        static::assertSame([[
            'level' => 'warning',
            'message' => 'VoiceLiveSessionAttachHandler: no call for live_session_seven after 5000ms — nothing to attach',
        ]], $attachHandler->attachLogger->loggedLines);
        static::assertSame($rightsRestrictionsAppliedBefore, DatabaseRepoEntity::getApplyRightsRestrictions());
    }

    public function testASessionGoneAtTheProviderIsUnrecoverable(): void
    {
        $attachHandler = $this->buildHandlerWithCall(lookupsBeforeTheCallAppears: 0);
        $liveSessionGoneException = new LiveSessionGoneException('Session live_session_seven is gone at the provider scriptedLive — nothing to attach to: gone');
        $attachHandler->attachingSessionsService->attachFailure = $liveSessionGoneException;
        $rightsRestrictionsAppliedBefore = DatabaseRepoEntity::getApplyRightsRestrictions();

        try {
            $attachHandler($this->buildMessage());
            static::fail('A gone session must end the job as unrecoverable.');
        } catch (UnrecoverableMessageHandlingException $unrecoverableException) {
            static::assertSame('Session live_session_seven is gone at the provider scriptedLive — nothing to attach to: gone', $unrecoverableException->getMessage());
            static::assertSame($liveSessionGoneException, $unrecoverableException->getPrevious());
        }
        static::assertSame($rightsRestrictionsAppliedBefore, DatabaseRepoEntity::getApplyRightsRestrictions(), 'restored after the throw');
    }

    public function testAnyOtherFailureIsLeftToTheRetryAndTheRightsAreRestored(): void
    {
        $attachHandler = $this->buildHandlerWithCall(lookupsBeforeTheCallAppears: 0);
        $attachHandler->attachingSessionsService->attachFailure = new RuntimeException('the sideband connect failed after 3 attempts');
        $rightsRestrictionsAppliedBefore = DatabaseRepoEntity::getApplyRightsRestrictions();

        try {
            $attachHandler($this->buildMessage());
            static::fail('The failure must reach the bus.');
        } catch (RuntimeException $attachFailure) {
            static::assertNotInstanceOf(UnrecoverableMessageHandlingException::class, $attachFailure);
            static::assertSame('the sideband connect failed after 3 attempts', $attachFailure->getMessage());
        }
        static::assertSame($rightsRestrictionsAppliedBefore, DatabaseRepoEntity::getApplyRightsRestrictions());
    }

    public function testTheRightsStateOfAnOuterCallerIsKept(): void
    {
        $attachHandler = $this->buildHandlerWithCall(lookupsBeforeTheCallAppears: 0);
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $attachHandler($this->buildMessage());

            static::assertFalse(DatabaseRepoEntity::getApplyRightsRestrictions(), 'an outer lift is not ended by the handler');
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    protected function buildHandlerWithCall(?int $lookupsBeforeTheCallAppears): StagedVoiceLiveSessionAttachHandler
    {
        $attachHandler = new StagedVoiceLiveSessionAttachHandler();
        $agentVoiceCall = AgentVoiceCallFixtures::buildCallAt(AgentVoiceCallFixtures::NOW_UNIX_TIME, createdOffsetSeconds: -5);
        $agentVoiceCall->id = 7;
        $agentVoiceCall->providerCallId = static::PROVIDER_SESSION_ID;
        $attachHandler->callLookupStoreService->agentVoiceCall = $agentVoiceCall;
        $attachHandler->callLookupStoreService->lookupsBeforeTheCallAppears = $lookupsBeforeTheCallAppears;

        return $attachHandler;
    }

    protected function buildMessage(): VoiceLiveSessionAttachMessage
    {
        $voiceLiveSessionAttachMessage = new VoiceLiveSessionAttachMessage(static::PROVIDER_SESSION_ID);
        // the job of no signed-in account: the handler signs in nobody (a signed-in one would be read from the database)
        $voiceLiveSessionAttachMessage->accountId = null;

        return $voiceLiveSessionAttachMessage;
    }
}
