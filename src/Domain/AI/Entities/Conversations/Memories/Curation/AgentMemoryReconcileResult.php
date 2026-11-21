<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Memories\Curation;

use DDD\Domain\AI\Entities\Conversations\Memories\AgentMemories;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * What one curation of a conversation did: the memories it wrote, the contradictions it left for the owner, every
 * operation the reconciliation decided and the neighbours it decided against (in the order of their `REF_<n>` labels).
 */
class AgentMemoryReconcileResult extends ValueObject
{
    /** @var AgentMemories The memories written (new rows and existing rows a superseded one now points at) */
    public AgentMemories $applied;

    /** @var AgentMemoryOperations The CONFLICT operations: raised to the owner, nothing stored */
    public AgentMemoryOperations $conflicts;

    /** @var AgentMemoryOperations Every operation of the reconciliation */
    public AgentMemoryOperations $operations;

    /** @var AgentMemories The neighbours in the order of their `REF_<n>` labels */
    public AgentMemories $orderedNeighbours;

    public function __construct()
    {
        parent::__construct();
        $this->applied = AgentMemories::newInstance();
        $this->conflicts = new AgentMemoryOperations();
        $this->operations = new AgentMemoryOperations();
        $this->orderedNeighbours = AgentMemories::newInstance();
    }
}
