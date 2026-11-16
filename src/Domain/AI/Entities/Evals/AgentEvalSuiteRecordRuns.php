<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The runs of a suite record ({@see AgentEvalSuiteRecordRun}), one per run key, in the order the runs finished their
 * first case.
 *
 * @property AgentEvalSuiteRecordRun[] $elements
 * @method AgentEvalSuiteRecordRun[] getElements()
 * @method AgentEvalSuiteRecordRun|null first()
 * @method AgentEvalSuiteRecordRun|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class AgentEvalSuiteRecordRuns extends ObjectSet
{
    /**
     * Every run as a suite record stores it, keyed by its run key: `runs` of the stored document of
     * `<suite run>/_suite.json`.
     *
     * @return array run key → run
     */
    public function toArraysByRunKey(): array
    {
        $runsByRunKey = [];
        foreach ($this->getElements() as $agentEvalSuiteRecordRun) {
            $runsByRunKey[$agentEvalSuiteRecordRun->runKey] = $agentEvalSuiteRecordRun->toArray();
        }
        return $runsByRunKey;
    }
}
