<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Evals\SuiteRuns\AgentEvalSuiteCases;

use DDD\Domain\AI\Entities\Evals\SuiteRuns\AgentEvalSuiteCases\AgentEvalSuiteCase;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Repo\DB\Attributes\EntityCache;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\AI\Services\Conversations\Rights\AgentAccountRightsService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\AuthService;

/**
 * @method AgentEvalSuiteCase find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @method AgentEvalSuiteCase update(DefaultObject &$entity, int $depth = 1)
 * @property DBAgentEvalSuiteCaseModel $ormInstance
 */
#[EntityCache(useExtendedRegistryCache: false, ttl: 0)]
class DBAgentEvalSuiteCase extends DBEntity
{
    public const string BASE_ENTITY_CLASS = AgentEvalSuiteCase::class;
    public const string BASE_ORM_MODEL = DBAgentEvalSuiteCaseModel::class;

    /**
     * Evaluation rows are visible to a reader of every evaluation row only ({@see isAuthenticatedAccountReaderOfEveryRow()}):
     * such a reader reads every row (no condition added), every other account and a request without an account read
     * nothing. The evaluation commands
     * write their record with the rights lifted (a system write). Returns true wherever a condition was added.
     */
    public static function applyReadRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        if (!self::$applyRightsRestrictions) {
            return false;
        }
        if (static::isAuthenticatedAccountReaderOfEveryRow()) {
            return false;
        }
        $baseModelAlias = static::getBaseModelAlias();
        $queryBuilder->andWhere("$baseModelAlias.id is null");
        return true;
    }

    /**
     * Whether the authenticated account reads every evaluation case: the framework account's admin role with access
     * to all resources. An application whose account decides this otherwise answers it in its same-path subclass.
     */
    protected static function isAuthenticatedAccountReaderOfEveryRow(): bool
    {
        $authAccount = AuthService::instance()->getAccount();
        if (!$authAccount instanceof Account) {
            return false;
        }
        /** @var AgentAccountRightsService $agentAccountRightsService */
        $agentAccountRightsService = DDDService::instance()->getService(AgentAccountRightsService::class);
        return $agentAccountRightsService->isAccountReaderOfAllEvaluationRows($authAccount);
    }
}
