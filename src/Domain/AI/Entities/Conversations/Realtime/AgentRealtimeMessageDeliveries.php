<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Realtime;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The realtime messages a run-state read delivers to the reading client.
 *
 * @property AgentRealtimeMessageDelivery[] $elements
 * @method AgentRealtimeMessageDelivery[] getElements()
 * @method AgentRealtimeMessageDelivery|null getByUniqueKey(string $uniqueKey)
 */
class AgentRealtimeMessageDeliveries extends ObjectSet
{
}
