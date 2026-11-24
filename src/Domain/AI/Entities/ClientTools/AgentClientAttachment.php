<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\ClientTools;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Repo\DB\ClientTools\DBAgentClientAttachment;
use DDD\Domain\AI\Services\ClientTools\AgentClientAttachmentsService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Repo\DB\Database\DatabaseForeignKey;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Infrastructure\Base\DateTime\DateTime;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * One client (a browser tab, an app) that shows a conversation with the agent: stamped by the client's run-state read
 * and its messages, at most every few seconds, with the UI state it reported ({@see AgentUIState}). A conversation
 * counts as having an attached client while one of its rows was seen recently ({@see self::isFresh()}); a client tool
 * request is addressed to one of them, and the newest one's UI state is the `[SIGNAL:ui]` note of the model.
 *
 * Rights (DBAgentClientAttachment): an Account reads and writes only its own rows, and only while it holds the right
 * {@see \DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRights::USE_UI}; nobody deletes a row with
 * own rights (the purge command does).
 *
 * @method static AgentClientAttachmentsService getService()
 * @method static DBAgentClientAttachment getRepoClassInstance(string $repoType = null)
 */
#[QueryOptions]
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentClientAttachment::class)]
#[DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['conversationId', 'clientId'])]
class AgentClientAttachment extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    /** @var int A client not seen for this long counts as gone (the idle poll of the package's chat is 20 s) */
    public const int FRESH_FOR_SECONDS = 25;

    /** @var int A stamp is written again only after this many seconds, unless visibility or the UI state changed */
    public const int STAMP_THROTTLE_SECONDS = 10;

    /** @var string A client id: 8 to 64 letters, digits and `-` */
    public const string CLIENT_ID_PATTERN = '/^[A-Za-z0-9\-]{8,64}$/';

    /** @var int|null The conversation the client shows */
    #[NotNull]
    public ?int $conversationId;

    /** @var AIConversation|null */
    #[LazyLoad(repoType: LazyLoadRepo::DB)]
    #[DatabaseForeignKey(onDeleteAction: DatabaseForeignKey::ACTION_CASCADE)]
    public ?AIConversation $conversation;

    /** @var int|null The Account that read or sent (the owner of the conversation) */
    #[NotNull]
    public ?int $accountId;

    /** @var Account|null */
    #[LazyLoad]
    #[DatabaseForeignKey(onDeleteAction: DatabaseForeignKey::ACTION_CASCADE)]
    public ?Account $account;

    /** @var string|null The id of the client: random per mount of the agent window */
    #[NotNull]
    #[Length(max: 64)]
    public ?string $clientId;

    /** @var DateTime|null When the client read the run state or sent last */
    #[NotNull]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?DateTime $lastSeenAt;

    /** @var bool|null Whether the client was visible at its last read */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?bool $visible;

    /** @var string|null The route path the client showed at its last read */
    #[Length(max: 512)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $route;

    /** @var DateTime|null When the user last sent something to the conversation from this client */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?DateTime $lastOwnerSendAt;

    /** @var string|null The UI state the client reported last ({@see AgentUIState}, normalized), as JSON */
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_TEXT)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $uiStateJson;

    /**
     * @param string|null $clientId
     * @return bool Whether the value is a client id as the agent window makes them
     */
    public static function isValidClientId(?string $clientId): bool
    {
        return $clientId !== null && preg_match(static::CLIENT_ID_PATTERN, $clientId) === 1;
    }

    /** The UI state the client reported last; null when it reported none */
    public function getUIState(): ?AgentUIState
    {
        $uiStateJson = $this->uiStateJson ?? null;
        if ($uiStateJson === null || $uiStateJson === '') {
            return null;
        }
        // setPropertiesFromObject() takes its argument by reference: it needs a variable, never an expression
        $uiStateObject = json_decode($uiStateJson);
        if (!is_object($uiStateObject)) {
            return null;
        }
        $agentUIState = new AgentUIState();
        $agentUIState->setPropertiesFromObject($uiStateObject);
        return $agentUIState->normalize();
    }

    /**
     * @param \DateTimeInterface $now
     * @return bool Whether the client was seen within {@see self::FRESH_FOR_SECONDS}
     */
    public function isFresh(\DateTimeInterface $now): bool
    {
        $lastSeenAt = $this->lastSeenAt ?? null;
        return $lastSeenAt !== null && $now->getTimestamp() - $lastSeenAt->getTimestamp() <= static::FRESH_FOR_SECONDS;
    }

    /**
     * @param \DateTimeInterface $now
     * @param bool|null $visible
     * @param string|null $route
     * @param string|null $uiStateJson
     * @return bool Whether a new stamp must be written: the throttle passed, or visibility, route or UI state changed
     */
    public function needsStamp(\DateTimeInterface $now, ?bool $visible, ?string $route, ?string $uiStateJson = null): bool
    {
        $lastSeenAt = $this->lastSeenAt ?? null;
        $uiStateChanged = $uiStateJson !== null && ($this->uiStateJson ?? null) !== $uiStateJson;
        if ($lastSeenAt === null || ($this->visible ?? null) !== $visible || ($this->route ?? null) !== $route || $uiStateChanged) {
            return true;
        }
        return $now->getTimestamp() - $lastSeenAt->getTimestamp() >= static::STAMP_THROTTLE_SECONDS;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->id ?? spl_object_id($this));
    }
}
