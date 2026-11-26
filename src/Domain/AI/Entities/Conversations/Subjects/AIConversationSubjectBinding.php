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
 * The subject of one conversation ({@see AIConversationSubject}), one row per conversation in its own table
 * `AIConversationSubjectBindings`. It is not a column of `AIConversations`: every conversation read selects all
 * columns of that table, so a new column there would break every read of a host that has not run the DDL yet. Here
 * a missing table only means "no subjects": {@see AIConversationSubjectBindingsService::isBindingTableAvailable()}.
 *
 * Two kinds of conversation carry a subject:
 * - a PURPOSED conversation started for one record (`purposeName` set): one open conversation per space, purpose and
 *   record, found on the composite index;
 * - a FREE conversation started about one record (`purposeName` null): any number per record, listed newest first
 *   on the subject index ({@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::findAllVisibleToCustomerOfSubject()}).
 *
 * Space and purpose are copied from the conversation at creation (never changed); `agentSpaceId` is null for a
 * conversation at account level. What the application says about the record (its name, one line for the model, its
 * link) is not stored here but on the conversation's working state
 * ({@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState::$subjectDescription}), so this table needs no
 * further column and the model's text is loaded with the conversation.
 */
// the reuse lookup of a purposed start: space + purpose + subject, newest first
#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['agentSpaceId', 'purposeName', 'subjectEntityClass', 'subjectEntityId'])]
// the conversations of one record, across spaces and purposes
#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['subjectEntityClass', 'subjectEntityId'])]
// one subject per conversation
#[DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['aiConversationId'])]
#[LazyLoadRepo(LazyLoadRepo::DB, DBAIConversationSubjectBinding::class)]
class AIConversationSubjectBinding extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    /** @var string The service lives under Services/Conversations/ */
    public const string SERVICE_NAME = AIConversationSubjectBindingsService::class;

    /** @var int|null The conversation */
    #[NotNull]
    public ?int $aiConversationId;

    /** @var int|null The space of the conversation; null for a conversation at account level */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $agentSpaceId = null;

    /** @var string|null The purpose of a purposed conversation; null for a free conversation about the record */
    #[Length(max: 255)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $purposeName = null;

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
