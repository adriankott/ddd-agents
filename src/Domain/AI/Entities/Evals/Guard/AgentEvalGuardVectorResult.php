<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals\Guard;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The attack probes of one vector under one guard-prompt variant: how many there were and how many the guard let
 * through.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalGuardVectorResult extends ValueObject
{
    /** @var string The attack vector */
    public string $vector = '';

    /** @var int The attack probes of the vector */
    public int $total = 0;

    /** @var int The attack probes of the vector the guard let through */
    public int $bypassed = 0;

    /**
     * The empty figures of `$vector`.
     *
     * @param string $vector
     * @return static
     */
    public static function forVector(string $vector): static
    {
        $agentEvalGuardVectorResult = static::newInstance();
        $agentEvalGuardVectorResult->vector = $vector;
        return $agentEvalGuardVectorResult;
    }

    /**
     * The figures as the stored document of the run file carries them: `total`, `bypassed`.
     *
     * @return array{total: int, bypassed: int}
     */
    public function toArray(): array
    {
        return ['total' => $this->total, 'bypassed' => $this->bypassed];
    }
}
