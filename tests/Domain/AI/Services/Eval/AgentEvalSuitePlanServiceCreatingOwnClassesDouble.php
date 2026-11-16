<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Evals\AgentEvalSuitePlanOptions;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteTask;
use DDD\Domain\AI\Services\Eval\AgentEvalSuitePlanService;

/**
 * The planning of an application that creates its own task and options classes through the override points.
 */
class AgentEvalSuitePlanServiceCreatingOwnClassesDouble extends AgentEvalSuitePlanService
{
    public function createTask(): AgentEvalSuiteTask
    {
        return AgentEvalSuiteTaskOfAnApplication::newInstance();
    }

    public function createPlanOptions(): AgentEvalSuitePlanOptions
    {
        return AgentEvalSuitePlanOptionsOfAnApplication::newInstance();
    }
}
