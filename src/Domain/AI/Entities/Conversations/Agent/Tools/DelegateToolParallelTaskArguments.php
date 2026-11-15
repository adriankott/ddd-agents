<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The items of a `delegate` call's `parallelTasks`, in the model's order: each item read like a call of its own
 * ({@see DelegateToolArguments::fromToolCallArguments()}) — one sub-agent per item. An item that is no object is an
 * item without a task (refused like a call without one). Elements are keyed by their object id: two identical items
 * are two sub-agents.
 *
 * @property DelegateToolArguments[] $elements
 * @method DelegateToolArguments[] getElements()
 * @method DelegateToolArguments|null first()
 */
class DelegateToolParallelTaskArguments extends ObjectSet
{
}
