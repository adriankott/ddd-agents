<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Voice\Calls;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The seed rows of a voice session ({@see AgentVoiceSeedInputMessage}) in the order the provider reads them, the most
 * important first: a provider adapter fills its seed in this order up to its size limits. Each row is held under the
 * key of its own object, so two rows with the same role and text both stay.
 *
 * @property AgentVoiceSeedInputMessage[] $elements
 * @method AgentVoiceSeedInputMessage[] getElements()
 * @method AgentVoiceSeedInputMessage|null first()
 * @method AgentVoiceSeedInputMessage|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class AgentVoiceSeedInputMessages extends ObjectSet
{
    /** A new set with the given rows, in their order. */
    public static function fromMessages(AgentVoiceSeedInputMessage ...$seedInputMessages): static
    {
        $seedInputMessagesSet = new static();
        foreach ($seedInputMessages as $seedInputMessage) {
            $seedInputMessagesSet->add($seedInputMessage);
        }

        return $seedInputMessagesSet;
    }
}
