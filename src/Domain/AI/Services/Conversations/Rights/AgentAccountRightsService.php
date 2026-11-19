<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Rights;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\Service;

/**
 * The read rights of the agent's tables, by account: the ONE rule every core DB repo's `applyReadRightsQuery()` calls
 * (each repo keeps its own `if (!self::$applyRightsRestrictions) return false;` first). A row is readable when its
 * account (the row's own `accountId`, or that of its conversation) is the authenticated account; an admin with access
 * to all resources reads everything; nobody authenticated reads nothing.
 *
 * An application with account hierarchies (resellers, parent and child accounts) puts a same-path subclass under its own
 * root namespace and overrides {@see applyNonAdminAccountCondition()}; the repos resolve this service only through
 * `DDDService::instance()->getService(AgentAccountRightsService::class)`.
 *
 * WHO READS EVERY ROW is the module's own predicate, {@see isAccountReaderOfAllAgentRows()}: by default the framework's
 * {@see Account::isAdminWithAccessToAllResources()}, which says yes for the admin role of ANY account. An application
 * whose scoped users (partner or reseller accounts) hold that role overrides the predicate, so the "admin reads all"
 * rules of the module stop at those accounts. Every such rule asks it: the account read rights of the conversation
 * tables, the admin-only rows (turn-event snapshots), the agent spaces and the use of a space, the mandates, the
 * message embeddings, the evaluation rows, the message context of a customer-hidden conversation, the raw tool errors of
 * the MCP route and the admin audience of the tool executor. Each table has a predicate of its own that delegates to it
 * ({@see isAccountReaderOfAllAgentSpaces()}, {@see isAccountReaderOfAllAgentMandates()},
 * {@see isAccountReaderOfAllMessageEmbeddings()}, {@see isAccountReaderOfAllEvaluationRows()},
 * {@see isAccountReaderOfAllTurnEventSnapshots()}), so an application can narrow one table alone.
 *
 * The rights methods return true whenever they added a condition or decided "all rows" for a reader of every row — the
 * expand merge of the DDD rights system merges the conditions only on true.
 */
class AgentAccountRightsService extends Service
{
    /**
     * Restricts `$queryBuilder` to the rows the authenticated account may read.
     *
     * - no authenticated account → no row (`<alias>.id is null`);
     * - {@see isAccountReaderOfAllAgentRows()} → every row, no condition and no join;
     * - otherwise → {@see applyNonAdminAccountCondition()} on the account id of the row: `<alias>.<accountIdProperty>`
     *   when `$conversationRelation` is null (conversations, schedules), else the `accountId` of the related
     *   conversation, joined as `<alias>_<conversationRelation>_rights` (only in this branch).
     *
     * @param DoctrineQueryBuilder $queryBuilder
     * @param string $baseModelAlias the repo's base model alias
     * @param string|null $conversationRelation the model relation to the owning conversation (e.g. `aiConversation`); null = the row carries the account id itself
     * @param string $accountIdProperty the account id property of the row (used when `$conversationRelation` is null)
     * @return bool always true (conditions were added, or the admin reads every row)
     */
    public function applyAccountReadRights(
        DoctrineQueryBuilder &$queryBuilder,
        string $baseModelAlias,
        ?string $conversationRelation = null,
        string $accountIdProperty = 'accountId'
    ): bool {
        $authAccount = AuthService::instance()->getAccount();
        if (!$authAccount instanceof Account) {
            $queryBuilder->andWhere("$baseModelAlias.id is null");
            return true;
        }
        if ($this->isAccountReaderOfAllAgentRows($authAccount)) {
            return true;
        }
        $accountIdExpression = $this->joinAccountIdExpression($queryBuilder, $baseModelAlias, $conversationRelation, $accountIdProperty);
        $this->applyNonAdminAccountCondition($queryBuilder, $authAccount, $accountIdExpression);
        return true;
    }

    /**
     * Restricts `$queryBuilder` to the readers of every turn-event snapshot ({@see isAccountReaderOfAllTurnEventSnapshots()}):
     * such a reader reads every row, anyone else none.
     *
     * @param DoctrineQueryBuilder $queryBuilder
     * @param string $baseModelAlias
     * @return bool always true
     */
    public function applyAdminOnlyReadRights(DoctrineQueryBuilder &$queryBuilder, string $baseModelAlias): bool
    {
        $authAccount = AuthService::instance()->getAccount();
        if ($authAccount instanceof Account && $this->isAccountReaderOfAllTurnEventSnapshots($authAccount)) {
            return true;
        }
        $queryBuilder->andWhere("$baseModelAlias.id is null");
        return true;
    }

    /**
     * The DQL expression of the row's account id; joins the owning conversation as `<alias>_<relation>_rights` when the
     * account id sits on it.
     *
     * @param DoctrineQueryBuilder $queryBuilder
     * @param string $baseModelAlias
     * @param string|null $conversationRelation
     * @param string $accountIdProperty
     * @return string e.g. `m_aiConversation_rights.accountId`
     */
    protected function joinAccountIdExpression(
        DoctrineQueryBuilder &$queryBuilder,
        string $baseModelAlias,
        ?string $conversationRelation,
        string $accountIdProperty
    ): string {
        if ($conversationRelation === null) {
            return "$baseModelAlias.$accountIdProperty";
        }
        $conversationRightsAlias = "{$baseModelAlias}_{$conversationRelation}_rights";
        $queryBuilder->leftJoin("$baseModelAlias.$conversationRelation", $conversationRightsAlias);
        return "$conversationRightsAlias.accountId";
    }

    /**
     * The condition for an authenticated account that is no admin: the row's account is the authenticated account
     * (parameter `rights_user_id`). An application's subclass adds its hierarchy branches here.
     *
     * @param DoctrineQueryBuilder $queryBuilder
     * @param Account $authAccount
     * @param string $accountIdExpression the DQL expression of the row's account id
     * @return void
     */
    protected function applyNonAdminAccountCondition(DoctrineQueryBuilder &$queryBuilder, Account $authAccount, string $accountIdExpression): void
    {
        $queryBuilder
            ->andWhere("$accountIdExpression = :rights_user_id")
            ->setParameter('rights_user_id', $authAccount->id);
    }

    /**
     * Whether `$account` reads every row of the agent's tables (support, debugging, the admin audience). The default is
     * the framework's {@see Account::isAdminWithAccessToAllResources()}; an application whose scoped accounts hold the
     * admin role narrows it here. Every "reads all" rule of the module asks this predicate or a per-table one below.
     *
     * @param Account $account
     * @return bool
     */
    public function isAccountReaderOfAllAgentRows(Account $account): bool
    {
        return $account->isAdminWithAccessToAllResources();
    }

    /**
     * {@see isAccountReaderOfAllAgentRows()} for the authenticated account; false without one.
     *
     * @return bool
     */
    public function isAuthenticatedAccountReaderOfAllAgentRows(): bool
    {
        $authAccount = AuthService::instance()->getAccount();
        return $authAccount instanceof Account && $this->isAccountReaderOfAllAgentRows($authAccount);
    }

    /**
     * Whether `$account` reads every agent space and may store rows for any space. Default: {@see isAccountReaderOfAllAgentRows()}.
     *
     * @param Account $account
     * @return bool
     */
    public function isAccountReaderOfAllAgentSpaces(Account $account): bool
    {
        return $this->isAccountReaderOfAllAgentRows($account);
    }

    /**
     * Whether `$account` reads every mandate. Default: {@see isAccountReaderOfAllAgentRows()}.
     *
     * @param Account $account
     * @return bool
     */
    public function isAccountReaderOfAllAgentMandates(Account $account): bool
    {
        return $this->isAccountReaderOfAllAgentRows($account);
    }

    /**
     * Whether `$account` reads every message embedding. Default: {@see isAccountReaderOfAllAgentRows()}.
     *
     * @param Account $account
     * @return bool
     */
    public function isAccountReaderOfAllMessageEmbeddings(Account $account): bool
    {
        return $this->isAccountReaderOfAllAgentRows($account);
    }

    /**
     * Whether `$account` reads the evaluation rows (suite runs and their cases); nobody else reads any.
     * Default: {@see isAccountReaderOfAllAgentRows()}.
     *
     * @param Account $account
     * @return bool
     */
    public function isAccountReaderOfAllEvaluationRows(Account $account): bool
    {
        return $this->isAccountReaderOfAllAgentRows($account);
    }

    /**
     * Whether `$account` reads the turn-event snapshots; nobody else reads any. Default: {@see isAccountReaderOfAllAgentRows()}.
     *
     * @param Account $account
     * @return bool
     */
    public function isAccountReaderOfAllTurnEventSnapshots(Account $account): bool
    {
        return $this->isAccountReaderOfAllAgentRows($account);
    }
}
