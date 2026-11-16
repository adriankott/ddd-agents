<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\Eval;

use DDD\Domain\Base\Entities\ObjectSet;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The cases of a suite run that run once more after an infrastructure error ({@see AgentEvalSuiteRetriedCase}), one
 * per task id: a case is retried at most once.
 *
 * A plain object of one command run; no container service.
 *
 * @property AgentEvalSuiteRetriedCase[] $elements
 * @method AgentEvalSuiteRetriedCase[] getElements()
 * @method AgentEvalSuiteRetriedCase|null first()
 * @method AgentEvalSuiteRetriedCase|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
#[Exclude]
class AgentEvalSuiteRetriedCases extends ObjectSet
{
    /** The retry of task `$taskId`; null when the task was not retried. */
    public function getByTaskId(string $taskId): ?AgentEvalSuiteRetriedCase
    {
        return $this->getByUniqueKey(AgentEvalSuiteRetriedCase::uniqueKeyOfTaskId($taskId));
    }
}
