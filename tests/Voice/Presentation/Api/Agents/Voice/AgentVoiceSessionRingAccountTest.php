<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Presentation\Api\Agents\Voice;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallRefusal;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\ConflictException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionAnswerRequestDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionCallStateGetRequestDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionDeclineRequestDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionDeviceStatusRequestDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionHangUpRequestDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionOpenRequestDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionRingRequestDto;
use Throwable;

/**
 * The account a ring of "call me" is started for (`AgentVoiceSessionsTrait::findRingAccountOfCallRequest()`):
 * by default the signed-in account is rung and owns the call, and the routes that name a call accept the signed-in
 * account's calls only. With the hook overridden ({@see SpaceOwnerRungAgentVoiceSessionsFixtureController}: an agency
 * user, account {@see ACCOUNT_ID}, presses "call me" for the space of the owner {@see OWNER_ACCOUNT_ID}) the owner is
 * rung and owns the call, the open of the rung call asks the admission for the owner, and each route that names a call
 * accepts the owner's call of that space while a call of any other account stays `404 call_not_found`.
 */
class AgentVoiceSessionRingAccountTest extends AgentVoiceSessionsTestCase
{
    public const int OWNER_ACCOUNT_ID = 50;

    public const int OUTSIDE_ACCOUNT_ID = 51;

    public const int AGENT_SPACE_ID = 3;

    public const string ANSWER_TOKEN = '1800000090.signature';

    /** @var string[] The routes that name a call */
    public const array ROUTES_THAT_NAME_A_CALL = ['answer', 'decline', 'hangUp', 'deviceStatus', 'callState'];

    protected SpaceOwnerRungAgentVoiceSessionsFixtureController $spaceOwnerRungController;

    protected AgentSpace $agentSpace;

    /** @var int[] The ids of the accounts the ring start was handed */
    protected array $accountIdsHandedToTheRingStart = [];

    /** @var int[] The ids of the accounts the admission was asked for */
    protected array $accountIdsTheAdmissionWasAskedFor = [];

    /** @var bool[] Whether the entity rights applied when the conversation of a call was read */
    protected array $rightsAppliedDuringConversationReads = [];

    protected ?AIConversation $conversationOfTheCall = null;

    protected ?AgentVoiceCall $callTheRingStartAnswers = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agentSpace = new AgentSpace();
        $this->agentSpace->id = static::AGENT_SPACE_ID;
        $this->agentSpace->accountId = static::OWNER_ACCOUNT_ID;

        $this->spaceOwnerRungController = new SpaceOwnerRungAgentVoiceSessionsFixtureController();
        $this->spaceOwnerRungController->agentVoiceCallsService = $this->agentVoiceCallsService;
        $this->spaceOwnerRungController->agentVoiceCallStoreService = $this->agentVoiceCallStoreService;
        $this->spaceOwnerRungController->agentVoiceCallStartsService = $this->agentVoiceCallStartsService;
        $this->spaceOwnerRungController->agentVoiceCallAdmissionService = $this->agentVoiceCallAdmissionService;
        $this->spaceOwnerRungController->agentVoiceRingService = $this->agentVoiceRingService;
        $this->spaceOwnerRungController->agentVoiceSessionContextsService = $this->agentVoiceSessionContextsService;
        $this->spaceOwnerRungController->agentLiveVoiceSessionsService = $this->agentLiveVoiceSessionsService;
        $this->spaceOwnerRungController->aIConversationsService = $this->aIConversationsService;
        $this->spaceOwnerRungController->aIConversationTraceEventsService = $this->recordingConversationTraceEventsService;
        $this->spaceOwnerRungController->realtimeVoiceProviderAdapter = $this->scriptedSessionVoiceProviderAdapter;
    }

    public function testByDefaultTheSignedInAccountIsRungAndOwnsTheCall(): void
    {
        $this->agentVoiceCallsService->expects(static::once())->method('findOpenAppCallForAccountId')->with(static::ACCOUNT_ID)->willReturn(null);
        $this->callTheRingStartAnswers = static::buildCallOfSignedInAccount(ringDeadlineOffsetSeconds: 90, direction: AgentVoiceCall::DIRECTION_OUTBOUND);
        $this->agentVoiceCallStartsService->expects(static::once())->method('startRing')
            ->with(static::identicalTo($this->signedInAccount), static::identicalTo($this->agentSpace), null, null)
            ->willReturnCallback([$this, 'answerRingStart']);

        $responseDto = $this->fixtureController->callBuildVoiceCallRingingResponse(new AgentVoiceSessionRingRequestDto(), $this->agentSpace);

        static::assertSame(static::CALL_ID, $responseDto->callId);
        static::assertSame([static::ACCOUNT_ID], $this->accountIdsHandedToTheRingStart);
    }

    public function testAnAgencyUserWhoPressesCallMeRingsTheOwnerOfTheSpaceWhoOwnsTheCall(): void
    {
        $this->agentVoiceCallsService->expects(static::once())->method('findOpenAppCallForAccountId')->with(static::OWNER_ACCOUNT_ID)->willReturn(null);
        $this->callTheRingStartAnswers = $this->buildCallOfAccount(static::OWNER_ACCOUNT_ID);
        $this->agentVoiceCallStartsService->expects(static::once())->method('startRing')
            ->with(static::isInstanceOf(Account::class), static::identicalTo($this->agentSpace), null, 'Go through the open points.')
            ->willReturnCallback([$this, 'answerRingStart']);
        $ringRequestDto = new AgentVoiceSessionRingRequestDto();
        $ringRequestDto->task = 'Go through the open points.';

        $responseDto = $this->spaceOwnerRungController->callBuildVoiceCallRingingResponse($ringRequestDto, $this->agentSpace);

        static::assertSame([static::OWNER_ACCOUNT_ID], $this->accountIdsHandedToTheRingStart, 'the owner of the space is rung, not the agency user');
        static::assertSame(static::CALL_ID, $responseDto->callId);
        static::assertSame([AgentVoiceSessionRingRequestDto::class . ':' . static::AGENT_SPACE_ID], $this->spaceOwnerRungController->ringAccountQuestions);
        static::assertTrue(DBEntity::getApplyRightsRestrictions(), 'the rights apply again after the ring start');
    }

    public function testARunningCallOfTheOwnerRefusesTheRingOfTheAgencyUser(): void
    {
        $this->agentVoiceCallsService->expects(static::once())->method('findOpenAppCallForAccountId')->with(static::OWNER_ACCOUNT_ID)
            ->willReturn($this->buildCallOfAccount(static::OWNER_ACCOUNT_ID));
        $this->agentVoiceCallStartsService->expects(static::never())->method(static::anything());

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('call_already_running');
        $this->spaceOwnerRungController->callBuildVoiceCallRingingResponse(new AgentVoiceSessionRingRequestDto(), $this->agentSpace);
    }

    public function testEveryRouteThatNamesACallAcceptsTheCallOfTheAccountTheHookNames(): void
    {
        $ownerCall = $this->buildCallOfAccount(static::OWNER_ACCOUNT_ID);
        $this->scriptStoreAndConversationOfCall($ownerCall);
        $this->agentVoiceRingService->expects(static::once())->method('answerRing')->with(static::identicalTo($ownerCall), static::ANSWER_TOKEN, null);
        $this->agentVoiceRingService->expects(static::once())->method('declineRing')->with(static::identicalTo($ownerCall), static::ANSWER_TOKEN);
        $this->agentVoiceCallsService->expects(static::once())->method('endCallNow')
            ->with(static::identicalTo($ownerCall), AgentVoiceCall::END_REASON_COMPLETED)->willReturn(true);

        $routeOutcomes = $this->runEveryRouteThatNamesACall($this->spaceOwnerRungController);

        static::assertSame(array_fill_keys(static::ROUTES_THAT_NAME_A_CALL, 'answered'), $routeOutcomes);
        static::assertCount(1, $this->recordingConversationTraceEventsService->recordedTraceEvents, 'the device status of the owner\'s call is written');
        static::assertSame(
            [
                AgentVoiceSessionAnswerRequestDto::class . ':' . static::AGENT_SPACE_ID,
                AgentVoiceSessionDeclineRequestDto::class . ':' . static::AGENT_SPACE_ID,
                AgentVoiceSessionHangUpRequestDto::class . ':' . static::AGENT_SPACE_ID,
                AgentVoiceSessionDeviceStatusRequestDto::class . ':' . static::AGENT_SPACE_ID,
                AgentVoiceSessionCallStateGetRequestDto::class . ':' . static::AGENT_SPACE_ID,
            ],
            $this->spaceOwnerRungController->ringAccountQuestions,
            'each route asks the hook with its own request and the space of the call\'s conversation'
        );
        static::assertSame(array_fill(0, 5, false), $this->rightsAppliedDuringConversationReads, 'the space of the call is read with the rights lifted');
        static::assertTrue(DBEntity::getApplyRightsRestrictions(), 'the rights apply again after the read');
    }

    public function testEveryRouteThatNamesACallRefusesACallOfAnOutsideAccountAsToday(): void
    {
        $this->scriptStoreAndConversationOfCall($this->buildCallOfAccount(static::OUTSIDE_ACCOUNT_ID));
        $this->agentVoiceRingService->expects(static::never())->method(static::anything());
        $this->agentVoiceCallsService->expects(static::never())->method(static::anything());

        $routeOutcomes = $this->runEveryRouteThatNamesACall($this->spaceOwnerRungController);

        static::assertSame(array_fill_keys(static::ROUTES_THAT_NAME_A_CALL, NotFoundException::class . ': call_not_found'), $routeOutcomes);
        static::assertSame([], $this->recordingConversationTraceEventsService->recordedTraceEvents);
    }

    public function testByDefaultTheCallOfTheOwnerOfTheSpaceStaysRefusedOnEveryRoute(): void
    {
        $this->scriptStoreAndConversationOfCall($this->buildCallOfAccount(static::OWNER_ACCOUNT_ID));
        $this->agentVoiceRingService->expects(static::never())->method(static::anything());
        $this->agentVoiceCallsService->expects(static::never())->method(static::anything());

        $routeOutcomes = $this->runEveryRouteThatNamesACall($this->fixtureController);

        static::assertSame(array_fill_keys(static::ROUTES_THAT_NAME_A_CALL, NotFoundException::class . ': call_not_found'), $routeOutcomes);
        static::assertSame([], $this->recordingConversationTraceEventsService->recordedTraceEvents);
    }

    public function testACallWhoseConversationNamesASpaceThatIsGoneIsRefused(): void
    {
        $this->scriptStoreAndConversationOfCall($this->buildCallOfAccount(static::OWNER_ACCOUNT_ID));
        $this->conversationOfTheCall->agentSpace = null;
        $this->agentVoiceRingService->expects(static::never())->method(static::anything());
        $this->agentVoiceCallsService->expects(static::never())->method(static::anything());

        $routeOutcomes = $this->runEveryRouteThatNamesACall($this->spaceOwnerRungController);

        static::assertSame(array_fill_keys(static::ROUTES_THAT_NAME_A_CALL, NotFoundException::class . ': call_not_found'), $routeOutcomes);
        static::assertSame([], $this->spaceOwnerRungController->ringAccountQuestions, 'the hook is not asked for a space that is gone');
    }

    public function testTheAgencyUserOpensTheOwnersRungCallAndTheAdmissionIsAskedForTheOwner(): void
    {
        $ownerCall = $this->buildCallOfAccount(static::OWNER_ACCOUNT_ID);
        $ownerCall->startedAt = static::buildMoment(-3);
        $this->scriptStoreAndConversationOfCall($ownerCall);
        $this->agentVoiceRingService->method('isCallAnsweredByClient')->willReturn(true);
        $this->agentVoiceCallAdmissionService->expects(static::once())->method('findRefusalOfCallStart')
            ->willReturnCallback([$this, 'answerAdmissionWithNoAiBudget']);
        $openRequestDto = new AgentVoiceSessionOpenRequestDto();
        $openRequestDto->sdpOffer = static::SDP_OFFER;
        $openRequestDto->callId = static::CALL_ID;

        try {
            $this->spaceOwnerRungController->callBuildVoiceSessionOpenedResponse($openRequestDto, $this->agentSpace);
            static::fail('the scripted admission refuses the open');
        } catch (ForbiddenException $forbiddenException) {
            static::assertSame(AgentVoiceCallRefusal::CODE_NO_AI_BUDGET, $forbiddenException->getMessage());
        }

        static::assertSame([static::OWNER_ACCOUNT_ID], $this->accountIdsTheAdmissionWasAskedFor, 'the admission is asked again for the account the ring was started for');
        static::assertSame([], $this->scriptedSessionVoiceProviderAdapter->sessionCreationPayloads);
    }

    /** The ring start's answer: records the account it was handed, answers the scripted call. */
    public function answerRingStart(Account $ownerAccount, ?AgentSpace $agentSpace, ?AIConversation $aiConversation = null, ?string $callTask = null): AgentVoiceCall
    {
        $this->accountIdsHandedToTheRingStart[] = (int)$ownerAccount->id;

        return $this->callTheRingStartAnswers;
    }

    /** The admission's answer: records the account it was asked for and refuses for a used-up budget. */
    public function answerAdmissionWithNoAiBudget(Account $account, ?AgentSpace $agentSpace, ?AIConversation $aiConversation): AgentVoiceCallRefusal
    {
        $this->accountIdsTheAdmissionWasAskedFor[] = (int)$account->id;

        return AgentVoiceCallRefusal::forNoAiBudget();
    }

    /** The conversations service's `find()`: records whether the rights applied, answers the call's conversation. */
    public function answerConversationOfTheCall(int|string|null $entityId, bool $useEntityRegistrCache = true): ?AIConversation
    {
        $this->rightsAppliedDuringConversationReads[] = DBEntity::getApplyRightsRestrictions();

        return (int)$entityId === static::CONVERSATION_ID ? $this->conversationOfTheCall : null;
    }

    /**
     * The store answers `$agentVoiceCall` by its id alone and, for the signed-in account, only when it is that
     * account's call; its conversation works for {@see $agentSpace}.
     */
    protected function scriptStoreAndConversationOfCall(AgentVoiceCall $agentVoiceCall): void
    {
        $this->storeAnswersCallOfSignedInAccount($agentVoiceCall);
        $this->agentVoiceCallStoreService->method('findCall')->willReturnMap([[static::CALL_ID, $agentVoiceCall]]);
        $this->conversationOfTheCall = new AIConversation();
        $this->conversationOfTheCall->id = static::CONVERSATION_ID;
        $this->conversationOfTheCall->accountId = $agentVoiceCall->accountId;
        $this->conversationOfTheCall->agentSpaceId = static::AGENT_SPACE_ID;
        $this->conversationOfTheCall->agentSpace = $this->agentSpace;
        $this->aIConversationsService->method('find')->willReturnCallback([$this, 'answerConversationOfTheCall']);
    }

    /** A call the agent rang for `$accountId` on the conversation {@see CONVERSATION_ID}, not answered yet. */
    protected function buildCallOfAccount(int $accountId): AgentVoiceCall
    {
        $agentVoiceCall = static::buildCallOfSignedInAccount(ringDeadlineOffsetSeconds: 60, direction: AgentVoiceCall::DIRECTION_OUTBOUND);
        $agentVoiceCall->accountId = $accountId;

        return $agentVoiceCall;
    }

    /**
     * Each route that names a call, with {@see CALL_ID}: `answered`, or the class and message of what it threw.
     *
     * @return array<string, string>
     */
    protected function runEveryRouteThatNamesACall(AgentVoiceSessionsFixtureController $fixtureController): array
    {
        $answerRequestDto = new AgentVoiceSessionAnswerRequestDto();
        $answerRequestDto->callId = static::CALL_ID;
        $answerRequestDto->answerToken = static::ANSWER_TOKEN;
        $declineRequestDto = new AgentVoiceSessionDeclineRequestDto();
        $declineRequestDto->callId = static::CALL_ID;
        $declineRequestDto->answerToken = static::ANSWER_TOKEN;
        $hangUpRequestDto = new AgentVoiceSessionHangUpRequestDto();
        $hangUpRequestDto->callId = static::CALL_ID;
        $deviceStatusRequestDto = new AgentVoiceSessionDeviceStatusRequestDto();
        $deviceStatusRequestDto->callId = static::CALL_ID;
        $deviceStatusRequestDto->status = 'MEDIA_CONNECTED';
        $callStateRequestDto = new AgentVoiceSessionCallStateGetRequestDto();
        $callStateRequestDto->callId = static::CALL_ID;

        $routeOutcomes = [];
        foreach (static::ROUTES_THAT_NAME_A_CALL as $routeName) {
            try {
                match ($routeName) {
                    'answer' => $fixtureController->answerAgentVoiceSession($answerRequestDto),
                    'decline' => $fixtureController->declineAgentVoiceSession($declineRequestDto),
                    'hangUp' => $fixtureController->hangUpAgentVoiceSession($hangUpRequestDto),
                    'deviceStatus' => $fixtureController->reportAgentVoiceSessionDeviceStatus($deviceStatusRequestDto),
                    'callState' => $fixtureController->getAgentVoiceSessionCallState($callStateRequestDto),
                };
                $routeOutcomes[$routeName] = 'answered';
            } catch (Throwable $routeRefusal) {
                $routeOutcomes[$routeName] = $routeRefusal::class . ': ' . $routeRefusal->getMessage();
            }
        }

        return $routeOutcomes;
    }
}
