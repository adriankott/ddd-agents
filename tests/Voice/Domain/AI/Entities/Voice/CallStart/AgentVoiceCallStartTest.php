<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Entities\Voice\CallStart;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallStart;
use DDD\Tests\Agents\Voice\Support\AgentVoiceUnitTestCase;

/** The answer of the agent's call start: a call that rings in the window, or the reason nothing started. */
class AgentVoiceCallStartTest extends AgentVoiceUnitTestCase
{
    public function testARingingStartCarriesTheCallAndItsConversation(): void
    {
        $agentVoiceCall = new AgentVoiceCall();
        $agentVoiceCall->id = 501;
        $agentVoiceCall->aiConversationId = 300;

        $agentVoiceCallStart = AgentVoiceCallStart::forAppRinging($agentVoiceCall);

        static::assertSame('APP', $agentVoiceCallStart->startedChannel);
        static::assertSame($agentVoiceCall, $agentVoiceCallStart->agentVoiceCall);
        static::assertSame(300, $agentVoiceCallStart->callConversationId);
        static::assertNull($agentVoiceCallStart->refusalReason);
        static::assertTrue($agentVoiceCallStart->appIsIncluded);
        static::assertFalse($agentVoiceCallStart->isRefused());
        static::assertSame([], $agentVoiceCallStart->callablePurposeNames);
    }

    public function testARingingStartWithoutAConversationHasNone(): void
    {
        static::assertNull(AgentVoiceCallStart::forAppRinging(new AgentVoiceCall())->callConversationId);
    }

    public function testARefusalCarriesItsReasonAndWhetherTheChannelIsIncluded(): void
    {
        $refusedNotIncluded = AgentVoiceCallStart::forRefused(AgentVoiceCallStart::REFUSED_APP_NOT_INCLUDED);
        $refusedIncluded = AgentVoiceCallStart::forRefused(AgentVoiceCallStart::REFUSED_DAILY_LIMIT_REACHED, true);

        static::assertTrue($refusedNotIncluded->isRefused());
        static::assertSame('APP_NOT_INCLUDED', $refusedNotIncluded->refusalReason);
        static::assertFalse($refusedNotIncluded->appIsIncluded);
        static::assertNull($refusedNotIncluded->agentVoiceCall);
        static::assertNull($refusedNotIncluded->startedChannel);
        static::assertSame('DAILY_LIMIT_REACHED', $refusedIncluded->refusalReason);
        static::assertTrue($refusedIncluded->appIsIncluded);
    }

    public function testTheStoredSpellingsOfTheReasonsAndConversations(): void
    {
        static::assertSame(
            ['SCOPE_NOT_ACTIVE', 'ALREADY_ON_A_CALL', 'APP_NOT_INCLUDED', 'APP_NOT_RINGABLE', 'APP_RING_DELIVERED_NOWHERE', 'NO_AI_BUDGET', 'DAILY_LIMIT_REACHED', 'PURPOSE_NOT_CALLABLE', 'PURPOSE_NEEDS_NEW_CONVERSATION', 'NO_CALL_CONVERSATION'],
            [
                AgentVoiceCallStart::REFUSED_SCOPE_NOT_ACTIVE,
                AgentVoiceCallStart::REFUSED_ALREADY_ON_A_CALL,
                AgentVoiceCallStart::REFUSED_APP_NOT_INCLUDED,
                AgentVoiceCallStart::REFUSED_APP_NOT_RINGABLE,
                AgentVoiceCallStart::REFUSED_APP_RING_DELIVERED_NOWHERE,
                AgentVoiceCallStart::REFUSED_NO_AI_BUDGET,
                AgentVoiceCallStart::REFUSED_DAILY_LIMIT_REACHED,
                AgentVoiceCallStart::REFUSED_PURPOSE_NOT_CALLABLE,
                AgentVoiceCallStart::REFUSED_PURPOSE_NEEDS_NEW_CONVERSATION,
                AgentVoiceCallStart::REFUSED_NO_CALL_CONVERSATION,
            ]
        );
        static::assertSame(['CURRENT', 'NEW'], AgentVoiceCallStart::REQUESTABLE_CONVERSATIONS);
    }
}
