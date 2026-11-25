<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Subjects;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Repo\DB\Conversations\Subjects\DBAIConversationSubjectBinding;
use DDD\Domain\AI\Services\Conversations\AIConversationSubjectBindingsService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseForeignKey;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * The subject of one purposed conversation ({@see AIConversationSubject}), one row per conversation in its own table
 * `AIConversationSubjectBindings`. It is not a column of `AIConversations`: every conversation read selects all
 * columns of that table, so a new column there would break every read of a host that has not run the DDL yet. Here
 * a missing table only means "no subjects": {@see AIConversationSubjectBindingsService::isBindingTableAvailable()}.
 *
 * Space and purpose are copied from the conversation at creation (never changed), so the reuse lookup reads this
 * table alone on its composite index.
 */
// the reuse lookup: space + purpose + subject, newest first
#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['agentSpaceId', 'purposeName', 'subjectEntityClass', 'subjectEntityId'])]
// one subject per conversation
#[DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['aiConversationId'])]
#[LazyLoadRepo(LazyLoadRepo::DB, DBAIConversationSubjectBinding::class)]
class AIConversationSubjectBinding extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    /** @var string The service lives under Services/Conversations/ */
    public const string SERVICE_NAME = AIConversationSubjectBindingsService::class;

    /** @var int|null The purposed conversation */
    #[NotNull]
    public ?int $aiConversationId;

    /** @var int|null The space of the conversation (purposed conversations with a subject always have one) */
    #[NotNull]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $agentSpaceId;

    /** @var string|null The purpose of the conversation */
    #[NotNull]
    #[Length(max: 255)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $purposeName;

    /** @var string|null {@see AIConversationSubject::$subjectEntityClass}, without a leading backslash */
    #[NotNull]
    #[Length(max: 255)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $subjectEntityClass;

    /** @var int|null {@see AIConversationSubject::$subjectEntityId} */
    #[NotNull]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $subjectEntityId;

    #[DatabaseForeignKey(onDeleteAction: DatabaseForeignKey::ACTION_CASCADE, onUpdateAction: DatabaseForeignKey::ACTION_CASCADE)]
    #[LazyLoad(repoType: LazyLoadRepo::DB)]
    public ?AIConversation $aiConversation;

    /** The subject this row stores. */
    public function toSubject(): AIConversationSubject
    {
        return AIConversationSubject::of((string)$this->subjectEntityClass, (int)$this->subjectEntityId);
    }
}
