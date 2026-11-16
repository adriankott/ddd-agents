<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRowHeader;

/**
 * The row header of an application whose rows name the scope with their own key.
 */
class AgentEvalCaseRowHeaderOfAnApplication extends AgentEvalCaseRowHeader
{
    /** @var string The application's key of the scope */
    public const string SCOPE_FIELD_NAME = 'storeId';
}
