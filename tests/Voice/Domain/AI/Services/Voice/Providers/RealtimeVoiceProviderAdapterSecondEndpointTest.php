<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Providers;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Services\Voice\Live\OpenAILiveVoiceProviderAdapter;
use DDD\Domain\AI\Services\Voice\Providers\RealtimeVoiceProviderAdapter;
use DDD\Tests\Agents\Voice\Support\AgentVoiceIntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A second endpoint of the same provider as the seam asks for it: a subclass of the shipped adapter registered under a
 * name of its own answers to that name and reads that name's settings, whatever the order of the registrations; the
 * shipped adapter keeps its own name. The subclass is put into the container as an application's service would be.
 */
class RealtimeVoiceProviderAdapterSecondEndpointTest extends AgentVoiceIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->replaceService(SecondEndpointOpenAILiveVoiceProviderAdapter::class, new SecondEndpointOpenAILiveVoiceProviderAdapter());
    }

    /** @return array<string, array{array<string, string>}> */
    public static function provideRegistrationsOfASecondEndpoint(): array
    {
        return [
            'the parent first' => [['liveA' => OpenAILiveVoiceProviderAdapter::class, 'liveB' => SecondEndpointOpenAILiveVoiceProviderAdapter::class]],
            'the subclass first' => [['liveB' => SecondEndpointOpenAILiveVoiceProviderAdapter::class, 'liveA' => OpenAILiveVoiceProviderAdapter::class]],
        ];
    }

    /** @param array<string, string> $adapterClassNames */
    #[DataProvider('provideRegistrationsOfASecondEndpoint')]
    public function testASubclassUnderANameOfItsOwnServesThatNameWithItsSettings(array $adapterClassNames): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith([
            'provider' => [
                'default' => 'liveA',
                'adapters' => $adapterClassNames,
                'liveA' => ['apiHost' => 'a.live-provider.test'],
                'liveB' => ['apiHost' => 'b.live-provider.test', 'model' => 'gpt-live-b'],
            ],
        ]));
        $agentVoiceCall = new AgentVoiceCall();
        $agentVoiceCall->voiceProvider = 'LIVE_B';

        $secondEndpointAdapter = RealtimeVoiceProviderAdapter::forCall($agentVoiceCall);
        $firstEndpointAdapter = RealtimeVoiceProviderAdapter::forCall(new AgentVoiceCall());

        static::assertInstanceOf(SecondEndpointOpenAILiveVoiceProviderAdapter::class, $secondEndpointAdapter);
        static::assertSame('liveB', $secondEndpointAdapter->getProviderName());
        static::assertSame('gpt-live-b', $secondEndpointAdapter->getModelName());
        static::assertSame('wss://b.live-provider.test/v1/live/sessions/live_1/attach', $secondEndpointAdapter->buildSidebandWebSocketUrl('live_1'));
        static::assertNotInstanceOf(SecondEndpointOpenAILiveVoiceProviderAdapter::class, $firstEndpointAdapter);
        static::assertSame('liveA', $firstEndpointAdapter->getProviderName());
        static::assertSame('gpt-live-1.1', $firstEndpointAdapter->getModelName());
        static::assertSame('wss://a.live-provider.test/v1/live/sessions/live_1/attach', $firstEndpointAdapter->buildSidebandWebSocketUrl('live_1'));
    }

    public function testAnOwnNameWrittenWithALeadingBackslashIsStillTheSubclasssName(): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith([
            'provider' => ['adapters' => ['liveA' => OpenAILiveVoiceProviderAdapter::class, 'liveB' => '\\' . SecondEndpointOpenAILiveVoiceProviderAdapter::class]],
        ]));

        static::assertSame('liveB', (new SecondEndpointOpenAILiveVoiceProviderAdapter())->getProviderName());
    }

    /** @return array<string, array{string, string}> */
    public static function provideAdaptersWrittenWithALeadingBackslash(): array
    {
        return [
            'the shipped adapter' => ['liveA', OpenAILiveVoiceProviderAdapter::class],
            'a subclass under a name of its own' => ['liveB', SecondEndpointOpenAILiveVoiceProviderAdapter::class],
        ];
    }

    #[DataProvider('provideAdaptersWrittenWithALeadingBackslash')]
    public function testAnAdapterWrittenWithALeadingBackslashIsServedUnderItsName(string $providerName, string $adapterClassName): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith([
            'provider' => [
                'default' => 'liveA',
                'adapters' => ['liveA' => '\\' . OpenAILiveVoiceProviderAdapter::class, 'liveB' => '\\' . SecondEndpointOpenAILiveVoiceProviderAdapter::class],
            ],
        ]));

        $realtimeVoiceProviderAdapter = RealtimeVoiceProviderAdapter::forProviderName($providerName);

        static::assertSame($adapterClassName, $realtimeVoiceProviderAdapter::class);
        static::assertSame($providerName, $realtimeVoiceProviderAdapter->getProviderName());
    }
}
