<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Subjects;

use DDD\Domain\AI\Entities\Conversations\Subjects\AIConversationSubject;
use DDD\Domain\AI\Entities\Conversations\Subjects\AIConversationSubjectDescription;
use DDD\Domain\Base\Entities\Entity;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Throwable;

/**
 * The port through which an application says what the record of a bound conversation is: one describer per entity
 * class (or one for several). Every service implementing it is collected by its tag; the first describer in
 * container order that names the record's class (or a parent class of it) answers.
 *
 * The answer is stored on the conversation's working state when it is bound and the model reads it in every call of that
 * conversation (the system segment `CONVERSATION_SUBJECT`), so a reopened conversation still knows its record. A
 * describer therefore answers what stays true for the record's life (its kind, id, name, what it belongs to) and
 * leaves the current state to the tools. It reads only; the caller's read rights are in force and the record was
 * read under them.
 */
#[AutoconfigureTag(AIConversationSubjectDescriber::TAG)]
interface AIConversationSubjectDescriber
{
    public const string TAG = 'ai.conversation_subject_describer';

    /** @return string[] The entity classes this describer answers for, fully qualified (a subclass of a named class is answered too) */
    public function getSubjectEntityClasses(): array;

    /**
     * What `$subjectEntity` is, or null when this describer has nothing to say about it (the next describer is asked).
     *
     * @param AIConversationSubject $aIConversationSubject the class and id the conversation is bound to
     * @param Entity $subjectEntity the record, read under the caller's rights
     * @return AIConversationSubjectDescription|null
     * @throws Throwable a failure is logged and the conversation is bound without a description
     */
    public function describeSubject(AIConversationSubject $aIConversationSubject, Entity $subjectEntity): ?AIConversationSubjectDescription;
}
