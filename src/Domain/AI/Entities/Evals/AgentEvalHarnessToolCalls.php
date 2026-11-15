<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Tool calls the evaluation harness makes itself ({@see AgentEvalHarnessToolCall}), in the order they were added: the
 * preparation steps or the `after` steps of a restore block ({@see AgentEvalRestorePlan}), the removals of what the
 * preparation created.
 *
 * Each call is held under the key of its own object (the default key of a value object), never under its tool or its
 * arguments: two calls of the same tool with the same arguments are two steps of the case and both stay, in the order
 * they were added. Only the same object added a second time is not added again.
 *
 * @property AgentEvalHarnessToolCall[] $elements
 * @method AgentEvalHarnessToolCall[] getElements()
 * @method AgentEvalHarnessToolCall|null first()
 * @method AgentEvalHarnessToolCall|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class AgentEvalHarnessToolCalls extends ObjectSet
{
}
