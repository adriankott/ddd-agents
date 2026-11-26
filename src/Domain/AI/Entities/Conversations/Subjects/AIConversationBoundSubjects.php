<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Subjects;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The records a set of conversations is bound to, one element per bound conversation
 * ({@see AIConversationBoundSubject::uniqueKey()}): what a client reads to name the record of every conversation of a
 * list with one request.
 *
 * @property AIConversationBoundSubject[] $elements
 * @method AIConversationBoundSubject[] getElements()
 * @method AIConversationBoundSubject|null first()
 * @method AIConversationBoundSubject|null getByUniqueKey(string $uniqueKey)
 */
class AIConversationBoundSubjects extends ObjectSet
{
    /**
     * @param int $aiConversationId
     * @return AIConversationBoundSubject|null The record the conversation is bound to; null when the set has none for it
     */
    public function findSubjectOfConversation(int $aiConversationId): ?AIConversationBoundSubject
    {
        return $this->getByUniqueKey(AIConversationBoundSubject::uniqueKeyOfConversation($aiConversationId));
    }
}
