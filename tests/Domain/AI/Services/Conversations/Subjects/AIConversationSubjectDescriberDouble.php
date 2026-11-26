<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Subjects;

use DDD\Domain\AI\Entities\Conversations\Subjects\AIConversationSubject;
use DDD\Domain\AI\Entities\Conversations\Subjects\AIConversationSubjectDescription;
use DDD\Domain\AI\Services\Conversations\Subjects\AIConversationSubjectDescriber;
use DDD\Domain\Base\Entities\Entity;
use RuntimeException;

/** An application's describer for the classes a test names: a fixed answer, none, or a failure; it counts its calls. */
class AIConversationSubjectDescriberDouble implements AIConversationSubjectDescriber
{
    public int $describeCalls = 0;

    public bool $fails = false;

    /** @param string[] $subjectEntityClasses */
    public function __construct(
        public array $subjectEntityClasses,
        public ?AIConversationSubjectDescription $description = null
    ) {
    }

    public function getSubjectEntityClasses(): array
    {
        return $this->subjectEntityClasses;
    }

    public function describeSubject(AIConversationSubject $aIConversationSubject, Entity $subjectEntity): ?AIConversationSubjectDescription
    {
        $this->describeCalls++;
        if ($this->fails) {
            throw new RuntimeException('the record store is down');
        }
        return $this->description;
    }
}
