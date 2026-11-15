<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The extractions of an `argsFromResult` / `argsFromToolResult` block or of a `capture` block
 * ({@see AgentEvalToolResultExtraction}), in the order of the block: one per argument the value is read for
 * ({@see AgentEvalToolResultExtraction::$argumentKey}).
 *
 * An argument key holds one extraction: the set keys an extraction by its argument key, so a second extraction for an
 * argument the set already holds is not added and the first one stays (a decoded block carries each key once).
 *
 * @property AgentEvalToolResultExtraction[] $elements
 * @method AgentEvalToolResultExtraction[] getElements()
 * @method AgentEvalToolResultExtraction|null first()
 * @method AgentEvalToolResultExtraction|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class AgentEvalToolResultExtractions extends ObjectSet
{
    /**
     * @param string $argumentKey
     * @return AgentEvalToolResultExtraction|null the extraction that reads the value of that argument
     */
    public function getByArgumentKey(string $argumentKey): ?AgentEvalToolResultExtraction
    {
        return $this->getByUniqueKey(AgentEvalToolResultExtraction::uniqueKeyForArgumentKey($argumentKey));
    }
}
