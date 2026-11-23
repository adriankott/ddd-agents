<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Sandboxes;

use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxActionLogEntry;
use DDD\Domain\AI\Services\Conversations\Rights\AgentAccountRightsService;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Infrastructure\Services\DDDService;

/**
 * @method AgentSandboxActionLogEntry find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @method AgentSandboxActionLogEntry update(DefaultObject &$entity, int $depth = 1)
 * @property DBAgentSandboxActionLogEntryModel $ormInstance
 */
class DBAgentSandboxActionLogEntry extends DBEntity
{
    public const string BASE_ENTITY_CLASS = AgentSandboxActionLogEntry::class;
    public const string BASE_ORM_MODEL = DBAgentSandboxActionLogEntryModel::class;

    /**
     * The audit is an operator record: only an account that reads every agent row sees it
     * ({@see AgentAccountRightsService::applyAdminOnlyReadRights()}).
     */
    public static function applyReadRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        if (!self::$applyRightsRestrictions) {
            return false;
        }
        /** @var AgentAccountRightsService $agentAccountRightsService */
        $agentAccountRightsService = DDDService::instance()->getService(AgentAccountRightsService::class);
        return $agentAccountRightsService->applyAdminOnlyReadRights($queryBuilder, static::getBaseModelAlias());
    }
}
