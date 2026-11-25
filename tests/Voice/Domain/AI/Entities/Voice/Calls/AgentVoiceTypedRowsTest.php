<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Entities\Voice\Calls;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallerRequestSentence;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceQueuedDelegation;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceRingCallerContext;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceRingPayload;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceSeedInputMessage;
use DDD\Domain\AI\Entities\Voice\Calls\LiveTranscriptBacklogSentence;
use DDD\Domain\AI\Entities\Voice\Calls\LiveTranscriptSentence;
use DDD\Tests\Agents\Voice\Support\AgentVoiceUnitTestCase;
use DDD\Tests\Agents\Voice\Support\AgentVoiceValueObjectAssertionsTrait;

/**
 * The factories of the typed rows of a call: each `create()` puts every value into its field unchanged, a backlog
 * sentence is no progress sentence unless it says so and keeps times it does not know as null, a seed row's three
 * roles are the words the provider's wire carries, and a ring payload keeps a call without a conversation as null.
 */
class AgentVoiceTypedRowsTest extends AgentVoiceUnitTestCase
{
    use AgentVoiceValueObjectAssertionsTrait;

    public function testATranscriptSentenceKeepsItsFourValues(): void
    {
        static::assertSame(
            ['class' => LiveTranscriptSentence::class, 'role' => 'USER', 'text' => 'Hello', 'startMs' => 100, 'endMs' => 900],
            static::describeValueObjects(LiveTranscriptSentence::create('USER', 'Hello', 100, 900))
        );
    }

    public function testABacklogSentenceIsNoProgressSentenceUnlessItSaysSo(): void
    {
        static::assertFalse(LiveTranscriptBacklogSentence::create('USER', 'And on Sunday?', 5000, 5600)->isProgress);
        static::assertTrue(LiveTranscriptBacklogSentence::create('ASSISTANT', 'One moment', 5000, 5600, true)->isProgress);
    }

    public function testABacklogSentenceKeepsTimesItDoesNotKnowAsNull(): void
    {
        static::assertSame(
            ['class' => LiveTranscriptBacklogSentence::class, 'role' => 'ASSISTANT', 'text' => 'Done', 'startMs' => null, 'endMs' => null, 'isProgress' => false],
            static::describeValueObjects(LiveTranscriptBacklogSentence::create('ASSISTANT', 'Done', null, null))
        );
        static::assertSame(0, LiveTranscriptBacklogSentence::create('USER', 'At once', 0, 0)->startMs, 'zero is a time, not an unknown one');
    }

    public function testTheThreeSeedRolesAreTheWordsOfTheWireAndAFreshRowIsADeveloperRow(): void
    {
        static::assertSame(['developer', 'user', 'assistant'], [AgentVoiceSeedInputMessage::ROLE_DEVELOPER, AgentVoiceSeedInputMessage::ROLE_USER, AgentVoiceSeedInputMessage::ROLE_ASSISTANT]);
        static::assertSame(AgentVoiceSeedInputMessage::ROLE_DEVELOPER, (new AgentVoiceSeedInputMessage())->role);
        static::assertSame(
            ['class' => AgentVoiceSeedInputMessage::class, 'role' => 'assistant', 'text' => 'Hi there'],
            static::describeValueObjects(AgentVoiceSeedInputMessage::create(AgentVoiceSeedInputMessage::ROLE_ASSISTANT, 'Hi there'))
        );
    }

    public function testAQueuedDelegationAndACallerRequestSentenceKeepTheirValues(): void
    {
        static::assertSame(
            ['class' => AgentVoiceQueuedDelegation::class, 'delegationId' => 'del_first', 'queuedAtUnixMs' => 1_800_000_000_500],
            static::describeValueObjects(AgentVoiceQueuedDelegation::create('del_first', 1_800_000_000_500))
        );
        static::assertSame(
            ['class' => AgentVoiceCallerRequestSentence::class, 'text' => 'What are my opening hours', 'completedAtUnixMs' => 1_800_000_000_900],
            static::describeValueObjects(AgentVoiceCallerRequestSentence::create('What are my opening hours', 1_800_000_000_900))
        );
    }

    public function testARingPayloadKeepsACallWithoutAConversationAsNull(): void
    {
        $callerContext = AgentVoiceRingCallerContext::newInstance();
        $agentVoiceRingPayload = AgentVoiceRingPayload::create(501, null, 'token', 1_800_000_090, 'label', $callerContext);

        static::assertNull($agentVoiceRingPayload->conversationId);
        static::assertSame($callerContext, $agentVoiceRingPayload->callerContext);
        static::assertSame(
            '{"callId":501,"conversationId":null,"answerToken":"token","ringExpiresAt":"2027-01-15T08:01:30.000Z",'
            . '"ringExpiresAtUnixMs":1800000090000,"callerLabelKey":"label","callerContext":{}}',
            $agentVoiceRingPayload->toPayloadJson()
        );
    }
}
