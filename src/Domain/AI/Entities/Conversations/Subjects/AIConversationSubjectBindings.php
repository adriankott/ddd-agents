<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Subjects;

use DDD\Domain\AI\Repo\DB\Conversations\Subjects\DBAIConversationSubjectBindings;
use DDD\Domain\AI\Services\Conversations\AIConversationSubjectBindingsService;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * @method AIConversationSubjectBinding|null first()
 * @method AIConversationSubjectBinding[] getElements()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBAIConversationSubjectBindings::class)]
class AIConversationSubjectBindings extends EntitySet
{
    use QueryOptionsTrait;

    /** @var string The service lives under Services/Conversations/ */
    public const string SERVICE_NAME = AIConversationSubjectBindingsService::class;
}
