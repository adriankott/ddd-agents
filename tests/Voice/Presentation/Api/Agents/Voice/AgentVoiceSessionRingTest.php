<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Presentation\Api\Agents\Voice;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\ConflictException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionRingRequestDto;
use DDD\Tests\Agents\Voice\Support\AgentVoiceCallFixtures;

/**
 * `ring` ("call me"): one call per account, the conversation the request names read under the chat's visibility, the
 * ring start asked with the entity rights lifted (and the rights put back, also after a refusal), the ring start's
 * refusal answered as it is, and the answer: the call, its conversation, the end of the ring in UTC and whether any
 * client was reached.
 */
class AgentVoiceSessionRingTest extends AgentVoiceSessionsTestCase
{
    public const string BERLIN_TIME_ZONE = 'Europe/Berlin';

    /** @var bool[] Whether the entity rights applied when the ring start ran */
    protected array $rightsAppliedDuringRingStart = [];

    protected ?AgentVoiceCall $callTheRingStartAnswers = null;

    protected ?string $timeZoneBeforeTheTest = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->timeZoneBeforeTheTest = date_default_timezone_get();
        // a process zone that is not UTC: a time written without converting it would show here
        date_default_timezone_set(static::BERLIN_TIME_ZONE);
    }

    protected function onTearDown(): void
    {
        date_default_timezone_set((string)$this->timeZoneBeforeTheTest);
        parent::onTearDown();
    }

    public function testASecondCallOfTheAccountIsRefusedBeforeTheRingStarts(): void
    {
        $this->agentVoiceCallsService->expects(static::once())->method('findOpenAppCallForAccountId')->with(static::ACCOUNT_ID)
            ->willReturn(static::buildCallOfSignedInAccount(ringDeadlineOffsetSeconds: 30));
        $this->agentVoiceCallStartsService->expects(static::never())->method(static::anything());

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('call_already_running');
        $this->fixtureController->callBuildVoiceCallRingingResponse(new AgentVoiceSessionRingRequestDto(), null);
    }

    public function testAConversationTheCallerMayNotSeeAnswersConversationNotFound(): void
    {
        $this->aIConversationsService->method('findVisibleToCustomer')->willThrowException(new NotFoundException('AIConversation 99 not found.'));
        $this->agentVoiceCallStartsService->expects(static::never())->method(static::anything());
        $ringRequestDto = new AgentVoiceSessionRingRequestDto();
        $ringRequestDto->conversationId = 99;

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('conversation_not_found');
        $this->fixtureController->callBuildVoiceCallRingingResponse($ringRequestDto, null);
    }

    public function testTheRingStartsWithTheRightsLiftedAndAnswersTheRingInUtc(): void
    {
        $agentSpace = new AgentSpace();
        $agentSpace->id = 3;
        $aiConversation = static::buildConversationOfSignedInAccount();
        $this->aIConversationsService->expects(static::once())->method('findVisibleToCustomer')->with(static::CONVERSATION_ID)->willReturn($aiConversation);
        $this->callTheRingStartAnswers = static::buildCallOfSignedInAccount(ringDeadlineOffsetSeconds: 90, direction: AgentVoiceCall::DIRECTION_OUTBOUND);
        $this->agentVoiceCallStartsService->expects(static::once())->method('startRing')
            ->with(static::identicalTo($this->signedInAccount), static::identicalTo($agentSpace), static::identicalTo($aiConversation), 'Go through the open points.')
            ->willReturnCallback([$this, 'answerRingStart']);
        $ringRequestDto = new AgentVoiceSessionRingRequestDto();
        $ringRequestDto->conversationId = static::CONVERSATION_ID;
        $ringRequestDto->task = 'Go through the open points.';

        $responseDto = $this->fixtureController->callBuildVoiceCallRingingResponse($ringRequestDto, $agentSpace);

        static::assertSame([false], $this->rightsAppliedDuringRingStart, 'the ring start runs with the entity rights lifted');
        static::assertTrue(DBEntity::getApplyRightsRestrictions(), 'the rights apply again after the ring start');
        static::assertSame(static::CALL_ID, $responseDto->callId);
        static::assertSame(static::CONVERSATION_ID, $responseDto->conversationId);
        static::assertSame(gmdate('Y-m-d\TH:i:s\Z', AgentVoiceCallFixtures::NOW_UNIX_TIME + 90), $responseDto->ringExpiresAt);
        static::assertSame('2027-01-15T08:01:30Z', $responseDto->ringExpiresAt);
        static::assertTrue($responseDto->anyDeviceReached);
    }

    public function testARingThatReachedNoClientAnswersThatNoDeviceWasReached(): void
    {
        $this->callTheRingStartAnswers = static::buildCallOfSignedInAccount(ringDeadlineOffsetSeconds: 90, endedOffsetSeconds: 0, direction: AgentVoiceCall::DIRECTION_OUTBOUND);
        $this->callTheRingStartAnswers->endReason = AgentVoiceCall::END_REASON_FAILED;
        $this->callTheRingStartAnswers->aiConversationId = null;
        $this->agentVoiceCallStartsService->expects(static::once())->method('startRing')
            ->with(static::identicalTo($this->signedInAccount), null, null, null)
            ->willReturnCallback([$this, 'answerRingStart']);

        $responseDto = $this->fixtureController->callBuildVoiceCallRingingResponse(new AgentVoiceSessionRingRequestDto(), null);

        static::assertFalse($responseDto->anyDeviceReached);
        static::assertSame(static::CALL_ID, $responseDto->callId);
        static::assertNull($responseDto->conversationId);
    }

    public function testARingWithoutADeadlineAnswersNoRingEnd(): void
    {
        $this->callTheRingStartAnswers = static::buildCallOfSignedInAccount(direction: AgentVoiceCall::DIRECTION_OUTBOUND);
        $this->agentVoiceCallStartsService->method('startRing')->willReturnCallback([$this, 'answerRingStart']);

        $responseDto = $this->fixtureController->callBuildVoiceCallRingingResponse(new AgentVoiceSessionRingRequestDto(), null);

        static::assertNull($responseDto->ringExpiresAt);
    }

    public function testARefusedRingStartIsAnsweredAsItIsAndTheRightsApplyAgain(): void
    {
        $this->agentVoiceCallStartsService->expects(static::once())->method('startRing')->willThrowException(new ForbiddenException('daily_limit_reached'));

        try {
            $this->fixtureController->callBuildVoiceCallRingingResponse(new AgentVoiceSessionRingRequestDto(), null);
            static::fail('a refused ring start must refuse the ring');
        } catch (ForbiddenException $forbiddenException) {
            static::assertSame('daily_limit_reached', $forbiddenException->getMessage());
        }
        static::assertTrue(DBEntity::getApplyRightsRestrictions(), 'the rights apply again after a refused ring start');
    }

    /** The ring start's answer: records whether the rights applied, answers the scripted call. */
    public function answerRingStart(Account $ownerAccount, ?AgentSpace $agentSpace, ?AIConversation $aiConversation = null, ?string $callTask = null): AgentVoiceCall
    {
        $this->rightsAppliedDuringRingStart[] = DBEntity::getApplyRightsRestrictions();

        return $this->callTheRingStartAnswers;
    }
}
