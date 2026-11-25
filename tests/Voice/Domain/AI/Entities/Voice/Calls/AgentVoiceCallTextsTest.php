<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Entities\Voice\Calls;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallReachableChannels;
use DDD\Tests\Agents\Voice\Doubles\RecordingAgentVoiceCallReachableChannels;
use DDD\Tests\Agents\Voice\Support\AgentVoiceCallFixtures;
use DDD\Tests\Agents\Voice\Support\AgentVoiceUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The texts of a call the model reads: the lifecycle signal (its wire shape is parsed by the chat's call marker:
 * `NNNs`, `reason <value>`, `call #<id>`), who started it and who called whom, the Markdown line of the call log, the
 * end the agent follows up on, and the default task of a call start. The persona names the agent "Ada".
 */
class AgentVoiceCallTextsTest extends AgentVoiceUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['limits' => ['maximumCallSeconds' => 600, 'ringSeconds' => 30]]));
        $this->declareAgentPersonaConfiguration(['agentName' => 'Ada', 'productName' => 'the product']);
    }

    #[DataProvider('provideLifecycleSignals')]
    public function testTheLifecycleSignalText(string $direction, ?int $startedOffset, ?int $endedOffset, ?string $endReason, bool $callEnded, string $expectedSignalText): void
    {
        $agentVoiceCall = AgentVoiceCallFixtures::buildCallAt(AgentVoiceCallFixtures::NOW_UNIX_TIME, -200, null, $startedOffset, $endedOffset);
        $agentVoiceCall->direction = $direction;
        $agentVoiceCall->endReason = $endReason;

        static::assertSame($expectedSignalText, $agentVoiceCall->buildLifecycleSignalText($callEnded));
    }

    /** @return array<string, array{string, ?int, ?int, ?string, bool, string}> */
    public static function provideLifecycleSignals(): array
    {
        return [
            'started, the owner called' => [AgentVoiceCall::DIRECTION_INBOUND, 0, null, null, false, 'In-app call started — in-app → Ada (call #7).'],
            'started, the agent called' => [AgentVoiceCall::DIRECTION_OUTBOUND, 0, null, null, false, 'In-app call started — Ada → in-app (call #7).'],
            'ended after 95 s' => [AgentVoiceCall::DIRECTION_INBOUND, -95, 0, AgentVoiceCall::END_REASON_COMPLETED, true, 'In-app call ended — in-app → Ada, 95s, reason COMPLETED (call #7).'],
            'ended the second it was answered' => [AgentVoiceCall::DIRECTION_OUTBOUND, -4, -4, AgentVoiceCall::END_REASON_FAILED, true, 'In-app call ended — Ada → in-app, 0s, reason FAILED (call #7).'],
            'ended unanswered: no duration' => [AgentVoiceCall::DIRECTION_OUTBOUND, null, 0, AgentVoiceCall::END_REASON_EXPIRED, true, 'In-app call ended — Ada → in-app, reason EXPIRED (call #7).'],
            'ended without a reason' => [AgentVoiceCall::DIRECTION_INBOUND, -95, 0, null, true, 'In-app call ended — in-app → Ada, 95s (call #7).'],
            'a diagnostic reason of a teardown path' => [AgentVoiceCall::DIRECTION_INBOUND, -30, 0, 'SESSION_SILENT', true, 'In-app call ended — in-app → Ada, 30s, reason SESSION_SILENT (call #7).'],
        ];
    }

    public function testThePersonaThatNamesNobodyGivesTheDefaultAgentLabel(): void
    {
        $this->declareAgentPersonaConfiguration(['agentName' => '  ', 'productName' => 'the product']);
        $agentVoiceCall = AgentVoiceCallFixtures::buildCallAt(AgentVoiceCallFixtures::NOW_UNIX_TIME, -200, null, 0);
        $agentVoiceCall->direction = AgentVoiceCall::DIRECTION_OUTBOUND;

        static::assertSame('In-app call started — the agent → in-app (call #7).', $agentVoiceCall->buildLifecycleSignalText(false));
        static::assertSame('the agent on request (the owner\'s call-me button or an operator)', $agentVoiceCall->buildStartedByLabel());
    }

    #[DataProvider('provideStartedByLabels')]
    public function testWhoStartedTheCall(string $direction, ?int $startedByAgentInConversationId, string $expectedStartedByLabel, string $expectedCallPartiesLabel): void
    {
        $agentVoiceCall = new AgentVoiceCall();
        $agentVoiceCall->direction = $direction;
        $agentVoiceCall->startedByAgentInConversationId = $startedByAgentInConversationId;

        static::assertSame($expectedStartedByLabel, $agentVoiceCall->buildStartedByLabel());
        static::assertSame($expectedCallPartiesLabel, $agentVoiceCall->buildCallPartiesLabel());
    }

    /** @return array<string, array{string, ?int, string, string}> */
    public static function provideStartedByLabels(): array
    {
        return [
            'the owner called' => [AgentVoiceCall::DIRECTION_INBOUND, null, 'the owner (called Ada in the app)', 'in-app → Ada'],
            'the owner called; a conversation id does not change it' => [AgentVoiceCall::DIRECTION_INBOUND, 31, 'the owner (called Ada in the app)', 'in-app → Ada'],
            'the agent called with its tool' => [AgentVoiceCall::DIRECTION_OUTBOUND, 31, 'Ada (startVoiceCall in conversation 31)', 'Ada → in-app'],
            'the agent called on request' => [AgentVoiceCall::DIRECTION_OUTBOUND, null, 'Ada on request (the owner\'s call-me button or an operator)', 'Ada → in-app'],
        ];
    }

    public function testTheMarkdownLineOfARingingCall(): void
    {
        $nowUnixTime = AgentVoiceCallFixtures::NOW_UNIX_TIME;
        $agentVoiceCall = AgentVoiceCallFixtures::buildCallAt($nowUnixTime, -10, 80);
        $agentVoiceCall->direction = AgentVoiceCall::DIRECTION_OUTBOUND;
        $agentVoiceCall->startedByAgentInConversationId = 31;
        $agentVoiceCall->aiConversationId = 30;
        $agentVoiceCall->callTask = '  Talk about the delivery date.  ';

        static::assertSame(
            '- #7 · APP · Ada → in-app · RINGING since ' . AgentVoiceCallFixtures::formatMomentAt($nowUnixTime, -10)
            . ' (not answered yet) · started by Ada (startVoiceCall in conversation 31) · conversation 30 · task: "Talk about the delivery date."',
            $agentVoiceCall->buildLlmMarkdownLine($nowUnixTime, false)
        );
        static::assertSame(
            '- #7 · APP · Ada → in-app · RINGING since ' . AgentVoiceCallFixtures::formatMomentAt($nowUnixTime, -10)
            . ' (not answered yet) · started by Ada (startVoiceCall in conversation 31) · conversation 30 ← THIS conversation\'s call · task: "Talk about the delivery date."',
            $agentVoiceCall->buildLlmMarkdownLine($nowUnixTime, true)
        );
    }

    public function testTheMarkdownLineOfARingingCallWithoutACreationTime(): void
    {
        $nowUnixTime = AgentVoiceCallFixtures::NOW_UNIX_TIME;
        $agentVoiceCall = AgentVoiceCallFixtures::buildCallAt($nowUnixTime, null, 80);

        static::assertSame(
            '- #7 · APP · in-app → Ada · RINGING since ? (not answered yet) · started by the owner (called Ada in the app) · no conversation',
            $agentVoiceCall->buildLlmMarkdownLine($nowUnixTime, false)
        );
    }

    public function testTheMarkdownLineOfACallInProgress(): void
    {
        $nowUnixTime = AgentVoiceCallFixtures::NOW_UNIX_TIME;
        $agentVoiceCall = AgentVoiceCallFixtures::buildCallAt($nowUnixTime, -50, null, -42);
        $agentVoiceCall->aiConversationId = 30;

        static::assertSame(
            '- #7 · APP · in-app → Ada · IN_PROGRESS since ' . AgentVoiceCallFixtures::formatMomentAt($nowUnixTime, -42)
            . ' (42s so far) · started by the owner (called Ada in the app) · conversation 30 ← THIS conversation\'s call',
            $agentVoiceCall->buildLlmMarkdownLine($nowUnixTime, true)
        );
    }

    public function testTheMarkdownLineOfAnAnsweredCallThatEnded(): void
    {
        $nowUnixTime = AgentVoiceCallFixtures::NOW_UNIX_TIME;
        $agentVoiceCall = AgentVoiceCallFixtures::buildCallAt($nowUnixTime, -120, null, -95, 0);
        $agentVoiceCall->aiConversationId = 30;
        $agentVoiceCall->endReason = AgentVoiceCall::END_REASON_COMPLETED;
        $agentVoiceCall->callTask = '   ';

        static::assertSame(
            '- #7 · APP · in-app → Ada · ENDED · answered ' . AgentVoiceCallFixtures::formatMomentAt($nowUnixTime, -95)
            . ' · 95s · started by the owner (called Ada in the app) · end COMPLETED · conversation 30 (this conversation)',
            $agentVoiceCall->buildLlmMarkdownLine($nowUnixTime, true)
        );
    }

    public function testTheMarkdownLineOfACallNobodyAnswered(): void
    {
        $nowUnixTime = AgentVoiceCallFixtures::NOW_UNIX_TIME;
        $agentVoiceCall = AgentVoiceCallFixtures::buildCallAt($nowUnixTime, -120, -30, null, -30);
        $agentVoiceCall->direction = AgentVoiceCall::DIRECTION_OUTBOUND;
        $agentVoiceCall->endReason = AgentVoiceCall::END_REASON_DECLINED;

        static::assertSame(
            '- #7 · APP · Ada → in-app · ENDED · never answered · started by Ada on request (the owner\'s call-me button or an operator) · end DECLINED · no conversation',
            $agentVoiceCall->buildLlmMarkdownLine($nowUnixTime, false)
        );
    }

    public function testTheMarkdownLineOfARowADeadWorkerLeftOpen(): void
    {
        $nowUnixTime = AgentVoiceCallFixtures::NOW_UNIX_TIME;
        $agentVoiceCall = AgentVoiceCallFixtures::buildCallAt($nowUnixTime, -800, null, -700);
        $agentVoiceCall->aiConversationId = 30;

        static::assertSame(
            '- #7 · APP · in-app → Ada · ENDED · answered ' . AgentVoiceCallFixtures::formatMomentAt($nowUnixTime, -700)
            . ' · started by the owner (called Ada in the app) · conversation 30 (this conversation)',
            $agentVoiceCall->buildLlmMarkdownLine($nowUnixTime, true)
        );
    }

    public function testToLlmMarkdownIsTheLineReadNowWithoutTheMarker(): void
    {
        $agentVoiceCall = new AgentVoiceCall();
        $agentVoiceCall->id = 7;
        $agentVoiceCall->aiConversationId = 30;
        $agentVoiceCall->endReason = AgentVoiceCall::END_REASON_CANCELED;

        static::assertSame(
            '- #7 · APP · in-app → Ada · ENDED · never answered · started by the owner (called Ada in the app) · end CANCELED · conversation 30',
            $agentVoiceCall->toLlmMarkdown()
        );
    }

    #[DataProvider('provideUnansweredEnds')]
    public function testTheAgentFollowsUpOnlyOnAnExpiredRingItStartedItself(?int $startedByAgentInConversationId, bool $answered, bool $ended, ?string $endReason, bool $expectedFollowsUp): void
    {
        $agentVoiceCall = AgentVoiceCallFixtures::buildCallAt(AgentVoiceCallFixtures::NOW_UNIX_TIME, -120, -30, $answered ? -60 : null, $ended ? -30 : null);
        $agentVoiceCall->direction = AgentVoiceCall::DIRECTION_OUTBOUND;
        $agentVoiceCall->startedByAgentInConversationId = $startedByAgentInConversationId;
        $agentVoiceCall->endReason = $endReason;

        static::assertSame($expectedFollowsUp, $agentVoiceCall->isUnansweredEndTheAgentFollowsUpOn());
    }

    /** @return array<string, array{?int, bool, bool, ?string, bool}> started by the agent in, answered, ended, end reason, follows up */
    public static function provideUnansweredEnds(): array
    {
        return [
            'the agent rang, nobody picked up' => [31, false, true, AgentVoiceCall::END_REASON_EXPIRED, true],
            'the owner declined' => [31, false, true, AgentVoiceCall::END_REASON_DECLINED, false],
            'the medium reported no answer' => [31, false, true, AgentVoiceCall::END_REASON_NOT_ANSWERED, false],
            'the agent withdrew it' => [31, false, true, AgentVoiceCall::END_REASON_ENDED_BY_AGENT, false],
            'withdrawn by its starter' => [31, false, true, AgentVoiceCall::END_REASON_CANCELED, false],
            'an expired ring somebody else started' => [null, false, true, AgentVoiceCall::END_REASON_EXPIRED, false],
            'answered, then expired' => [31, true, true, AgentVoiceCall::END_REASON_EXPIRED, false],
            'still ringing' => [31, false, false, AgentVoiceCall::END_REASON_EXPIRED, false],
            'ended without a reason' => [31, false, true, null, false],
        ];
    }

    public function testTheDefaultTaskOfACallStart(): void
    {
        static::assertSame(
            'The owner asked to be called. Ask in a friendly way what you can help with, and work the request through on the call.',
            AgentVoiceCall::getDefaultOutboundCallTask()
        );
    }

    #[DataProvider('provideFollowUpTopics')]
    public function testTheFollowUpSignalOfAnUnansweredCall(?string $callTask, string $expectedTopicPart): void
    {
        $agentVoiceCall = AgentVoiceCallFixtures::buildCallAt(AgentVoiceCallFixtures::NOW_UNIX_TIME, -120, -30, null, -30);
        $agentVoiceCall->direction = AgentVoiceCall::DIRECTION_OUTBOUND;
        $agentVoiceCall->startedByAgentInConversationId = 31;
        $agentVoiceCall->endReason = AgentVoiceCall::END_REASON_EXPIRED;
        $agentVoiceCall->callTask = $callTask;
        $agentVoiceCallReachableChannels = new RecordingAgentVoiceCallReachableChannels();

        static::assertSame(
            'The in-app call you started to the owner (call #7) was not answered: the app rang for 30 seconds and nobody picked up.'
            . ' Write the owner ONE short message in their language: say that they did not pick up, then offer the fixed thing.'
            . $expectedTopicPart
            . ' Do NOT call again on your own: no startVoiceCall in this turn. Start a call only after the owner says yes in a later message.',
            $agentVoiceCall->buildUnansweredFollowUpSignalText($agentVoiceCallReachableChannels)
        );
        static::assertSame([$agentVoiceCall], $agentVoiceCallReachableChannels->callsAskedFor);
    }

    /** @return array<string, array{?string, string}> the call task, the topic part of the signal */
    public static function provideFollowUpTopics(): array
    {
        return [
            'no task' => [null, ''],
            'an empty task' => ['   ', ''],
            'the default task is no topic' => [AgentVoiceCall::getDefaultOutboundCallTask(), ''],
            'the default task with spaces around it is no topic' => ['  ' . AgentVoiceCall::getDefaultOutboundCallTask() . ' ', ''],
            'a task, trimmed' => ['  Confirm the delivery date.  ', ' The call was meant for: "Confirm the delivery date." — if that can be done here in writing, offer that too.'],
            'a long task is cut at 300 characters' => [str_repeat('a', 299) . 'bc', ' The call was meant for: "' . str_repeat('a', 299) . 'b" — if that can be done here in writing, offer that too.'],
        ];
    }

    #[DataProvider('provideReachableChannels')]
    public function testTheFollowUpOfferOfTheBaseFollowsWhatReachesTheOwner(bool $appIsRingableNow, string $expectedOffer): void
    {
        $agentVoiceCall = AgentVoiceCallFixtures::buildCallAt(AgentVoiceCallFixtures::NOW_UNIX_TIME, -120, -30, null, -30);
        $agentVoiceCallReachableChannels = new AgentVoiceCallReachableChannels();
        $agentVoiceCallReachableChannels->appIsIncluded = true;
        $agentVoiceCallReachableChannels->appIsRingableNow = $appIsRingableNow;

        static::assertStringContainsString(
            'say that they did not pick up, then ' . $expectedOffer . ' Do NOT call again',
            $agentVoiceCall->buildUnansweredFollowUpSignalText($agentVoiceCallReachableChannels)
        );
    }

    /** @return array<string, array{bool, string}> */
    public static function provideReachableChannels(): array
    {
        return [
            'a ring reaches the owner now' => [true, 'ask whether you should try the app again a bit later.'],
            'nothing rings now' => [false, 'say they can call you in the app or write here whenever it suits them — no other call channel reaches them right now.'],
        ];
    }

    public function testTheFollowUpSignalNamesTheRingSecondsOfTheConfig(): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['limits' => ['ringSeconds' => 45]]));
        $agentVoiceCall = AgentVoiceCallFixtures::buildCallAt(AgentVoiceCallFixtures::NOW_UNIX_TIME, -120, -30, null, -30);

        static::assertStringStartsWith(
            'The in-app call you started to the owner (call #7) was not answered: the app rang for 45 seconds and nobody picked up.',
            $agentVoiceCall->buildUnansweredFollowUpSignalText(new RecordingAgentVoiceCallReachableChannels())
        );
    }
}
