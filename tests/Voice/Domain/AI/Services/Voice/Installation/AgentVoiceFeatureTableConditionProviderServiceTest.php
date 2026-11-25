<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Installation;

use DDD\Domain\AI\Entities\Installation\AgentFeatureTableCondition;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Services\Installation\AgentFeatureTableConditionProvider;
use DDD\Domain\AI\Services\Installation\AgentInstallationCheckService;
use DDD\Domain\AI\Services\Voice\Installation\AgentVoiceFeatureTableConditionProviderService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Voice\Support\AgentVoiceIntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

/**
 * When `app:agent:check-installation` requires the call table: only while the voice channel is enabled AND calls are
 * kept in the table. The provider is collected by the installation check through the package's tag.
 */
class AgentVoiceFeatureTableConditionProviderServiceTest extends AgentVoiceIntegrationTestCase
{
    #[DataProvider('provideConfigurations')]
    public function testTheCallTableIsRequiredOnlyWhileEnabledWithTheEntityStore(bool $enabled, string $callStore, bool $expectedTableRequired): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['enabled' => $enabled, 'callStore' => $callStore]));

        $agentFeatureTableConditions = $this->getConditionProvider()->getFeatureTableConditions();

        static::assertSame(1, $agentFeatureTableConditions->count());
        /** @var AgentFeatureTableCondition $callTableCondition */
        $callTableCondition = $agentFeatureTableConditions->first();
        static::assertSame(AgentVoiceCall::class, $callTableCondition->entityClassName);
        static::assertSame($expectedTableRequired, $callTableCondition->isFeatureSwitchedOn);
        static::assertNull($callTableCondition->requiredWhileSkillBound);
        static::assertSame(
            'voice calls kept in the call table (AI.agentVoice.enabled with AI.agentVoice.callStore "entity")',
            $callTableCondition->featureDescription
        );
        static::assertSame(
            $expectedTableRequired ? null : 'voice calls kept in the call table (AI.agentVoice.enabled with AI.agentVoice.callStore "entity") is switched off',
            $callTableCondition->findReasonTableIsNotRequired([])
        );
    }

    /** @return array<string, array{bool, string, bool}> enabled, call store, table required */
    public static function provideConfigurations(): array
    {
        return [
            'enabled, entity' => [true, 'entity', true],
            'enabled, conversation' => [true, 'conversation', false],
            'disabled, entity' => [false, 'entity', false],
            'disabled, conversation' => [false, 'conversation', false],
        ];
    }

    public function testTheShippedConfigRequiresNoTable(): void
    {
        static::assertFalse($this->getConditionProvider()->getFeatureTableConditions()->first()->isFeatureSwitchedOn);
    }

    public function testTheConditionIsAboutTheCallEntityAndItsSubclasses(): void
    {
        $callTableCondition = $this->getConditionProvider()->getFeatureTableConditions()->first();

        static::assertTrue($callTableCondition->isAboutEntityClass(AgentVoiceCall::class));
        static::assertFalse($callTableCondition->isAboutEntityClass(AgentFeatureTableCondition::class));
    }

    public function testTheInstallationCheckCollectsTheProvider(): void
    {
        $agentInstallationCheckService = DDDService::instance()->getService(AgentInstallationCheckService::class);
        $collectedProviders = (new ReflectionProperty(AgentInstallationCheckService::class, 'agentFeatureTableConditionProviders'))->getValue($agentInstallationCheckService);

        $collectedProviderClassNames = [];
        foreach ($collectedProviders as $collectedProvider) {
            $collectedProviderClassNames[] = $collectedProvider::class;
        }

        static::assertContains(AgentVoiceFeatureTableConditionProviderService::class, $collectedProviderClassNames);
        static::assertInstanceOf(AgentFeatureTableConditionProvider::class, $this->getConditionProvider());
    }

    protected function getConditionProvider(): AgentVoiceFeatureTableConditionProviderService
    {
        /** @var AgentVoiceFeatureTableConditionProviderService $agentVoiceFeatureTableConditionProviderService */
        $agentVoiceFeatureTableConditionProviderService = DDDService::instance()->getService(AgentVoiceFeatureTableConditionProviderService::class);
        return $agentVoiceFeatureTableConditionProviderService;
    }
}
