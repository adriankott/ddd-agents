<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Calls\StoreAdditions;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCalls;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Tests\Agents\Voice\Doubles\RecordingAgentVoiceCall;
use DDD\Tests\Agents\Voice\Doubles\RepositoryDoubleAgentVoiceCallEntityStoreService;
use DDD\Tests\Agents\Voice\Support\AgentVoiceCallFixtures;
use DDD\Tests\Agents\Voice\Support\AgentVoiceUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * The reads and the write the `entity` store gained after its core tests: the provider pin, the running call by
 * conversation id, the calls of several accounts (null = every account, [] = no call without a question to the
 * repository) and the rings past their deadline. The reads run without the entity rights and give them back, also
 * when the repository throws.
 */
class AgentVoiceCallEntityStoreAdditionsTest extends AgentVoiceUnitTestCase
{
    protected AccountReadsRecordingDBAgentVoiceCalls $callsRepository;

    protected RepositoryDoubleAgentVoiceCallEntityStoreService $entityCallStore;

    protected bool $rightsAppliedBeforeTheTest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rightsAppliedBeforeTheTest = DBEntity::getApplyRightsRestrictions();
        DBEntity::setApplyRightsRestrictions(true);
        $this->callsRepository = new AccountReadsRecordingDBAgentVoiceCalls();
        $this->entityCallStore = new RepositoryDoubleAgentVoiceCallEntityStoreService($this->callsRepository);
    }

    protected function tearDown(): void
    {
        DBEntity::setApplyRightsRestrictions($this->rightsAppliedBeforeTheTest);
        parent::tearDown();
    }

    public function testThePinnedProviderIsWrittenAsItsOneColumn(): void
    {
        $agentVoiceCall = new RecordingAgentVoiceCall();
        $agentVoiceCall->id = 7;

        $this->entityCallStore->setVoiceProviderOfCall($agentVoiceCall, 'openAiLive');

        static::assertSame('openAiLive', $agentVoiceCall->voiceProvider);
        static::assertSame([['write' => 'updatePartialIgnoringRights', 'propertyNames' => ['voiceProvider'], 'rightsApplied' => true]], $agentVoiceCall->recordedWrites);
    }

    public function testTheRunningCallOfAConversationIdIsTheOpenAnsweredOne(): void
    {
        $runningCall = $this->buildCall(8);
        $this->callsRepository->answersByMethod['findOpenAnsweredCallByConversationId'] = $runningCall;

        static::assertSame($runningCall, $this->entityCallStore->findRunningCallOfConversationId(30));
        static::assertSame([['method' => 'findOpenAnsweredCallByConversationId', 'arguments' => [30], 'rightsApplied' => false]], $this->callsRepository->recordedCalls);
        static::assertTrue(DBEntity::getApplyRightsRestrictions());
    }

    /** @param int[]|null $accountIds */
    #[DataProvider('provideAccountIdLists')]
    public function testTheCallsOfAccountIdsAreAskedOfTheRepositoryAsGiven(?array $accountIds): void
    {
        $storedCalls = $this->buildCallSet($this->buildCall(9));
        $this->callsRepository->answersByMethod['findCallsOfAccounts'] = $storedCalls;

        static::assertSame($storedCalls, $this->entityCallStore->findCallsOfAccountIds($accountIds));
        static::assertSame([['method' => 'findCallsOfAccounts', 'arguments' => [$accountIds], 'rightsApplied' => false]], $this->callsRepository->recordedCalls);
        static::assertTrue(DBEntity::getApplyRightsRestrictions());
    }

    /** @param int[]|null $accountIds */
    #[DataProvider('provideAccountIdLists')]
    public function testTheCallOfAccountIdsIsAskedOfTheRepositoryAsGiven(?array $accountIds): void
    {
        $storedCall = $this->buildCall(9);
        $this->callsRepository->answersByMethod['findCallOfAccountsById'] = $storedCall;

        static::assertSame($storedCall, $this->entityCallStore->findCallOfAccountIds(9, $accountIds));
        static::assertSame([['method' => 'findCallOfAccountsById', 'arguments' => [9, $accountIds], 'rightsApplied' => false]], $this->callsRepository->recordedCalls);
        static::assertTrue(DBEntity::getApplyRightsRestrictions());
    }

    /** @return array<string, array{int[]|null}> */
    public static function provideAccountIdLists(): array
    {
        return ['every account' => [null], 'one account' => [[5]], 'several accounts' => [[5, 6]]];
    }

    public function testAnEmptyAccountListAnswersNoCallWithoutAskingTheRepository(): void
    {
        $agentVoiceCalls = $this->entityCallStore->findCallsOfAccountIds([]);

        static::assertInstanceOf(AgentVoiceCalls::class, $agentVoiceCalls);
        static::assertSame(0, $agentVoiceCalls->count());
        static::assertNull($this->entityCallStore->findCallOfAccountIds(9, []));
        static::assertSame([], $this->callsRepository->recordedCalls);
        static::assertTrue(DBEntity::getApplyRightsRestrictions());
    }

    public function testWithoutAnAnswerTheCallsOfAccountIdsAreAnEmptySet(): void
    {
        static::assertSame(0, $this->entityCallStore->findCallsOfAccountIds(null)->count());
        static::assertNull($this->entityCallStore->findCallOfAccountIds(9, null));
    }

    public function testTheRingsPastTheirDeadlineAreAskedForTheAccountAtTheGivenMomentUpToTheListLimit(): void
    {
        $overdueRings = $this->buildCallSet($this->buildCall(9), $this->buildCall(8));
        $this->callsRepository->answersByMethod['findUnansweredCallsPastRingDeadline'] = $overdueRings;

        static::assertSame($overdueRings, $this->entityCallStore->findUnansweredCallsOfAccountPastRingDeadline($this->buildAccount(5), AgentVoiceCallFixtures::NOW_UNIX_TIME));
        static::assertSame(
            [['method' => 'findUnansweredCallsPastRingDeadline', 'arguments' => [AgentVoiceCallFixtures::NOW_UNIX_TIME, 50, 5], 'rightsApplied' => false]],
            $this->callsRepository->recordedCalls
        );
        unset($this->callsRepository->answersByMethod['findUnansweredCallsPastRingDeadline']);
        static::assertSame(0, $this->entityCallStore->findUnansweredCallsOfAccountPastRingDeadline($this->buildAccount(5), AgentVoiceCallFixtures::NOW_UNIX_TIME)->count());
    }

    /** @param array{string, array<int, mixed>} $storeCall */
    #[DataProvider('provideAddedReads')]
    public function testTheRightsComeBackWhenTheRepositoryThrows(array $storeCall): void
    {
        $this->callsRepository->failureMessage = 'table locked';
        [$methodName, $arguments] = $storeCall;
        if ($arguments === ['account']) {
            $arguments = [$this->buildAccount(5), AgentVoiceCallFixtures::NOW_UNIX_TIME];
        }

        try {
            $this->entityCallStore->$methodName(...$arguments);
            static::fail($methodName . ' did not pass the repository failure on');
        } catch (RuntimeException $repositoryFailure) {
            static::assertSame('table locked', $repositoryFailure->getMessage());
        }
        static::assertTrue(DBEntity::getApplyRightsRestrictions());
    }

    /** @return array<string, array{array{string, array<int, mixed>}}> */
    public static function provideAddedReads(): array
    {
        return [
            'running call of a conversation id' => [['findRunningCallOfConversationId', [30]]],
            'calls of account ids' => [['findCallsOfAccountIds', [[5]]]],
            'call of account ids' => [['findCallOfAccountIds', [9, null]]],
            'rings past their deadline' => [['findUnansweredCallsOfAccountPastRingDeadline', ['account']]],
        ];
    }

    protected function buildCall(int $callId): AgentVoiceCall
    {
        $agentVoiceCall = new AgentVoiceCall();
        $agentVoiceCall->id = $callId;

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
}
