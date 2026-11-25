<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Providers;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Services\Voice\Live\OpenAILiveVoiceProviderAdapter;
use DDD\Domain\AI\Services\Voice\Providers\RealtimeVoiceProviderAdapter;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Tests\Agents\Voice\Support\AgentVoiceUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

/**
 * Which adapter serves a call: the one registered (`AI.agentVoice.provider.adapters`) under the name pinned on the
 * call, else the configured default; a name matches whatever its spelling (`OPENAI_LIVE` finds `openAiLive`); an
 * unknown name and a registered class that is no adapter answer `voice_provider_not_registered`. The adapter's name
 * and its settings follow the registration.
 */
class RealtimeVoiceProviderAdapterRegistryTest extends AgentVoiceUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith([]));
    }

    /** @return array<string, array{?string, ?string}> */
    public static function provideRequestedProviderNames(): array
    {
        return [
            'no name: the configured default' => [null, 'openAiLive'],
            'an empty name: the configured default' => ['  ', 'openAiLive'],
            'the registered name' => ['openAiLive', 'openAiLive'],
            'the registered name with spaces' => [' openAiLive ', 'openAiLive'],
            'the stored spelling of a call' => ['OPENAI_LIVE', 'openAiLive'],
            'another spelling' => ['open-ai.live', 'openAiLive'],
            'an unknown name' => ['otherVoice', null],
            'a name without a letter or digit' => ['__', null],
        ];
    }

    #[DataProvider('provideRequestedProviderNames')]
    public function testTheRegisteredNameOfARequestedProviderName(?string $requestedProviderName, ?string $expectedRegisteredName): void
    {
        static::assertSame($expectedRegisteredName, RealtimeVoiceProviderAdapter::findRegisteredProviderName($requestedProviderName));
    }

    public function testADefaultInAnotherSpellingFindsItsAdapter(): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['provider' => ['default' => 'OPENAI_LIVE']]));

        static::assertSame('openAiLive', RealtimeVoiceProviderAdapter::findRegisteredProviderName(null));
        static::assertInstanceOf(OpenAILiveVoiceProviderAdapter::class, RealtimeVoiceProviderAdapter::forProviderName(null));
    }

    public function testTheAdapterOfACallIsTheOnePinnedOnItElseTheDefault(): void
    {
        $agentVoiceCall = new AgentVoiceCall();
        $defaultAdapter = RealtimeVoiceProviderAdapter::forCall($agentVoiceCall);
        $agentVoiceCall->voiceProvider = 'OPENAI_LIVE';
        $pinnedAdapter = RealtimeVoiceProviderAdapter::forCall($agentVoiceCall);

        static::assertInstanceOf(OpenAILiveVoiceProviderAdapter::class, $defaultAdapter);
        static::assertSame($defaultAdapter, $pinnedAdapter, 'the adapter is the container service, one instance');
        static::assertSame('openAiLive', $pinnedAdapter->getProviderName());
    }

    public function testAnUnknownProviderNameIsRefused(): void
    {
        $agentVoiceCall = new AgentVoiceCall();
        $agentVoiceCall->voiceProvider = 'otherVoice';

        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage('voice_provider_not_registered');
        RealtimeVoiceProviderAdapter::forCall($agentVoiceCall);
    }

    public function testADefaultThatNoAdapterAnswersToIsRefused(): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['provider' => ['default' => 'missingVoice']]));

        static::assertNull(RealtimeVoiceProviderAdapter::findRegisteredProviderName(null));
        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage('voice_provider_not_registered');
        RealtimeVoiceProviderAdapter::forProviderName(null);
    }

    public function testARegisteredClassThatIsNoAdapterIsRefused(): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['provider' => ['adapters' => ['openAiLive' => stdClass::class]]]));

        static::assertSame('openAiLive', RealtimeVoiceProviderAdapter::findRegisteredProviderName('openAiLive'));
        try {
            RealtimeVoiceProviderAdapter::forProviderName('openAiLive');
            static::fail('a class that is no adapter must be refused');
        } catch (InternalErrorException $internalErrorException) {
            static::assertSame(InternalErrorException::class, $internalErrorException::class);
            static::assertSame('voice_provider_not_registered', $internalErrorException->getMessage());
        }
    }

    public function testTheAdapterAnswersToTheNameItIsRegisteredUnderAndReadsThatNamesSettings(): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith([
            'provider' => [
                'default' => 'liveEu',
                'adapters' => ['liveEu' => OpenAILiveVoiceProviderAdapter::class],
                'liveEu' => ['apiHost' => 'eu.live-provider.test', 'model' => 'gpt-live-eu'],
            ],
        ]));

        $openAILiveVoiceProviderAdapter = RealtimeVoiceProviderAdapter::forProviderName(null);

        static::assertSame('liveEu', $openAILiveVoiceProviderAdapter->getProviderName());
        static::assertSame('gpt-live-eu', $openAILiveVoiceProviderAdapter->getModelName());
        static::assertSame('wss://eu.live-provider.test/v1/live/sessions/live_1/attach', $openAILiveVoiceProviderAdapter->buildSidebandWebSocketUrl('live_1'));
        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage('voice_provider_not_registered');
        RealtimeVoiceProviderAdapter::forProviderName('openAiLive');
    }

    public function testAClassRegisteredUnderASecondNameIsRefusedForThatName(): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith([
            'provider' => [
                'default' => 'liveA',
                'adapters' => ['liveA' => OpenAILiveVoiceProviderAdapter::class, 'liveB' => OpenAILiveVoiceProviderAdapter::class],
                'liveA' => ['apiHost' => 'a.live-provider.test'],
                'liveB' => ['apiHost' => 'b.live-provider.test'],
            ],
        ]));

        $firstNameAdapter = RealtimeVoiceProviderAdapter::forProviderName('liveA');
        static::assertSame('liveA', $firstNameAdapter->getProviderName());
        static::assertSame('wss://a.live-provider.test/v1/live/sessions/live_1/attach', $firstNameAdapter->buildSidebandWebSocketUrl('live_1'));
        static::assertSame('liveB', RealtimeVoiceProviderAdapter::findRegisteredProviderName('LIVE_B'));
        try {
            RealtimeVoiceProviderAdapter::forProviderName('liveB');
            static::fail('the second name of one class must be refused');
        } catch (InternalErrorException $internalErrorException) {
            static::assertSame(InternalErrorException::class, $internalErrorException::class);
            static::assertSame('voice_provider_adapter_registered_twice', $internalErrorException->getMessage());
            static::assertSame(RealtimeVoiceProviderAdapter::ERROR_VOICE_PROVIDER_ADAPTER_REGISTERED_TWICE, $internalErrorException->getMessage());
        }
    }

    public function testACallPinnedToTheSecondNameOfOneClassIsRefused(): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith([
            'provider' => ['adapters' => ['liveA' => OpenAILiveVoiceProviderAdapter::class, 'liveB' => OpenAILiveVoiceProviderAdapter::class], 'default' => 'liveA'],
        ]));
        $agentVoiceCall = new AgentVoiceCall();
        $agentVoiceCall->voiceProvider = 'LIVE_B';

        static::assertSame('liveA', RealtimeVoiceProviderAdapter::forCall(new AgentVoiceCall())->getProviderName());
        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage('voice_provider_adapter_registered_twice');
        RealtimeVoiceProviderAdapter::forCall($agentVoiceCall);
    }

    public function testASubclassWithoutANameOfItsOwnAnswersToTheAdapterItStandsInFor(): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith([
            'provider' => ['adapters' => ['liveA' => OpenAILiveVoiceProviderAdapter::class], 'liveA' => ['apiHost' => 'a.live-provider.test']],
        ]));
        $standInAdapter = new SecondEndpointOpenAILiveVoiceProviderAdapter();

        static::assertSame('liveA', $standInAdapter->getProviderName());
        static::assertSame('wss://a.live-provider.test/v1/live/sessions/live_1/attach', $standInAdapter->buildSidebandWebSocketUrl('live_1'));
    }

    public function testASubclassOfARegisteredAdapterAnswersToItsName(): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith([
            'provider' => ['adapters' => ['houseVoice' => FixedAppendCapRealtimeVoiceProviderAdapter::class], 'houseVoice' => ['region' => 'north']],
        ]));
        $fixedAppendCapAdapter = new FixedAppendCapRealtimeVoiceProviderAdapter();

        static::assertSame('houseVoice', $fixedAppendCapAdapter->getProviderName());
        static::assertSame('north', $fixedAppendCapAdapter->findProviderSettingForTest('region'));
        static::assertNull($fixedAppendCapAdapter->findProviderSettingForTest('apiHost'));
    }

    public function testAnAdapterThatIsNotRegisteredAnswersItsDefaultNameAndHasNoSettings(): void
    {
        $fixedAppendCapAdapter = new FixedAppendCapRealtimeVoiceProviderAdapter();

        static::assertSame('fixedCapTest', $fixedAppendCapAdapter->getProviderName());
        static::assertNull($fixedAppendCapAdapter->findProviderSettingForTest('apiHost'));
    }
}
