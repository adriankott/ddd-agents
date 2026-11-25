<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Entities\Voice\CallLife;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallEnd;
use DDD\Tests\Agents\Voice\Support\AgentVoiceUnitTestCase;

/** The answer of the agent ending a call ({@see AgentVoiceCallEnd}): an end, an end after the goodbye, a refusal. */
class AgentVoiceCallEndTest extends AgentVoiceUnitTestCase
{
    public function testAnEndNowCarriesTheCallAndItsStateBefore(): void
    {
        $agentVoiceCall = AgentVoiceCall::newInstance();

        $agentVoiceCallEnd = AgentVoiceCallEnd::forEnded(AgentVoiceCallEnd::OUTCOME_ENDED_NOW, $agentVoiceCall, AgentVoiceCall::CALL_STATE_RINGING);

        static::assertSame('ENDED_NOW', $agentVoiceCallEnd->outcome);
        static::assertSame($agentVoiceCall, $agentVoiceCallEnd->agentVoiceCall);
        static::assertSame('RINGING', $agentVoiceCallEnd->callStateBeforeTheEnd);
        static::assertNull($agentVoiceCallEnd->refusalReason);
        static::assertFalse($agentVoiceCallEnd->isRefused());
        static::assertFalse($agentVoiceCallEnd->endsAfterGoodbye());
        static::assertSame([], $agentVoiceCallEnd->otherOpenCallIds);
    }

    public function testAnEndAfterTheGoodbyeSaysSo(): void
    {
        $agentVoiceCallEnd = AgentVoiceCallEnd::forEnded(AgentVoiceCallEnd::OUTCOME_ENDS_AFTER_GOODBYE, AgentVoiceCall::newInstance(), AgentVoiceCall::CALL_STATE_IN_PROGRESS);

        static::assertSame('ENDS_AFTER_GOODBYE', $agentVoiceCallEnd->outcome);
        static::assertTrue($agentVoiceCallEnd->endsAfterGoodbye());
        static::assertFalse($agentVoiceCallEnd->isRefused());
    }

    public function testARefusalNamesTheOtherOpenCallsAsNumbers(): void
    {
        $agentVoiceCallEnd = AgentVoiceCallEnd::forRefused(AgentVoiceCallEnd::REFUSED_CALL_NOT_FOUND, null, [4 => '8', 9 => 6]);

        static::assertTrue($agentVoiceCallEnd->isRefused());
        static::assertSame('CALL_NOT_FOUND', $agentVoiceCallEnd->refusalReason);
        static::assertNull($agentVoiceCallEnd->outcome);
        static::assertNull($agentVoiceCallEnd->agentVoiceCall);
        static::assertSame([8, 6], $agentVoiceCallEnd->otherOpenCallIds);
        static::assertFalse($agentVoiceCallEnd->endsAfterGoodbye());
    }

    public function testTheRefusalReasonsAreTheToolsWords(): void
    {
        static::assertSame('NO_CALL_IN_THIS_CONVERSATION', AgentVoiceCallEnd::REFUSED_NO_CALL_IN_THIS_CONVERSATION);
        static::assertSame('CALL_ALREADY_ENDED', AgentVoiceCallEnd::REFUSED_CALL_ALREADY_ENDED);
    }
}
