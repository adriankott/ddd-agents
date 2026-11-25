<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Entities\Voice\CallLife;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallGoodbyeHangUp;
use DDD\Tests\Agents\Voice\Support\AgentVoiceUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * When the session worker hangs up after the agent's goodbye ({@see AgentVoiceCallGoodbyeHangUp::isDueAt()}): the four
 * rules, each at its boundary. All clocks are milliseconds after a fixed arming moment.
 */
class AgentVoiceCallGoodbyeHangUpTest extends AgentVoiceUnitTestCase
{
    protected const int ARMED_AT_UNIX_MS = 1_800_000_000_000;

    public function testTheLiveSessionHangUpCarriesItsEndReasonAndQuietWindow(): void
    {
        $goodbyeHangUp = AgentVoiceCallGoodbyeHangUp::forLiveSession(static::ARMED_AT_UNIX_MS, AgentVoiceCall::END_REASON_ENDED_BY_AGENT);

        static::assertSame(static::ARMED_AT_UNIX_MS, $goodbyeHangUp->armedAtUnixMs);
        static::assertSame(AgentVoiceCall::END_REASON_ENDED_BY_AGENT, $goodbyeHangUp->endReason);
        static::assertSame(1500, $goodbyeHangUp->quietMilliseconds);
        static::assertSame(1500, (new AgentVoiceCallGoodbyeHangUp(static::ARMED_AT_UNIX_MS, AgentVoiceCall::END_REASON_COMPLETED))->quietMilliseconds);
    }

    /**
     * @param int $nowAfterArmingMs
     * @param int|null $lastSpeechAfterArmingMs
     * @param int|null $handedOverAfterArmingMs
     */
    #[DataProvider('provideMoments')]
    public function testTheHangUpIsDueByItsFourRules(
        int $nowAfterArmingMs,
        ?int $lastSpeechAfterArmingMs,
        ?int $handedOverAfterArmingMs,
        bool $answerStillOnItsWay,
        bool $expectedDue
    ): void {
        $goodbyeHangUp = AgentVoiceCallGoodbyeHangUp::forLiveSession(static::ARMED_AT_UNIX_MS, AgentVoiceCall::END_REASON_ENDED_BY_AGENT);

        static::assertSame($expectedDue, $goodbyeHangUp->isDueAt(
            static::ARMED_AT_UNIX_MS + $nowAfterArmingMs,
            $lastSpeechAfterArmingMs === null ? null : static::ARMED_AT_UNIX_MS + $lastSpeechAfterArmingMs,
            $handedOverAfterArmingMs === null ? null : static::ARMED_AT_UNIX_MS + $handedOverAfterArmingMs,
            $answerStillOnItsWay
        ));
    }

    /** @return array<string, array{int, ?int, ?int, bool, bool}> */
    public static function provideMoments(): array
    {
        return [
            // rule 1: the answer maximum after the arming, even while the answer is on its way
            'answer on its way, just under the answer maximum' => [119_999, null, null, true, false],
            'answer on its way, at the answer maximum' => [120_000, null, null, true, true],
            // rule 2: nothing while the answer is on its way
            'answer on its way, voice long quiet' => [60_000, 1_000, 500, true, false],
            // rule 3: the maximum after the goodbye's start, whatever is spoken
            'a voice that keeps talking, just under the maximum' => [29_999, 29_998, null, false, false],
            'a voice that keeps talking, at the maximum' => [30_000, 29_999, null, false, true],
            'the maximum counts from the hand-over after the arming' => [40_000, 39_999, 10_001, false, false],
            'the maximum from the hand-over is reached' => [40_001, 40_000, 10_001, false, true],
            // rule 4: spoken = speech after the goodbye's start, then the quiet window
            'speech after the start, quiet just under the window' => [5_000, 3_501, null, false, false],
            'speech after the start, quiet for the window' => [5_000, 3_500, null, false, true],
            'speech before the hand-over does not count' => [9_000, 4_000, 5_000, false, false],
            'no speech after the start, just under the floor' => [5_999, null, null, false, false],
            'no speech after the start, at the floor' => [6_000, null, null, false, true],
            'speech only at the start is no speech after it' => [6_000, 0, null, false, true],
            'a late goodbye is not cut' => [7_000, 6_500, null, false, false],
            'a pause shorter than the window is not the end' => [9_000, 8_000, 2_000, false, false],
            'a hand-over before the arming starts the clock at the arming' => [6_000, null, -4_000, false, true],
        ];
    }
}
