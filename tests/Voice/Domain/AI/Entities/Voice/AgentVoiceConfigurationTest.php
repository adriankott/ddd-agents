<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Entities\Voice;

use DDD\Domain\AI\Entities\Voice\AgentVoiceConfiguration;
use DDD\Infrastructure\Libs\Config;
use DDD\Tests\Agents\Voice\Support\AgentVoiceUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The configuration of the voice channel as one typed value: the defaults, every entry kind of `AI.agentVoice`,
 * malformed entries (each keeps its default), the provider registry and settings, the agent name of `AI.agentPersona`,
 * and {@see AgentVoiceConfiguration::current()} reading both through Config.
 */
class AgentVoiceConfigurationTest extends AgentVoiceUnitTestCase
{
    /** @var string The adapter class the shipped config file registers */
    public const string SHIPPED_ADAPTER_CLASS_NAME = 'DDD\\Domain\\AI\\Services\\Voice\\Live\\OpenAILiveVoiceProviderAdapter';

    public function testWithoutEntriesEveryValueIsItsDefault(): void
    {
        $agentVoiceConfiguration = AgentVoiceConfiguration::fromConfigEntries(null, null);

        static::assertSame(static::buildDefaultValues(), static::readValues($agentVoiceConfiguration));
    }

    public function testTheShippedConfigFileHoldsTheDefaultsWithTheOneProviderOfThePackage(): void
    {
        $shippedConfigEntries = require dirname(__DIR__, 6) . '/config/app/AI/agentVoice.php';

        $agentVoiceConfiguration = AgentVoiceConfiguration::fromConfigEntries($shippedConfigEntries, ['agentName' => 'Ada']);

        $expectedValues = static::buildDefaultValues();
        $expectedValues['defaultProviderName'] = 'openAiLive';
        $expectedValues['providerAdapterClassNames'] = ['openAiLive' => static::SHIPPED_ADAPTER_CLASS_NAME];
        $expectedValues['providerSettings'] = ['openAiLive' => [
            'apiHost' => 'api.openai.com',
            'apiKeyEnvironmentVariable' => 'API_OPENAI_REALTIME_API_KEY',
            'model' => 'gpt-live-1',
            'defaultVoice' => 'marin',
            'storeSessionAtProvider' => false,
        ]];
        $expectedValues['agentName'] = 'Ada';
        static::assertSame($expectedValues, static::readValues($agentVoiceConfiguration));
    }

    /**
     * @param array<string, mixed> $voiceConfigEntries
     * @param array<string, mixed> $changedValues the values that differ from the defaults
     */
    #[DataProvider('provideEntryKinds')]
    public function testEachEntryKindIsRead(array $voiceConfigEntries, array $changedValues): void
    {
        $agentVoiceConfiguration = AgentVoiceConfiguration::fromConfigEntries($voiceConfigEntries, null);

        static::assertSame(array_replace(static::buildDefaultValues(), $changedValues), static::readValues($agentVoiceConfiguration));
    }

    /** @return array<string, array{array<string, mixed>, array<string, mixed>}> */
    public static function provideEntryKinds(): array
    {
        return [
            'enabled' => [['enabled' => true], ['enabled' => true]],
            'disabled' => [['enabled' => false], []],
            'call store entity' => [['callStore' => 'entity'], ['callStore' => 'entity']],
            'a call store name no store answers to is kept as given' => [['callStore' => 'conversation'], ['callStore' => 'conversation']],
            'a call store name of the application' => [['callStore' => 'applicationStore'], ['callStore' => 'applicationStore']],
            'a call store name, trimmed' => [['callStore' => "  table\t"], ['callStore' => 'table']],
            'a call store in other case is kept as given' => [['callStore' => 'Entity'], ['callStore' => 'Entity']],
            'the entity call store, trimmed' => [['callStore' => ' entity '], []],
            'a SIGTERM lets the call run to its end' => [['endCallOnTerminationSignal' => false], ['endCallOnTerminationSignal' => false]],
            'a SIGTERM ends the call' => [['endCallOnTerminationSignal' => true], []],
            'language, trimmed' => [['language' => ' de '], ['language' => 'de']],
            'language null' => [['language' => null], []],
            'maximum call seconds' => [['limits' => ['maximumCallSeconds' => 600]], ['maximumCallSeconds' => 600]],
            'ring seconds' => [['limits' => ['ringSeconds' => 20]], ['ringSeconds' => 20]],
            'speech silent nudge seconds' => [['limits' => ['speechSilentNudgeSeconds' => 11]], ['speechSilentNudgeSeconds' => 11]],
            'speech silent hang-up seconds' => [['limits' => ['speechSilentHangUpSeconds' => 12]], ['speechSilentHangUpSeconds' => 12]],
            'session silent seconds' => [['limits' => ['sessionSilentSeconds' => 13]], ['sessionSilentSeconds' => 13]],
            'admission recheck seconds' => [['limits' => ['admissionRecheckSeconds' => 14]], ['admissionRecheckSeconds' => 14]],
            'usage booking minimum seconds' => [['limits' => ['usageBookingMinimumSeconds' => 15]], ['usageBookingMinimumSeconds' => 15]],
            'daily seconds per account' => [['limits' => ['dailySecondsPerAccount' => 1800]], ['dailySecondsPerAccount' => 1800]],
            'daily seconds per account null' => [['limits' => ['dailySecondsPerAccount' => null]], []],
            'the smallest positive limit' => [['limits' => ['ringSeconds' => 1]], ['ringSeconds' => 1]],
            'default provider' => [['provider' => ['default' => 'otherVoice']], ['defaultProviderName' => 'otherVoice']],
            'adapters with settings' => [
                ['provider' => [
                    'default' => 'firstVoice',
                    'adapters' => ['firstVoice' => 'Vendor\\FirstVoiceAdapter', 'secondVoice' => 'Vendor\\SecondVoiceAdapter'],
                    'firstVoice' => ['model' => 'first-model', 'defaultVoice' => 'calm'],
                    'secondVoice' => ['model' => 'second-model'],
                ]],
                [
                    'defaultProviderName' => 'firstVoice',
                    'providerAdapterClassNames' => ['firstVoice' => 'Vendor\\FirstVoiceAdapter', 'secondVoice' => 'Vendor\\SecondVoiceAdapter'],
                    'providerSettings' => [
                        'firstVoice' => ['model' => 'first-model', 'defaultVoice' => 'calm'],
                        'secondVoice' => ['model' => 'second-model'],
                    ],
                ],
            ],
            'an adapter without settings gets empty settings' => [
                ['provider' => ['adapters' => ['firstVoice' => 'Vendor\\FirstVoiceAdapter']]],
                ['providerAdapterClassNames' => ['firstVoice' => 'Vendor\\FirstVoiceAdapter'], 'providerSettings' => ['firstVoice' => []]],
            ],
            'settings of a name that is no adapter are not read' => [
                ['provider' => ['adapters' => [], 'otherVoice' => ['model' => 'other-model']]],
                [],
            ],
        ];
    }

    /**
     * @param mixed $voiceConfigEntries
     * @param array<string, mixed> $changedValues the values that differ from the defaults
     */
    #[DataProvider('provideMalformedEntries')]
    public function testAMalformedEntryKeepsItsDefault(mixed $voiceConfigEntries, array $changedValues): void
    {
        $agentVoiceConfiguration = AgentVoiceConfiguration::fromConfigEntries($voiceConfigEntries, null);

        static::assertSame(array_replace(static::buildDefaultValues(), $changedValues), static::readValues($agentVoiceConfiguration));
    }

    /** @return array<string, array{mixed, array<string, mixed>}> */
    public static function provideMalformedEntries(): array
    {
        return [
            'the entry is a string' => ['enabled', []],
            'the entry is a number' => [1, []],
            'enabled as the number 1' => [['enabled' => 1], []],
            'enabled as a string' => [['enabled' => 'true'], []],
            'end on SIGTERM as a string' => [['endCallOnTerminationSignal' => 'false'], []],
            'a call store that is an array' => [['callStore' => ['table']], []],
            'a call store that is a number' => [['callStore' => 7], []],
            'a call store that is a boolean' => [['callStore' => true], []],
            'a call store that is null' => [['callStore' => null], []],
            'an empty call store' => [['callStore' => ''], []],
            'a call store of only whitespace' => [['callStore' => " \t\n "], []],
            'an empty language' => [['language' => '   '], []],
            'a language that is no string' => [['language' => 7], []],
            'limits that are no array' => [['limits' => 600], []],
            'a limit of zero' => [['limits' => ['maximumCallSeconds' => 0]], []],
            'a negative limit' => [['limits' => ['ringSeconds' => -5]], []],
            'a limit as a string' => [['limits' => ['ringSeconds' => '30']], []],
            'a limit as a float' => [['limits' => ['sessionSilentSeconds' => 30.0]], []],
            'a daily cap of zero' => [['limits' => ['dailySecondsPerAccount' => 0]], []],
            'a daily cap as a string' => [['limits' => ['dailySecondsPerAccount' => '1800']], []],
            'a provider that is no array' => [['provider' => 'openAiLive'], []],
            'a default provider that is no string' => [['provider' => ['default' => 3]], []],
            'adapters that are no array' => [['provider' => ['default' => 'firstVoice', 'adapters' => 'Vendor\\FirstVoiceAdapter']], ['defaultProviderName' => 'firstVoice']],
            'an adapter under a numeric key' => [['provider' => ['adapters' => ['Vendor\\FirstVoiceAdapter']]], []],
            'an adapter class that is no string' => [['provider' => ['adapters' => ['firstVoice' => ['Vendor\\FirstVoiceAdapter']]]], []],
            'settings that are no array' => [
                ['provider' => ['adapters' => ['firstVoice' => 'Vendor\\FirstVoiceAdapter'], 'firstVoice' => 'first-model']],
                ['providerAdapterClassNames' => ['firstVoice' => 'Vendor\\FirstVoiceAdapter'], 'providerSettings' => ['firstVoice' => []]],
            ],
        ];
    }

    /** @param mixed $agentPersonaConfigEntries */
    #[DataProvider('provideAgentPersonaEntries')]
    public function testTheAgentNameIsReadFromThePersona(mixed $agentPersonaConfigEntries, string $expectedAgentName): void
    {
        $agentVoiceConfiguration = AgentVoiceConfiguration::fromConfigEntries(null, $agentPersonaConfigEntries);

        static::assertSame($expectedAgentName, $agentVoiceConfiguration->agentName);
    }

    /** @return array<string, array{mixed, string}> */
    public static function provideAgentPersonaEntries(): array
    {
        return [
            'a name' => [['agentName' => 'Ada', 'productName' => 'the product'], 'Ada'],
            'a name, trimmed' => [['agentName' => '  Ada  '], 'Ada'],
            'an empty name' => [['agentName' => ''], ''],
            'no name' => [['productName' => 'the product'], ''],
            'a name that is no string' => [['agentName' => 5], ''],
            'a persona that is no array' => ['Ada', ''],
            'no persona' => [null, ''],
        ];
    }

    public function testTheAgentNameIsReadAlsoWhenTheVoiceEntriesAreMalformed(): void
    {
        $agentVoiceConfiguration = AgentVoiceConfiguration::fromConfigEntries('enabled', ['agentName' => 'Ada']);

        static::assertSame('Ada', $agentVoiceConfiguration->agentName);
        static::assertFalse($agentVoiceConfiguration->enabled);
    }

    #[DataProvider('provideCallStores')]
    public function testOnlyTheEntityStoreKeepsCallsInTheCallTable(mixed $callStoreEntry, bool $expectedKeepsCallsInTheCallTable): void
    {
        $agentVoiceConfiguration = AgentVoiceConfiguration::fromConfigEntries(['callStore' => $callStoreEntry], null);

        static::assertSame($expectedKeepsCallsInTheCallTable, $agentVoiceConfiguration->keepsCallsInTheCallTable());
    }

    /** @return array<string, array{mixed, bool}> */
    public static function provideCallStores(): array
    {
        return [
            'entity' => ['entity', true],
            'entity, trimmed' => [' entity ', true],
            'entity in other case' => ['Entity', false],
            'conversation' => ['conversation', false],
            'an unknown store' => ['table', false],
            'no store keeps the default entity' => [null, true],
            'an empty store keeps the default entity' => ['', true],
        ];
    }

    public function testTheDefaultCallStoreIsTheEntityStore(): void
    {
        static::assertSame('entity', AgentVoiceConfiguration::CALL_STORE_ENTITY);
        static::assertSame(AgentVoiceConfiguration::CALL_STORE_ENTITY, (new AgentVoiceConfiguration())->callStore);
        static::assertTrue((new AgentVoiceConfiguration())->keepsCallsInTheCallTable());
    }

    public function testTheProviderRegistryAnswersAdaptersAndSettingsByName(): void
    {
        $agentVoiceConfiguration = AgentVoiceConfiguration::fromConfigEntries(['provider' => [
            'default' => 'firstVoice',
            'adapters' => ['firstVoice' => 'Vendor\\FirstVoiceAdapter'],
            'firstVoice' => ['model' => 'first-model', 'storeSessionAtProvider' => false],
        ]], null);

        static::assertSame('Vendor\\FirstVoiceAdapter', $agentVoiceConfiguration->findProviderAdapterClassName('firstVoice'));
        static::assertNull($agentVoiceConfiguration->findProviderAdapterClassName('otherVoice'));
        static::assertSame('first-model', $agentVoiceConfiguration->findProviderSetting('firstVoice', 'model'));
        static::assertFalse($agentVoiceConfiguration->findProviderSetting('firstVoice', 'storeSessionAtProvider'));
        static::assertNull($agentVoiceConfiguration->findProviderSetting('firstVoice', 'apiHost'));
        static::assertNull($agentVoiceConfiguration->findProviderSetting('otherVoice', 'model'));
    }

    public function testCurrentReadsBothEntriesThroughConfig(): void
    {
        $this->declareAgentVoiceConfiguration(['enabled' => true, 'callStore' => 'entity', 'limits' => ['ringSeconds' => 25]]);
        $this->declareAgentPersonaConfiguration(['agentName' => 'Ada']);

        $agentVoiceConfiguration = AgentVoiceConfiguration::current();

        static::assertTrue($agentVoiceConfiguration->enabled);
        static::assertSame('entity', $agentVoiceConfiguration->callStore);
        static::assertSame(25, $agentVoiceConfiguration->ringSeconds);
        static::assertSame('Ada', $agentVoiceConfiguration->agentName);
    }

    public function testCurrentIsBuiltOnEachCallAndFollowsAChangedConfig(): void
    {
        $this->declareAgentVoiceConfiguration(['limits' => ['ringSeconds' => 25]]);
        $agentVoiceConfigurationBefore = AgentVoiceConfiguration::current();
        $this->declareAgentVoiceConfiguration(['limits' => ['ringSeconds' => 40]]);

        static::assertSame(25, $agentVoiceConfigurationBefore->ringSeconds);
        static::assertSame(40, AgentVoiceConfiguration::current()->ringSeconds);
    }

    public function testWithoutADeclarationCurrentReadsTheModuleConfigFileAndThePersonaOfTheAgentPackage(): void
    {
        $agentVoiceConfiguration = AgentVoiceConfiguration::current();

        $shippedConfigEntries = require dirname(__DIR__, 6) . '/config/app/AI/agentVoice.php';
        static::assertSame($shippedConfigEntries, Config::get('AI.agentVoice'));
        static::assertSame(
            static::readValues(AgentVoiceConfiguration::fromConfigEntries($shippedConfigEntries, Config::get('AI.agentPersona'))),
            static::readValues($agentVoiceConfiguration)
        );
        static::assertSame(trim((string)Config::get('AI.agentPersona.agentName')), $agentVoiceConfiguration->agentName);
        static::assertNotSame('', $agentVoiceConfiguration->agentName);
    }

    /** @return array<string, mixed> Every public value of the configuration, in declaration order */
    protected static function readValues(AgentVoiceConfiguration $agentVoiceConfiguration): array
    {
        return [
            'enabled' => $agentVoiceConfiguration->enabled,
            'callStore' => $agentVoiceConfiguration->callStore,
            'defaultProviderName' => $agentVoiceConfiguration->defaultProviderName,
            'providerAdapterClassNames' => $agentVoiceConfiguration->providerAdapterClassNames,
            'providerSettings' => $agentVoiceConfiguration->providerSettings,
            'endCallOnTerminationSignal' => $agentVoiceConfiguration->endCallOnTerminationSignal,
            'language' => $agentVoiceConfiguration->language,
            'maximumCallSeconds' => $agentVoiceConfiguration->maximumCallSeconds,
            'dailySecondsPerAccount' => $agentVoiceConfiguration->dailySecondsPerAccount,
            'ringSeconds' => $agentVoiceConfiguration->ringSeconds,
            'speechSilentNudgeSeconds' => $agentVoiceConfiguration->speechSilentNudgeSeconds,
            'speechSilentHangUpSeconds' => $agentVoiceConfiguration->speechSilentHangUpSeconds,
            'sessionSilentSeconds' => $agentVoiceConfiguration->sessionSilentSeconds,
            'admissionRecheckSeconds' => $agentVoiceConfiguration->admissionRecheckSeconds,
            'usageBookingMinimumSeconds' => $agentVoiceConfiguration->usageBookingMinimumSeconds,
            'agentName' => $agentVoiceConfiguration->agentName,
        ];
    }

    /** @return array<string, mixed> The defaults of every value (contract section 2) */
    protected static function buildDefaultValues(): array
    {
        return [
            'enabled' => false,
            'callStore' => 'entity',
            'defaultProviderName' => '',
            'providerAdapterClassNames' => [],
            'providerSettings' => [],
            'endCallOnTerminationSignal' => true,
            'language' => null,
            'maximumCallSeconds' => 3600,
            'dailySecondsPerAccount' => null,
            'ringSeconds' => 90,
            'speechSilentNudgeSeconds' => 45,
            'speechSilentHangUpSeconds' => 90,
            'sessionSilentSeconds' => 300,
            'admissionRecheckSeconds' => 30,
            'usageBookingMinimumSeconds' => 30,
            'agentName' => '',
        ];
    }
}
