<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Conversations\Subjects;

use DDD\Domain\AI\Entities\Conversations\Subjects\AIConversationSubjectBindings;
use DDD\Domain\Base\Repo\DB\Attributes\EntityCache;
use DDD\Domain\Base\Repo\DB\DBEntitySet;

#[EntityCache(useExtendedRegistryCache: false, ttl: 0)]
class DBAIConversationSubjectBindings extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBAIConversationSubjectBinding::class;
    public const string BASE_ENTITY_SET_CLASS = AIConversationSubjectBindings::class;
}
