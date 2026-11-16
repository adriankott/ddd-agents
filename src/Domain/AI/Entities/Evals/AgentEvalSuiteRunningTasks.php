<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The running tasks of a suite run ({@see AgentEvalSuiteRunningTask}), in the order they were added.
 *
 * @property AgentEvalSuiteRunningTask[] $elements
 * @method AgentEvalSuiteRunningTask[] getElements()
 * @method AgentEvalSuiteRunningTask|null first()
 * @method AgentEvalSuiteRunningTask|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class AgentEvalSuiteRunningTasks extends ObjectSet
{
    /**
     * The list the progress file stores under `running`: part of the stored document of `<suite run>/_progress.json`.
     *
     * @return array the list of `{case, suite, run, lane, access, pid, seconds}` entries
     */
    public function toArrays(): array
    {
        return array_map(static fn (AgentEvalSuiteRunningTask $agentEvalSuiteRunningTask): array => $agentEvalSuiteRunningTask->toArray(), $this->getElements());
    }
}
