<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Evals\SuiteRuns\AgentEvalSuiteCases;

use DDD\Domain\AI\Entities\Evals\SuiteRuns\AgentEvalSuiteCases\AgentEvalSuiteCases;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method AgentEvalSuiteCases find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBAgentEvalSuiteCases extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBAgentEvalSuiteCase::class;
    public const string BASE_ENTITY_SET_CLASS = AgentEvalSuiteCases::class;
}
