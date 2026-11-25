<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\MessageHandlers\Voice\Ring;

use DDD\Domain\AI\MessageHandlers\Voice\AgentVoiceMessengerTransports;
use DDD\Domain\AI\MessageHandlers\Voice\AgentVoiceRingDeadlineHandler;
use DDD\Domain\AI\MessageHandlers\Voice\AgentVoiceRingDeadlineMessage;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Ring\AgentVoiceRingStagingTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The delayed look at ONE ring after its deadline: it ends a ring nobody answered EXPIRED (once, with its message
 * cleared) and does nothing for a call that is missing, ended, answered or not yet due. It never throws, and the
 * rights it lifts are back afterwards.
 */
class AgentVoiceRingDeadlineHandlerTest extends AgentVoiceRingStagingTestCase
{
    public function testAnOverdueRingEndsExpiredOnceAndItsMessageIsCleared(): void
    {
        $this->storeRingingCall(501, time(), -5);

        $this->handleDeadlineMessageOfCall(501);
        $this->handleDeadlineMessageOfCall(501);

        static::assertSame('EXPIRED', $this->callStore->storedCallsById[501]->endReason);
        static::assertSame([501], array_column($this->callsService->recordedLifecycleMarkers, 'callId'));
        static::assertSame([501], $this->callsService->recordedFollowUpCallIds);
        static::assertSame([['audience' => 'account-41', 'messageType' => 'agent.voice.ring', 'messageKey' => '501']], $this->realtimeChannel->askedClears);
        static::assertSame(['findCall', 'claimUnansweredCallEnd', 'markCallEnded', 'findCall'], $this->callStore->getRecordedMethodNames(), 'the second look stops at the ended call');
        static::assertFalse($this->callStore->recordedCalls[0]['rightsApplied'], 'the call is read with the rights lifted');
        static::assertTrue(DBEntity::getApplyRightsRestrictions());
    }

    #[DataProvider('provideCallsThatAreLeftAlone')]
    public function testACallThatIsNotAnOverdueRingIsLeftAlone(string $callState): void
    {
        $nowUnixTime = time();
        if ($callState !== 'missing') {
            $agentVoiceCall = $this->storeRingingCall(501, $nowUnixTime, $callState === 'not yet due' ? 30 : -5);
            $agentVoiceCall->startedAt = $callState === 'answered' ? DateTime::fromTimestamp($nowUnixTime - 20) : null;
            $agentVoiceCall->endedAt = $callState === 'ended' ? DateTime::fromTimestamp($nowUnixTime - 20) : null;
            $agentVoiceCall->endReason = $callState === 'ended' ? 'DECLINED' : null;
        }

        $this->handleDeadlineMessageOfCall(501);

        static::assertSame(['findCall'], $this->callStore->getRecordedMethodNames());
        static::assertSame([501], array_column($this->callStore->recordedCalls, 'subject'));
        static::assertSame([], $this->callsService->recordedLifecycleMarkers);
        static::assertSame([], $this->realtimeChannel->askedClears);
        static::assertTrue(DBEntity::getApplyRightsRestrictions());
    }

    /** @return array<string, array{string}> */
    public static function provideCallsThatAreLeftAlone(): array
    {
        return [
            'missing' => ['missing'],
            'ended' => ['ended'],
            'answered' => ['answered'],
            'not yet due' => ['not yet due'],
        ];
    }

    public function testAFailureIsLoggedNeverThrownAndTheRightsAreBack(): void
    {
        $this->callStore->failingMethodNames = ['findCall'];

        $this->handleDeadlineMessageOfCall(501);

        static::assertTrue(DBEntity::getApplyRightsRestrictions());
        $loggedFailures = $this->logger->findMessagesContaining('Ring deadline check of call 501');
        static::assertCount(1, $loggedFailures);
        static::assertStringStartsWith('Ring deadline check of call 501 error [RuntimeException #0] findCall failed in ', $loggedFailures[0]);
    }

    public function testAFailingEndIsLoggedNeverThrown(): void
    {
        $this->storeRingingCall(501, time(), -5);
        $this->callStore->failingMethodNames = ['claimUnansweredCallEnd'];

        $this->handleDeadlineMessageOfCall(501);

        static::assertCount(1, $this->logger->findMessagesContaining('Ring deadline check of call 501 error [RuntimeException #0] claimUnansweredCallEnd failed'));
        static::assertTrue(DBEntity::getApplyRightsRestrictions());
    }

    public function testTheHandlerConsumesTheRingDeadlineTransport(): void
    {
        $asMessageHandlerAttributes = (new ReflectionClass(AgentVoiceRingDeadlineHandler::class))->getAttributes(AsMessageHandler::class);

        static::assertCount(1, $asMessageHandlerAttributes);
        static::assertSame('voice_ring_deadline', $asMessageHandlerAttributes[0]->newInstance()->fromTransport);
        static::assertSame(AgentVoiceMessengerTransports::RING_DEADLINE, AgentVoiceRingDeadlineMessage::TRANSPORT);
        static::assertSame(AgentVoiceRingDeadlineHandler::class, AgentVoiceRingDeadlineMessage::$messageHandler);
    }

    public function testTheMessageCarriesOnlyTheCallId(): void
    {
        $agentVoiceRingDeadlineMessage = new AgentVoiceRingDeadlineMessage(501);

        static::assertSame(501, $agentVoiceRingDeadlineMessage->agentVoiceCallId);
        $ownPropertyNames = [];
        foreach ((new ReflectionClass(AgentVoiceRingDeadlineMessage::class))->getProperties() as $reflectionProperty) {
            if ($reflectionProperty->getDeclaringClass()->getName() === AgentVoiceRingDeadlineMessage::class && !$reflectionProperty->isStatic()) {
                $ownPropertyNames[] = $reflectionProperty->getName();
            }
        }
        static::assertSame(['agentVoiceCallId'], $ownPropertyNames);
    }

    protected function handleDeadlineMessageOfCall(int $callId): void
    {
        $agentVoiceRingDeadlineHandler = new AgentVoiceRingDeadlineHandler();
        $agentVoiceRingDeadlineHandler(new AgentVoiceRingDeadlineMessage($callId));
    }
}
