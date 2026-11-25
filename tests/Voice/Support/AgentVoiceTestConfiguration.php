<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Support;

use DDD\Domain\AI\Entities\Voice\AgentVoiceConfiguration;
use DDD\Infrastructure\Libs\Config;
use LogicException;
use ReflectionProperty;

/**
 * The one place a test declares voice configuration: an entry goes where {@see Config::get()} reads first (the values
 * it has already read), and every declaration answers the values Config held before, which the test puts back with
 * {@see restoreConfigValues()} in its tear-down. A declaration replaces the whole entry, as an application's config
 * file replaces the package's. An empty array cannot be declared: Config takes an empty node for one it has not read
 * and reads the file.
 *
 * A test of the voice folder uses it through {@see AgentVoiceTestConfigurationTrait} (which keeps the values before
 * the first declaration of the test and puts them back in the base classes' tear-down); a test elsewhere, or a
 * script, calls it directly.
 */
class AgentVoiceTestConfiguration
{
    /**
     * @param array<string, mixed> $agentVoiceConfiguration the whole `AI.agentVoice` array
     * @return array<string, mixed> the values Config held before
     */
    public static function declareAgentVoiceConfiguration(array $agentVoiceConfiguration): array
    {
        return static::declareConfigEntries(AgentVoiceConfiguration::CONFIG_KEY, $agentVoiceConfiguration);
    }

    /**
     * @param array<string, mixed> $agentPersonaConfiguration the whole `AI.agentPersona` array
     * @return array<string, mixed> the values Config held before
     */
    public static function declareAgentPersonaConfiguration(array $agentPersonaConfiguration): array
    {
        return static::declareConfigEntries(AgentVoiceConfiguration::CONFIG_KEY_AGENT_PERSONA, $agentPersonaConfiguration);
    }

    /**
     * @param string $configPath the dotted path of the entry, e.g. `AI.accountRights`
     * @param array<string, mixed> $configEntries the whole entry
     * @return array<string, mixed> the values Config held before
     * @throws LogicException for an empty array
     */
    public static function declareConfigEntries(string $configPath, array $configEntries): array
    {
        if ($configEntries === []) {
            throw new LogicException('An empty array cannot be declared for ' . $configPath . ': Config would read the file instead.');
        }
        $configTree = new ReflectionProperty(Config::class, 'configTree');
        $configValuesBefore = $configTree->isInitialized() ? (array)$configTree->getValue() : [];
        $configValues = $configValuesBefore;
        [$areaKey, $entryKey] = Config::explodeSearchString($configPath);
        $configValues[$areaKey][$entryKey] = $configEntries;
        $configTree->setValue(null, $configValues);
        return $configValuesBefore;
    }

    /** @param array<string, mixed> $configValuesBefore what a declaration answered */
    public static function restoreConfigValues(array $configValuesBefore): void
    {
        (new ReflectionProperty(Config::class, 'configTree'))->setValue(null, $configValuesBefore);
    }

    /**
     * The package's shipped `AI.agentVoice` with entries replaced: `$replacedEntries` keys at the top level, and the
     * keys of `limits` / `provider` merged one level deep.
     *
     * @param array<string, mixed> $replacedEntries
     * @return array<string, mixed>
     */
    public static function buildShippedAgentVoiceConfigurationWith(array $replacedEntries): array
    {
        $agentVoiceConfiguration = require dirname(__DIR__, 3) . '/config/app/AI/agentVoice.php';
        foreach ($replacedEntries as $entryName => $entryValue) {
            if (in_array($entryName, ['limits', 'provider'], true) && is_array($entryValue)) {
                $agentVoiceConfiguration[$entryName] = array_replace($agentVoiceConfiguration[$entryName], $entryValue);
                continue;
            }
            $agentVoiceConfiguration[$entryName] = $entryValue;
        }
        return $agentVoiceConfiguration;
    }

    /**
     * The package's shipped configuration with the voice channel switched on.
     *
     * @return array<string, mixed>
     */
    public static function buildSwitchedOnConfiguration(): array
    {
        return static::buildShippedAgentVoiceConfigurationWith(['enabled' => true]);
    }
}
