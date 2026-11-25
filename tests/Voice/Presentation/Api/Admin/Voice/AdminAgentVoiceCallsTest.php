<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Presentation\Api\Admin\Voice;

use DDD\Domain\AI\Entities\ModelCalls\AIModelCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCalls;
use DDD\Domain\AI\Services\AISessionUsageService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallAdmissionService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallsService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallStoreService;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\QueryOptions\OrderByOptions;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Entities\Money\MoneyAmount;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Presentation\Api\Admin\Voice\AdminAgentVoiceCallsTrait;
use DDD\Presentation\Api\Admin\Voice\Dtos\AdminAgentVoiceCallDerivedValues;
use DDD\Presentation\Api\Admin\Voice\Dtos\AdminAgentVoiceCallGetRequestDto;
use DDD\Presentation\Api\Admin\Voice\Dtos\AdminAgentVoiceCallGetResponseDto;
use DDD\Presentation\Api\Admin\Voice\Dtos\AdminAgentVoiceCallsGetRequestDto;
use DDD\Presentation\Api\Admin\Voice\Dtos\AdminAgentVoiceCallsGetResponseDto;
use DDD\Presentation\Base\Router\Routes\Route;
use DDD\Tests\Agents\Voice\Support\AgentVoiceCallFixtures;
use DDD\Tests\Agents\Voice\Support\AgentVoiceIntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionAttribute;
use ReflectionMethod;
use RuntimeException;

/**
 * The admin call endpoints (list and detail): the account hook decides whose calls are read (default: the signed-in
 * account's own; null = every account) and its answer is handed to the store as the filter of both reads; a call
 * outside it answers 404 `call_not_found`; switched off both answer 403 `feature_not_available` before any store read;
 * the request's query options apply to the list read only and are put back after it, also after a failed read; every
 * row and the detail carry the state of the call, judged at one reading of the clock for all rows, and the costs of
 * the call's booked seconds, priced once per provider through the override point `findBookedCostsOfVoiceCall()`.
 *
 * State tested: the trait and DTOs with the derived values (`AdminAgentVoiceCallDerivedValues`: callId, callState,
 * bookedCosts; the detail's `callState`), as re-copied from the package tree on 2026-11-25 16:49.
 */
class AdminAgentVoiceCallsTest extends AgentVoiceIntegrationTestCase
{
    public const int ACCOUNT_ID = 12;

    public const int CALL_ID = 7;

    protected Account $signedInAccount;

    protected AdminAgentVoiceCallsFixtureController $fixtureController;

    protected AgentVoiceCallStoreService&MockObject $agentVoiceCallStoreService;

    protected AgentVoiceCallAdmissionService&MockObject $agentVoiceCallAdmissionService;

    protected AISessionUsageService&MockObject $aISessionUsageService;

    /** @var AgentVoiceCall[] what the list read answers */
    protected array $callsOfListRead = [];

    protected ?AgentVoiceCalls $callSetOfListRead = null;

    /** @var (int|null)[] */
    protected array $topDuringListReads = [];

    /** @var string[] */
    protected array $orderByDuringListReads = [];

    /** @var (string|null)[] the providers a session model call was built for */
    protected array $providerNamesOfSessionModelCalls = [];

    /** @var array<string, AIModelCall> the session model call built per provider ('' = the default) */
    protected array $sessionModelCallsByProvider = [];

    /** @var float[] the seconds priced */
    protected array $pricedSessionSeconds = [];

    protected bool $catalogHasPrice = true;

    /** @var string[] the providers whose session model call cannot be built */
    protected array $providerNamesWithoutModelCall = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['enabled' => true]));
        $this->signedInAccount = new Account();
        $this->signedInAccount->id = static::ACCOUNT_ID;
        AuthService::instance()->setAccount($this->signedInAccount);
        $this->agentVoiceCallStoreService = $this->createMock(AgentVoiceCallStoreService::class);
        $this->agentVoiceCallAdmissionService = $this->createMock(AgentVoiceCallAdmissionService::class);
        $this->agentVoiceCallAdmissionService->method('buildSessionModelCall')->willReturnCallback([$this, 'answerSessionModelCall']);
        $this->aISessionUsageService = $this->createMock(AISessionUsageService::class);
        $this->aISessionUsageService->method('calculateCostsOfSessionSeconds')->willReturnCallback([$this, 'answerCostsOfSessionSeconds']);
        $this->fixtureController = new AdminAgentVoiceCallsFixtureController();
        $this->fixtureController->agentVoiceCallStoreService = $this->agentVoiceCallStoreService;
        $this->fixtureController->agentVoiceCallAdmissionService = $this->agentVoiceCallAdmissionService;
        $this->fixtureController->aISessionUsageService = $this->aISessionUsageService;
    }

    public static function buildCall(int $callId, ?string $voiceProvider, int $bookedSeconds, ?int $conversationId = null): AgentVoiceCall
    {
        $agentVoiceCall = AgentVoiceCallFixtures::buildCallAt(AgentVoiceCallFixtures::NOW_UNIX_TIME, startedOffsetSeconds: -400, endedOffsetSeconds: -100);
        $agentVoiceCall->id = $callId;
        $agentVoiceCall->accountId = static::ACCOUNT_ID;
        $agentVoiceCall->voiceProvider = $voiceProvider;
        $agentVoiceCall->bookedSeconds = $bookedSeconds;
        $agentVoiceCall->aiConversationId = $conversationId;
        $agentVoiceCall->endReason = AgentVoiceCallsService::END_REASON_REAPED_STALE;

        return $agentVoiceCall;
    }

    public function answerSessionModelCall(Account $billedAccount, ?Entity $billedEntity, ?string $providerName = null): AIModelCall
    {
        static::assertSame($this->signedInAccount, $billedAccount);
        static::assertNull($billedEntity);
        $this->providerNamesOfSessionModelCalls[] = $providerName;
        if (in_array((string)$providerName, $this->providerNamesWithoutModelCall, true)) {
            throw new RuntimeException('no adapter answers to the voice provider ' . $providerName);
        }
        $this->sessionModelCallsByProvider[(string)$providerName] = $this->createStub(AIModelCall::class);

        return $this->sessionModelCallsByProvider[(string)$providerName];
    }

    /** One cent per second, or no price at all while {@see $catalogHasPrice} is false. */
    public function answerCostsOfSessionSeconds(AIModelCall $aiModelCall, float $sessionSeconds, ?MoneyAmount $costsPerSessionSecond = null): ?MoneyAmount
    {
        static::assertContains($aiModelCall, $this->sessionModelCallsByProvider);
        static::assertNull($costsPerSessionSecond);
        $this->pricedSessionSeconds[] = $sessionSeconds;

        return $this->catalogHasPrice ? new MoneyAmount($sessionSeconds / 100) : null;
    }

    /** @param int[]|null $accountIds */
    public function answerListRead(?array $accountIds): AgentVoiceCalls
    {
        $this->recordQueryOptionsOfListRead();
        $this->callSetOfListRead = new AgentVoiceCalls();
        foreach ($this->callsOfListRead as $agentVoiceCall) {
            $this->callSetOfListRead->add($agentVoiceCall);
        }

        return $this->callSetOfListRead;
    }

    /** @param int[]|null $accountIds */
    public function failListRead(?array $accountIds): AgentVoiceCalls
    {
        $this->recordQueryOptionsOfListRead();
        throw new RuntimeException('the call table is gone');
    }

    protected function recordQueryOptionsOfListRead(): void
    {
        $this->topDuringListReads[] = AgentVoiceCalls::getDefaultQueryOptions()->getTop();
        $this->orderByDuringListReads[] = static::describeOrderBy(AgentVoiceCalls::getDefaultQueryOptions()->getOrderBy());
    }

    /** An order as `property DIRECTION`, comma-separated; '' for none. */
    protected static function describeOrderBy(?OrderByOptions $orderByOptions): string
    {
        $orderParts = [];
        foreach ($orderByOptions?->getElements() ?? [] as $orderByOption) {
            $orderParts[] = $orderByOption->propertyName . ' ' . strtoupper($orderByOption->direction);
        }

        return implode(', ', $orderParts);
    }

    protected function listCalls(?AdminAgentVoiceCallsGetRequestDto $listRequestDto = null): AdminAgentVoiceCallsGetResponseDto
    {
        $listRequestDto ??= new AdminAgentVoiceCallsGetRequestDto();

        return $this->fixtureController->listAgentVoiceCalls($listRequestDto);
    }

    protected function getCall(int $callId): AdminAgentVoiceCallGetResponseDto
    {
        $getRequestDto = new AdminAgentVoiceCallGetRequestDto();
        $getRequestDto->callId = $callId;

        return $this->fixtureController->getAgentVoiceCall($getRequestDto);
    }

    /** @return array<string, array{bool, int[]|null, int[]|null}> */
    public static function provideAccountHookAnswers(): array
    {
        return [
            'the default: the own account' => [false, null, [12]],
            'null: every account' => [true, null, null],
            'the accounts an oversight role looks after' => [true, [12, 13, 14], [12, 13, 14]],
            'an empty list: no call' => [true, [], []],
        ];
    }

    /**
     * @param int[]|null $hookAnswer
     * @param int[]|null $expectedAccountFilter
     */
    #[DataProvider('provideAccountHookAnswers')]
    public function testTheListReadsTheCallsOfTheAccountsTheHookAnswers(bool $overridesHook, ?array $hookAnswer, ?array $expectedAccountFilter): void
    {
        $this->fixtureController->overridesListableAccountIds = $overridesHook;
        $this->fixtureController->listableAccountIds = $hookAnswer;
        $this->agentVoiceCallStoreService->expects(static::once())->method('findCallsOfAccountIds')->with($expectedAccountFilter)
            ->willReturnCallback([$this, 'answerListRead']);

        $responseDto = $this->listCalls();

        static::assertSame($this->callSetOfListRead, $responseDto->agentVoiceCalls);
        static::assertSame([static::ACCOUNT_ID], $this->fixtureController->accountIdsAskedForListableCalls);
    }

    /**
     * @param int[]|null $hookAnswer
     * @param int[]|null $expectedAccountFilter
     */
    #[DataProvider('provideAccountHookAnswers')]
    public function testTheDetailReadsTheCallWithinTheAccountsTheHookAnswers(bool $overridesHook, ?array $hookAnswer, ?array $expectedAccountFilter): void
    {
        $this->fixtureController->overridesListableAccountIds = $overridesHook;
        $this->fixtureController->listableAccountIds = $hookAnswer;
        $agentVoiceCall = static::buildCall(static::CALL_ID, 'openAiLive', 0);
        $this->agentVoiceCallStoreService->expects(static::once())->method('findCallOfAccountIds')->with(static::CALL_ID, $expectedAccountFilter)
            ->willReturn($agentVoiceCall);

        static::assertSame($agentVoiceCall, $this->getCall(static::CALL_ID)->agentVoiceCall);
    }

    public function testACallOutsideTheAccountsAnswersCallNotFound(): void
    {
        $this->agentVoiceCallStoreService->expects(static::once())->method('findCallOfAccountIds')->with(99, [static::ACCOUNT_ID])->willReturn(null);

        try {
            $this->getCall(99);
            static::fail('a call outside the accounts must answer 404');
        } catch (NotFoundException $notFound) {
            static::assertSame('call_not_found', $notFound->getMessage());
            static::assertSame(404, $notFound->getCode());
        }
    }

    /** @return array<string, array{string}> */
    public static function provideRoutes(): array
    {
        return ['list' => ['list'], 'detail' => ['detail']];
    }

    #[DataProvider('provideRoutes')]
    public function testSwitchedOffBothRoutesAnswerFeatureNotAvailableBeforeAnyRead(string $route): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['enabled' => false]));
        $this->agentVoiceCallStoreService->expects(static::never())->method(static::anything());

        try {
            $route === 'list' ? $this->listCalls() : $this->getCall(static::CALL_ID);
            static::fail('switched off the route must refuse');
        } catch (ForbiddenException $refusal) {
            static::assertSame(ForbiddenException::class, $refusal::class);
            static::assertSame('feature_not_available', $refusal->getMessage());
            static::assertSame(403, $refusal->getCode());
        }
        static::assertSame(1, $this->fixtureController->accessHookCallCount);
        static::assertSame([], $this->fixtureController->accountIdsAskedForListableCalls);
    }

    #[DataProvider('provideRoutes')]
    public function testTheAccessHookRunsFirstAndItsRefusalStopsTheRoute(string $route): void
    {
        $this->fixtureController->accessRefused = true;
        $this->agentVoiceCallStoreService->expects(static::never())->method(static::anything());

        try {
            $route === 'list' ? $this->listCalls() : $this->getCall(static::CALL_ID);
            static::fail('a refusing access hook must stop the route');
        } catch (ForbiddenException $refusal) {
            static::assertSame('admin_refused', $refusal->getMessage());
        }
        static::assertSame([], $this->fixtureController->accountIdsAskedForListableCalls);
    }

    #[DataProvider('provideRoutes')]
    public function testWithoutASignedInAccountBothRoutesRefuse(string $route): void
    {
        $noAccount = null;
        AuthService::instance()->setAccount($noAccount);
        $this->agentVoiceCallStoreService->expects(static::never())->method(static::anything());

        try {
            $route === 'list' ? $this->listCalls() : $this->getCall(static::CALL_ID);
            static::fail('without an account the route must refuse');
        } catch (ForbiddenException $refusal) {
            static::assertSame(ForbiddenException::class, $refusal::class);
            static::assertSame('No authenticated account', $refusal->getMessage());
        }
    }

    public function testTheListIsReadUnderTheRequestsQueryOptionsAndTheyArePutBack(): void
    {
        $topBefore = AgentVoiceCalls::getDefaultQueryOptions()->getTop();
        $orderByBefore = static::describeOrderBy(AgentVoiceCalls::getDefaultQueryOptions()->getOrderBy());
        $this->agentVoiceCallStoreService->method('findCallsOfAccountIds')->willReturnCallback([$this, 'answerListRead']);
        $listRequestDto = new AdminAgentVoiceCallsGetRequestDto();
        $listRequestDto->top = 5;
        $listRequestDto->orderBy = OrderByOptions::fromString('bookedSeconds desc');

        $this->listCalls($listRequestDto);
        $this->listCalls();

        static::assertSame([5, $topBefore], $this->topDuringListReads);
        static::assertSame(['bookedSeconds DESC', 'id DESC'], $this->orderByDuringListReads, 'newest first when the request asks for no order');
        static::assertSame($topBefore, AgentVoiceCalls::getDefaultQueryOptions()->getTop());
        static::assertSame($orderByBefore, static::describeOrderBy(AgentVoiceCalls::getDefaultQueryOptions()->getOrderBy()));
    }

    public function testTheQueryOptionsArePutBackAfterAFailedRead(): void
    {
        $topBefore = AgentVoiceCalls::getDefaultQueryOptions()->getTop();
        $orderByBefore = static::describeOrderBy(AgentVoiceCalls::getDefaultQueryOptions()->getOrderBy());
        $this->agentVoiceCallStoreService->method('findCallsOfAccountIds')->willReturnCallback([$this, 'failListRead']);
        $listRequestDto = new AdminAgentVoiceCallsGetRequestDto();
        $listRequestDto->top = 3;
        $listRequestDto->orderBy = OrderByOptions::fromString('startedAt asc');

        try {
            $this->listCalls($listRequestDto);
            static::fail('a failed read must reach the caller');
        } catch (RuntimeException $readFailure) {
            static::assertSame('the call table is gone', $readFailure->getMessage());
        }
        static::assertSame([3], $this->topDuringListReads);
        static::assertSame(['startedAt ASC'], $this->orderByDuringListReads);
        static::assertSame($topBefore, AgentVoiceCalls::getDefaultQueryOptions()->getTop());
        static::assertSame($orderByBefore, static::describeOrderBy(AgentVoiceCalls::getDefaultQueryOptions()->getOrderBy()));
    }

    public function testEveryRowCarriesTheCostsOfItsBookedSecondsPricedOncePerProvider(): void
    {
        $this->callsOfListRead = [
            static::buildCall(7, 'openAiLive', 300),
            static::buildCall(8, 'openAiLive', 120),
            static::buildCall(9, null, 60),
            static::buildCall(10, 'openAiLive', 0),
        ];
        $this->agentVoiceCallStoreService->method('findCallsOfAccountIds')->willReturnCallback([$this, 'answerListRead']);

        $responseDto = $this->listCalls();

        static::assertSame(['openAiLive', null], $this->providerNamesOfSessionModelCalls);
        static::assertSame([300.0, 120.0, 60.0], $this->pricedSessionSeconds);
        static::assertContainsOnlyInstancesOf(AdminAgentVoiceCallDerivedValues::class, $responseDto->agentVoiceCallDerivedValues);
        static::assertSame([7, 8, 9, 10], array_map([static::class, 'readCallIdOfDerivedValues'], $responseDto->agentVoiceCallDerivedValues));
        static::assertSame([3.0, 1.2, 0.6, null], array_map([static::class, 'readAmountOfDerivedValues'], $responseDto->agentVoiceCallDerivedValues));
    }

    public function testAPageBuildsTheSessionModelCallsAgainForEveryRequest(): void
    {
        $this->callsOfListRead = [static::buildCall(7, 'openAiLive', 300)];
        $this->agentVoiceCallStoreService->method('findCallsOfAccountIds')->willReturnCallback([$this, 'answerListRead']);

        $this->listCalls();
        $this->listCalls();

        static::assertSame(['openAiLive', 'openAiLive'], $this->providerNamesOfSessionModelCalls);
    }

    public function testWithoutAPriceInTheCatalogTheCostsAreNull(): void
    {
        $this->catalogHasPrice = false;
        $this->callsOfListRead = [static::buildCall(7, 'openAiLive', 300)];
        $this->agentVoiceCallStoreService->method('findCallsOfAccountIds')->willReturnCallback([$this, 'answerListRead']);

        $responseDto = $this->listCalls();

        static::assertCount(1, $responseDto->agentVoiceCallDerivedValues);
        static::assertNull($responseDto->agentVoiceCallDerivedValues[0]->bookedCosts);
    }

    public function testTheDetailCarriesDurationBookedSecondsCostsEndReasonAndConversation(): void
    {
        $agentVoiceCall = static::buildCall(static::CALL_ID, 'openAiLive', 280, 40);
        $this->agentVoiceCallStoreService->method('findCallOfAccountIds')->willReturn($agentVoiceCall);

        $responseDto = $this->getCall(static::CALL_ID);

        static::assertSame(300, $responseDto->durationInSeconds);
        static::assertSame(280, $responseDto->bookedSeconds);
        static::assertSame(2.8, $responseDto->bookedCosts?->amount);
        static::assertSame('reaped_stale', $responseDto->endReason);
        static::assertSame(40, $responseDto->conversationId);
        static::assertSame([280.0], $this->pricedSessionSeconds);
    }

    public function testADetailThatBookedNothingHasNoCostsAndBuildsNoModelCall(): void
    {
        $agentVoiceCall = AgentVoiceCallFixtures::buildCallAt(AgentVoiceCallFixtures::NOW_UNIX_TIME, ringDeadlineOffsetSeconds: 60);
        $agentVoiceCall->accountId = static::ACCOUNT_ID;
        $this->agentVoiceCallStoreService->method('findCallOfAccountIds')->willReturn($agentVoiceCall);

        $responseDto = $this->getCall(static::CALL_ID);

        static::assertNull($responseDto->durationInSeconds);
        static::assertSame(0, $responseDto->bookedSeconds);
        static::assertNull($responseDto->bookedCosts);
        static::assertNull($responseDto->conversationId);
        static::assertSame([], $this->providerNamesOfSessionModelCalls);
    }

    public function testADetailReadTwiceBuildsTheSessionModelCallForEachRequest(): void
    {
        $this->agentVoiceCallStoreService->method('findCallOfAccountIds')->willReturn(static::buildCall(static::CALL_ID, 'openAiLive', 280));

        $this->getCall(static::CALL_ID);
        $this->getCall(static::CALL_ID);

        static::assertSame(['openAiLive', 'openAiLive'], $this->providerNamesOfSessionModelCalls);
    }

    /** A call of the signed-in account at times relative to the real clock, which the routes judge the state at. */
    public static function buildCallJudgedNow(int $callId, ?int $ringDeadlineOffsetSeconds, ?int $startedOffsetSeconds, ?int $endedOffsetSeconds): ClockRecordingAgentVoiceCall
    {
        $agentVoiceCall = AgentVoiceCallFixtures::buildCallAt(
            time(),
            ringDeadlineOffsetSeconds: $ringDeadlineOffsetSeconds,
            startedOffsetSeconds: $startedOffsetSeconds,
            endedOffsetSeconds: $endedOffsetSeconds,
            callClassName: ClockRecordingAgentVoiceCall::class
        );
        $agentVoiceCall->id = $callId;
        $agentVoiceCall->accountId = static::ACCOUNT_ID;

        return $agentVoiceCall;
    }

    public static function readCallStateOfDerivedValues(AdminAgentVoiceCallDerivedValues $agentVoiceCallDerivedValues): string
    {
        return $agentVoiceCallDerivedValues->callState;
    }

    public function testEveryRowCarriesTheStateOfItsCallAndAnAnsweredCallNeverEndedLongAgoReadsEnded(): void
    {
        $this->callsOfListRead = [
            static::buildCallJudgedNow(7, 600, null, null),
            static::buildCallJudgedNow(8, null, -60, null),
            static::buildCallJudgedNow(9, null, -7300, null),
            static::buildCallJudgedNow(10, null, -400, -100),
        ];
        $this->agentVoiceCallStoreService->method('findCallsOfAccountIds')->willReturnCallback([$this, 'answerListRead']);

        $responseDto = $this->listCalls();

        static::assertSame([7, 8, 9, 10], array_map([static::class, 'readCallIdOfDerivedValues'], $responseDto->agentVoiceCallDerivedValues));
        static::assertSame(
            [AgentVoiceCall::CALL_STATE_RINGING, AgentVoiceCall::CALL_STATE_IN_PROGRESS, AgentVoiceCall::CALL_STATE_ENDED, AgentVoiceCall::CALL_STATE_ENDED],
            array_map([static::class, 'readCallStateOfDerivedValues'], $responseDto->agentVoiceCallDerivedValues)
        );
    }

    public function testAllRowsAreJudgedAtOneReadingOfTheClock(): void
    {
        ClockRecordingAgentVoiceCall::$judgedAtUnixTimes = [];
        $firstCall = static::buildCallJudgedNow(7, null, -60, null);
        $firstCall->waitsForTheNextSecond = true;
        $this->callsOfListRead = [$firstCall, static::buildCallJudgedNow(8, null, -60, null), static::buildCallJudgedNow(9, 600, null, null)];
        $this->agentVoiceCallStoreService->method('findCallsOfAccountIds')->willReturnCallback([$this, 'answerListRead']);
        $timeBeforeTheRequest = time();

        $this->listCalls();

        static::assertCount(3, ClockRecordingAgentVoiceCall::$judgedAtUnixTimes);
        static::assertCount(1, array_unique(ClockRecordingAgentVoiceCall::$judgedAtUnixTimes), 'the clock is read once for all rows');
        static::assertGreaterThanOrEqual($timeBeforeTheRequest, ClockRecordingAgentVoiceCall::$judgedAtUnixTimes[0]);
        static::assertLessThan(time(), ClockRecordingAgentVoiceCall::$judgedAtUnixTimes[0], 'judged at the request, not after it');
    }

    /** @return array<string, array{int|null, int|null, int|null, string}> */
    public static function provideDetailStates(): array
    {
        return [
            'ringing' => [600, null, null, AgentVoiceCall::CALL_STATE_RINGING],
            'running' => [null, -60, null, AgentVoiceCall::CALL_STATE_IN_PROGRESS],
            'answered long ago and never ended' => [null, -7300, null, AgentVoiceCall::CALL_STATE_ENDED],
            'ended' => [null, -400, -100, AgentVoiceCall::CALL_STATE_ENDED],
            'a ring that ran out' => [-1, null, null, AgentVoiceCall::CALL_STATE_ENDED],
        ];
    }

    #[DataProvider('provideDetailStates')]
    public function testTheDetailCarriesTheStateOfTheCallNow(?int $ringDeadlineOffsetSeconds, ?int $startedOffsetSeconds, ?int $endedOffsetSeconds, string $expectedCallState): void
    {
        $this->agentVoiceCallStoreService->method('findCallOfAccountIds')
            ->willReturn(static::buildCallJudgedNow(static::CALL_ID, $ringDeadlineOffsetSeconds, $startedOffsetSeconds, $endedOffsetSeconds));

        static::assertSame($expectedCallState, $this->getCall(static::CALL_ID)->callState);
    }

    public function testAnApplicationsCostLedgerAnswersTheCostsOfTheListAndTheDetail(): void
    {
        $this->fixtureController->ledgerCostsByCallId = [7 => new MoneyAmount(4.25), 9 => new MoneyAmount(0.5)];
        $this->callsOfListRead = [static::buildCall(7, 'openAiLive', 300), static::buildCall(8, 'openAiLive', 120), static::buildCall(9, null, 0)];
        $this->agentVoiceCallStoreService->method('findCallsOfAccountIds')->willReturnCallback([$this, 'answerListRead']);
        $this->agentVoiceCallStoreService->method('findCallOfAccountIds')->willReturn(static::buildCall(9, null, 0));

        $listResponseDto = $this->listCalls();
        $detailResponseDto = $this->getCall(9);

        static::assertSame([4.25, null, 0.5], array_map([static::class, 'readAmountOfDerivedValues'], $listResponseDto->agentVoiceCallDerivedValues));
        static::assertSame(0.5, $detailResponseDto->bookedCosts?->amount);
        static::assertSame([], $this->providerNamesOfSessionModelCalls, 'the ledger answers: no catalog price is built');
        static::assertSame([], $this->pricedSessionSeconds);
    }

    public function testAProviderWhoseModelCallCannotBeBuiltIsListedWithoutCostsBesideOneThatPrices(): void
    {
        $this->providerNamesWithoutModelCall = ['retiredLive'];
        $this->callsOfListRead = [
            static::buildCall(7, 'openAiLive', 300),
            static::buildCall(8, 'retiredLive', 120),
            static::buildCall(9, 'retiredLive', 60),
            static::buildCall(10, 'openAiLive', 100),
        ];
        $this->agentVoiceCallStoreService->method('findCallsOfAccountIds')->willReturnCallback([$this, 'answerListRead']);

        $responseDto = $this->listCalls();

        static::assertSame([7, 8, 9, 10], array_map([static::class, 'readCallIdOfDerivedValues'], $responseDto->agentVoiceCallDerivedValues));
        static::assertSame([3.0, null, null, 1.0], array_map([static::class, 'readAmountOfDerivedValues'], $responseDto->agentVoiceCallDerivedValues));
        static::assertSame(['openAiLive', 'retiredLive'], $this->providerNamesOfSessionModelCalls, 'a failing provider is asked once per request');
        static::assertSame([300.0, 100.0], $this->pricedSessionSeconds);

        $this->listCalls();

        static::assertSame(['openAiLive', 'retiredLive', 'openAiLive', 'retiredLive'], $this->providerNamesOfSessionModelCalls, 'and again at the next request');
    }

    public function testTheDetailOfACallWhoseModelCallCannotBeBuiltHasNoCosts(): void
    {
        $this->providerNamesWithoutModelCall = ['retiredLive'];
        $this->agentVoiceCallStoreService->method('findCallOfAccountIds')->willReturn(static::buildCall(static::CALL_ID, 'retiredLive', 280, 40));

        $responseDto = $this->getCall(static::CALL_ID);

        static::assertNull($responseDto->bookedCosts);
        static::assertSame(280, $responseDto->bookedSeconds);
        static::assertSame(AgentVoiceCall::CALL_STATE_ENDED, $responseDto->callState);
        static::assertSame(['retiredLive'], $this->providerNamesOfSessionModelCalls);
        static::assertSame([], $this->pricedSessionSeconds);
    }

    /** @return array<string, array{string, string}> */
    public static function provideRoutedMethods(): array
    {
        return [
            'list' => ['listAgentVoiceCalls', '/calls'],
            'detail' => ['getAgentVoiceCall', '/calls/{callId}'],
        ];
    }

    #[DataProvider('provideRoutedMethods')]
    public function testTheTraitRoutesItsTwoGetRoutes(string $methodName, string $expectedPath): void
    {
        $routeAttributes = (new ReflectionMethod(AdminAgentVoiceCallsTrait::class, $methodName))->getAttributes(Route::class, ReflectionAttribute::IS_INSTANCEOF);

        static::assertCount(1, $routeAttributes);
        $route = $routeAttributes[0]->newInstance();
        static::assertSame(['GET'], $route->methods);
        static::assertSame($expectedPath, $route->path);
    }

    public static function readCallIdOfDerivedValues(AdminAgentVoiceCallDerivedValues $agentVoiceCallDerivedValues): int
    {
        return $agentVoiceCallDerivedValues->callId;
    }

    public static function readAmountOfDerivedValues(AdminAgentVoiceCallDerivedValues $agentVoiceCallDerivedValues): ?float
    {
        return $agentVoiceCallDerivedValues->bookedCosts?->amount;
    }
}
