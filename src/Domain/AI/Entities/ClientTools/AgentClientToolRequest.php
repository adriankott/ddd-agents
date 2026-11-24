<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\ClientTools;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Repo\DB\ClientTools\DBAgentClientToolRequest;
use DDD\Domain\AI\Services\ClientTools\AgentClientToolRequestsService;
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
use DDD\Infrastructure\Validation\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * A request of a tool to the user's client (a browser tab, an app) and its answer, the channel of `Agent.UI`: a tool
 * stores it and waits; the client receives it with its run-state read, executes it once and posts the answer; the tool
 * reads the answer and blanks it. The row stays as the record of the call (type, arguments, client,
 * outcome, timing, details) for {@see self::KEPT_FOR_DAYS} days; the screen text itself never stays
 * ({@see AgentClientToolRequestsService::blankAnswerText()}).
 *
 * Rights (DBAgentClientToolRequest): an Account reads and writes only its own rows, and only while it holds the right
 * {@see \DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRights::USE_UI}; nobody deletes a row with
 * own rights (the purge command does).
 *
 * @method static AgentClientToolRequestsService getService()
 * @method static DBAgentClientToolRequest getRepoClassInstance(string $repoType = null)
 */
#[QueryOptions]
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentClientToolRequest::class)]
#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['conversationId', 'targetClientId', 'status'])]
#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['status', 'expiresAt'])]
class AgentClientToolRequest extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    /** @var string Read the screen as Markdown */
    public const string TYPE_READ_SCREEN = 'READ_SCREEN';

    /** @var string List the navigation targets */
    public const string TYPE_LIST_TARGETS = 'LIST_TARGETS';

    /** @var string Navigate to a target */
    public const string TYPE_OPEN_TARGET = 'OPEN_TARGET';

    /** @var string Mark elements on the screen */
    public const string TYPE_HIGHLIGHT = 'HIGHLIGHT';

    public const array TYPES = [self::TYPE_READ_SCREEN, self::TYPE_LIST_TARGETS, self::TYPE_OPEN_TARGET, self::TYPE_HIGHLIGHT];

    /** @var string Stored, not yet received by the client */
    public const string STATUS_OPEN = 'OPEN';

    /** @var string Received by the client with a run-state read */
    public const string STATUS_DELIVERED = 'DELIVERED';

    /** @var string The client answered and did what was asked */
    public const string STATUS_ANSWERED = 'ANSWERED';

    /** @var string The client answered that it did not do it (unsaved changes, unknown target, …) */
    public const string STATUS_REFUSED = 'REFUSED';

    /** @var string The tool stopped waiting before an answer came */
    public const string STATUS_EXPIRED = 'EXPIRED';

    public const array STATUSES = [self::STATUS_OPEN, self::STATUS_DELIVERED, self::STATUS_ANSWERED, self::STATUS_REFUSED, self::STATUS_EXPIRED];

    /** @var string The outcome of a request the client carried out */
    public const string OUTCOME_OK = 'OK';

    /** @var string The outcome of a request whose tool stopped waiting */
    public const string OUTCOME_NO_ANSWER = 'NO_ANSWER';

    /** @var int The longest arguments JSON a tool may store */
    public const int MAX_ARGUMENTS_JSON_CHARS = 2048;

    /** @var int The longest answer text the client may post */
    public const int MAX_ANSWER_TEXT_CHARS = 24000;

    /** @var int The longest details JSON the client may post */
    public const int MAX_ANSWER_DETAILS_JSON_CHARS = 1024;

    /** @var int The longest outcome code */
    public const int MAX_OUTCOME_CHARS = 48;

    /** @var int A request the client received but did not answer within this many seconds is delivered again */
    public const int REDELIVER_AFTER_SECONDS = 5;

    /** @var int How long a row is kept */
    public const int KEPT_FOR_DAYS = 30;

    /** @var int|null The conversation the calling tool runs in (the owner's thread) */
    #[NotNull]
    public ?int $conversationId;

    /** @var AIConversation|null */
    #[LazyLoad(repoType: LazyLoadRepo::DB)]
    #[DatabaseForeignKey(onDeleteAction: DatabaseForeignKey::ACTION_CASCADE)]
    public ?AIConversation $conversation;

    /** @var int|null The Account the tool runs for (the owner of the conversation) */
    #[NotNull]
    public ?int $accountId;

    /** @var Account|null */
    #[LazyLoad]
    #[DatabaseForeignKey(onDeleteAction: DatabaseForeignKey::ACTION_CASCADE)]
    public ?Account $account;

    /** @var string|null The provider's id of the tool call */
    #[Length(max: 64)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $toolCallId;

    /** @var string|null What the client is to do */
    #[NotNull]
    #[Choice(choices: self::TYPES)]
    #[Length(max: 24)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $requestType;

    /** @var string|null The arguments as one JSON object, at most {@see self::MAX_ARGUMENTS_JSON_CHARS} characters */
    #[NotNull]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_TEXT)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $argumentsJson;

    /** @var string|null The client that is to execute it (AgentClientAttachment::$clientId) */
    #[NotNull]
    #[Length(max: 64)]
    public ?string $targetClientId;

    /** @var string|null Where the request stands */
    #[NotNull]
    #[Choice(choices: self::STATUSES)]
    #[Length(max: 16)]
    public ?string $status;

    /** @var DateTime|null Until when the tool waits */
    #[NotNull]
    public ?DateTime $expiresAt;

    /** @var DateTime|null When the client received it last */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?DateTime $deliveredAt;

    /** @var DateTime|null When the answer came */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?DateTime $answeredAt;

    /** @var string|null The client's outcome code (`OK`, `UNSAVED_CHANGES`, …), or NO_ANSWER */
    #[Length(max: 48)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $outcome;

    /**
     * @var string|null The answer of the client (screen Markdown, a refusal sentence); held only until the waiting tool
     * read it, then null
     */
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_MEDIUMTEXT)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $answerText;

    /** @var int|null How many characters the answer text had (kept after the text is blanked) */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $answerChars;

    /** @var string|null Small facts of the answer as one flat JSON object (route before and after, title, counts); kept */
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_TEXT)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $answerDetailsJson;

    /**
     * @param \DateTimeInterface $now
     * @return int Whole seconds until the tool stops waiting; 0 once it has
     */
    public function getSecondsLeft(\DateTimeInterface $now): int
    {
        $expiresAt = $this->expiresAt ?? null;
        if ($expiresAt === null) {
            return 0;
        }
        return max(0, $expiresAt->getTimestamp() - $now->getTimestamp());
    }

    /**
     * @return bool Whether the client may still answer (received or not, not yet answered, not expired by the tool)
     */
    public function isAwaitingAnswer(): bool
    {
        return in_array($this->status ?? null, [self::STATUS_OPEN, self::STATUS_DELIVERED], true);
    }

    /**
     * One argument as stored: a string, an int, a bool, a list of strings, or null when the request carries none.
     *
     * @return string|int|bool|string[]|null
     */
    public function getArgument(string $argumentName): string|int|bool|array|null
    {
        $arguments = json_decode((string)($this->argumentsJson ?? '{}'), true);
        $argument = is_array($arguments) ? ($arguments[$argumentName] ?? null) : null;
        if (is_array($argument)) {
            return array_values(array_map('strval', array_filter($argument, 'is_scalar')));
        }
        return is_string($argument) || is_int($argument) || is_bool($argument) ? $argument : null;
    }

    /**
     * @param \DateTimeInterface $now
     * @return AgentClientToolRequestDelivery What the client receives
     */
    public function toDelivery(\DateTimeInterface $now): AgentClientToolRequestDelivery
    {
        $delivery = new AgentClientToolRequestDelivery();
        $delivery->id = (int)$this->id;
        $delivery->requestType = (string)$this->requestType;
        $delivery->argumentsJson = (string)($this->argumentsJson ?? '{}');
        $delivery->secondsLeft = $this->getSecondsLeft($now);
        return $delivery;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->id ?? spl_object_id($this));
    }
}
