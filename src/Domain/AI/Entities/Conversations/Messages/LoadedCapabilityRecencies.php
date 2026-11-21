<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * How many OWNER TURNS ago each loaded capability was last used (replaces the
 * `$turnsSince` closure): from the compaction-proof initiator stamp over the loaded tail when the capability carries
 * one; a stamp-less row (legacy, `tool`-mode history) falls back to the turn count minus the current turn (the stamp's
 * semantics). Computed once for the whole set; the skill-granular unloading reads it.
 *
 * @phpstan-consistent-constructor
 */
class LoadedCapabilityRecencies extends ValueObject
{
    /** @var array<string, int> capability name → owner turns since its last use */
    protected array $ownerTurnsSinceByName = [];

    /**
     * The recencies of every capability of `$loadedCapabilities` at the current turn.
     *
     * @param int[] $coveredInitiatorIndexes the turn initiators BELOW the loaded tail (the rows a compaction cover
     * stands for). Without them a stamp below the tail counts only the loaded turns ("at least that old"); with them
     * the turns the cover stands for are counted too, so the count is exact across a compaction.
     */
    public static function buildFor(LoadedCapabilities $loadedCapabilities, AIConversationOwnerTurns $ownerTurns, ?int $currentInitiatorIndex, int $currentTurnIndex, array $coveredInitiatorIndexes = []): self
    {
        $turnsByName = [];
        foreach ($loadedCapabilities->getElements() as $capability) {
            $initiatorIndex = $capability->resolveRecencyInitiatorIndex();
            if ($initiatorIndex === null) {
                // a row without a stamp: the earlier turn count, minus the current turn (the stamp semantics)
                $turnsByName[$capability->name] = max(0, $capability->turnsSinceLastUse($currentTurnIndex) - 1);
                continue;
            }
            $turns = $ownerTurns->countTurnsBetweenInitiators($initiatorIndex, $currentInitiatorIndex);
            foreach ($coveredInitiatorIndexes as $coveredInitiatorIndex) {
                if ($coveredInitiatorIndex > $initiatorIndex && ($currentInitiatorIndex === null || $coveredInitiatorIndex < $currentInitiatorIndex)) {
                    $turns++;
                }
            }
            $turnsByName[$capability->name] = $turns;
        }
        return self::fromTurnsByName($turnsByName);
    }

    /** @param array<string, int> $ownerTurnsSinceByName capability name → owner turns since its last use */
    public static function fromTurnsByName(array $ownerTurnsSinceByName): static
    {
        $loadedCapabilityRecencies = new static();
        $loadedCapabilityRecencies->ownerTurnsSinceByName = $ownerTurnsSinceByName;
        return $loadedCapabilityRecencies;
    }

    /** Owner turns since the capability's last use (0 for a capability not known here — it was loaded just now). */
    public function getOwnerTurnsSince(LoadedCapability $capability): int
    {
        return $this->ownerTurnsSinceByName[$capability->name] ?? 0;
    }
}
