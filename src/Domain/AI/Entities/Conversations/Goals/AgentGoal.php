<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Goals;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Repo\DB\Conversations\Goals\DBAgentGoal;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\Conversations\Goals\AgentGoalStoreService;
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
use DDD\Infrastructure\Base\DateTime\Date;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Validation\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * One goal of the goal register: what the owner wants to reach (an OWNER goal, held as the owner's belief: corrected or
 * dropped, never blocked) or what the agent commits to pursue in its service (an AGENT goal, with real work beneath it).
 * Four independent axes carry every kind of goal: {@see $holder} (whose), {@see $horizonLevel} (how far),
 * {@see $parentGoalId} (derived from which goal; one edge for decomposition and for an agent goal serving an owner goal)
 * and {@see $status} (the lifecycle).
 *
 * The scope is the memory store's: a goal belongs to an account; a goal of one space also names that space, a goal
 * without a space holds at account level. Read "for a space" means this space's goals plus the account-level goals.
 *
 * Bitemporal like a memory: a reworded or corrected goal is a new row and the old row is closed ({@see $validTo},
 * {@see $invalidatedAt}, {@see $supersededByGoalId}), so the history stays readable; the agent's paths never delete a
 * row. Progress is never stored: it is derived from the work beneath the goal.
 *
 * The rules of the lifecycle, each a method: the initial status follows the provenance ({@see deriveInitialStatus()}:
 * a goal the agent derived or learned waits for the owner's confirmation), a VISION never becomes ACHIEVED and only an
 * AGENT goal can be BLOCKED ({@see isStatusReachable()}), an OWNER goal never hangs under an AGENT goal
 * ({@see mayHaveParent()}), and the tree is at most {@see MAX_TREE_DEPTH} levels deep (the service walks the ancestors).
 *
 * At most one CURRENT row per scope and statement: the unique index on the generated column {@see $currentDedupKey}.
 *
 * The carrier of the goal port ({@see AgentGoalStoreService}): a host whose goals live in rows of its own fills it from
 * those rows (`id` = the host row's id, `accountId` / `agentSpaceId` the scope) and never writes the package's table.
 *
 * @property AgentGoals $parent
 * @method AgentGoals getParent()
 * @method static AgentGoalStoreService getService()
 */
#[QueryOptions]
#[DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['virtualCurrentDedupKey'])]
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentGoal::class)]
class AgentGoal extends Entity implements LlmMarkdownRenderable
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    /** What the owner wants: held as the owner's belief, corrected or dropped, never blocked. */
    public const string HOLDER_OWNER = 'OWNER';

    /** What the agent commits to pursue in service of an owner goal: it has work beneath it and can be blocked. */
    public const string HOLDER_AGENT = 'AGENT';

    /** @var string[] Every holder */
    public const array HOLDERS = [self::HOLDER_OWNER, self::HOLDER_AGENT];

    /** A direction that never completes ("be the bakery the street talks about"): it orients, it has no end. */
    public const string HORIZON_VISION = 'VISION';

    /** Completable over months or years ("a second shop in the old town"). */
    public const string HORIZON_LONG_TERM = 'LONG_TERM';

    /** Completable in weeks ("the summer menu is online this month"). */
    public const string HORIZON_SHORT_TERM = 'SHORT_TERM';

    /** @var string[] Every horizon */
    public const array HORIZONS = [self::HORIZON_VISION, self::HORIZON_LONG_TERM, self::HORIZON_SHORT_TERM];

    /** Waits for the owner's confirmation (a goal the agent derived or learned). */
    public const string STATUS_PROPOSED = 'PROPOSED';

    /** Held and pursued. */
    public const string STATUS_ACTIVE = 'ACTIVE';

    /** Reached; never for a VISION. */
    public const string STATUS_ACHIEVED = 'ACHIEVED';

    /** Given up by the owner or the agent. */
    public const string STATUS_DROPPED = 'DROPPED';

    /** Not pursued for a while. */
    public const string STATUS_PAUSED = 'PAUSED';

    /** An AGENT goal that cannot proceed; {@see $statusNote} names the blocker. */
    public const string STATUS_BLOCKED = 'BLOCKED';

    /** @var string[] Every status */
    public const array STATUSES = [
        self::STATUS_PROPOSED,
        self::STATUS_ACTIVE,
        self::STATUS_ACHIEVED,
        self::STATUS_DROPPED,
        self::STATUS_PAUSED,
        self::STATUS_BLOCKED,
    ];

    /** @var string[] The statuses of a goal that is still live work or orientation ({@see isHeld()}) */
    public const array HELD_STATUSES = [self::STATUS_ACTIVE, self::STATUS_PROPOSED, self::STATUS_BLOCKED];

    /** The owner stated it. */
    public const string PROVENANCE_USER_STATED = 'USER_STATED';

    /** The agent derived it; it starts PROPOSED. */
    public const string PROVENANCE_AGENT_DERIVED = 'AGENT_DERIVED';

    /** The agent derived it and the owner confirmed it. */
    public const string PROVENANCE_CONFIRMED = 'CONFIRMED';

    /** A standing default goal of the application; it starts ACTIVE. */
    public const string PROVENANCE_PRODUCT_DEFAULT = 'PRODUCT_DEFAULT';

    /** Learned from the owner's behaviour over time (less authority than anything stated); it starts PROPOSED. */
    public const string PROVENANCE_LEARNED = 'LEARNED';

    /** @var string[] Every provenance */
    public const array PROVENANCES = [
        self::PROVENANCE_USER_STATED,
        self::PROVENANCE_AGENT_DERIVED,
        self::PROVENANCE_CONFIRMED,
        self::PROVENANCE_PRODUCT_DEFAULT,
        self::PROVENANCE_LEARNED,
    ];

    /** The scope prefix of an account-level goal in {@see $scopeKey}. */
    public const string SCOPE_KEY_PREFIX_ACCOUNT = 'a:';

    /** The scope prefix of a goal of a space in {@see $scopeKey}. */
    public const string SCOPE_KEY_PREFIX_AGENT_SPACE = 's:';

    /** @var int The deepest goal tree (a root goal is level 1) */
    public const int MAX_TREE_DEPTH = 3;

    /** @var int The longest statement stored; a longer one is cut (the hash and every prompt see the cut text) */
    public const int MAX_STATEMENT_CHARS = 500;

    /** @var int The longest success signal and status note: one line each */
    public const int MAX_ONE_LINE_CHARS = 255;

    /** @var int|null The owning account: the read-rights anchor; set on every row */
    #[NotNull]
    public ?int $accountId;

    /** @var Account|null The owning account */
    #[LazyLoad(addAsParent: true)]
    public ?Account $account;

    /** @var int|null The space the goal belongs to; empty = account level */
    public ?int $agentSpaceId = null;

    /** @var AgentSpace|null The space; deleting the space deletes its goals */
    #[LazyLoad(repoType: LazyLoadRepo::DB, addAsParent: true)]
    public ?AgentSpace $agentSpace;

    /** @var string Whose goal it is: OWNER or AGENT */
    #[Length(max: 20)]
    #[Choice(choices: self::HOLDERS)]
    public string $holder = self::HOLDER_OWNER;

    /** @var string How far it reaches: VISION (never completes), LONG_TERM or SHORT_TERM */
    #[Length(max: 20)]
    #[Choice(choices: self::HORIZONS)]
    public string $horizonLevel = self::HORIZON_LONG_TERM;

    /** @var string The lifecycle: PROPOSED, ACTIVE, ACHIEVED, DROPPED, PAUSED or BLOCKED */
    #[Length(max: 20)]
    #[Choice(choices: self::STATUSES)]
    public string $status = self::STATUS_ACTIVE;

    /** @var string Who put it here: USER_STATED, AGENT_DERIVED, CONFIRMED, PRODUCT_DEFAULT or LEARNED */
    #[Length(max: 20)]
    #[Choice(choices: self::PROVENANCES)]
    public string $provenance = self::PROVENANCE_USER_STATED;

    /** @var string The goal in one sentence, cut to {@see MAX_STATEMENT_CHARS}; never filtered on, so without an index */
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_TEXT)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public string $statement;

    /** @var string|null Why it matters to the owner */
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_TEXT)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $reason = null;

    /** @var string|null How one would notice it worked, in one line; never filtered on, so without an index */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[Length(max: self::MAX_ONE_LINE_CHARS)]
    public ?string $successSignal = null;

    /** @var Date|null The day the goal should be reached by; optional */
    public ?Date $targetDate = null;

    /** @var string|null The one line of the last status change (what was reached, why it was dropped, what blocks it); without an index */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[Length(max: self::MAX_ONE_LINE_CHARS)]
    public ?string $statusNote = null;

    /** @var bool Only on BLOCKED: only the owner can unblock it (missing information, an approval, a lapsed connection) */
    public bool $requiresUserAction = false;

    /** @var bool Only on BLOCKED: a passing failure, a later run may simply retry */
    public bool $retryable = false;

    /** @var int The attention weight; higher comes first */
    public int $priority = 0;

    /** @var int|null The conversation message the goal was stated or derived in */
    public ?int $sourceMessageId = null;

    /** @var string|null The key of the blueprint node the goal was adopted from ({@see \DDD\Domain\AI\Entities\Conversations\Goals\Blueprints\AgentGoalBlueprintNode::$key}); empty for an individual goal */
    #[Length(max: 64)]
    public ?string $blueprintKey = null;

    /** @var DateTime|null When the goal is due a one-question check with the owner ("still the plan?"); empty = none planned */
    public ?DateTime $revisitDueAt = null;

    /** @var DateTime|null When the goal became ACHIEVED */
    public ?DateTime $achievedAt = null;

    /** @var DateTime|null When this version stopped being the held one; empty = current */
    public ?DateTime $validTo = null;

    /** @var DateTime|null When the store closed this version (superseded or invalidated) */
    public ?DateTime $invalidatedAt = null;

    /** @var int|null The goal row that replaced this one */
    public ?int $supersededByGoalId = null;

    /** @var int|null The goal this one serves or decomposes; empty = a root goal */
    public ?int $parentGoalId = null;

    /**
     * @var string|null The scope the dedup key is built from: `s:<agentSpaceId>` or `a:<accountId>`
     * ({@see deriveScopeKey()}). A plain column without a foreign key: a generated column may not read a column a
     * foreign key points from, so {@see $currentDedupKey} reads this one instead of the scope ids.
     */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[Length(max: 40)]
    public ?string $scopeKey = null;

    /** @var string|null md5 of the normalized statement ({@see hashForStatement()}): the exact-restatement check */
    #[Length(max: 32)]
    public ?string $statementHash = null;

    /**
     * @var string|null Generated column `virtualCurrentDedupKey`: `scopeKey|statementHash` while the row is current,
     * NULL once it is closed. Its unique index keeps one current row per scope and statement while closed versions
     * stay as history. Never written by PHP.
     */
    #[DatabaseColumn(ignoreProperty: true)]
    #[DatabaseVirtualColumn(
        as: "(CASE WHEN validTo IS NULL AND invalidatedAt IS NULL THEN CONCAT_WS('|', scopeKey, statementHash) ELSE NULL END)",
        asPostgres: '(CASE WHEN "validTo" IS NULL AND "invalidatedAt" IS NULL THEN "scopeKey" || \'|\' || "statementHash" ELSE NULL END)'
    )]
    public ?string $currentDedupKey = null;

    /**
     * A goal as it is stated (`addGoal`): the statement trimmed, the horizon (LONG_TERM when none is named), the reason,
     * the success signal, the target day, the priority and the parent. The scope, the holder and the provenance are
     * set by the caller.
     *
     * @param string $statement
     * @param string|null $horizonLevel
     * @param string|null $reason
     * @param string|null $successSignal
     * @param Date|null $targetDate
     * @param int $priority
     * @param int|null $parentGoalId
     * @return static
     */
    public static function buildStated(
        string $statement,
        ?string $horizonLevel,
        ?string $reason,
        ?string $successSignal,
        ?Date $targetDate,
        int $priority,
        ?int $parentGoalId
    ): static {
        $agentGoal = static::newInstance();
        $agentGoal->statement = trim($statement);
        $agentGoal->horizonLevel = $horizonLevel ?? self::HORIZON_LONG_TERM;
        $agentGoal->reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;
        $agentGoal->successSignal = $successSignal !== null && trim($successSignal) !== '' ? trim($successSignal) : null;
        $agentGoal->targetDate = $targetDate;
        $agentGoal->priority = $priority;
        $agentGoal->parentGoalId = $parentGoalId !== null && $parentGoalId > 0 ? $parentGoalId : null;
        return $agentGoal;
    }

    /**
     * Binds the goal to the account and, for a goal of a space, to that space.
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
     * Who holds the goal and where it came from: the owner's own goal (stated by the owner) or the agent's proposal
     * (derived by the agent).
     *
     * @param bool $isStatedByOwner
     * @return void
     */
    public function markHeldByOwnerOrAgent(bool $isStatedByOwner): void
    {
        $this->holder = $isStatedByOwner ? self::HOLDER_OWNER : self::HOLDER_AGENT;
        $this->provenance = $isStatedByOwner ? self::PROVENANCE_USER_STATED : self::PROVENANCE_AGENT_DERIVED;
    }

    /**
     * The status a new goal starts in, from its provenance: a goal the agent derived or learned waits for the owner's
     * confirmation (PROPOSED); a stated, confirmed or default goal is ACTIVE.
     *
     * @return string
     */
    public function deriveInitialStatus(): string
    {
        return in_array($this->provenance, [self::PROVENANCE_AGENT_DERIVED, self::PROVENANCE_LEARNED], true)
            ? self::STATUS_PROPOSED
            : self::STATUS_ACTIVE;
    }

    /**
     * Whether the goal may ever reach `$newStatus`: a VISION never becomes ACHIEVED (a direction has no end), and only
     * an AGENT goal can be BLOCKED (an owner goal is corrected or dropped). Pure; the service turns a no into its
     * refusal.
     *
     * @param string $newStatus
     * @return bool
     */
    public function isStatusReachable(string $newStatus): bool
    {
        if (!in_array($newStatus, self::STATUSES, true)) {
            return false;
        }
        if ($newStatus === self::STATUS_ACHIEVED && $this->horizonLevel === self::HORIZON_VISION) {
            return false;
        }
        if ($newStatus === self::STATUS_BLOCKED && $this->holder !== self::HOLDER_AGENT) {
            return false;
        }
        return true;
    }

    /**
     * Whether `$parentGoal` may be this goal's parent: derivation flows downward only, so an OWNER goal never hangs
     * under an AGENT goal (the agent does not own the owner's wants). Pure; the depth is the service's (it walks the
     * ancestors).
     *
     * @param AgentGoal $parentGoal
     * @return bool
     */
    public function mayHaveParent(AgentGoal $parentGoal): bool
    {
        return !($this->holder === self::HOLDER_OWNER && $parentGoal->holder === self::HOLDER_AGENT);
    }

    /**
     * Whether the goal is the current version: neither closed nor invalidated.
     *
     * @return bool
     */
    public function isCurrentlyValid(): bool
    {
        return !isset($this->validTo) && !isset($this->invalidatedAt);
    }

    /**
     * Whether the goal is current and still live work or orientation (ACTIVE, PROPOSED or BLOCKED).
     *
     * @return bool
     */
    public function isHeld(): bool
    {
        return $this->isCurrentlyValid() && in_array($this->status, self::HELD_STATUSES, true);
    }

    /**
     * Whether the goal belongs to one space.
     *
     * @return bool
     */
    public function isAgentSpaceBound(): bool
    {
        return isset($this->agentSpaceId) && $this->agentSpaceId;
    }

    /**
     * Whether the same statement restated as `$restatedGoal` corrects this goal: another horizon, another target day
     * (or one added or removed), or this goal was dropped. A restatement that corrects nothing changes nothing.
     *
     * @param AgentGoal $restatedGoal
     * @return bool
     */
    public function isCorrectedByRestatement(AgentGoal $restatedGoal): bool
    {
        $targetDay = isset($this->targetDate) ? $this->targetDate->format('Y-m-d') : null;
        $restatedTargetDay = isset($restatedGoal->targetDate) ? $restatedGoal->targetDate->format('Y-m-d') : null;
        return $this->horizonLevel !== $restatedGoal->horizonLevel
            || $targetDay !== $restatedTargetDay
            || $this->status === self::STATUS_DROPPED;
    }

    /**
     * Moves the goal to `$newStatus` (the service checked that it may): a PROPOSED goal the agent derived and the owner
     * now makes ACTIVE is CONFIRMED; a non-empty note replaces the stored one; the two BLOCKED flags hold only on
     * BLOCKED; ACHIEVED stamps {@see $achievedAt}.
     *
     * @param string $newStatus
     * @param string|null $statusNote
     * @param bool $requiresUserAction
     * @param bool $retryable
     * @return void
     */
    public function applyStatusTransition(string $newStatus, ?string $statusNote, bool $requiresUserAction, bool $retryable): void
    {
        if ($this->status === self::STATUS_PROPOSED
            && $newStatus === self::STATUS_ACTIVE
            && $this->provenance === self::PROVENANCE_AGENT_DERIVED
        ) {
            $this->provenance = self::PROVENANCE_CONFIRMED;
        }
        $this->status = $newStatus;
        if ($statusNote !== null && trim($statusNote) !== '') {
            $this->statusNote = trim($statusNote);
        }
        $this->requiresUserAction = $newStatus === self::STATUS_BLOCKED && $requiresUserAction;
        $this->retryable = $newStatus === self::STATUS_BLOCKED && $retryable;
        if ($newStatus === self::STATUS_ACHIEVED) {
            $this->achievedAt = new DateTime('now');
        }
        $this->cutOneLineTexts();
    }

    /**
     * Closes the goal because `$successorGoal` replaces it: the chain points at the successor (empty while the successor
     * has no id).
     *
     * @param AgentGoal $successorGoal
     * @param DateTime|null $invalidatedAt
     * @return void
     */
    public function supersedeBy(AgentGoal $successorGoal, ?DateTime $invalidatedAt = null): void
    {
        $invalidationTime = $invalidatedAt ?? new DateTime('now');
        $this->validTo = $invalidationTime;
        $this->invalidatedAt = $invalidationTime;
        $this->supersededByGoalId = $successorGoal->id ?? null;
    }

    /**
     * The scope the dedup key is built from: `s:<agentSpaceId>` for a goal of a space, else `a:<accountId>`; null when
     * neither is set (a goal no write path stores).
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
     * Cuts the statement to {@see MAX_STATEMENT_CHARS} and the one-line texts to {@see MAX_ONE_LINE_CHARS}, and stamps
     * the scope key and the statement hash: what every write does before the row is stored.
     *
     * @return void
     */
    public function prepareForPersistence(): void
    {
        $this->statement = static::cutToLength(trim($this->statement), self::MAX_STATEMENT_CHARS);
        $this->cutOneLineTexts();
        $this->scopeKey = $this->deriveScopeKey();
        $this->statementHash = static::hashForStatement($this->statement);
    }

    /**
     * The md5 of the normalized statement (trimmed, lower case, whitespace collapsed): stored as {@see $statementHash}.
     *
     * @param string $statement
     * @return string
     */
    public static function hashForStatement(string $statement): string
    {
        return md5(static::normalizeStatement($statement));
    }

    /**
     * The statement as the restatement checks compare it: trimmed, lower case, whitespace collapsed.
     *
     * @param string $statement
     * @return string
     */
    public static function normalizeStatement(string $statement): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', mb_strtolower($statement)));
    }

    /**
     * One line for the model: `#<id>` first (the id `updateGoalStatus` and `parentGoalId` take), then holder, horizon,
     * status and scope, the statement, and the provenance with what else is set (target day, priority, blueprint key,
     * the goal it serves, the status note).
     *
     * @return string
     */
    public function toLlmMarkdown(): string
    {
        $idLabel = isset($this->id) ? '#' . $this->id : '#?';
        $scope = $this->isAgentSpaceBound() ? $this->buildAgentSpaceLabel() : 'account';
        $metaParts = [$this->provenance];
        if (isset($this->targetDate)) {
            $metaParts[] = 'target ' . $this->targetDate->format('Y-m-d');
        }
        if ($this->priority !== 0) {
            $metaParts[] = 'priority ' . $this->priority;
        }
        if ($this->blueprintKey !== null && $this->blueprintKey !== '') {
            $metaParts[] = 'blueprint ' . $this->blueprintKey;
        }
        if ($this->parentGoalId !== null && $this->parentGoalId !== 0) {
            $metaParts[] = '↳ serves #' . $this->parentGoalId;
        }
        if ($this->statusNote !== null && $this->statusNote !== '') {
            $metaParts[] = $this->statusNote;
        }
        return sprintf(
            '- %s [%s/%s/%s/%s] "%s" (%s)',
            $idLabel,
            $this->holder,
            $this->horizonLevel,
            $this->status,
            $scope,
            $this->statement,
            implode('; ', $metaParts)
        );
    }

    /**
     * How the line names the space of a goal of a space: the application's label of the space
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
     * Cuts the success signal and the status note to {@see MAX_ONE_LINE_CHARS} (a model's essay becomes a line, never a
     * refused write).
     *
     * @return void
     */
    protected function cutOneLineTexts(): void
    {
        if ($this->successSignal !== null) {
            $this->successSignal = static::cutToLength($this->successSignal, self::MAX_ONE_LINE_CHARS);
        }
        if ($this->statusNote !== null) {
            $this->statusNote = static::cutToLength($this->statusNote, self::MAX_ONE_LINE_CHARS);
        }
    }

    /**
     * `$text` unchanged when it has at most `$maxChars` characters, else cut so that it ends in `…` and has
     * `$maxChars` characters at most.
     *
     * @param string $text
     * @param int $maxChars
     * @return string
     */
    public static function cutToLength(string $text, int $maxChars): string
    {
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $maxChars - 1)) . '…';
    }
}
