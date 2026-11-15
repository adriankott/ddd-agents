<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Quality;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Quality\AgentConversationScorecardSelection;
use DDD\Domain\AI\Entities\Conversations\Scopes\AgentApplicationSqlFilter;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\Base\Repo\DB\Dialect\Json\JsonPath;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\Service;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\Query\QueryBuilder;
use Throwable;

/**
 * The quality scorecard of a finalized conversation — measurement, assessment and quality analysis — asked by the
 * scorecard worker ({@see \DDD\Domain\AI\MessageHandlers\AIConversationScorecardHandler}) after the completion
 * compaction. The quality component (metrics, assessments, defects, wishes, capability tree) belongs to the application;
 * the core keeps only the worker, its transport, the erased-scope guard and the selection of the conversations a
 * scorecard run reads ({@see findConversationIdsForSelection()}).
 *
 * The default writes nothing. An application with a quality component puts a same-path subclass under its own root
 * namespace; the core resolves this service only through
 * `DDDService::instance()->getService(AgentConversationScorecardsService::class)`.
 */
class AgentConversationScorecardsService extends Service
{
    /** @var string The alias of the conversations table in {@see findConversationIdsForSelection()}; an application's filter names it */
    public const string CONVERSATION_TABLE_ALIAS = 'conversation';

    /**
     * Writes the scorecard of the finalized conversation `$conversation` and runs its quality analysis
     * (`$forceAnalysis` = analyse again even when the analysis already covers the newest message). The default has no
     * quality component and writes nothing.
     *
     * @param AIConversation $conversation
     * @param bool $forceAnalysis
     * @return string|null the analysis outcome for the worker's trace line; null when nothing was written
     * @throws Throwable the worker logs a failure, it never rethrows
     */
    public function writeScorecardOfFinalizedConversation(AIConversation $conversation, bool $forceAnalysis): ?string
    {
        return null;
    }

    /**
     * The ids of the ROOT conversations (no delegation child) `$selection` names, oldest first: created on or after
     * its moment, of its purposes when it is restricted by purpose (the regular chats as "no purpose"), without the
     * eval runs (`runSettings.evalRun` = true; a missing key, JSON null or false is no eval run) unless included, and
     * without the conversations {@see buildInternalAccountsExclusionSqlFilter()} leaves out unless the internal
     * accounts are included; at most its limit. The eval runs are left out in SQL, so the limit counts the
     * conversations a run works on. One read, no rights applied (a console selection over every account); runs on
     * MariaDB and PostgreSQL.
     *
     * @param AgentConversationScorecardSelection $selection
     * @return int[]
     * @throws DBALException
     * @throws InternalErrorException when the application's filter needs a join
     */
    public function findConversationIdsForSelection(AgentConversationScorecardSelection $selection): array
    {
        $sqlDialect = EntityManagerFactory::getDialect();
        $queryBuilder = EntityManagerFactory::getInstance()->getConnection()->createQueryBuilder();
        $queryBuilder
            ->select($this->buildConversationColumnSql('id'))
            ->from($sqlDialect->quote(DBAIConversationModel::getTableName()), $sqlDialect->quote(self::CONVERSATION_TABLE_ALIAS))
            ->where($this->buildConversationColumnSql('created') . ' >= :createdSince')
            ->andWhere($this->buildConversationColumnSql('delegatedFromMessageId') . ' IS NULL')
            ->setParameter('createdSince', $selection->createdSince)
            ->orderBy($this->buildConversationColumnSql('id'), 'ASC')
            ->setMaxResults(max(1, $selection->limit));
        if ($selection->isRestrictedByPurpose) {
            $this->restrictSelectionToPurposes($queryBuilder, $selection);
        }
        if (!$selection->includesEvalRuns) {
            $queryBuilder->andWhere($this->buildNotAnEvalRunConditionSql());
        }
        if (!$selection->includesInternalAccounts) {
            $this->restrictSelectionWithFilter($queryBuilder, $this->buildInternalAccountsExclusionSqlFilter());
        }
        return array_map('intval', $queryBuilder->executeQuery()->fetchFirstColumn());
    }

    /**
     * Override point: the condition that leaves the conversations of internal accounts (test and staff accounts) out
     * of {@see findConversationIdsForSelection()}. The statement reads the conversations table under the alias
     * {@see self::CONVERSATION_TABLE_ALIAS}; the account of a conversation is its `accountId`. The filter of this read
     * is a condition only — a filter with a join is refused. Keep it tolerant of a conversation without an account
     * when such a conversation is to be read, and write identifiers that run on every engine the application uses
     * (an unquoted camelCase name is read in lower case on PostgreSQL).
     *
     * The package knows no account table, so the default is the empty filter: without an application filter the
     * internal accounts are selected like every other.
     *
     * @return AgentApplicationSqlFilter
     */
    protected function buildInternalAccountsExclusionSqlFilter(): AgentApplicationSqlFilter
    {
        return AgentApplicationSqlFilter::none();
    }

    /**
     * Adds "of these purposes, or without a purpose" to the selection.
     *
     * @param QueryBuilder $queryBuilder
     * @param AgentConversationScorecardSelection $selection
     * @return void
     */
    protected function restrictSelectionToPurposes(QueryBuilder $queryBuilder, AgentConversationScorecardSelection $selection): void
    {
        $purposeConditions = [];
        if ($selection->includesConversationsWithoutPurpose) {
            $purposeConditions[] = $this->buildConversationColumnSql('purposeName') . ' IS NULL';
        }
        if ($selection->purposeNames !== []) {
            $purposeConditions[] = $this->buildConversationColumnSql('purposeName') . ' IN (:purposeNames)';
            $queryBuilder->setParameter('purposeNames', $selection->purposeNames, ArrayParameterType::STRING);
        }
        if ($purposeConditions !== []) {
            $queryBuilder->andWhere('(' . implode(' OR ', $purposeConditions) . ')');
        }
    }

    /**
     * "The conversation is no eval run": `runSettings.evalRun` is not JSON true (missing, JSON null and false are no
     * eval run) — the rule of {@see \DDD\Domain\AI\Repo\DB\Conversations\DBAIConversations::buildNotAnEvalRunPredicate()},
     * written through the dialect's JSON boolean read.
     *
     * @return string
     */
    protected function buildNotAnEvalRunConditionSql(): string
    {
        $sqlDialect = EntityManagerFactory::getDialect();
        $isEvalRunSql = $sqlDialect->jsonBool($this->buildConversationColumnSql('runSettings'), JsonPath::fromKeys('evalRun'));
        return 'NOT COALESCE(' . $isEvalRunSql . ', ' . $sqlDialect->boolLiteral(false) . ')';
    }

    /**
     * Adds the condition of an application's filter, with its parameters, to the selection.
     *
     * @param QueryBuilder $queryBuilder
     * @param AgentApplicationSqlFilter $agentApplicationSqlFilter
     * @return void
     * @throws InternalErrorException when the filter needs a join
     */
    protected function restrictSelectionWithFilter(QueryBuilder $queryBuilder, AgentApplicationSqlFilter $agentApplicationSqlFilter): void
    {
        if (trim($agentApplicationSqlFilter->joinSql) !== '') {
            throw new InternalErrorException('The internal-accounts filter of the scorecard selection is a condition only; it may not add a join');
        }
        if ($agentApplicationSqlFilter->conditionSql === '') {
            return;
        }
        $queryBuilder->andWhere('(' . $agentApplicationSqlFilter->conditionSql . ')');
        foreach ($agentApplicationSqlFilter->parameters as $parameterName => $parameterValue) {
            $queryBuilder->setParameter($parameterName, $parameterValue);
        }
    }

    /**
     * A column of the conversations table under {@see self::CONVERSATION_TABLE_ALIAS}, quoted for the engine.
     *
     * @param string $columnName
     * @return string
     */
    protected function buildConversationColumnSql(string $columnName): string
    {
        $sqlDialect = EntityManagerFactory::getDialect();
        return $sqlDialect->quote(self::CONVERSATION_TABLE_ALIAS) . '.' . $sqlDialect->quote($columnName);
    }
}
