<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The case rows of one run of a suite run (one model in one flavor, {@see AgentEvalSuiteTask::getRunKey()}), in the
 * order the cases finished: what the run's summary, its record and its stored run read.
 *
 * The unique key is the run key.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalCaseRowsOfRun extends ValueObject
{
    /** @var string The run key `<model>|<flavor>` */
    public string $runKey = '';

    /**
     * @var string The model as the run key names it (`default` for the conversation default): the `model` the run's
     * summary is written with
     */
    public string $modelName = '';

    /** @var string The flavor of the run */
    public string $flavor = AgentEvalSuiteTask::FLAVOR_CALL;

    /** @var AgentEvalCaseRows The rows of the run */
    public AgentEvalCaseRows $caseRows;

    /** The run of `$agentEvalSuiteTask`, without a row yet. */
    public static function forTask(AgentEvalSuiteTask $agentEvalSuiteTask): static
    {
        $agentEvalCaseRowsOfRun = static::newInstance();
        $agentEvalCaseRowsOfRun->runKey = $agentEvalSuiteTask->getRunKey();
        [$agentEvalCaseRowsOfRun->modelName, $agentEvalCaseRowsOfRun->flavor] = explode('|', $agentEvalCaseRowsOfRun->runKey, 2);
        $agentEvalCaseRowsOfRun->caseRows = AgentEvalCaseRows::newInstance();
        return $agentEvalCaseRowsOfRun;
    }

    /** The key of the rows of the run `$runKey` in their set (the module's class name, also for an application's subclass). */
    public static function uniqueKeyOfRunKey(string $runKey): string
    {
        return self::class . '_' . $runKey;
    }

    /** The run key. */
    public function uniqueKey(): string
    {
        return static::uniqueKeyOfRunKey($this->runKey);
    }
}
