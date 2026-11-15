<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\Base\Repo\DatabaseRepoEntity;

/**
 * A stored conversation that records the writes asked of it instead of reaching a database: each full update with its
 * depth, each targeted write with its column names, in order. Its compaction tree and the reads of its lazy `messages`
 * relation are those of {@see ConversationWithScriptedCompactionNodesDouble}.
 */
class StoredConversationRecordingWritesDouble extends ConversationWithScriptedCompactionNodesDouble
{
    /** @var string[] The writes in order: `update(<depth>)` for a full update, the column names of a targeted write */
    public array $recordedWrites = [];

    public function update(int $depth = DatabaseRepoEntity::UPDATE_DEFAULT_RECURSIVE_DEPTH): ?static
    {
        $this->recordedWrites[] = 'update(' . $depth . ')';
        return $this;
    }

    public function updatePartialIgnoringRights(string ...$propertyNames): static
    {
        $this->recordedWrites[] = implode(',', $propertyNames);
        return $this;
    }
}
