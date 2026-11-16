<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * An eviction fixture: `{"settings": {…}, "trajectories": [{"name", "prompts": [...]}, …]}`. The settings are the
 * stress variant of the context retention a trajectory runs on (eager loading and low thresholds, so a few varied
 * prompts make the carried load grow until eviction fires); every key is optional:
 *
 * - `eager` (default true): load every tool of a skill when the skill loads;
 * - `soft`, `hard`, `ref`: the soft eviction target, the hard ceiling and the size-factor denominator in tokens;
 * - `unloadToolAfterUnusedTokens`, `unloadToolAfterUnusedTurns`, `unloadSkillAfterUnusedTokens`,
 *   `unloadSkillAfterUnusedTurns`, `minResidencyTurns`: the unload horizons and the residency grace.
 *
 * A missing or unreadable file is a fixture without trajectories.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalEvictionFixture extends ValueObject
{
    /** @var string The key of the settings block */
    public const string KEY_SETTINGS = 'settings';

    /** @var string The key of the trajectory list */
    public const string KEY_TRAJECTORIES = 'trajectories';

    /** @var string The settings key of eager tool loading */
    public const string SETTINGS_KEY_EAGER = 'eager';

    /** @var string[] The settings key of each threshold, by field name of {@see ContextRetentionSettings} */
    public const array SETTINGS_KEYS_BY_FIELD = [
        'pressureBudgetTokens' => 'soft',
        'hardCapTokens' => 'hard',
        'referenceResidentTokens' => 'ref',
        'unloadToolAfterUnusedTokens' => 'unloadToolAfterUnusedTokens',
        'unloadToolAfterUnusedTurns' => 'unloadToolAfterUnusedTurns',
        'unloadSkillAfterUnusedTokens' => 'unloadSkillAfterUnusedTokens',
        'unloadSkillAfterUnusedTurns' => 'unloadSkillAfterUnusedTurns',
        'minResidencyTurns' => 'minResidencyTurns',
    ];

    /** @var array<string, mixed> The fixture as decoded from its file; empty for a missing or unreadable file */
    protected array $fixtureFields = [];

    /**
     * The fixture of the file `$fixturePath`.
     *
     * @param string $fixturePath
     * @return static
     */
    public static function fromFile(string $fixturePath): static
    {
        $decodedFixture = is_file($fixturePath) ? json_decode((string)file_get_contents($fixturePath), true) : null;
        return static::fromDecodedFields(is_array($decodedFixture) ? $decodedFixture : []);
    }

    /**
     * The fixture of decoded file contents.
     *
     * @param array<string, mixed> $fixtureFields
     * @return static
     */
    public static function fromDecodedFields(array $fixtureFields): static
    {
        $agentEvalEvictionFixture = static::newInstance();
        $agentEvalEvictionFixture->fixtureFields = $fixtureFields;
        return $agentEvalEvictionFixture;
    }

    /**
     * The trajectories of the fixture, in file order; an entry that is no object is left out.
     *
     * @return AgentEvalEvictionTrajectories
     */
    public function getTrajectories(): AgentEvalEvictionTrajectories
    {
        $agentEvalEvictionTrajectories = AgentEvalEvictionTrajectories::newInstance();
        $decodedTrajectories = is_array($this->fixtureFields[self::KEY_TRAJECTORIES] ?? null) ? $this->fixtureFields[self::KEY_TRAJECTORIES] : [];
        foreach ($decodedTrajectories as $decodedTrajectory) {
            if (!is_array($decodedTrajectory)) {
                continue;
            }
            $agentEvalEvictionTrajectory = AgentEvalEvictionTrajectory::fromDecodedFields($decodedTrajectory);
            $agentEvalEvictionTrajectories->add($agentEvalEvictionTrajectory);
        }
        return $agentEvalEvictionTrajectories;
    }

    /**
     * Whether the fixture lists trajectories at all (an entry that is no object counts).
     *
     * @return bool
     */
    public function listsTrajectories(): bool
    {
        return is_array($this->fixtureFields[self::KEY_TRAJECTORIES] ?? null) && $this->fixtureFields[self::KEY_TRAJECTORIES] !== [];
    }

    /**
     * The context retention of the fixture's settings: eager loading unless `eager` is false, and each threshold the
     * settings name; every other field keeps its default.
     *
     * @return ContextRetentionSettings
     */
    public function buildRetentionSettings(): ContextRetentionSettings
    {
        $fixtureSettings = is_array($this->fixtureFields[self::KEY_SETTINGS] ?? null) ? $this->fixtureFields[self::KEY_SETTINGS] : [];
        $contextRetentionSettings = ContextRetentionSettings::newInstance();
        $contextRetentionSettings->loadToolsOnSkillLoad = (bool)($fixtureSettings[self::SETTINGS_KEY_EAGER] ?? true);
        foreach (self::SETTINGS_KEYS_BY_FIELD as $fieldName => $settingsKey) {
            if (isset($fixtureSettings[$settingsKey])) {
                $contextRetentionSettings->{$fieldName} = (int)$fixtureSettings[$settingsKey];
            }
        }
        return $contextRetentionSettings;
    }
}
