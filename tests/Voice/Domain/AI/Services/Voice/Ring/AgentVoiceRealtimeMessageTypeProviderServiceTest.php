<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Ring;

use DDD\Domain\AI\Entities\Conversations\Realtime\AgentRealtimeMessageType;
use DDD\Domain\AI\Services\Conversations\Realtime\AgentRealtimeChannelService;
use DDD\Domain\AI\Services\Voice\AgentVoiceRealtimeMessageTypeProviderService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Voice\Support\AgentVoiceIntegrationTestCase;

/**
 * The one realtime message type of the voice channel, `agent.voice.ring` (contract section 6): a valid type name of
 * the package's port, registered by `agent.voice`, and found by the port of the kernel through its tag.
 */
class AgentVoiceRealtimeMessageTypeProviderServiceTest extends AgentVoiceIntegrationTestCase
{
    public function testTheProviderRegistersExactlyTheRingType(): void
    {
        $agentRealtimeMessageTypes = (new AgentVoiceRealtimeMessageTypeProviderService())->getRealtimeMessageTypes();

        static::assertCount(1, $agentRealtimeMessageTypes->getElements());
        $ringMessageType = $agentRealtimeMessageTypes->getElements()[0];
        static::assertSame('agent.voice.ring', $ringMessageType->name);
        static::assertSame('agent.voice', $ringMessageType->registeredBy);
        static::assertTrue(AgentRealtimeMessageType::isValidName($ringMessageType->name));
        static::assertSame(4096, $ringMessageType->maxPayloadJsonChars);
        static::assertSame(
            'The agent rings the owner: show the incoming call until ringExpiresAt, answer or decline it with the answerToken, and stop when the message is cleared.',
            $ringMessageType->description
        );
    }

    public function testThePortOfTheKernelKnowsTheRingType(): void
    {
        /** @var AgentRealtimeChannelService $agentRealtimeChannelService */
        $agentRealtimeChannelService = DDDService::instance()->getService(AgentRealtimeChannelService::class);

        $ringMessageType = $agentRealtimeChannelService->getRegisteredMessageTypes()->findMessageType(AgentVoiceRealtimeMessageTypeProviderService::MESSAGE_TYPE_RING);

        static::assertNotNull($ringMessageType);
        static::assertSame(AgentVoiceRealtimeMessageTypeProviderService::REGISTERED_BY, $ringMessageType->registeredBy);
    }
}
