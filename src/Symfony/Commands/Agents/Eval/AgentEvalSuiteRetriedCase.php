<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\Eval;

use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRow;
use DDD\Domain\Base\Entities\ValueObject;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * A case of a suite run whose first attempt ended in an infrastructure error and that runs once more: its task id,
 * the error the first attempt ended with and the row the first attempt left (its error, process seconds and harness
 * log go onto the row of the second attempt). The unique key is the task id, so a set holds one retry per task.
 *
 * A plain object of one command run; no container service, never stored.
 *
 * @phpstan-consistent-constructor
 */
#[Exclude]
class AgentEvalSuiteRetriedCase extends ValueObject
{
    /** @var string The task id of the case */
    public string $taskId = '';

    /** @var string The infrastructure error the first attempt ended with */
    public string $infrastructureError = '';

    /** @var AgentEvalCaseRow The row the first attempt left */
    public AgentEvalCaseRow $firstAttemptRow;

    /** The retry of task `$taskId` after its first attempt left `$firstAttemptRow` with `$infrastructureError`. */
    public static function forFirstAttempt(string $taskId, string $infrastructureError, AgentEvalCaseRow $firstAttemptRow): static
    {
        $agentEvalSuiteRetriedCase = static::newInstance();
        $agentEvalSuiteRetriedCase->taskId = $taskId;
        $agentEvalSuiteRetriedCase->infrastructureError = $infrastructureError;
        $agentEvalSuiteRetriedCase->firstAttemptRow = $firstAttemptRow;
        return $agentEvalSuiteRetriedCase;
    }

    /** The key of the retry of task `$taskId` in its set (the module's class name, also for a subclass). */
    public static function uniqueKeyOfTaskId(string $taskId): string
    {
        return self::class . '_' . $taskId;
    }

    /** The task id. */
    public function uniqueKey(): string
    {
        return static::uniqueKeyOfTaskId($this->taskId);
    }
}
