<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals\SeedAccounts;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The seeds an evaluation fixture may run its cases on — one seed per dedicated test ACCOUNT. Every case
 * of the fixture works on the account of ONE seed and resets that account at its start, so two cases that run at the
 * same time must hold different seeds: a case leases one seed of the pool
 * ({@see \DDD\Domain\AI\Services\Eval\AgentEvalSeedAccountLeasesService}).
 *
 * A seed is an id the application's lease service maps to its account; by default the id of an agent space. How a
 * fixture names its pool is the application's: its subclass of this class reads its fixture keys and builds the pool
 * with {@see fromSeedIds()}.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalSeedPool extends ValueObject
{
    /** @var int[] The seed ids, in the order they were named, without duplicates. */
    public array $seedIds = [];

    /**
     * The pool of the named seeds: every value cast to int, values below 1 and duplicates left out, the first
     * occurrence's order kept.
     *
     * @param array<int, int|string> $seedIds
     */
    public static function fromSeedIds(array $seedIds): static
    {
        $agentEvalSeedPool = static::newInstance();
        foreach ($seedIds as $seedId) {
            $seedId = (int)$seedId;
            if ($seedId > 0 && !in_array($seedId, $agentEvalSeedPool->seedIds, true)) {
                $agentEvalSeedPool->seedIds[] = $seedId;
            }
        }
        return $agentEvalSeedPool;
    }

    public function isEmpty(): bool
    {
        return $this->seedIds === [];
    }

    public function getSize(): int
    {
        return count($this->seedIds);
    }

    /** The seed a path without a lease uses (a path that reads only an auth context from it). */
    public function getFirstSeedId(): ?int
    {
        return $this->seedIds[0] ?? null;
    }

    /**
     * The order in which a process tries the seeds: rotated by `$rotationOffset` (the process id), so processes that
     * start at the same moment do not all queue for the first seed.
     *
     * @return int[]
     */
    public function buildSeedIdsInTryOrder(int $rotationOffset): array
    {
        $poolSize = $this->getSize();
        if ($poolSize === 0) {
            return [];
        }
        $startPosition = (($rotationOffset % $poolSize) + $poolSize) % $poolSize;
        return array_merge(array_slice($this->seedIds, $startPosition), array_slice($this->seedIds, 0, $startPosition));
    }

    /** The key a suite plan shares between the fixtures of the same pool — the seeds sorted. */
    public function buildPoolKey(): string
    {
        $sortedSeedIds = $this->seedIds;
        sort($sortedSeedIds);
        return implode(',', $sortedSeedIds);
    }

    /** The refusal text of a lease from an empty pool; an application names its fixture keys here. */
    public function describeEmptyPool(): string
    {
        return 'The seed pool is empty — the fixture names no seed.';
    }
}
