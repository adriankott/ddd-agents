<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations\Quality;

use DDD\Domain\AI\Entities\Conversations\Scopes\AgentApplicationSqlFilter;
use DDD\Domain\AI\Services\Conversations\Quality\AgentConversationScorecardsService;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;

/**
 * The scorecards service of an application that counts one account as internal: its filter leaves that account's
 * conversations out (a conversation without an account stays), or names a join when the test asks for one.
 */
class ScorecardsServiceLeavingOutOneAccountDouble extends AgentConversationScorecardsService
{
    public function __construct(public int $internalAccountId, public bool $filterNamesAJoin = false)
    {
    }

    protected function buildInternalAccountsExclusionSqlFilter(): AgentApplicationSqlFilter
    {
        $sqlDialect = EntityManagerFactory::getDialect();
        $accountIdColumnSql = $sqlDialect->quote(self::CONVERSATION_TABLE_ALIAS) . '.' . $sqlDialect->quote('accountId');
        return AgentApplicationSqlFilter::of(
            $this->filterNamesAJoin ? 'LEFT JOIN ' . $sqlDialect->quote('Accounts') . ' internalAccount ON internalAccount.id = ' . $accountIdColumnSql : '',
            "({$accountIdColumnSql} IS NULL OR {$accountIdColumnSql} <> :internalAccountId)",
            ['internalAccountId' => $this->internalAccountId]
        );
    }
}
