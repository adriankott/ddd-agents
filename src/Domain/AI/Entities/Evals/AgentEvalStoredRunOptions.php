<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The options of a finished evaluation run as its stored run keeps them ({@see SuiteRuns\AgentEvalSuiteRun::$runOptionsJson}):
 * each evaluation command states its own keys, in the order it has always stored them.
 *
 *  - a run of one model in one flavor ({@see forSingleModelRun()}): `model`, `flavor`, `arms`, then what the command
 *    adds — the tools run `repeat`, `skill`, `case` ({@see addRepeat()}, {@see addSkillFilter()},
 *    {@see addCaseFilter()}), the selection run `case`, a run of one fixture `fixture` ({@see addFixtureName()});
 *  - a suite run ({@see forSuiteRecord()}): `models`, `flavors`, `suites`, `arms`, `persistedConversations`,
 *    `stopped`, `verdict`, `baseline`.
 *
 * A value that is null is stored as null (a key the command states is always there). An application that stores keys
 * of its own adds them on its same-path class through {@see addStoredValue()}; the object is built with
 * `newInstance()`, which gives that class.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalStoredRunOptions extends ValueObject
{
    /**
     * @var array<string, mixed> The stored document in its key order (built by the factories and adders, or decoded
     * from a stored run)
     */
    protected array $storedFields = [];

    /** No options: the stored run keeps none (its column stays empty). */
    public static function none(): static
    {
        return static::newInstance();
    }

    /**
     * The options of a run of one model in one flavor: `model`, `flavor`, `arms`.
     *
     * @param string|null $modelName null = the conversation default (stored as null)
     * @param string $flavor
     * @param string[] $arms option name → value
     * @return static
     */
    public static function forSingleModelRun(?string $modelName, string $flavor, array $arms): static
    {
        return static::none()->addStoredValue('model', $modelName)->addStoredValue('flavor', $flavor)->addStoredValue('arms', $arms);
    }

    /**
     * The options of a suite run as its record states them: `models`, `flavors`, `suites`, `arms` and
     * `persistedConversations` of the record's settings, then `stopped`, `verdict` (the two verdicts) and `baseline`
     * (the folder name of the baseline run, null without one).
     */
    public static function forSuiteRecord(AgentEvalSuiteRecord $agentEvalSuiteRecord): static
    {
        $agentEvalSuiteRecordSettings = $agentEvalSuiteRecord->settings;
        return static::none()
            ->addStoredValue('models', $agentEvalSuiteRecordSettings->modelNames)
            ->addStoredValue('flavors', $agentEvalSuiteRecordSettings->flavors)
            ->addStoredValue('suites', $agentEvalSuiteRecordSettings->suites)
            ->addStoredValue('arms', $agentEvalSuiteRecordSettings->arms)
            ->addStoredValue('persistedConversations', $agentEvalSuiteRecordSettings->persistedConversations)
            ->addStoredValue('stopped', $agentEvalSuiteRecord->stopped)
            ->addStoredValue('verdict', $agentEvalSuiteRecord->buildVerdict()->toArray())
            ->addStoredValue('baseline', $agentEvalSuiteRecord->baselineDirectoryName);
    }

    /**
     * The options a stored run holds, from its decoded `runOptionsJson` (empty when none were stored).
     *
     * @param array $storedFields the decoded document
     * @return static
     */
    public static function fromStoredDocument(array $storedFields): static
    {
        $agentEvalStoredRunOptions = static::none();
        $agentEvalStoredRunOptions->storedFields = $storedFields;
        return $agentEvalStoredRunOptions;
    }

    /** Appends `repeat`: how often every case ran. */
    public function addRepeat(int $repeat): static
    {
        return $this->addStoredValue('repeat', $repeat);
    }

    /** Appends `skill`: the skill filter of the run; null without one. */
    public function addSkillFilter(?string $skillFilter): static
    {
        return $this->addStoredValue('skill', $skillFilter);
    }

    /** Appends `case`: the case filter of the run; null without one. */
    public function addCaseFilter(?string $caseFilter): static
    {
        return $this->addStoredValue('case', $caseFilter);
    }

    /** Appends `fixture`: the name of the one fixture the run ran. */
    public function addFixtureName(string $fixtureName): static
    {
        return $this->addStoredValue('fixture', $fixtureName);
    }

    /**
     * Appends one key (a key the options carry keeps its place and takes the new value). For an application's own key:
     * a scalar, a list or map of scalars, or the stored document of an object.
     */
    public function addStoredValue(string $key, mixed $value): static
    {
        $this->storedFields[$key] = $value;
        return $this;
    }

    /** Whether the options carry the key `$key` (a null value counts as carried). */
    public function hasKey(string $key): bool
    {
        return array_key_exists($key, $this->storedFields);
    }

    /** The value of the key `$key`; null when the options do not carry it. */
    public function getValue(string $key): mixed
    {
        return $this->storedFields[$key] ?? null;
    }

    /** `model`: the model of a run of one model; null for a suite run, the conversation default or a missing key. */
    public function getModelName(): ?string
    {
        return is_string($this->storedFields['model'] ?? null) ? $this->storedFields['model'] : null;
    }

    /** `flavor`: the flavor of a run of one flavor; null for a suite run or a missing key. */
    public function getFlavor(): ?string
    {
        return is_string($this->storedFields['flavor'] ?? null) ? $this->storedFields['flavor'] : null;
    }

    /** Whether no key is stored (the stored run keeps no options). */
    public function isEmpty(): bool
    {
        return $this->storedFields === [];
    }

    /**
     * The options as the stored run keeps them: the stored document of the column `runOptionsJson` of
     * `AgentEvalSuiteRuns`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->storedFields;
    }
}
