<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Support;

/**
 * Declares voice configuration for ONE test through {@see AgentVoiceTestConfiguration}: it keeps the values Config
 * held before the first declaration of the test and puts them back with {@see restoreConfigurationOfTheTest()}, which
 * the base classes call in their tear-down.
 */
trait AgentVoiceTestConfigurationTrait
{
    /** @var array<string, mixed>|null The config values before the first declaration of this test; null = none declared */
    protected ?array $configValuesBeforeTheTest = null;

    /** @param array<string, mixed> $agentVoiceConfigEntries The whole `AI.agentVoice` array */
    protected function declareAgentVoiceConfiguration(array $agentVoiceConfigEntries): void
    {
        $this->rememberConfigValuesBeforeTheTest(AgentVoiceTestConfiguration::declareAgentVoiceConfiguration($agentVoiceConfigEntries));
    }

    /** @param array<string, mixed> $agentPersonaConfigEntries The whole `AI.agentPersona` array */
    protected function declareAgentPersonaConfiguration(array $agentPersonaConfigEntries): void
    {
        $this->rememberConfigValuesBeforeTheTest(AgentVoiceTestConfiguration::declareAgentPersonaConfiguration($agentPersonaConfigEntries));
    }

    /** @param array<string, mixed> $configEntries */
    protected function declareConfigEntries(string $configPath, array $configEntries): void
    {
        $this->rememberConfigValuesBeforeTheTest(AgentVoiceTestConfiguration::declareConfigEntries($configPath, $configEntries));
    }

    /**
     * {@see AgentVoiceTestConfiguration::buildShippedAgentVoiceConfigurationWith()}
     *
     * @param array<string, mixed> $replacedEntries
     * @return array<string, mixed>
     */
    protected static function buildShippedAgentVoiceConfigurationWith(array $replacedEntries): array
    {
        return AgentVoiceTestConfiguration::buildShippedAgentVoiceConfigurationWith($replacedEntries);
    }

    /** Puts back the config values held before the first declaration of this test. */
    protected function restoreConfigurationOfTheTest(): void
    {
        if ($this->configValuesBeforeTheTest === null) {
            return;
        }
        AgentVoiceTestConfiguration::restoreConfigValues($this->configValuesBeforeTheTest);
        $this->configValuesBeforeTheTest = null;
    }

    /** @param array<string, mixed> $configValuesBefore what a declaration answered: kept only for the first one */
    protected function rememberConfigValuesBeforeTheTest(array $configValuesBefore): void
    {
        $this->configValuesBeforeTheTest ??= $configValuesBefore;
    }
}
