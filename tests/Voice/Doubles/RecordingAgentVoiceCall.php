<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Doubles;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\Base\Repo\DB\DBEntity;
use RuntimeException;

/**
 * A call whose writes are recorded instead of persisted: each {@see update()} and
 * {@see updatePartialIgnoringRights()} records the columns it would write and whether the entity rights applied at that
 * moment. With {@see $writeFailureMessage} set, every write throws.
 */
class RecordingAgentVoiceCall extends AgentVoiceCall
{
    /** @var array<int, array{write: string, propertyNames: string[], rightsApplied: bool}> The writes, in order */
    public array $recordedWrites = [];

    /** @var int|null The id a full write gives the call (a new row) */
    public ?int $idAssignedByFullWrite = null;

    /** @var string|null When set, every write throws a RuntimeException with this message */
    public ?string $writeFailureMessage = null;

    public function update(int $depth = 2): ?static
    {
        $this->recordedWrites[] = ['write' => 'update', 'propertyNames' => [], 'rightsApplied' => DBEntity::getApplyRightsRestrictions()];
        if ($this->writeFailureMessage !== null) {
            throw new RuntimeException($this->writeFailureMessage);
        }
        if ($this->idAssignedByFullWrite !== null) {
            $this->id = $this->idAssignedByFullWrite;
        }
        return $this;
    }

    public function updatePartialIgnoringRights(string ...$propertyNames): static
    {
        $this->recordedWrites[] = ['write' => 'updatePartialIgnoringRights', 'propertyNames' => $propertyNames, 'rightsApplied' => DBEntity::getApplyRightsRestrictions()];
        if ($this->writeFailureMessage !== null) {
            throw new RuntimeException($this->writeFailureMessage);
        }
        return $this;
    }
}
