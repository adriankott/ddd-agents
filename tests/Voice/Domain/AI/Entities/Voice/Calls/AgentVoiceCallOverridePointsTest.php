<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Entities\Voice\Calls;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Tests\Agents\Voice\Doubles\AnotherMediumAgentVoiceCall;
use DDD\Tests\Agents\Voice\Support\AgentVoiceCallFixtures;
use DDD\Tests\Agents\Voice\Support\AgentVoiceUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The override points of the call, through a subclass of an application with a further medium
 * ({@see AnotherMediumAgentVoiceCall}): the medium, the parts of the Markdown line, the other party, the word of the
 * lifecycle signal, the agent label, the maximum call length and the ring window without a deadline. The config says
 * 600 s / 30 s and the persona "Ada"; the subclass says 120 s / 10 s and "the voice desk", and the subclass wins.
 */
class AgentVoiceCallOverridePointsTest extends AgentVoiceUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['limits' => ['maximumCallSeconds' => 600, 'ringSeconds' => 30]]));
        $this->declareAgentPersonaConfiguration(['agentName' => 'Ada']);
    }

    public function testTheTextsOfTheSubclass(): void
    {
        $nowUnixTime = AgentVoiceCallFixtures::NOW_UNIX_TIME;
        $anotherMediumCall = AgentVoiceCallFixtures::buildCallAt($nowUnixTime, -120, null, -95, 0, AnotherMediumAgentVoiceCall::class);
        $anotherMediumCall->direction = AgentVoiceCall::DIRECTION_OUTBOUND;
        $anotherMediumCall->aiConversationId = 30;
        $anotherMediumCall->endReason = AgentVoiceCall::END_REASON_COMPLETED;

        static::assertSame('KIOSK', $anotherMediumCall->getChannelType());
        static::assertSame('the voice desk → kiosk 0100', $anotherMediumCall->buildCallPartiesLabel());
        static::assertSame('the voice desk on request (the owner\'s call-me button or an operator)', $anotherMediumCall->buildStartedByLabel());
        static::assertSame('Kiosk call started — the voice desk → kiosk 0100 (call #7).', $anotherMediumCall->buildLifecycleSignalText(false));
        static::assertSame('Kiosk call ended — the voice desk → kiosk 0100, 95s, reason COMPLETED (call #7).', $anotherMediumCall->buildLifecycleSignalText(true));
        static::assertSame(
            '- #7 · KIOSK · the voice desk → kiosk 0100 · ENDED · answered ' . AgentVoiceCallFixtures::formatMomentAt($nowUnixTime, -95)
            . ' · 95s · started by the voice desk on request (the owner\'s call-me button or an operator) · signed in by code · kiosk 0100'
            . ' · end COMPLETED · conversation 30',
            $anotherMediumCall->buildLlmMarkdownLine($nowUnixTime, false)
        );
    }

    public function testTheInboundTextsOfTheSubclass(): void
    {
        $anotherMediumCall = AgentVoiceCallFixtures::buildCallAt(AgentVoiceCallFixtures::NOW_UNIX_TIME, -10, null, 0, null, AnotherMediumAgentVoiceCall::class);

        static::assertSame('kiosk 0100 → the voice desk', $anotherMediumCall->buildCallPartiesLabel());
        static::assertSame('the owner (called the voice desk in the app)', $anotherMediumCall->buildStartedByLabel());
    }

    #[DataProvider('provideBoundsOfTheSubclass')]
    public function testTheBoundsOfTheSubclass(?int $createdOffset, ?int $startedOffset, string $expectedCallState): void
    {
        $nowUnixTime = AgentVoiceCallFixtures::NOW_UNIX_TIME;
        $anotherMediumCall = AgentVoiceCallFixtures::buildCallAt($nowUnixTime, $createdOffset, null, $startedOffset, null, AnotherMediumAgentVoiceCall::class);
        $baseCall = AgentVoiceCallFixtures::buildCallAt($nowUnixTime, $createdOffset, null, $startedOffset);

        static::assertSame($expectedCallState, $anotherMediumCall->getCallStateAt($nowUnixTime));
        static::assertNotSame($expectedCallState, $baseCall->getCallStateAt($nowUnixTime));
    }

    /** @return array<string, array{?int, ?int, string}> created, started (seconds from now), the state of the subclass */
    public static function provideBoundsOfTheSubclass(): array
    {
        return [
            'answered 121 s ago: past the subclass\'s 120 s' => [-200, -121, AgentVoiceCall::CALL_STATE_ENDED],
            'created 11 s ago without a deadline: past the subclass\'s 10 s' => [-11, null, AgentVoiceCall::CALL_STATE_ENDED],
        ];
    }

    public function testTheSubclassBoundsAtTheirEdge(): void
    {
        $nowUnixTime = AgentVoiceCallFixtures::NOW_UNIX_TIME;
        $answeredCall = AgentVoiceCallFixtures::buildCallAt($nowUnixTime, -200, null, -120, null, AnotherMediumAgentVoiceCall::class);
        $ringingCall = AgentVoiceCallFixtures::buildCallAt($nowUnixTime, -10, null, null, null, AnotherMediumAgentVoiceCall::class);

        static::assertSame(AgentVoiceCall::CALL_STATE_IN_PROGRESS, $answeredCall->getCallStateAt($nowUnixTime));
        static::assertSame(120, $answeredCall->getRunningSecondsAt($nowUnixTime));
        static::assertSame(AgentVoiceCall::CALL_STATE_RINGING, $ringingCall->getCallStateAt($nowUnixTime));
    }
}
