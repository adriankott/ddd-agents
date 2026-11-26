<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Memories;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentClock;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Texts\Embeddings\TextEmbedding;
use DDD\Domain\AI\Repo\DB\Conversations\Memories\DBAgentMemory;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoryStoreService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\Base\Repo\DB\Database\DatabaseVirtualColumn;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Entities\MathEntities\Vector;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;
use DDD\Infrastructure\Validation\Constraints\Choice;
use DateTimeZone;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * A durable memory of the owner that holds across conversations: a standing directive for the agent, a soft fact about
 * the owner or the business, or a reusable approach. It belongs to an account; a memory about the business of one space
 * also names that space, a memory about the owner as a person names none (it holds in every space of the account).
 *
 * Bitemporal: a newer value closes the old row ({@see $validTo}, {@see $invalidatedAt}, {@see $supersededByMemoryId})
 * instead of overwriting it, so the history stays readable; the agent's paths never delete a row.
 *
 * {@see $embedding} is the key of the similarity search; a row the embedding provider could not embed keeps the zero
 * vector and is left out of that search until the embedding repair covers it.
 *
 * At most one CURRENT row per scope and content: the unique index on the generated column
 * {@see $currentDedupKey} (the scope and the content hash while the row is current, NULL afterwards).
 *
 * The carrier of the memory port ({@see AgentMemoryStoreService}): a host whose memories live in rows of its own fills
 * it from those rows — `id` = the host row's id, `accountId` / `agentSpaceId` the scope, `memoryType`, `content`,
 * `validFrom` / `validTo` the validity — and is never written through the package's table.
 *
 * @property AgentMemories $parent
 * @method AgentMemories getParent()
 * @method static AgentMemoryStoreService getService()
 */
#[QueryOptions]
#[DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['virtualCurrentDedupKey'])]
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentMemory::class)]
class AgentMemory extends Entity implements LlmMarkdownRenderable
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    /** A standing rule for how the agent works for the owner, injected into every conversation of its scope. */
    public const string MEMORY_TYPE_DIRECTIVE = 'DIRECTIVE';

    /** A soft fact about the owner or the business, searched on demand. */
    public const string MEMORY_TYPE_FACT = 'FACT';

    /** A reusable approach of the owner ("problem X → approach Y"), searched on demand. */
    public const string MEMORY_TYPE_SOLUTION = 'SOLUTION';

    /** @var string[] Every memory type */
    public const array MEMORY_TYPES = [self::MEMORY_TYPE_DIRECTIVE, self::MEMORY_TYPE_FACT, self::MEMORY_TYPE_SOLUTION];

    /** The owner stated it in a conversation (the highest priority when two values disagree). */
    public const string SOURCE_USER_STATED = 'USER_STATED';

    /** The agent inferred it. */
    public const string SOURCE_AGENT_INFERRED = 'AGENT_INFERRED';

    /** An administrator configured it. */
    public const string SOURCE_ADMIN_CONFIG = 'ADMIN_CONFIG';

    /** The owner entered or edited it by hand in a screen of the application (no source message). */
    public const string SOURCE_USER_MANUAL = 'USER_MANUAL';

    /** The memory is about the owner as a person: it belongs to the account and holds in every space. */
    public const string SUBJECT_USER = 'USER';

    /** The memory is about the business: it belongs to the space of the conversation it was said in. */
    public const string SUBJECT_BUSINESS = 'BUSINESS';

    /** The scope prefix of an account-level memory in {@see $scopeKey}. */
    public const string SCOPE_KEY_PREFIX_ACCOUNT = 'a:';

    /** The scope prefix of a space memory in {@see $scopeKey}. */
    public const string SCOPE_KEY_PREFIX_AGENT_SPACE = 's:';

    /** @var int The longest content stored; a longer one is cut (the hash, the embedding and every prompt see the cut text) */
    public const int MAX_CONTENT_CHARS = 1000;

    /** @var int|null The owning account: the read-rights anchor; set on every row */
    #[NotNull]
    public ?int $accountId;

    /** @var Account|null The owning account */
    #[LazyLoad(addAsParent: true)]
    public ?Account $account;

    /**
     * @var int|null The space a memory about the business belongs to; empty = account level (a memory about the owner,
     * or a memory said in a conversation without a space). Read "for a space" means this space's rows plus the
     * account-level rows.
     */
    public ?int $agentSpaceId = null;

    /** @var AgentSpace|null The space; deleting the space deletes its memories */
    #[LazyLoad(repoType: LazyLoadRepo::DB, addAsParent: true)]
    public ?AgentSpace $agentSpace;

    /** @var string The kind of memory: DIRECTIVE, FACT or SOLUTION */
    #[Length(max: 20)]
    #[Choice(choices: self::MEMORY_TYPES)]
    public string $memoryType = self::MEMORY_TYPE_FACT;

    /** @var string Whether the memory is about the owner as a person (USER) or about the business (BUSINESS) */
    #[Length(max: 20)]
    #[Choice(choices: [self::SUBJECT_USER, self::SUBJECT_BUSINESS])]
    public string $subject = self::SUBJECT_BUSINESS;

    /** @var string The memory text; never filtered on, so without an index */
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_TEXT)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public string $content;

    /** @var TextEmbedding The embedding of {@see $content}, the key of the similarity search; the zero vector when none was generated */
    #[DatabaseColumn(vectorDimensions: Vector::DIMENSION_OPENAI_EMBEDDING_SMALL)]
    #[HideProperty]
    public TextEmbedding $embedding;

    /** @var string Who asserted the memory: USER_STATED, AGENT_INFERRED, ADMIN_CONFIG or USER_MANUAL */
    #[Length(max: 20)]
    #[Choice(choices: [self::SOURCE_USER_STATED, self::SOURCE_AGENT_INFERRED, self::SOURCE_ADMIN_CONFIG, self::SOURCE_USER_MANUAL])]
    public string $source = self::SOURCE_USER_STATED;

    /** @var string|null md5 of the normalized content ({@see hashForContent()}): the exact-duplicate check before any model call */
    #[Length(max: 32)]
    public ?string $contentHash = null;

    /** @var DateTime|null When the memory became true in the world (the creation time when nobody said) */
    public ?DateTime $validFrom = null;

    /** @var DateTime|null When the memory stopped being true; empty = currently valid */
    public ?DateTime $validTo = null;

    /** @var DateTime|null When the store closed the memory (superseded or forgotten) */
    public ?DateTime $invalidatedAt = null;

    /** @var int|null The memory that replaced this one */
    public ?int $supersededByMemoryId = null;

    /**
     * @var string|null The scope the dedup key is built from: `s:<agentSpaceId>` or `a:<accountId>`
     * ({@see deriveScopeKey()}). A plain column without a foreign key: a generated column may not read a column a
     * foreign key points from, so {@see $currentDedupKey} reads this one instead of the scope ids.
     */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[Length(max: 40)]
    public ?string $scopeKey = null;

    /**
     * @var string|null Generated column `virtualCurrentDedupKey`: `scopeKey|contentHash` while the row is current, NULL
     * once it is closed. Its unique index keeps one current row per scope and content while closed duplicates stay as
     * history. Never written by PHP.
     */
    #[DatabaseColumn(ignoreProperty: true)]
    #[DatabaseVirtualColumn(
        as: "(CASE WHEN validTo IS NULL AND invalidatedAt IS NULL THEN CONCAT_WS('|', scopeKey, contentHash) ELSE NULL END)",
        asPostgres: '(CASE WHEN "validTo" IS NULL AND "invalidatedAt" IS NULL THEN "scopeKey" || \'|\' || "contentHash" ELSE NULL END)'
    )]
    public ?string $currentDedupKey = null;

    /** @var int|null The conversation message the memory was said in */
    public ?int $sourceMessageId = null;

    /** @var DateTime|null When the source message was sent; empty for a memory entered by hand */
    public ?DateTime $sourceInstant = null;

    /** @var string|null The time zone the conversation was read in when the memory was said; empty = unknown */
    #[Length(max: 64)]
    public ?string $sourceTimezone = null;

    /**
     * A memory the owner just stated (the `remember` tool): its content, the type (FACT when none is given) and the
     * source (USER_STATED when none is given). The scope and the provenance are set by the caller.
     *
     * @param string $content
     * @param string|null $memoryType
     * @param string|null $source
     * @return static
     */
    public static function buildStated(string $content, ?string $memoryType, ?string $source): static
    {
        $agentMemory = static::newInstance();
        $agentMemory->content = $content;
        $agentMemory->memoryType = $memoryType ?? self::MEMORY_TYPE_FACT;
        $agentMemory->source = $source ?? self::SOURCE_USER_STATED;
        return $agentMemory;
    }

    /**
     * Stamps where the memory was said: the owner message the call traces back to, its send time and the zone the call
     * reads time in. All three stay empty without a conversation (a call over HTTP).
     *
     * @param AgentToolCallProvenance $callProvenance
     * @return void
     */
    public function stampSourceFrom(AgentToolCallProvenance $callProvenance): void
    {
        $this->sourceMessageId = $callProvenance->getSourceUserMessageId();
        $this->sourceInstant = $callProvenance->sourceUserMessage?->getCreatedTime();
        $this->sourceTimezone = $callProvenance->getTimezone()?->getName();
    }

    /**
     * Binds the memory to the account and, for a memory of a space, to that space.
     *
     * @param int $accountId
     * @param int|null $agentSpaceId Empty = account level
     * @return void
     */
    public function anchorToScope(int $accountId, ?int $agentSpaceId): void
    {
        $this->accountId = $accountId;
        $this->agentSpaceId = $agentSpaceId;
    }

    /**
     * Edits the memory in place: a non-empty content and a non-empty type replace the stored ones. The provenance (where
     * and when it was said) stays.
     *
     * @param string|null $content
     * @param string|null $memoryType
     * @return void
     */
    public function applyEdit(?string $content, ?string $memoryType): void
    {
        if ($content !== null && $content !== '') {
            $this->content = $content;
        }
        if ($memoryType !== null && $memoryType !== '') {
            $this->memoryType = $memoryType;
        }
    }

    /**
     * Whether the memory is the current version: neither closed nor invalidated. A set {@see $validTo} counts as closed
     * whatever moment it names, as in the store's queries.
     *
     * @return bool
     */
    public function isCurrentlyValid(): bool
    {
        return !isset($this->validTo) && !isset($this->invalidatedAt);
    }

    /**
     * Whether the memory belongs to one space (a memory about the business of that space).
     *
     * @return bool
     */
    public function isAgentSpaceBound(): bool
    {
        return isset($this->agentSpaceId) && $this->agentSpaceId;
    }

    /**
     * Marks the memory as entered or edited by hand in a screen of the application. The provenance stays: it records
     * where and when the fact was first said, which a change of the wording does not move.
     *
     * @return void
     */
    public function markUserCurated(): void
    {
        $this->source = self::SOURCE_USER_MANUAL;
    }

    /**
     * Closes the memory because `$successor` replaces it: the validity ends when the successor became true, and the
     * chain points at the successor (empty while the successor has no id).
     *
     * @param AgentMemory $successor
     * @param DateTime|null $invalidatedAt
     * @return void
     */
    public function supersedeBy(AgentMemory $successor, ?DateTime $invalidatedAt = null): void
    {
        $invalidationTime = $invalidatedAt ?? new DateTime('now');
        $this->validTo = $successor->validFrom ?? $invalidationTime;
        $this->invalidatedAt = $invalidationTime;
        $this->supersededByMemoryId = $successor->id ?? null;
    }

    /**
     * The age of the memory for a prompt, `since YYYY-MM-DD (Nd ago)`, or null without {@see $validFrom}. The day is read
     * in `$timezone` when given, else in the zone of the running tool call ({@see AgentClock::formatDay()}).
     *
     * @param DateTimeZone|null $timezone
     * @return string|null
     */
    public function getValidFromAgeDescription(?DateTimeZone $timezone = null): ?string
    {
        if (!isset($this->validFrom)) {
            return null;
        }
        $validFromDay = $timezone !== null
            ? (clone $this->validFrom)->setTimezone($timezone)->format('Y-m-d')
            : AgentClock::formatDay($this->validFrom);
        $ageSeconds = (new DateTime('now'))->getTimestamp() - $this->validFrom->getTimestamp();
        $ageDays = (int)floor($ageSeconds / 86400);
        return sprintf('since %s (%dd ago)', $validFromDay, max(0, $ageDays));
    }

    /**
     * One line for the model: `#<id>` first (the id `updateMemory` and `forget` take), then the type and scope, the
     * content, the source and the age.
     *
     * @return string
     */
    public function toLlmMarkdown(): string
    {
        $idLabel = isset($this->id) ? '#' . $this->id : '#?';
        $scope = $this->isAgentSpaceBound() ? $this->buildAgentSpaceLabel() : 'account';
        $ageDescription = $this->getValidFromAgeDescription();
        $meta = $this->source . ($ageDescription !== null ? '; ' . $ageDescription : '');
        return sprintf('- %s [%s/%s] "%s" (%s)', $idLabel, $this->memoryType, $scope, $this->content, $meta);
    }

    /**
     * How the line names the space of a space-bound memory: the application's label of the space
     * ({@see AgentSpacesService::buildAgentSpaceLabelForTheModel()}, default `space:<id>`).
     *
     * @return string
     */
    protected function buildAgentSpaceLabel(): string
    {
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        return $agentSpacesService->buildAgentSpaceLabelForTheModel((int)$this->agentSpaceId);
    }

    /**
     * The scope the dedup key is built from: `s:<agentSpaceId>` for a memory of a space, else `a:<accountId>`; null when
     * neither is set (a row no write path stores).
     *
     * @return string|null
     */
    public function deriveScopeKey(): ?string
    {
        if ($this->isAgentSpaceBound()) {
            return self::SCOPE_KEY_PREFIX_AGENT_SPACE . $this->agentSpaceId;
        }
        if (isset($this->accountId) && $this->accountId) {
            return self::SCOPE_KEY_PREFIX_ACCOUNT . $this->accountId;
        }
        return null;
    }

    /**
     * Cuts the content to {@see MAX_CONTENT_CHARS} and stamps the scope key and the content hash: what every write does
     * before the row is embedded and stored.
     *
     * @return void
     */
    public function prepareForPersistence(): void
    {
        if (mb_strlen($this->content) > self::MAX_CONTENT_CHARS) {
            $this->content = rtrim(mb_substr($this->content, 0, self::MAX_CONTENT_CHARS)) . '…';
        }
        $this->scopeKey = $this->deriveScopeKey();
        $this->contentHash = static::hashForContent($this->content);
    }

    /**
     * The md5 of the normalized content (trimmed, lower case, whitespace collapsed): stored as {@see $contentHash} and
     * used to find an exact duplicate before any model call.
     *
     * @param string $content
     * @return string
     */
    public static function hashForContent(string $content): string
    {
        $normalizedContent = mb_strtolower(trim($content));
        $normalizedContent = (string)preg_replace('/\s+/u', ' ', $normalizedContent);
        return md5($normalizedContent);
    }
}
