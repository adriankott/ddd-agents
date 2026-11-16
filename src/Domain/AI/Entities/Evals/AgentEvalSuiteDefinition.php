<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One suite a suite run can plan, as the configuration `AI.evalSuite.suites` names it: its name, the folder of its
 * fixtures, the kind of its tasks ({@see AgentEvalSuiteTask}'s `KIND_*` or an application's own), whether a run
 * without a suite list takes it, and the flavors it runs in.
 *
 * The unique key of a definition is its suite name.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalSuiteDefinition extends ValueObject
{
    /** @var string The suite name (the value of the suite list option, the `suite` of the case rows) */
    public string $suiteName = '';

    /** @var string The absolute folder of the suite's fixture files */
    public string $fixtureFolder = '';

    /** @var string The kind of the suite's tasks */
    public string $taskKind = AgentEvalSuiteTask::KIND_TOOLS_CASE;

    /** @var bool Whether a run without a suite list takes the suite */
    public bool $plannedByDefault = true;

    /** @var string[] The flavors the suite runs in; empty = every flavor of the run */
    public array $flavors = [];

    /**
     * @param string $suiteName
     * @param string $fixtureFolder the absolute folder of the fixtures
     * @param string $taskKind
     * @param bool $plannedByDefault
     * @param string[] $flavors empty = every flavor
     * @return static
     */
    public static function forSuite(string $suiteName, string $fixtureFolder, string $taskKind, bool $plannedByDefault, array $flavors): static
    {
        $agentEvalSuiteDefinition = static::newInstance();
        $agentEvalSuiteDefinition->suiteName = $suiteName;
        $agentEvalSuiteDefinition->fixtureFolder = $fixtureFolder;
        $agentEvalSuiteDefinition->taskKind = $taskKind;
        $agentEvalSuiteDefinition->plannedByDefault = $plannedByDefault;
        $agentEvalSuiteDefinition->flavors = $flavors;
        return $agentEvalSuiteDefinition;
    }

    /**
     * The flavors of a run the suite is planned in: every flavor of `$runFlavors` the suite runs in, in the run's
     * order. Empty when the suite runs in none of them (it is then not planned).
     *
     * @param string[] $runFlavors
     * @return string[]
     */
    public function buildPlannedFlavors(array $runFlavors): array
    {
        if ($this->flavors === []) {
            return array_values($runFlavors);
        }
        return array_values(array_filter($runFlavors, fn (string $runFlavor): bool => in_array($runFlavor, $this->flavors, true)));
    }

    /** The key of the definition of `$suiteName` in its set (the module's class name, also for an application's subclass). */
    public static function uniqueKeyOfSuiteName(string $suiteName): string
    {
        return self::class . '_' . $suiteName;
    }

    /** The suite name. */
    public function uniqueKey(): string
    {
        return static::uniqueKeyOfSuiteName($this->suiteName);
    }
}
