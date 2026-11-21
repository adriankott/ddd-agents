<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Memories\Curation;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Operations of the memory curation, in the order the model wrote them. Keyed by object: two equal operations (one
 * INVALIDATE per neighbour that carries the same new value) are both kept.
 *
 * @property AgentMemoryOperation[] $elements
 * @method AgentMemoryOperation|null first()
 * @method AgentMemoryOperation[] getElements()
 */
class AgentMemoryOperations extends ObjectSet
{
    /**
     * The operations that carry a content.
     *
     * @return AgentMemoryOperations
     */
    public function getOperationsWithContent(): AgentMemoryOperations
    {
        $operationsWithContent = new AgentMemoryOperations();
        foreach ($this->getElements() as $agentMemoryOperation) {
            if ($agentMemoryOperation->hasContent()) {
                $operationsWithContent->add($agentMemoryOperation);
            }
        }
        return $operationsWithContent;
    }

    /**
     * The operations whose {@see AgentMemoryOperation::$resolvesConflict} equals `$resolvesConflict`.
     *
     * @param bool $resolvesConflict
     * @return AgentMemoryOperations
     */
    public function getOperationsResolvingConflict(bool $resolvesConflict): AgentMemoryOperations
    {
        $matchingOperations = new AgentMemoryOperations();
        foreach ($this->getElements() as $agentMemoryOperation) {
            if ($agentMemoryOperation->resolvesConflict === $resolvesConflict) {
                $matchingOperations->add($agentMemoryOperation);
            }
        }
        return $matchingOperations;
    }

    /**
     * Whether one of the operations is of `$memoryType`.
     *
     * @param string $memoryType
     * @return bool
     */
    public function containsMemoryType(string $memoryType): bool
    {
        foreach ($this->getElements() as $agentMemoryOperation) {
            if ($agentMemoryOperation->memoryType === $memoryType) {
                return true;
            }
        }
        return false;
    }
}
