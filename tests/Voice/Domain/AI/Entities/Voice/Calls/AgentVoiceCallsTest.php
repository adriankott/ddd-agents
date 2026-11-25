<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Entities\Voice\Calls;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCalls;
use DDD\Tests\Agents\Voice\Doubles\AnotherMediumAgentVoiceCall;
use DDD\Tests\Agents\Voice\Doubles\KioskCountingAgentVoiceCalls;
use DDD\Tests\Agents\Voice\Support\AgentVoiceCallFixtures;
use DDD\Tests\Agents\Voice\Support\AgentVoiceUnitTestCase;

/**
 * A list of calls: the call log the model reads (summary line, one line per call, the marker of the current
 * conversation, the wording of a list of open calls), the calls open at a moment and the running calls.
 */
class AgentVoiceCallsTest extends AgentVoiceUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['limits' => ['maximumCallSeconds' => 600, 'ringSeconds' => 30]]));
        $this->declareAgentPersonaConfiguration(['agentName' => 'Ada']);
    }

    public function testTheCallLogWithASummaryLineAndOneLinePerCall(): void
    {
        $nowUnixTime = AgentVoiceCallFixtures::NOW_UNIX_TIME;
        $agentVoiceCalls = $this->buildCallLogAt($nowUnixTime);

        static::assertSame(
            "Calls — 3 total (2 inbound, 1 outbound), newest first:\n"
            . $agentVoiceCalls->getElements()[0]->buildLlmMarkdownLine($nowUnixTime, false) . "\n"
            . $agentVoiceCalls->getElements()[1]->buildLlmMarkdownLine($nowUnixTime, true) . "\n"
            . $agentVoiceCalls->getElements()[2]->buildLlmMarkdownLine($nowUnixTime, false),
            $agentVoiceCalls->buildLlmMarkdown($nowUnixTime, 30, false)
        );
    }

    public function testTheMarkerGoesOnlyToTheCallOfTheCurrentConversation(): void
    {
        $nowUnixTime = AgentVoiceCallFixtures::NOW_UNIX_TIME;
        $agentVoiceCalls = $this->buildCallLogAt($nowUnixTime);

        $callLogLines = explode("\n", $agentVoiceCalls->buildLlmMarkdown($nowUnixTime, 30, false));

        static::assertCount(4, $callLogLines);
        static::assertStringNotContainsString('THIS conversation', $callLogLines[1]);
        static::assertStringEndsWith('conversation 30 ← THIS conversation\'s call', $callLogLines[2]);
        static::assertStringNotContainsString('this conversation', $callLogLines[3]);
        static::assertStringNotContainsString('THIS conversation', $agentVoiceCalls->buildLlmMarkdown($nowUnixTime, null, false));
        static::assertStringNotContainsString('THIS conversation', $agentVoiceCalls->buildLlmMarkdown($nowUnixTime, 99, false));
    }

    public function testACallWithoutAConversationGetsNoMarkerWhenNoConversationIsCurrent(): void
    {
        $nowUnixTime = AgentVoiceCallFixtures::NOW_UNIX_TIME;
        $agentVoiceCalls = new AgentVoiceCalls();
        $callWithoutConversation = AgentVoiceCallFixtures::buildCallAt($nowUnixTime, -100, null, -90);
        $agentVoiceCalls->add($callWithoutConversation);

        static::assertSame(
            "Calls — 1 total (1 inbound, 0 outbound), newest first:\n" . $callWithoutConversation->buildLlmMarkdownLine($nowUnixTime, false),
            $agentVoiceCalls->buildLlmMarkdown($nowUnixTime, null, false)
        );
        static::assertStringEndsWith(' · no conversation', $agentVoiceCalls->buildLlmMarkdown($nowUnixTime, null, false));
    }

    public function testTheListOfOpenCallsIsWordedAsSuch(): void
    {
        $nowUnixTime = AgentVoiceCallFixtures::NOW_UNIX_TIME;
        $agentVoiceCalls = $this->buildCallLogAt($nowUnixTime);

        static::assertStringStartsWith(
            "Calls ringing or running right now — 3 total (2 inbound, 1 outbound), newest first:\n- #9 · ",
            $agentVoiceCalls->buildLlmMarkdown($nowUnixTime, null, true)
        );
    }

    public function testAnEmptyCallLogIsTheSummaryLineAlone(): void
    {
        $agentVoiceCalls = new AgentVoiceCalls();

        static::assertSame('Calls — 0 total (0 inbound, 0 outbound), newest first:', $agentVoiceCalls->buildLlmMarkdown(AgentVoiceCallFixtures::NOW_UNIX_TIME, null, false));
        static::assertSame('Calls ringing or running right now — 0 total (0 inbound, 0 outbound), newest first:', $agentVoiceCalls->buildLlmMarkdown(AgentVoiceCallFixtures::NOW_UNIX_TIME, 30, true));
        static::assertCount(0, $agentVoiceCalls->getOpenCallsAt(AgentVoiceCallFixtures::NOW_UNIX_TIME));
        static::assertCount(0, $agentVoiceCalls->getRunningCalls());
    }

    public function testToLlmMarkdownIsTheLogReadNowWithoutAMarker(): void
    {
        $agentVoiceCalls = new AgentVoiceCalls();
        $endedCall = new AgentVoiceCall();
        $endedCall->id = 4;
        $endedCall->direction = AgentVoiceCall::DIRECTION_OUTBOUND;
        $endedCall->aiConversationId = 30;
        $endedCall->endReason = AgentVoiceCall::END_REASON_EXPIRED;
        $agentVoiceCalls->add($endedCall);

        static::assertSame(
            "Calls — 1 total (0 inbound, 1 outbound), newest first:\n"
            . '- #4 · APP · Ada → in-app · ENDED · never answered · started by Ada on request (the owner\'s call-me button or an operator) · end EXPIRED · conversation 30',
            $agentVoiceCalls->toLlmMarkdown()
        );
    }

    public function testTheCallsOpenAtAMoment(): void
    {
        $nowUnixTime = AgentVoiceCallFixtures::NOW_UNIX_TIME;
        $agentVoiceCalls = $this->buildCallLogAt($nowUnixTime);
        $deadWorkerCall = AgentVoiceCallFixtures::buildCallAt($nowUnixTime, -900, null, -800);
        $deadWorkerCall->id = 6;
        $agentVoiceCalls->add($deadWorkerCall);
        $endedCall = AgentVoiceCallFixtures::buildCallAt($nowUnixTime, -300, null, -200, -100);
        $endedCall->id = 5;
        $agentVoiceCalls->add($endedCall);

        static::assertSame([9, 8, 7], static::collectCallIds($agentVoiceCalls->getOpenCallsAt($nowUnixTime)));
        static::assertCount(0, $agentVoiceCalls->getOpenCallsAt($nowUnixTime + 100000));
    }

    public function testTheRunningCalls(): void
    {
        $nowUnixTime = AgentVoiceCallFixtures::NOW_UNIX_TIME;
        $agentVoiceCalls = $this->buildCallLogAt($nowUnixTime);
        $endedCall = AgentVoiceCallFixtures::buildCallAt($nowUnixTime, -300, null, -200, -100);
        $endedCall->id = 5;
        $agentVoiceCalls->add($endedCall);

        static::assertSame([8, 7], static::collectCallIds($agentVoiceCalls->getRunningCalls()));
    }

    public function testTheOpenAndTheRunningCallsAreASetOfTheReceiversClassInItsOrder(): void
    {
        $nowUnixTime = AgentVoiceCallFixtures::NOW_UNIX_TIME;
        $agentVoiceCalls = $this->buildKioskCallLogAt($nowUnixTime);

        $openCalls = $agentVoiceCalls->getOpenCallsAt($nowUnixTime);
        $runningCalls = $agentVoiceCalls->getRunningCalls();

        static::assertSame(KioskCountingAgentVoiceCalls::class, $openCalls::class);
        static::assertSame(KioskCountingAgentVoiceCalls::class, $runningCalls::class);
        static::assertSame(AgentVoiceCalls::class, (new AgentVoiceCalls())->getOpenCallsAt($nowUnixTime)::class);
        static::assertSame([3, 9, 8], static::collectCallIds($openCalls));
        static::assertSame([3, 8], static::collectCallIds($runningCalls));
        static::assertNotSame($agentVoiceCalls, $openCalls, 'a set of its own, the receiver keeps its calls');
        static::assertCount(5, $agentVoiceCalls);
    }

    public function testTheSummaryLineCountsTheSetItIsCalledOn(): void
    {
        $nowUnixTime = AgentVoiceCallFixtures::NOW_UNIX_TIME;
        $agentVoiceCalls = $this->buildKioskCallLogAt($nowUnixTime);

        static::assertSame('Calls — 5 total (3 inbound, 2 outbound), newest first: (2 by kiosk)', explode("\n", $agentVoiceCalls->buildLlmMarkdown($nowUnixTime, null, false))[0]);
        static::assertSame(
            'Calls ringing or running right now — 3 total (2 inbound, 1 outbound), newest first: (1 by kiosk)',
            explode("\n", $agentVoiceCalls->getOpenCallsAt($nowUnixTime)->buildLlmMarkdown($nowUnixTime, null, true))[0]
        );
        static::assertSame(
            'Calls — 2 total (2 inbound, 0 outbound), newest first: (1 by kiosk)',
            static::invokeMethod($agentVoiceCalls->getRunningCalls(), 'buildLlmMarkdownSummaryLine', [false])
        );
        static::assertSame('Calls — 0 total (0 inbound, 0 outbound), newest first:', static::invokeMethod(new AgentVoiceCalls(), 'buildLlmMarkdownSummaryLine', [false]));
    }

    /**
     * @return array<int, int|string|null>
     */
    protected static function collectCallIds(AgentVoiceCalls $agentVoiceCalls): array
    {
        $callIds = [];
        foreach ($agentVoiceCalls->getElements() as $agentVoiceCall) {
            $callIds[] = $agentVoiceCall->id;
        }
        return $callIds;
    }

    /**
     * Three calls, newest first: #9 rings (the agent rang the owner), #8 runs on conversation 30, #7 runs on
     * conversation 40.
     */
    protected function buildCallLogAt(int $nowUnixTime): AgentVoiceCalls
    {
        $agentVoiceCalls = new AgentVoiceCalls();
        $ringingCall = AgentVoiceCallFixtures::buildCallAt($nowUnixTime, -5, 60);
        $ringingCall->id = 9;
        $ringingCall->direction = AgentVoiceCall::DIRECTION_OUTBOUND;
        $ringingCall->aiConversationId = 50;
        $agentVoiceCalls->add($ringingCall);
        $callOnTheCurrentConversation = AgentVoiceCallFixtures::buildCallAt($nowUnixTime, -100, null, -90);
        $callOnTheCurrentConversation->id = 8;
        $callOnTheCurrentConversation->aiConversationId = 30;
        $agentVoiceCalls->add($callOnTheCurrentConversation);
        $callOnAnotherConversation = AgentVoiceCallFixtures::buildCallAt($nowUnixTime, -100, null, -20);
        $callOnAnotherConversation->aiConversationId = 40;
        $agentVoiceCalls->add($callOnAnotherConversation);
        return $agentVoiceCalls;
    }

    /**
     * Five calls in the set of an application with a kiosk medium, newest first: #3 a running kiosk call, #9 rings
     * (outbound), #8 runs, #4 an ended outbound kiosk call, #5 an ended call. Three are inbound, two by kiosk.
     */
    protected function buildKioskCallLogAt(int $nowUnixTime): KioskCountingAgentVoiceCalls
    {
        $agentVoiceCalls = new KioskCountingAgentVoiceCalls();
        $runningKioskCall = AgentVoiceCallFixtures::buildCallAt($nowUnixTime, -60, null, -50, null, AnotherMediumAgentVoiceCall::class);
        $runningKioskCall->id = 3;
        $ringingCall = AgentVoiceCallFixtures::buildCallAt($nowUnixTime, -5, 60);
        $ringingCall->id = 9;
        $ringingCall->direction = AgentVoiceCall::DIRECTION_OUTBOUND;
        $runningCall = AgentVoiceCallFixtures::buildCallAt($nowUnixTime, -100, null, -90);
        $runningCall->id = 8;
        $endedKioskCall = AgentVoiceCallFixtures::buildCallAt($nowUnixTime, -300, null, -250, -200, AnotherMediumAgentVoiceCall::class);
        $endedKioskCall->id = 4;
        $endedKioskCall->direction = AgentVoiceCall::DIRECTION_OUTBOUND;
        $endedCall = AgentVoiceCallFixtures::buildCallAt($nowUnixTime, -400, null, -350, -300);
        $endedCall->id = 5;
        $agentVoiceCalls->add($runningKioskCall, $ringingCall, $runningCall, $endedKioskCall, $endedCall);
        return $agentVoiceCalls;
    }
}
