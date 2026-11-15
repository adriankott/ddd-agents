<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNodes;

/**
 * A stored conversation whose compaction node tree is the set the test scripted (read without a database), and which
 * counts the reads of its lazily loaded `messages` relation: a resumed chat must never read it.
 */
class ConversationWithScriptedCompactionNodesDouble extends AIConversation
{
    /** @var AIConversationCompactionNodes|null The node tree the read answers */
    public ?AIConversationCompactionNodes $scriptedCompactionNodes = null;

    /** @var int How often the lazy `messages` relation was asked to load */
    public int $lazyMessagesLoadCount = 0;

    public function findCompactionNodesInOrder(): ?AIConversationCompactionNodes
    {
        return $this->scriptedCompactionNodes;
    }

    /**
     * Counts a read of the `messages` relation before it was set and answers no rows; every other property loads as
     * usual.
     *
     * @param string $propertyName
     * @return mixed
     */
    public function __get(string $propertyName)
    {
        if ($propertyName === 'messages') {
            $this->lazyMessagesLoadCount++;
            return null;
        }
        return parent::__get($propertyName);
    }
}
