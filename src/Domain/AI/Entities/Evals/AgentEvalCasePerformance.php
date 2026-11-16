<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The `performance` block of a case row: the run figures as the row stores them ({@see AgentEvalRunFigures::toArray()};
 * a row written earlier may lack the newer keys). The block is kept as decoded and read through the accessors, so a
 * row's stored block is never rewritten.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalCasePerformance extends ValueObject
{
    /** @var array The decoded block, key → number or per-turn list */
    protected array $performanceFields = [];

    /**
     * The block `$performanceFields` as a row stores it (empty for a row without one).
     *
     * @param array $performanceFields the decoded `performance` block
     * @return static
     */
    public static function fromBlock(array $performanceFields): static
    {
        $agentEvalCasePerformance = static::newInstance();
        $agentEvalCasePerformance->performanceFields = $performanceFields;
        return $agentEvalCasePerformance;
    }

    /** Whether the row carried a block. */
    public function isMeasured(): bool
    {
        return $this->performanceFields !== [];
    }

    /** One number of the block, 0 when the key is missing. */
    public function getNumber(string $key): float
    {
        return (float)($this->performanceFields[$key] ?? 0);
    }

    /** One number of the block; null when the key is missing or holds no number. */
    public function findNumber(string $key): ?float
    {
        $value = $this->performanceFields[$key] ?? null;
        return is_numeric($value) ? (float)$value : null;
    }

    /**
     * Every entry of a per-turn list of the block (`$key`) as a number; empty when the key is missing.
     *
     * @return float[]
     */
    public function getListAsNumbers(string $key): array
    {
        return array_map('floatval', array_values((array)($this->performanceFields[$key] ?? [])));
    }

    /**
     * The numeric entries of a per-turn list of the block (`$key`), a turn that measured nothing (null) left out.
     *
     * @return float[]
     */
    public function getNumericListEntries(string $key): array
    {
        $numericEntries = [];
        foreach ((array)($this->performanceFields[$key] ?? []) as $listEntry) {
            if (is_numeric($listEntry)) {
                $numericEntries[] = (float)$listEntry;
            }
        }
        return $numericEntries;
    }

    /** The uncached input tokens of the case: the stored number, else input − cached (a block written before the speed figures). */
    public function getUncachedInputTokens(): int
    {
        if (is_numeric($this->performanceFields['uncachedInputTokens'] ?? null)) {
            return (int)$this->performanceFields['uncachedInputTokens'];
        }
        return max(0, (int)($this->performanceFields['inputTokens'] ?? 0) - (int)($this->performanceFields['cachedInputTokens'] ?? 0));
    }

    /**
     * The block exactly as the row stores it.
     *
     * @return array
     */
    public function toArray(): array
    {
        return $this->performanceFields;
    }
}
