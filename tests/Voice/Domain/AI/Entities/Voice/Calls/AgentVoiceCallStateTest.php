<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Entities\Voice\Calls;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Tests\Agents\Voice\Support\AgentVoiceCallFixtures;
use DDD\Tests\Agents\Voice\Support\AgentVoiceUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Where a call stands at a moment: the three states, their bounds (the ring deadline, the fallback ring window of a
 * row without a deadline, the maximum call length of an answered call), running seconds and duration, and the two
 * state transitions of the call itself. The limits come from `AI.agentVoice` (here: 600 s per call, 30 s ring).
 */
class AgentVoiceCallStateTest extends AgentVoiceUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['limits' => ['maximumCallSeconds' => 600, 'ringSeconds' => 30]]));
    }

    #[DataProvider('provideCallStates')]
    public function testTheStateOfACallAtAMoment(?int $createdOffset, ?int $ringDeadlineOffset, ?int $startedOffset, ?int $endedOffset, string $expectedCallState): void
    {
        $nowUnixTime = AgentVoiceCallFixtures::NOW_UNIX_TIME;
        $agentVoiceCall = AgentVoiceCallFixtures::buildCallAt($nowUnixTime, $createdOffset, $ringDeadlineOffset, $startedOffset, $endedOffset);

        static::assertSame($expectedCallState, $agentVoiceCall->getCallStateAt($nowUnixTime));
        static::assertSame($expectedCallState !== AgentVoiceCall::CALL_STATE_ENDED, $agentVoiceCall->isOpenAt($nowUnixTime));
    }

    /** @return array<string, array{?int, ?int, ?int, ?int, string}> created, ring deadline, started, ended (seconds from now), state */
    public static function provideCallStates(): array
    {
        return [
            'hung up after it was answered' => [-200, null, -100, -10, AgentVoiceCall::CALL_STATE_ENDED],
            'hung up while it rang, deadline still ahead' => [-20, 70, null, -5, AgentVoiceCall::CALL_STATE_ENDED],
            'hung up a moment ago, answered a moment ago' => [-2, null, -1, 0, AgentVoiceCall::CALL_STATE_ENDED],
            'answered now' => [-5, null, 0, null, AgentVoiceCall::CALL_STATE_IN_PROGRESS],
            'answered exactly the maximum call length ago' => [-700, null, -600, null, AgentVoiceCall::CALL_STATE_IN_PROGRESS],
            'answered one second more than the maximum ago: a dead worker\'s row' => [-700, null, -601, null, AgentVoiceCall::CALL_STATE_ENDED],
            'answered in the future (clock skew)' => [-5, null, 5, null, AgentVoiceCall::CALL_STATE_IN_PROGRESS],
            'answered after its ring deadline passed' => [-120, -100, -50, null, AgentVoiceCall::CALL_STATE_IN_PROGRESS],
            'rings, deadline one second ahead' => [-89, 1, null, null, AgentVoiceCall::CALL_STATE_RINGING],
            'deadline is now' => [-90, 0, null, null, AgentVoiceCall::CALL_STATE_ENDED],
            'deadline passed a second ago' => [-91, -1, null, null, AgentVoiceCall::CALL_STATE_ENDED],
            'rings by its deadline although created long ago' => [-10000, 60, null, null, AgentVoiceCall::CALL_STATE_RINGING],
            'rings by its deadline, created moments ago: the window does not end it' => [-1, 3000, null, null, AgentVoiceCall::CALL_STATE_RINGING],
            'no deadline, created now' => [0, null, null, null, AgentVoiceCall::CALL_STATE_RINGING],
            'no deadline, created exactly the ring length ago' => [-30, null, null, null, AgentVoiceCall::CALL_STATE_RINGING],
            'no deadline, created one second more than the ring length ago' => [-31, null, null, null, AgentVoiceCall::CALL_STATE_ENDED],
            'no deadline, created in the future' => [5, null, null, null, AgentVoiceCall::CALL_STATE_RINGING],
            'no deadline and no creation time' => [null, null, null, null, AgentVoiceCall::CALL_STATE_ENDED],
        ];
    }

    public function testTheBoundsFollowAChangedConfig(): void
    {
        $nowUnixTime = AgentVoiceCallFixtures::NOW_UNIX_TIME;
        $answeredCall = AgentVoiceCallFixtures::buildCallAt($nowUnixTime, -1000, null, -900);
        $ringingCall = AgentVoiceCallFixtures::buildCallAt($nowUnixTime, -60);
        static::assertSame(AgentVoiceCall::CALL_STATE_ENDED, $answeredCall->getCallStateAt($nowUnixTime));
        static::assertSame(AgentVoiceCall::CALL_STATE_ENDED, $ringingCall->getCallStateAt($nowUnixTime));

        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['limits' => ['maximumCallSeconds' => 900, 'ringSeconds' => 60]]));

        static::assertSame(AgentVoiceCall::CALL_STATE_IN_PROGRESS, $answeredCall->getCallStateAt($nowUnixTime));
        static::assertSame(AgentVoiceCall::CALL_STATE_RINGING, $ringingCall->getCallStateAt($nowUnixTime));
    }

    #[DataProvider('provideRunningSeconds')]
    public function testRunningSecondsOnlyWhileTheCallIsInProgress(?int $createdOffset, ?int $ringDeadlineOffset, ?int $startedOffset, ?int $endedOffset, ?int $expectedRunningSeconds): void
    {
        $nowUnixTime = AgentVoiceCallFixtures::NOW_UNIX_TIME;
        $agentVoiceCall = AgentVoiceCallFixtures::buildCallAt($nowUnixTime, $createdOffset, $ringDeadlineOffset, $startedOffset, $endedOffset);

        static::assertSame($expectedRunningSeconds, $agentVoiceCall->getRunningSecondsAt($nowUnixTime));
    }

    /** @return array<string, array{?int, ?int, ?int, ?int, ?int}> */
    public static function provideRunningSeconds(): array
    {
        return [
            'answered 42 s ago' => [-50, null, -42, null, 42],
            'answered now' => [-5, null, 0, null, 0],
            'answered the maximum ago' => [-700, null, -600, null, 600],
            'answered in the future: never negative' => [-5, null, 5, null, 0],
            'past the maximum' => [-700, null, -601, null, null],
            'ringing' => [-5, 60, null, null, null],
            'hung up' => [-100, null, -90, -10, null],
        ];
    }

    #[DataProvider('provideDurations')]
    public function testTheDurationOfACallThatWasAnsweredAndHungUp(?int $startedOffset, ?int $endedOffset, ?int $expectedDurationInSeconds): void
    {
        $nowUnixTime = AgentVoiceCallFixtures::NOW_UNIX_TIME;
        $agentVoiceCall = AgentVoiceCallFixtures::buildCallAt($nowUnixTime, -1000, null, $startedOffset, $endedOffset);

        static::assertSame($expectedDurationInSeconds, $agentVoiceCall->getDurationInSeconds());
    }

    /** @return array<string, array{?int, ?int, ?int}> */
    public static function provideDurations(): array
    {
        return [
            'answered and hung up' => [-95, 0, 95],
            'hung up the second it was answered' => [-3, -3, 0],
            'still running' => [-95, null, null],
            'never answered' => [null, -5, null],
            'neither' => [null, null, null],
        ];
    }

    #[DataProvider('provideRunningCalls')]
    public function testARunningCallIsAnsweredAndNotHungUp(?int $startedOffset, ?int $endedOffset, bool $expectedIsRunning): void
    {
        $agentVoiceCall = AgentVoiceCallFixtures::buildCallAt(AgentVoiceCallFixtures::NOW_UNIX_TIME, -1000, null, $startedOffset, $endedOffset);

        static::assertSame($expectedIsRunning, $agentVoiceCall->isRunning());
    }

    /** @return array<string, array{?int, ?int, bool}> */
    public static function provideRunningCalls(): array
    {
        return [
            'answered, not hung up' => [-10, null, true],
            'answered, hung up' => [-10, -1, false],
            'never answered' => [null, null, false],
            'never answered, ended' => [null, -1, false],
        ];
    }

    public function testMarkStartedSetsTheAnswerTimeToNowAndNothingElse(): void
    {
        $agentVoiceCall = AgentVoiceCallFixtures::buildCallAt(AgentVoiceCallFixtures::NOW_UNIX_TIME, -10, 80);
        $beforeUnixTime = time();

        $agentVoiceCall->markStarted();

        static::assertGreaterThanOrEqual($beforeUnixTime, $agentVoiceCall->startedAt->getTimestamp());
        static::assertLessThanOrEqual(time(), $agentVoiceCall->startedAt->getTimestamp());
        static::assertNull($agentVoiceCall->endedAt);
        static::assertNull($agentVoiceCall->endReason);
        static::assertTrue($agentVoiceCall->isRunning());
    }

    public function testMarkEndedSetsTheHangUpTimeToNowAndTheReason(): void
    {
        $agentVoiceCall = AgentVoiceCallFixtures::buildCallAt(AgentVoiceCallFixtures::NOW_UNIX_TIME, -100, null, -90);
        $startedAtBefore = $agentVoiceCall->startedAt;
        $beforeUnixTime = time();

        $agentVoiceCall->markEnded(AgentVoiceCall::END_REASON_LIMIT_REACHED);

        static::assertGreaterThanOrEqual($beforeUnixTime, $agentVoiceCall->endedAt->getTimestamp());
        static::assertLessThanOrEqual(time(), $agentVoiceCall->endedAt->getTimestamp());
        static::assertSame('LIMIT_REACHED', $agentVoiceCall->endReason);
        static::assertSame($startedAtBefore, $agentVoiceCall->startedAt);
        static::assertFalse($agentVoiceCall->isRunning());
        static::assertSame(AgentVoiceCall::CALL_STATE_ENDED, $agentVoiceCall->getCallStateAt(time()));
    }

    public function testARingingCallGoesToInProgressWhenAnsweredAndToEndedWhenHungUp(): void
    {
        $agentVoiceCall = AgentVoiceCallFixtures::buildCallAt(time(), -5, 60);
        static::assertSame(AgentVoiceCall::CALL_STATE_RINGING, $agentVoiceCall->getCallStateAt(time()));

        $agentVoiceCall->markStarted();
        static::assertSame(AgentVoiceCall::CALL_STATE_IN_PROGRESS, $agentVoiceCall->getCallStateAt(time()));

        $agentVoiceCall->markEnded(AgentVoiceCall::END_REASON_COMPLETED);
        static::assertSame(AgentVoiceCall::CALL_STATE_ENDED, $agentVoiceCall->getCallStateAt(time()));
    }

    public function testARingingCallEndsWithoutBeingAnswered(): void
    {
        $agentVoiceCall = AgentVoiceCallFixtures::buildCallAt(time(), -5, 60);

        $agentVoiceCall->markEnded(AgentVoiceCall::END_REASON_DECLINED);

        static::assertSame(AgentVoiceCall::CALL_STATE_ENDED, $agentVoiceCall->getCallStateAt(time()));
        static::assertNull($agentVoiceCall->getDurationInSeconds());
        static::assertNull($agentVoiceCall->startedAt);
    }

    public function testTheConstantsOfTheContract(): void
    {
        static::assertSame(['INBOUND', 'OUTBOUND'], [AgentVoiceCall::DIRECTION_INBOUND, AgentVoiceCall::DIRECTION_OUTBOUND]);
        static::assertSame(
            ['COMPLETED', 'ENDED_BY_AGENT', 'LIMIT_REACHED', 'FAILED', 'NOT_ANSWERED', 'DECLINED', 'EXPIRED', 'CANCELED'],
            [
                AgentVoiceCall::END_REASON_COMPLETED,
                AgentVoiceCall::END_REASON_ENDED_BY_AGENT,
                AgentVoiceCall::END_REASON_LIMIT_REACHED,
                AgentVoiceCall::END_REASON_FAILED,
                AgentVoiceCall::END_REASON_NOT_ANSWERED,
                AgentVoiceCall::END_REASON_DECLINED,
                AgentVoiceCall::END_REASON_EXPIRED,
                AgentVoiceCall::END_REASON_CANCELED,
            ]
        );
        static::assertSame(['RINGING', 'IN_PROGRESS', 'ENDED'], [AgentVoiceCall::CALL_STATE_RINGING, AgentVoiceCall::CALL_STATE_IN_PROGRESS, AgentVoiceCall::CALL_STATE_ENDED]);
        static::assertSame('APP', (new AgentVoiceCall())->getChannelType());
    }

    public function testANewCallIsAnInboundCallWithNothingBooked(): void
    {
        $agentVoiceCall = new AgentVoiceCall();

        static::assertSame(AgentVoiceCall::DIRECTION_INBOUND, $agentVoiceCall->direction);
        static::assertSame(0, $agentVoiceCall->bookedSeconds);
        static::assertNull($agentVoiceCall->startedAt);
        static::assertNull($agentVoiceCall->endedAt);
        static::assertNull($agentVoiceCall->ringDeadlineAt);
    }
}
