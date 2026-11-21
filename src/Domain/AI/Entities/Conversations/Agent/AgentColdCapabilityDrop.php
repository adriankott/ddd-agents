<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The cold unloading of one append-only prefix epoch at its compaction rebase
 * ({@see \DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings::$dropColdSkillsAtCompactionRebase}):
 * the epoch's start index, the newest history row at that moment and the capabilities (skills and tools) that left —
 * none when nothing was cold. One per epoch; an empty one still marks the epoch as done.
 *
 * @phpstan-consistent-constructor
 */
class AgentColdCapabilityDrop extends ValueObject
{
    /** @var int The index of the first row of the prefix epoch the drop belongs to. */
    public int $epochStartIndex = 0;

    /** @var int The newest history row when the drop ran; rows up to it are rendered without the dropped names (-1 = none). */
    public int $afterMessageIndex = -1;

    /** @var string[] The skill and tool names that left; empty when none was cold. */
    public array $names = [];

    /** @param string[] $names */
    public static function create(int $epochStartIndex, int $afterMessageIndex, array $names): static
    {
        $agentColdCapabilityDrop = new static();
        $agentColdCapabilityDrop->epochStartIndex = $epochStartIndex;
        $agentColdCapabilityDrop->afterMessageIndex = $afterMessageIndex;
        $agentColdCapabilityDrop->names = array_values(array_map('strval', $names));
        return $agentColdCapabilityDrop;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic((string)$this->epochStartIndex);
    }

    /** @return array<string, int|string[]> */
    public function toRepositoryArray(): array
    {
        return ['epochStartIndex' => $this->epochStartIndex, 'afterMessageIndex' => $this->afterMessageIndex, 'names' => $this->names];
    }

    /**
     * The drop of a stored row; null for a row without a numeric `epochStartIndex` (a legacy or broken row reads as none).
     *
     * @param array<string, mixed> $repositoryFields
     */
    public static function fromRepositoryArray(array $repositoryFields): ?static
    {
        if (!is_numeric($repositoryFields['epochStartIndex'] ?? null)) {
            return null;
        }
        return static::create(
            (int)$repositoryFields['epochStartIndex'],
            is_numeric($repositoryFields['afterMessageIndex'] ?? null) ? (int)$repositoryFields['afterMessageIndex'] : -1,
            is_array($repositoryFields['names'] ?? null) ? array_filter($repositoryFields['names'], 'is_scalar') : []
        );
    }
}
