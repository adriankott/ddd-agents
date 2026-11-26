<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Subjects;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The record one conversation is bound to, as a client reads it: the entity class and id of the binding
 * ({@see AIConversationSubjectBinding}), the purpose of a purposed conversation, and the name and link the
 * application's describer gave when the conversation was bound ({@see AIConversationSubjectDescription}). The line
 * for the model is not part of it.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationBoundSubject extends ValueObject
{
    /** @var int|null The conversation that is bound to the record; null for a record named without a conversation */
    public ?int $aiConversationId = null;

    /** @var string|null The fully qualified class of the record, without a leading backslash */
    public ?string $subjectEntityClass = null;

    /** @var int|null The id of the record */
    public ?int $subjectEntityId = null;

    /** @var string|null The purpose of a purposed conversation for the record; null for a free conversation about it */
    public ?string $purposeName = null;

    /** @var string|null The record's display name when the conversation was bound; data, never markup */
    public ?string $name = null;

    /** @var string|null Where the owner opens the record: an absolute URL or a path of the application */
    public ?string $link = null;

    /**
     * The bound subject of a binding and of what the conversation's working state stores about the record.
     *
     * @param AIConversationSubjectBinding $aIConversationSubjectBinding
     * @param AIConversationSubjectDescription|null $aIConversationSubjectDescription null when no describer answered
     * @return static
     */
    public static function of(AIConversationSubjectBinding $aIConversationSubjectBinding, ?AIConversationSubjectDescription $aIConversationSubjectDescription): static
    {
        $aIConversationBoundSubject = new static();
        $aIConversationBoundSubject->aiConversationId = $aIConversationSubjectBinding->aiConversationId ?? null;
        $aIConversationBoundSubject->subjectEntityClass = $aIConversationSubjectBinding->subjectEntityClass ?? null;
        $aIConversationBoundSubject->subjectEntityId = $aIConversationSubjectBinding->subjectEntityId ?? null;
        $aIConversationBoundSubject->purposeName = $aIConversationSubjectBinding->purposeName ?? null;
        $aIConversationBoundSubject->name = $aIConversationSubjectDescription?->name;
        $aIConversationBoundSubject->link = $aIConversationSubjectDescription?->link;
        return $aIConversationBoundSubject;
    }

    /**
     * A record before any conversation about it is named: its class and id, and the name and link the application's
     * describer gives now. Without a purpose.
     *
     * @param AIConversationSubject $aIConversationSubject
     * @param AIConversationSubjectDescription|null $aIConversationSubjectDescription null when no describer answered
     * @return static
     */
    public static function ofSubject(AIConversationSubject $aIConversationSubject, ?AIConversationSubjectDescription $aIConversationSubjectDescription): static
    {
        $aIConversationBoundSubject = new static();
        $aIConversationBoundSubject->subjectEntityClass = $aIConversationSubject->getNormalizedEntityClass() ?: null;
        $aIConversationBoundSubject->subjectEntityId = $aIConversationSubject->subjectEntityId ?? null;
        $aIConversationBoundSubject->name = $aIConversationSubjectDescription?->name;
        $aIConversationBoundSubject->link = $aIConversationSubjectDescription?->link;
        return $aIConversationBoundSubject;
    }

    /**
     * One element per conversation in a set ({@see AIConversationBoundSubjects}): a conversation is bound to one
     * record, so the conversation's id is the key. A record named without a conversation ({@see self::ofSubject()})
     * is keyed by its class and id. Built from the declaring class, so the element of a class an application derived
     * is found by the same key.
     *
     * @return string
     */
    public function uniqueKey(): string
    {
        if (($this->aiConversationId ?? null) !== null) {
            return self::uniqueKeyOfConversation($this->aiConversationId);
        }
        return self::class . '_' . ($this->subjectEntityClass ?? '') . '#' . ($this->subjectEntityId ?? '');
    }

    /**
     * @param int $aiConversationId
     * @return string The key of the element of this conversation in a set
     */
    public static function uniqueKeyOfConversation(int $aiConversationId): string
    {
        return self::class . '_conversation_' . $aiConversationId;
    }
}
