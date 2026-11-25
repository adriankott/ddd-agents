<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Calls;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCalls;
use DDD\Domain\AI\Repo\DB\Voice\Calls\DBAgentVoiceCalls;
use DDD\Domain\AI\Services\Voice\Calls\AgentVoiceCallEntityStoreService;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Voice\Doubles\RecordingAgentVoiceCall;
use DDD\Tests\Agents\Voice\Doubles\RecordingDBAgentVoiceCalls;
use DDD\Tests\Agents\Voice\Doubles\RepositoryDoubleAgentVoiceCallEntityStoreService;
use DDD\Tests\Agents\Voice\Support\AgentVoiceUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * The binding `entity` of the call store over a double of the call table's repository: each method asks the
 * repository the one question it stands for, with its arguments, and answers the repository's answer; the reads and
 * the creating write run without the entity rights, and the rights come back as they were, also when the repository
 * throws. The claims and the booking write the won state onto the call; the targeted writes name their columns.
 */
class AgentVoiceCallEntityStoreServiceTest extends AgentVoiceUnitTestCase
{
    protected RecordingDBAgentVoiceCalls $callsRepository;

    protected RepositoryDoubleAgentVoiceCallEntityStoreService $entityCallStore;

    protected bool $rightsAppliedBeforeTheTest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rightsAppliedBeforeTheTest = DBEntity::getApplyRightsRestrictions();
        DBEntity::setApplyRightsRestrictions(true);
        $this->callsRepository = new RecordingDBAgentVoiceCalls();
        $this->entityCallStore = new RepositoryDoubleAgentVoiceCallEntityStoreService($this->callsRepository);
    }

    protected function tearDown(): void
    {
        DBEntity::setApplyRightsRestrictions($this->rightsAppliedBeforeTheTest);
        parent::tearDown();
    }

    public function testFindCallAsksTheRepositoryByIdWithoutRights(): void
    {
        $storedCall = $this->buildCall(9);
        $this->callsRepository->answersByMethod['findCallById'] = $storedCall;

        static::assertSame($storedCall, $this->entityCallStore->findCall(9));
        static::assertSame([['method' => 'findCallById', 'arguments' => [9], 'rightsApplied' => false]], $this->callsRepository->recordedCalls);
        static::assertTrue(DBEntity::getApplyRightsRestrictions());
    }

    public function testFindCallOfAccountAsksForTheCallOfThatAccount(): void
    {
        $storedCall = $this->buildCall(9);
        $this->callsRepository->answersByMethod['findCallOfAccountById'] = $storedCall;

        static::assertSame($storedCall, $this->entityCallStore->findCallOfAccount(9, $this->buildAccount(5)));
        static::assertSame([['method' => 'findCallOfAccountById', 'arguments' => [9, 5], 'rightsApplied' => false]], $this->callsRepository->recordedCalls);
    }

    public function testFindCallByProviderCallIdAsksByTheProvidersId(): void
    {
        $storedCall = $this->buildCall(9);
        $this->callsRepository->answersByMethod['findCallByProviderCallId'] = $storedCall;

        static::assertSame($storedCall, $this->entityCallStore->findCallByProviderCallId('session-41'));
        static::assertSame([['method' => 'findCallByProviderCallId', 'arguments' => ['session-41'], 'rightsApplied' => false]], $this->callsRepository->recordedCalls);
    }

    public function testFindOpenCallOfAccountAsksForTheNewestOpenCallNow(): void
    {
        $newestOpenCall = $this->buildCall(9);
        $this->callsRepository->answersByMethod['findOpenCalls'] = $this->buildCallSet($newestOpenCall, $this->buildCall(8));
        $beforeUnixTime = time();

        static::assertSame($newestOpenCall, $this->entityCallStore->findOpenCallOfAccount($this->buildAccount(5)));

        $recordedCall = $this->callsRepository->recordedCalls[0];
        static::assertSame('findOpenCalls', $recordedCall['method']);
        static::assertGreaterThanOrEqual($beforeUnixTime, $recordedCall['arguments'][0]);
        static::assertLessThanOrEqual(time(), $recordedCall['arguments'][0]);
        static::assertSame([1, 5], array_slice($recordedCall['arguments'], 1));
        static::assertFalse($recordedCall['rightsApplied']);
    }

    public function testFindOpenCallOfAccountIsNullWhenNoCallIsOpen(): void
    {
        static::assertNull($this->entityCallStore->findOpenCallOfAccount($this->buildAccount(5)));
    }

    public function testFindCallsOfConversationAsksByTheConversationId(): void
    {
        $storedCalls = $this->buildCallSet($this->buildCall(8));
        $this->callsRepository->answersByMethod['findCallsByConversationId'] = $storedCalls;

        static::assertSame($storedCalls, $this->entityCallStore->findCallsOfConversation($this->buildConversation(30)));
        static::assertSame([['method' => 'findCallsByConversationId', 'arguments' => [30, 50], 'rightsApplied' => false]], $this->callsRepository->recordedCalls);
    }

    public function testFindCallsOfConversationIsAnEmptySetWithoutAnAnswer(): void
    {
        $agentVoiceCalls = $this->entityCallStore->findCallsOfConversation($this->buildConversation(30));

        static::assertInstanceOf(AgentVoiceCalls::class, $agentVoiceCalls);
        static::assertSame(0, $agentVoiceCalls->count());
    }

    public function testFindRunningCallOfConversationAsksForTheOpenAnsweredCall(): void
    {
        $runningCall = $this->buildCall(8);
        $this->callsRepository->answersByMethod['findOpenAnsweredCallByConversationId'] = $runningCall;

        static::assertSame($runningCall, $this->entityCallStore->findRunningCallOfConversation($this->buildConversation(30)));
        static::assertSame([['method' => 'findOpenAnsweredCallByConversationId', 'arguments' => [30], 'rightsApplied' => false]], $this->callsRepository->recordedCalls);
    }

    public function testFindCallsOfAccountReadsTheWholeLog(): void
    {
        $storedCalls = $this->buildCallSet($this->buildCall(9), $this->buildCall(8));
        $this->callsRepository->answersByMethod['findCallsOfAccount'] = $storedCalls;

        static::assertSame($storedCalls, $this->entityCallStore->findCallsOfAccount($this->buildAccount(5), false));
        static::assertSame([['method' => 'findCallsOfAccount', 'arguments' => [5], 'rightsApplied' => false]], $this->callsRepository->recordedCalls);
    }

    public function testFindCallsOfAccountReadsOnlyTheOpenCallsUpToTheListLimit(): void
    {
        $openCalls = $this->buildCallSet($this->buildCall(9));
        $this->callsRepository->answersByMethod['findOpenCalls'] = $openCalls;
        $beforeUnixTime = time();

        static::assertSame($openCalls, $this->entityCallStore->findCallsOfAccount($this->buildAccount(5), true));

        $recordedCall = $this->callsRepository->recordedCalls[0];
        static::assertSame('findOpenCalls', $recordedCall['method']);
        static::assertGreaterThanOrEqual($beforeUnixTime, $recordedCall['arguments'][0]);
        static::assertLessThanOrEqual(time(), $recordedCall['arguments'][0]);
        static::assertSame([50, 5], array_slice($recordedCall['arguments'], 1));
        static::assertFalse($recordedCall['rightsApplied']);
        static::assertSame(50, RepositoryDoubleAgentVoiceCallEntityStoreService::CALL_LIST_LIMIT);
    }

    #[DataProvider('provideOnlyOpenCalls')]
    public function testFindCallsOfAccountIsAnEmptySetWithoutAnAnswer(bool $onlyOpenCalls): void
    {
        $agentVoiceCalls = $this->entityCallStore->findCallsOfAccount($this->buildAccount(5), $onlyOpenCalls);

        static::assertInstanceOf(AgentVoiceCalls::class, $agentVoiceCalls);
        static::assertSame(0, $agentVoiceCalls->count());
    }

    /** @return array<string, array{bool}> */
    public static function provideOnlyOpenCalls(): array
    {
        return ['the whole log' => [false], 'the open calls' => [true]];
    }

    public function testFindBookedSecondsOfAccountOnDayAsksForThatDay(): void
    {
        $this->callsRepository->answersByMethod['findBookedSecondsOfAccountOnDay'] = 1260;

        static::assertSame(1260, $this->entityCallStore->findBookedSecondsOfAccountOnDay($this->buildAccount(5), 1_799_971_200));
        static::assertSame([['method' => 'findBookedSecondsOfAccountOnDay', 'arguments' => [5, 1_799_971_200], 'rightsApplied' => false]], $this->callsRepository->recordedCalls);
    }

    public function testFindRunningCallsStartedBeforeAsksWithTheMinutes(): void
    {
        $leftOpenCalls = $this->buildCallSet($this->buildCall(3));
        $this->callsRepository->answersByMethod['findRunningCallsStartedBefore'] = $leftOpenCalls;

        static::assertSame($leftOpenCalls, $this->entityCallStore->findRunningCallsStartedBefore(75));
        static::assertSame([['method' => 'findRunningCallsStartedBefore', 'arguments' => [75], 'rightsApplied' => false]], $this->callsRepository->recordedCalls);
    }

    public function testFindRunningCallsStartedBeforeIsAnEmptySetWithoutAnAnswer(): void
    {
        static::assertSame(0, $this->entityCallStore->findRunningCallsStartedBefore(75)->count());
    }

    #[DataProvider('provideRightsFreeReads')]
    public function testTheRightsComeBackWhenTheRepositoryThrows(string $storeMethodName): void
    {
        $this->callsRepository->failureMessage = 'The call table is gone.';

        try {
            $this->callStoreMethod($storeMethodName);
            static::fail($storeMethodName . '() did not let the repository\'s exception through.');
        } catch (RuntimeException $runtimeException) {
            static::assertSame('The call table is gone.', $runtimeException->getMessage());
        }

        static::assertFalse($this->callsRepository->recordedCalls[0]['rightsApplied']);
        static::assertTrue(DBEntity::getApplyRightsRestrictions());
    }

    #[DataProvider('provideRightsFreeReads')]
    public function testTheRightsComeBackAsTheyWereBefore(string $storeMethodName): void
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $this->callStoreMethod($storeMethodName);
            static::assertFalse(DBEntity::getApplyRightsRestrictions());
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        static::assertTrue(DBEntity::getApplyRightsRestrictions());
    }

    /** @return array<string, array{string}> Every method of the binding that reads without the entity rights */
    public static function provideRightsFreeReads(): array
    {
        return [
            'findCall' => ['findCall'],
            'findCallOfAccount' => ['findCallOfAccount'],
            'findCallByProviderCallId' => ['findCallByProviderCallId'],
            'findOpenCallOfAccount' => ['findOpenCallOfAccount'],
            'findCallsOfConversation' => ['findCallsOfConversation'],
            'findRunningCallOfConversation' => ['findRunningCallOfConversation'],
            'findCallsOfAccount, the whole log' => ['findCallsOfAccount'],
            'findCallsOfAccount, the open calls' => ['findOpenCallsOfAccount'],
            'findBookedSecondsOfAccountOnDay' => ['findBookedSecondsOfAccountOnDay'],
            'findRunningCallsStartedBefore' => ['findRunningCallsStartedBefore'],
        ];
    }

    public function testCreateCallWritesTheWholeCallWithoutRightsAndAnswersIt(): void
    {
        $newCall = new RecordingAgentVoiceCall();
        $newCall->idAssignedByFullWrite = 12;

        static::assertSame($newCall, $this->entityCallStore->createCall($newCall));
        static::assertSame(12, $newCall->id);
        static::assertSame([['write' => 'update', 'propertyNames' => [], 'rightsApplied' => false]], $newCall->recordedWrites);
        static::assertTrue(DBEntity::getApplyRightsRestrictions());
    }

    public function testCreateCallBringsTheRightsBackWhenTheWriteThrows(): void
    {
        $newCall = new RecordingAgentVoiceCall();
        $newCall->writeFailureMessage = 'Duplicate provider session.';

        try {
            $this->entityCallStore->createCall($newCall);
            static::fail('createCall() did not let the write\'s exception through.');
        } catch (RuntimeException $runtimeException) {
            static::assertSame('Duplicate provider session.', $runtimeException->getMessage());
        }
        static::assertTrue(DBEntity::getApplyRightsRestrictions());
    }

    public function testClaimRingingCallAnswerWinsAndSetsTheAnswerTime(): void
    {
        $ringingCall = $this->buildCall(9);
        $this->callsRepository->answersByMethod['claimRingingCallAnswer'] = true;
        $beforeUnixTime = time();

        static::assertTrue($this->entityCallStore->claimRingingCallAnswer($ringingCall));

        $recordedCall = $this->callsRepository->recordedCalls[0];
        static::assertSame('claimRingingCallAnswer', $recordedCall['method']);
        static::assertSame(9, $recordedCall['arguments'][0]);
        static::assertGreaterThanOrEqual($beforeUnixTime, $recordedCall['arguments'][1]);
        static::assertLessThanOrEqual(time(), $recordedCall['arguments'][1]);
        static::assertSame($recordedCall['arguments'][1], $ringingCall->startedAt->getTimestamp());
        static::assertNull($ringingCall->endedAt);
    }

    public function testClaimRingingCallAnswerThatLostLeavesTheCallAsItWas(): void
    {
        $ringingCall = $this->buildCall(9);
        $this->callsRepository->answersByMethod['claimRingingCallAnswer'] = false;

        static::assertFalse($this->entityCallStore->claimRingingCallAnswer($ringingCall));
        static::assertSame(['claimRingingCallAnswer'], $this->callsRepository->getRecordedMethodNames());
        static::assertNull($ringingCall->startedAt);
    }

    #[DataProvider('provideCallsThatCannotBeClaimed')]
    public function testClaimRingingCallAnswerOfACallThatCannotRingAsksNobody(?int $callId, bool $answered, bool $ended): void
    {
        $agentVoiceCall = $this->buildCallInState($callId, $answered, $ended);
        $startedAtBefore = $agentVoiceCall->startedAt;
        $this->callsRepository->answersByMethod['claimRingingCallAnswer'] = true;

        static::assertFalse($this->entityCallStore->claimRingingCallAnswer($agentVoiceCall));
        static::assertSame([], $this->callsRepository->recordedCalls);
        static::assertSame($startedAtBefore, $agentVoiceCall->startedAt);
    }

    public function testClaimUnansweredCallEndWinsAndSetsTheEnd(): void
    {
        $ringingCall = $this->buildCall(9);
        $this->callsRepository->answersByMethod['claimUnansweredCallEnd'] = true;
        $beforeUnixTime = time();

        static::assertTrue($this->entityCallStore->claimUnansweredCallEnd($ringingCall, AgentVoiceCall::END_REASON_DECLINED));

        $recordedCall = $this->callsRepository->recordedCalls[0];
        static::assertSame('claimUnansweredCallEnd', $recordedCall['method']);
        static::assertSame([9, 'DECLINED'], array_slice($recordedCall['arguments'], 0, 2));
        static::assertGreaterThanOrEqual($beforeUnixTime, $recordedCall['arguments'][2]);
        static::assertLessThanOrEqual(time(), $recordedCall['arguments'][2]);
        static::assertSame($recordedCall['arguments'][2], $ringingCall->endedAt->getTimestamp());
        static::assertSame('DECLINED', $ringingCall->endReason);
        static::assertNull($ringingCall->startedAt);
    }

    public function testClaimUnansweredCallEndThatLostLeavesTheCallAsItWas(): void
    {
        $ringingCall = $this->buildCall(9);
        $this->callsRepository->answersByMethod['claimUnansweredCallEnd'] = false;

        static::assertFalse($this->entityCallStore->claimUnansweredCallEnd($ringingCall, AgentVoiceCall::END_REASON_EXPIRED));
        static::assertSame(['claimUnansweredCallEnd'], $this->callsRepository->getRecordedMethodNames());
        static::assertNull($ringingCall->endedAt);
        static::assertNull($ringingCall->endReason);
    }

    #[DataProvider('provideCallsThatCannotBeClaimed')]
    public function testClaimUnansweredCallEndOfACallThatCannotRingAsksNobody(?int $callId, bool $answered, bool $ended): void
    {
        $agentVoiceCall = $this->buildCallInState($callId, $answered, $ended);
        $endedAtBefore = $agentVoiceCall->endedAt;
        $this->callsRepository->answersByMethod['claimUnansweredCallEnd'] = true;

        static::assertFalse($this->entityCallStore->claimUnansweredCallEnd($agentVoiceCall, AgentVoiceCall::END_REASON_EXPIRED));
        static::assertSame([], $this->callsRepository->recordedCalls);
        static::assertSame($endedAtBefore, $agentVoiceCall->endedAt);
        static::assertNull($agentVoiceCall->endReason);
    }

    /** @return array<string, array{?int, bool, bool}> call id, answered, ended */
    public static function provideCallsThatCannotBeClaimed(): array
    {
        return [
            'not stored' => [null, false, false],
            'answered' => [9, true, false],
            'ended' => [9, false, true],
            'answered and ended' => [9, true, true],
        ];
    }

    public function testMarkCallStartedWritesOnlyTheAnswerTime(): void
    {
        $agentVoiceCall = new RecordingAgentVoiceCall();
        $agentVoiceCall->id = 9;
        $beforeUnixTime = time();

        $this->entityCallStore->markCallStarted($agentVoiceCall);

        static::assertGreaterThanOrEqual($beforeUnixTime, $agentVoiceCall->startedAt->getTimestamp());
        static::assertSame([['write' => 'updatePartialIgnoringRights', 'propertyNames' => ['startedAt'], 'rightsApplied' => true]], $agentVoiceCall->recordedWrites);
    }

    public function testMarkCallEndedWritesOnlyTheEndAndItsReason(): void
    {
        $agentVoiceCall = new RecordingAgentVoiceCall();
        $agentVoiceCall->id = 9;
        $beforeUnixTime = time();

        $this->entityCallStore->markCallEnded($agentVoiceCall, AgentVoiceCall::END_REASON_ENDED_BY_AGENT);

        static::assertGreaterThanOrEqual($beforeUnixTime, $agentVoiceCall->endedAt->getTimestamp());
        static::assertSame('ENDED_BY_AGENT', $agentVoiceCall->endReason);
        static::assertSame([['write' => 'updatePartialIgnoringRights', 'propertyNames' => ['endedAt', 'endReason'], 'rightsApplied' => true]], $agentVoiceCall->recordedWrites);
    }

    public function testSetProviderCallIdOfCallWritesOnlyTheProvidersId(): void
    {
        $agentVoiceCall = new RecordingAgentVoiceCall();
        $agentVoiceCall->id = 9;

        $this->entityCallStore->setProviderCallIdOfCall($agentVoiceCall, 'session-41');

        static::assertSame('session-41', $agentVoiceCall->providerCallId);
        static::assertSame([['write' => 'updatePartialIgnoringRights', 'propertyNames' => ['providerCallId'], 'rightsApplied' => true]], $agentVoiceCall->recordedWrites);
    }

    public function testSetVoiceProviderOfCallWritesOnlyTheAdapterName(): void
    {
        $agentVoiceCall = new RecordingAgentVoiceCall();
        $agentVoiceCall->id = 9;

        $this->entityCallStore->setVoiceProviderOfCall($agentVoiceCall, 'firstVoice');

        static::assertSame('firstVoice', $agentVoiceCall->voiceProvider);
        static::assertSame([['write' => 'updatePartialIgnoringRights', 'propertyNames' => ['voiceProvider'], 'rightsApplied' => true]], $agentVoiceCall->recordedWrites);
    }

    public function testMarkCallStartedByAgentInConversationWritesOnlyThatConversation(): void
    {
        $agentVoiceCall = new RecordingAgentVoiceCall();
        $agentVoiceCall->id = 9;

        $this->entityCallStore->markCallStartedByAgentInConversation($agentVoiceCall, $this->buildConversation(31));

        static::assertSame(31, $agentVoiceCall->startedByAgentInConversationId);
        static::assertSame([['write' => 'updatePartialIgnoringRights', 'propertyNames' => ['startedByAgentInConversationId'], 'rightsApplied' => true]], $agentVoiceCall->recordedWrites);
    }

    public function testAddBookedSecondsAddsInTheTableAndOnTheCall(): void
    {
        $agentVoiceCall = $this->buildCall(9);
        $agentVoiceCall->bookedSeconds = 60;

        $this->entityCallStore->addBookedSeconds($agentVoiceCall, 30);

        static::assertSame([['method' => 'addBookedSeconds', 'arguments' => [9, 30], 'rightsApplied' => true]], $this->callsRepository->recordedCalls);
        static::assertSame(90, $agentVoiceCall->bookedSeconds);
    }

    #[DataProvider('provideBookingsThatWriteNothing')]
    public function testAddBookedSecondsWritesNothing(?int $callId, int $seconds): void
    {
        $agentVoiceCall = $this->buildCall($callId);
        $agentVoiceCall->bookedSeconds = 60;

        $this->entityCallStore->addBookedSeconds($agentVoiceCall, $seconds);

        static::assertSame([], $this->callsRepository->recordedCalls);
        static::assertSame(60, $agentVoiceCall->bookedSeconds);
    }

    /** @return array<string, array{?int, int}> */
    public static function provideBookingsThatWriteNothing(): array
    {
        return [
            'a call that is not stored' => [null, 30],
            'no seconds' => [9, 0],
            'negative seconds' => [9, -30],
        ];
    }

    public function testTheBindingAsksTheRepositoryOfTheCallTableByDefault(): void
    {
        $agentVoiceCallEntityStoreService = DDDService::instance()->getService(AgentVoiceCallEntityStoreService::class);

        $callsRepository = static::invokeMethod($agentVoiceCallEntityStoreService, 'getCallsRepository');

        static::assertSame(DBAgentVoiceCalls::class, $callsRepository::class);
    }

    /** Calls one method of the binding with fixed arguments; `findOpenCallsOfAccount` is findCallsOfAccount() of the open calls. */
    protected function callStoreMethod(string $storeMethodName): mixed
    {
        return match ($storeMethodName) {
            'findCall' => $this->entityCallStore->findCall(9),
            'findCallOfAccount' => $this->entityCallStore->findCallOfAccount(9, $this->buildAccount(5)),
            'findCallByProviderCallId' => $this->entityCallStore->findCallByProviderCallId('session-41'),
            'findOpenCallOfAccount' => $this->entityCallStore->findOpenCallOfAccount($this->buildAccount(5)),
            'findCallsOfConversation' => $this->entityCallStore->findCallsOfConversation($this->buildConversation(30)),
            'findRunningCallOfConversation' => $this->entityCallStore->findRunningCallOfConversation($this->buildConversation(30)),
            'findCallsOfAccount' => $this->entityCallStore->findCallsOfAccount($this->buildAccount(5), false),
            'findOpenCallsOfAccount' => $this->entityCallStore->findCallsOfAccount($this->buildAccount(5), true),
            'findBookedSecondsOfAccountOnDay' => $this->entityCallStore->findBookedSecondsOfAccountOnDay($this->buildAccount(5), 1_799_971_200),
            'findRunningCallsStartedBefore' => $this->entityCallStore->findRunningCallsStartedBefore(75),
        };
    }

    protected function buildCall(?int $callId): AgentVoiceCall
    {
        $agentVoiceCall = new AgentVoiceCall();
        $agentVoiceCall->id = $callId;
        return $agentVoiceCall;
    }

    protected function buildCallInState(?int $callId, bool $answered, bool $ended): AgentVoiceCall
    {
        $agentVoiceCall = $this->buildCall($callId);
        $agentVoiceCall->startedAt = $answered ? DateTime::fromTimestamp(1_799_999_000) : null;
        $agentVoiceCall->endedAt = $ended ? DateTime::fromTimestamp(1_799_999_500) : null;
        return $agentVoiceCall;
    }

    protected function buildCallSet(AgentVoiceCall ...$agentVoiceCalls): AgentVoiceCalls
    {
        $agentVoiceCallSet = new AgentVoiceCalls();
        foreach ($agentVoiceCalls as $agentVoiceCall) {
            $agentVoiceCallSet->add($agentVoiceCall);
        }
        return $agentVoiceCallSet;
    }

    protected function buildAccount(int $accountId): Account
    {
        $account = Account::newInstance();
        $account->id = $accountId;
        return $account;
    }

    protected function buildConversation(int $conversationId): AIConversation
    {
        $aiConversation = AIConversation::newInstance();
        $aiConversation->id = $conversationId;
        return $aiConversation;
    }
}
