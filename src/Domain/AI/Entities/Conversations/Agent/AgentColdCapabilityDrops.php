<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The cold unloadings of an append-only conversation, one {@see AgentColdCapabilityDrop} per prefix epoch, in record
 * order. Stored in the agent working state's `coldCapabilityDrops` slice as a list.
 *
 * @property AgentColdCapabilityDrop[] $elements
 * @method AgentColdCapabilityDrop[] getElements()
 * @phpstan-consistent-constructor
 */
class AgentColdCapabilityDrops extends ObjectSet
{
    /** The drop recorded for the epoch starting at `$epochStartIndex`, or null. */
    public function findDropOfEpoch(int $epochStartIndex): ?AgentColdCapabilityDrop
    {
        foreach ($this->getElements() as $agentColdCapabilityDrop) {
            if ($agentColdCapabilityDrop->epochStartIndex === $epochStartIndex) {
                return $agentColdCapabilityDrop;
            }
        }
        return null;
    }

    /** @return array<int, array<string, int|string[]>> the stored list */
    public function toRepositoryList(): array
    {
        return array_map(
            static fn (AgentColdCapabilityDrop $agentColdCapabilityDrop): array => $agentColdCapabilityDrop->toRepositoryArray(),
            array_values($this->getElements())
        );
    }

    /** @param array<array-key, mixed> $repositoryList the stored list; rows that are no drop are skipped */
    public static function fromRepositoryList(array $repositoryList): static
    {
        $agentColdCapabilityDrops = new static();
        foreach ($repositoryList as $repositoryFields) {
            $agentColdCapabilityDrop = is_array($repositoryFields) ? AgentColdCapabilityDrop::fromRepositoryArray($repositoryFields) : null;
            if ($agentColdCapabilityDrop !== null && $agentColdCapabilityDrops->findDropOfEpoch($agentColdCapabilityDrop->epochStartIndex) === null) {
                $agentColdCapabilityDrops->add($agentColdCapabilityDrop);
            }
        }
        return $agentColdCapabilityDrops;
    }
}
