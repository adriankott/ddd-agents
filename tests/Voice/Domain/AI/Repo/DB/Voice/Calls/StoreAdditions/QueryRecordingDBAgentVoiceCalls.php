<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Repo\DB\Voice\Calls\StoreAdditions;

use DDD\Domain\AI\Repo\DB\Voice\Calls\DBAgentVoiceCalls;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * The call table's set repository with its read stopped at the database's door: `find()` keeps the query it was
 * handed (and the cache flag) and answers what the test put in, so a read is pinned by its DQL without a database.
 * The account scope is reachable from outside to pin it on its own.
 */
class QueryRecordingDBAgentVoiceCalls extends DBAgentVoiceCalls
{
    /** @var array<int, array{queryBuilder: DoctrineQueryBuilder|null, useEntityRegistrCache: bool}> */
    public array $recordedQueries = [];

    public ?EntitySet $answer = null;

    public function find(
        ?DoctrineQueryBuilder $queryBuilder = null,
        bool $useEntityRegistrCache = true,
        array $initiatorClasses = []
    ): ?EntitySet {
        $this->recordedQueries[] = ['queryBuilder' => $queryBuilder, 'useEntityRegistrCache' => $useEntityRegistrCache];

        return $this->answer;
    }

    /** @param int[]|null $ownerAccountIds */
    public function restrictQueryToCallsOfAccounts(DoctrineQueryBuilder $queryBuilder, ?array $ownerAccountIds): void
    {
        $this->restrictToCallsOfAccounts($queryBuilder, static::getBaseModelAlias(), $ownerAccountIds);
    }
}
