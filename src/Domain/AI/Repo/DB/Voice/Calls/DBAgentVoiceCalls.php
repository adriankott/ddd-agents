<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Voice\Calls;

use DDD\Domain\AI\Entities\Voice\AgentVoiceConfiguration;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCalls;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * The reads of the call table. Every read that serves a running call is a fresh one (no registry cache): the session
 * worker and the routes look at a row another process has just written.
 *
 * @method AgentVoiceCalls|null find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBAgentVoiceCalls extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBAgentVoiceCall::class;
    public const string BASE_ENTITY_SET_CLASS = AgentVoiceCalls::class;

    /**
     * The calls that ring or run at `$nowUnixTime`, newest first: not ended, and answered less than the maximum call
     * length ago (the worker's hard stop: a row a dead worker left open is not running), or never answered and still
     * inside the ring window. The same bounds as {@see AgentVoiceCall::getCallStateAt()}. Only the calls of
     * `$ownerAccountId` ({@see restrictToCallsOfAccount()}). The ring window of a row without a deadline of its own is
     * {@see getRingSecondsWithoutDeadline()}.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findOpenCalls(int $nowUnixTime, int $limit, int $ownerAccountId): ?AgentVoiceCalls
    {
        $alias = static::getBaseModelAlias();
        $agentVoiceConfiguration = AgentVoiceConfiguration::current();
        $queryBuilder = static::createQueryBuilder(true);
        $this->restrictToCallsOfAccount($queryBuilder, $alias, $ownerAccountId);
        $queryBuilder->andWhere("$alias.endedAt IS NULL")
            ->andWhere(
                "($alias.startedAt IS NOT NULL AND $alias.startedAt >= :openCallAnsweredFloor)"
                . " OR ($alias.startedAt IS NULL AND $alias.ringDeadlineAt > :openCallNow)"
                . " OR ($alias.startedAt IS NULL AND $alias.ringDeadlineAt IS NULL AND $alias.created >= :openCallRingFloor)"
            )
            ->setParameter('openCallAnsweredFloor', date('Y-m-d H:i:s', $nowUnixTime - $agentVoiceConfiguration->maximumCallSeconds))
            ->setParameter('openCallNow', date('Y-m-d H:i:s', $nowUnixTime))
            ->setParameter('openCallRingFloor', date('Y-m-d H:i:s', $nowUnixTime - $this->getRingSecondsWithoutDeadline()))
            ->orderBy("$alias.id", 'DESC')
            ->setMaxResults(max(1, $limit));

        return $this->find($queryBuilder, useEntityRegistrCache: false);
    }

    /**
     * The call log of ONE account. The request's query options (filters, orderBy, top, skip) apply on top of the
     * account scope ({@see restrictToCallsOfAccount()}), which is a query filter the options cannot widen.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findCallsOfAccount(int $ownerAccountId): ?AgentVoiceCalls
    {
        $alias = static::getBaseModelAlias();
        $queryBuilder = static::createQueryBuilder(true);
        $this->restrictToCallsOfAccount($queryBuilder, $alias, $ownerAccountId);

        return $this->find($queryBuilder, useEntityRegistrCache: false);
    }

    /**
     * One call by id when it is a call of `$ownerAccountId`, else null: another account's call reads as missing.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findCallOfAccountById(int $callId, int $ownerAccountId): ?AgentVoiceCall
    {
        $alias = static::getBaseModelAlias();
        $queryBuilder = static::createQueryBuilder(true);
        $this->restrictToCallsOfAccount($queryBuilder, $alias, $ownerAccountId);
        $queryBuilder->andWhere("$alias.id = :scopeCallId")
            ->setParameter('scopeCallId', $callId)
            ->setMaxResults(1);

        return $this->find($queryBuilder, useEntityRegistrCache: false)?->first();
    }

    /**
     * The call log over SEVERAL accounts (a list an operator reads): null = the calls of every account, an empty list
     * = no call. The request's query options (filters, orderBy, top, skip) apply on top of the account scope
     * ({@see restrictToCallsOfAccounts()}), which is a query filter the options cannot widen; newest first when they
     * name no order.
     *
     * @param int[]|null $ownerAccountIds
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findCallsOfAccounts(?array $ownerAccountIds): ?AgentVoiceCalls
    {
        if ($ownerAccountIds === []) {
            return null;
        }
        $alias = static::getBaseModelAlias();
        $queryBuilder = static::createQueryBuilder(true);
        $this->restrictToCallsOfAccounts($queryBuilder, $alias, $ownerAccountIds);
        // Only when the request names no order: an order pinned here is applied first and would win over the requested one.
        if (!(static::BASE_ENTITY_SET_CLASS)::getDefaultQueryOptions()->getOrderBy()) {
            $queryBuilder->orderBy("$alias.id", 'DESC');
        }

        return $this->find($queryBuilder, useEntityRegistrCache: false);
    }

    /**
     * One call by id when it is a call of one of the accounts `$ownerAccountIds`, else null: a call of another account
     * reads as missing. Null = whichever account the call belongs to, an empty list = no call.
     *
     * @param int[]|null $ownerAccountIds
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findCallOfAccountsById(int $callId, ?array $ownerAccountIds): ?AgentVoiceCall
    {
        if ($ownerAccountIds === []) {
            return null;
        }
        $alias = static::getBaseModelAlias();
        $queryBuilder = static::createQueryBuilder(true);
        $this->restrictToCallsOfAccounts($queryBuilder, $alias, $ownerAccountIds);
        $queryBuilder->andWhere("$alias.id = :scopeCallId")
            ->setParameter('scopeCallId', $callId)
            ->setMaxResults(1);

        return $this->find($queryBuilder, useEntityRegistrCache: false)?->first();
    }

    /**
     * One call by id, whoever it belongs to. Fresh read: a route and the session worker look at a row another process
     * has just written.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findCallById(int $callId): ?AgentVoiceCall
    {
        $alias = static::getBaseModelAlias();
        $queryBuilder = static::createQueryBuilder();
        $queryBuilder->andWhere("$alias.id = :lookupCallId")
            ->setParameter('lookupCallId', $callId)
            ->setMaxResults(1);

        return $this->find($queryBuilder, useEntityRegistrCache: false)?->first();
    }

    /**
     * The row of one live session, found by the provider's id for it: the idempotency read of a session announced more
     * than once, and the read of the session worker. Always fresh: the worker polls this while the creating write
     * lands, and the registry would keep the first empty answer.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findCallByProviderCallId(string $providerCallId): ?AgentVoiceCall
    {
        $alias = static::getBaseModelAlias();
        $queryBuilder = static::createQueryBuilder();
        $queryBuilder->andWhere("$alias.providerCallId = :lookupProviderCallId")
            ->setParameter('lookupProviderCallId', $providerCallId)
            ->setMaxResults(1);

        return $this->find($queryBuilder, useEntityRegistrCache: false)?->first();
    }

    /**
     * The calls carried by one conversation, NEWEST first, at most `$limit`: a conversation that carried more calls
     * answers its newest ones, so a read that looks for a call that still rings or for the latest call never misses it
     * behind older calls.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findCallsByConversationId(int $aiConversationId, int $limit = 50): ?AgentVoiceCalls
    {
        $alias = static::getBaseModelAlias();
        $queryBuilder = static::createQueryBuilder();
        $queryBuilder->andWhere("$alias.aiConversationId = :scopeConversationId")
            ->setParameter('scopeConversationId', $aiConversationId)
            ->orderBy("$alias.id", 'DESC')
            ->setMaxResults($limit);

        return $this->find($queryBuilder, useEntityRegistrCache: false);
    }

    /**
     * The calls of `$ownerAccountId` nobody answered whose ring deadline has passed at `$nowUnixTime`, newest first:
     * not answered, not ended, a ring deadline set and not after `$nowUnixTime` (the counterpart of the answer claim,
     * which wins only before the deadline). What a lazy expiry of an account's rings ends. Only the calls of the
     * account ({@see restrictToCallsOfAccount()}); at most `$limit`, so a pass after a long pause stays bounded and
     * the next pass takes the rest.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findUnansweredCallsPastRingDeadline(int $nowUnixTime, int $limit, int $ownerAccountId): ?AgentVoiceCalls
    {
        $alias = static::getBaseModelAlias();
        $queryBuilder = static::createQueryBuilder(true);
        $this->restrictToCallsOfAccount($queryBuilder, $alias, $ownerAccountId);
        $queryBuilder->andWhere("$alias.startedAt IS NULL")
            ->andWhere("$alias.endedAt IS NULL")
            ->andWhere("$alias.ringDeadlineAt IS NOT NULL")
            ->andWhere("$alias.ringDeadlineAt <= :ringDeadlineNow")
            ->setParameter('ringDeadlineNow', date('Y-m-d H:i:s', $nowUnixTime))
            ->orderBy("$alias.id", 'DESC')
            ->setMaxResults(max(1, $limit));

        return $this->find($queryBuilder, useEntityRegistrCache: false);
    }

    /**
     * The calls that were answered and never hung up, answered more than `$olderThanMinutes` ago: what a reaper closes
     * when a worker or a provider died. The bound keeps calls that really run out of the result.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findRunningCallsStartedBefore(int $olderThanMinutes): ?AgentVoiceCalls
    {
        $alias = static::getBaseModelAlias();
        $queryBuilder = static::createQueryBuilder();
        $queryBuilder->andWhere("$alias.endedAt IS NULL")
            ->andWhere("$alias.startedAt IS NOT NULL")
            ->andWhere("$alias.startedAt < :startedBefore")
            ->setParameter('startedBefore', date('Y-m-d H:i:s', time() - $olderThanMinutes * 60))
            ->orderBy("$alias.id", 'ASC');

        return $this->find($queryBuilder, useEntityRegistrCache: false);
    }

    /**
     * The open (answered, not ended) call of ONE conversation: the owner-presence probe of the agent context. A call
     * counts while it is answered and not ended, for at most the maximum call length after it was answered: the row
     * carries no heartbeat, and that bound is the hard stop of the worker that accompanies the call.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findOpenAnsweredCallByConversationId(int $aiConversationId): ?AgentVoiceCall
    {
        $alias = static::getBaseModelAlias();
        $queryBuilder = static::createQueryBuilder();
        $queryBuilder->andWhere("$alias.aiConversationId = :aiConversationId")
            ->andWhere("$alias.startedAt IS NOT NULL")
            ->andWhere("$alias.endedAt IS NULL")
            ->andWhere("$alias.startedAt >= :liveCallStartedAtFloor")
            ->setParameter('aiConversationId', $aiConversationId)
            ->setParameter('liveCallStartedAtFloor', date('Y-m-d H:i:s', time() - AgentVoiceConfiguration::current()->maximumCallSeconds))
            ->orderBy("$alias.id", 'DESC')
            ->setMaxResults(1);

        return $this->find($queryBuilder, useEntityRegistrCache: false)?->first();
    }

    /**
     * The sum of the booked seconds of the calls of `$ownerAccountId` created on the day that starts at
     * `$dayStartUnixTime` (the daily cap reads it).
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findBookedSecondsOfAccountOnDay(int $ownerAccountId, int $dayStartUnixTime): int
    {
        $alias = static::getBaseModelAlias();
        $queryBuilder = static::createQueryBuilder(true);
        $this->restrictToCallsOfAccount($queryBuilder, $alias, $ownerAccountId);
        $queryBuilder->andWhere("$alias.created >= :bookedSecondsDayStart")
            ->andWhere("$alias.created < :bookedSecondsDayEnd")
            ->andWhere("$alias.bookedSeconds > 0")
            ->setParameter('bookedSecondsDayStart', date('Y-m-d H:i:s', $dayStartUnixTime))
            ->setParameter('bookedSecondsDayEnd', date('Y-m-d H:i:s', $dayStartUnixTime + 86400));
        $bookedSeconds = 0;
        foreach ($this->find($queryBuilder, useEntityRegistrCache: false)?->getElements() ?? [] as $agentVoiceCall) {
            $bookedSeconds += $agentVoiceCall->bookedSeconds;
        }

        return $bookedSeconds;
    }

    /**
     * The answer claim of a ringing call: writes `startedAt` only while the call is neither answered nor ended and its
     * ring deadline has not passed. True for the one caller whose write changed the row; everyone else lost.
     *
     * One conditional UPDATE decides it: the condition is entirely inside the statement, so the database serialises
     * the callers and no lock is held across a read. `startedAt` is the gate on purpose: it is the column that makes a
     * call a running one ({@see AgentVoiceCall::isRunning()}), so winning the claim and becoming a running call are one
     * write.
     */
    public function claimRingingCallAnswer(int $callId, int $nowUnixTime): bool
    {
        $sqlDialect = EntityManagerFactory::getDialect();
        $nowFormatted = date('Y-m-d H:i:s', $nowUnixTime);

        return (int)EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            'UPDATE ' . $sqlDialect->quote($this->getCallTableName())
            . ' SET ' . $sqlDialect->quote('startedAt') . ' = :now'
            . ' WHERE ' . $sqlDialect->quote('id') . ' = :callId'
            . ' AND ' . $sqlDialect->quote('startedAt') . ' IS NULL'
            . ' AND ' . $sqlDialect->quote('endedAt') . ' IS NULL'
            . ' AND (' . $sqlDialect->quote('ringDeadlineAt') . ' IS NULL OR ' . $sqlDialect->quote('ringDeadlineAt') . ' > :now)',
            ['now' => $nowFormatted, 'callId' => $callId]
        ) === 1;
    }

    /**
     * The end claim of a call nobody answered: writes `endedAt` and `endReason` only while the call is neither answered
     * nor ended. True for the one caller whose write changed the row (a ring deadline, a decline and a withdrawal can
     * race; exactly one of them ends the call).
     */
    public function claimUnansweredCallEnd(int $callId, string $endReason, int $nowUnixTime): bool
    {
        $sqlDialect = EntityManagerFactory::getDialect();

        return (int)EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            'UPDATE ' . $sqlDialect->quote($this->getCallTableName())
            . ' SET ' . $sqlDialect->quote('endedAt') . ' = :endedAt, ' . $sqlDialect->quote('endReason') . ' = :endReason'
            . ' WHERE ' . $sqlDialect->quote('id') . ' = :callId'
            . ' AND ' . $sqlDialect->quote('startedAt') . ' IS NULL'
            . ' AND ' . $sqlDialect->quote('endedAt') . ' IS NULL',
            ['endedAt' => date('Y-m-d H:i:s', $nowUnixTime), 'endReason' => $endReason, 'callId' => $callId]
        ) === 1;
    }

    /**
     * Adds `$seconds` to the booked seconds of a call in one relative UPDATE, so that two slices booked close together
     * (a worker and its successor) never overwrite each other.
     */
    public function addBookedSeconds(int $callId, int $seconds): void
    {
        $sqlDialect = EntityManagerFactory::getDialect();
        EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            'UPDATE ' . $sqlDialect->quote($this->getCallTableName())
            . ' SET ' . $sqlDialect->quote('bookedSeconds') . ' = ' . $sqlDialect->quote('bookedSeconds') . ' + :seconds'
            . ' WHERE ' . $sqlDialect->quote('id') . ' = :callId',
            ['seconds' => $seconds, 'callId' => $callId]
        );
    }

    /**
     * How long a never-answered call WITHOUT a ring deadline still counts as open in {@see findOpenCalls()}, in
     * seconds: the read's half of {@see AgentVoiceCall::getRingSecondsWithoutDeadline()}. The base: the configured ring
     * length (`AI.agentVoice.limits.ringSeconds`), the same value as the call's half.
     *
     * Override point: an application whose own medium rings longer than the configured length answers that length
     * here, together with the call's half in its subclass of the call, instead of overriding the whole query. It is
     * ONE number for the whole read, not one per row: the call table of the package names no medium (a call's medium
     * is answered by the application's subclass of the call from its own columns), so the query has no column to
     * pick a window by. An application with several lengths answers the longest; the readers that list open calls
     * narrow the rows once more with the call's own window ({@see AgentVoiceCalls::getOpenCallsAt()}).
     */
    protected function getRingSecondsWithoutDeadline(): int
    {
        return AgentVoiceConfiguration::current()->ringSeconds;
    }

    /** The table of the call rows, as the application's model names it. */
    protected function getCallTableName(): string
    {
        return (static::BASE_REPO_CLASS)::BASE_ORM_MODEL::getTableName();
    }

    /**
     * Narrows a query to the calls of ONE account: the single-account form of {@see restrictToCallsOfAccounts()}. The
     * agent's voice tools read and end calls through this filter, never through the rights alone: the rights are lifted
     * inside many service paths of a turn, and when they apply they scope to the signed-in account, which is not
     * always the owner the tools work for (an operator driving the conversation).
     */
    protected function restrictToCallsOfAccount(DoctrineQueryBuilder $queryBuilder, string $alias, int $ownerAccountId): void
    {
        $this->restrictToCallsOfAccounts($queryBuilder, $alias, [$ownerAccountId]);
    }

    /**
     * Narrows a query to the calls of the accounts `$ownerAccountIds` by the call's own `accountId`: the ONE place
     * that says which calls are an account's. Every read by account goes through it, the single-account reads too, so
     * a subclass whose older rows carry no account adds its fallback here and nowhere else.
     *
     * Null leaves the query over every account. An empty list matches no call (the reads answer it without a query;
     * here it still narrows to nothing, never to everything). One account is compared with `=`, several with `IN`.
     *
     * @param int[]|null $ownerAccountIds
     */
    protected function restrictToCallsOfAccounts(DoctrineQueryBuilder $queryBuilder, string $alias, ?array $ownerAccountIds): void
    {
        if ($ownerAccountIds === null) {
            return;
        }
        $ownerAccountIds = array_values(array_unique(array_map('intval', $ownerAccountIds)));
        if ($ownerAccountIds === []) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }
        if (count($ownerAccountIds) === 1) {
            $queryBuilder->andWhere("$alias.accountId = :ownerScopeAccountId")
                ->setParameter('ownerScopeAccountId', $ownerAccountIds[0]);

            return;
        }
        $queryBuilder->andWhere("$alias.accountId IN (:ownerScopeAccountIds)")
            ->setParameter('ownerScopeAccountIds', $ownerAccountIds);
    }
}
