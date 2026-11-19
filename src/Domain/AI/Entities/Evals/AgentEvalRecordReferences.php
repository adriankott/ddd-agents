<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Record references of a fixture ({@see AgentEvalRecordReference}), one per kind and name, in the order first written.
 *
 * @property AgentEvalRecordReference[] $elements
 * @method AgentEvalRecordReference[] getElements()
 * @method AgentEvalRecordReference|null first()
 * @method AgentEvalRecordReference|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class AgentEvalRecordReferences extends ObjectSet
{
    /** The references written in `$text` (`{{ref:<kind>:<name>}}`), in the order written, each kind and name once. */
    public static function fromText(string $text): static
    {
        $agentEvalRecordReferences = static::newInstance();
        if (!str_contains($text, '{{ref:') || preg_match_all(AgentEvalRecordReference::TOKEN_PATTERN, $text, $tokenMatches, PREG_SET_ORDER) === 0) {
            return $agentEvalRecordReferences;
        }
        foreach ($tokenMatches as $tokenMatch) {
            $agentEvalRecordReference = AgentEvalRecordReference::fromTokenMatch($tokenMatch[0], $tokenMatch[1], $tokenMatch[2]);
            if ($agentEvalRecordReferences->getByUniqueKey($agentEvalRecordReference->uniqueKey()) === null) {
                $agentEvalRecordReferences->add($agentEvalRecordReference);
            }
        }
        return $agentEvalRecordReferences;
    }
}
