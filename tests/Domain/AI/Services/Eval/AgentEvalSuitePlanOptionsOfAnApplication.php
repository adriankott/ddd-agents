<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Evals\AgentEvalSuitePlanOptions;

/**
 * The plan options of an application with an option of its own.
 */
class AgentEvalSuitePlanOptionsOfAnApplication extends AgentEvalSuitePlanOptions
{
    /** @var int An option of the application */
    public int $applicationWaveSize = 6;
}
