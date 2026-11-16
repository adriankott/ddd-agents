<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The prior of one case's duration for a kind and a flavor of task: what the plan's wall-time estimate counts for a
 * task of that kind and flavor before it ran.
 *
 * The unique key of a prior is its kind and flavor.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalDurationPrior extends ValueObject
{
    /** @var string The kind of task ({@see AgentEvalSuiteTask::$kind}) */
    public string $kind = '';

    /** @var string The flavor ({@see AgentEvalSuiteTask::$flavor}) */
    public string $flavor = '';

    /** @var float The seconds one case of this kind and flavor takes */
    public float $seconds = 0.0;

    /** The prior `$seconds` of a task of the kind `$kind` in the flavor `$flavor`. */
    public static function forKindAndFlavor(string $kind, string $flavor, float $seconds): static
    {
        $agentEvalDurationPrior = static::newInstance();
        $agentEvalDurationPrior->kind = $kind;
        $agentEvalDurationPrior->flavor = $flavor;
        $agentEvalDurationPrior->seconds = $seconds;
        return $agentEvalDurationPrior;
    }

    /** The key of the prior of the kind `$kind` and the flavor `$flavor` in its set. */
    public static function uniqueKeyForKindAndFlavor(string $kind, string $flavor): string
    {
        return self::class . '_' . $kind . '|' . $flavor;
    }

    /** The kind and the flavor. */
    public function uniqueKey(): string
    {
        return static::uniqueKeyForKindAndFlavor($this->kind, $this->flavor);
    }
}
