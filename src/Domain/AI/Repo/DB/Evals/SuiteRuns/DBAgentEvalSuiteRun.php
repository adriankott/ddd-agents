<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Evals\SuiteRuns;

use DDD\Domain\AI\Entities\Evals\SuiteRuns\AgentEvalSuiteRun;
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
 * @method AgentEvalSuiteRun find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @method AgentEvalSuiteRun update(DefaultObject &$entity, int $depth = 1)
 * @property DBAgentEvalSuiteRunModel $ormInstance
 */
#[EntityCache(useExtendedRegistryCache: false, ttl: 0)]
class DBAgentEvalSuiteRun extends DBEntity
{
    public const string BASE_ENTITY_CLASS = AgentEvalSuiteRun::class;
    public const string BASE_ORM_MODEL = DBAgentEvalSuiteRunModel::class;

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
     * Whether the authenticated account reads every evaluation row: {@see AgentAccountRightsService::isAccountReaderOfAllEvaluationRows()}
     * (an application narrows it there for every evaluation table, or here in a same-path subclass for this one).
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
