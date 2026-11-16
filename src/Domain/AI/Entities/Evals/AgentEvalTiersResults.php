<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The results of the tool evaluation per model tier ({@see AgentEvalTiersResult}), in the order the tiers ran; a tier
 * that could not run (unknown, no eligible model) has none.
 *
 * @property AgentEvalTiersResult[] $elements
 * @method AgentEvalTiersResult[] getElements()
 * @method AgentEvalTiersResult|null first()
 * @method AgentEvalTiersResult|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class AgentEvalTiersResults extends ObjectSet
{
    /** The header of the summary table: `TIER MODEL PASS% CALLS FAILED TOKENS DUR(s)`. */
    public function buildSummaryHeaderLine(): string
    {
        return sprintf('%-10s %-30s %8s %7s %7s %9s %7s', 'TIER', 'MODEL', 'PASS%', 'CALLS', 'FAILED', 'TOKENS', 'DUR(s)');
    }
}
