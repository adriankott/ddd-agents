<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\ClientTools;

use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRights;
use DDD\Domain\AI\Services\Conversations\Entitlements\AIConversationEntitlementsService;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;

/**
 * The rights of the client tool rows (`Agent.UI`): an Account reads, creates and changes only the rows of its own
 * Account, and only while it holds {@see AgentAccountRights::USE_UI} (asked through
 * {@see AIConversationEntitlementsService::findAccountRightsOfCurrentAccount()}; null = ungated). Every other caller
 * touches no row. Nobody deletes a row with own rights: the purge command lifts the rights.
 */
trait AgentClientToolRowRights
{
    public static function applyReadRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        return static::restrictToOwnRowsOfUIUser($queryBuilder);
    }

    public static function applyUpdateRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        return static::restrictToOwnRowsOfUIUser($queryBuilder);
    }

    public static function applyCreateRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        return static::restrictToOwnRowsOfUIUser($queryBuilder);
    }

    public static function applyDeleteRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        if (!self::$applyRightsRestrictions) {
            return false;
        }
        $baseModelAlias = static::getBaseModelAlias();
        $queryBuilder->andWhere("$baseModelAlias.id is null");
        return true;
    }

    protected static function restrictToOwnRowsOfUIUser(DoctrineQueryBuilder &$queryBuilder): bool
    {
        if (!self::$applyRightsRestrictions) {
            return false;
        }
        $baseModelAlias = static::getBaseModelAlias();
        $account = AuthService::instance()->getAccount();
        if ($account === null || !static::currentAccountHoldsTheUIRight()) {
            $queryBuilder->andWhere("$baseModelAlias.id is null");
            return true;
        }
        $queryBuilder->andWhere("$baseModelAlias.accountId = :clientToolRowAccountId")
            ->setParameter('clientToolRowAccountId', $account->id);
        return true;
    }

    protected static function currentAccountHoldsTheUIRight(): bool
    {
        /** @var AIConversationEntitlementsService $aiConversationEntitlementsService */
        $aiConversationEntitlementsService = DDDService::instance()->getService(AIConversationEntitlementsService::class);
        $accountRights = $aiConversationEntitlementsService->findAccountRightsOfCurrentAccount();
        return $accountRights === null || $accountRights->getMissingRightNames([AgentAccountRights::USE_UI]) === [];
    }
}
