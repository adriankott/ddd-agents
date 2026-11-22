<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use Attribute;

/**
 * What an #[MCPTool] does to the stored world — declared on the tool, never derived from its name.
 *
 * - READ: changes nothing outside the conversation — no row, link or external object is created or changed for the
 *   agent space or the account, no job is started, no outward action is taken. A language-model call, an external
 *   look-up, a cache and the housekeeping every load of an agent space performs (the stored time zone, closing
 *   expired mandates, the application's own clean-up of stale rows, a refreshed link) do not make a tool a
 *   write; neither does a widget the tool writes into its own conversation.
 * - WORKSPACE: changes only the agent's private working area of this agent space — nothing and no one else.
 *   See {@see self::WORKSPACE} for the four conditions, the examples and how the package treats it.
 * - WRITE: creates, changes, publishes, sends or starts something.
 * - DELETE: removes something (a WRITE that takes an object away).
 *
 * Order of harm: READ < WORKSPACE < WRITE < DELETE ({@see getHarmRank()}).
 *
 * `identicalRepeatDoesNewWork` (WRITE / DELETE only): a byte-identical repeat of the call is real work — a build, a
 * batch tool re-called until nothing is pending, a retry the tool itself asks for, a started job, a sent message.
 * The identical-write repeat refusal ({@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationToolCallHistory::getEarlierCompletedIdenticalWriteIndex()})
 * never refuses such a tool; every other WRITE / DELETE repeated with identical arguments is refused as no new work.
 * An application that replaces a name-prefix rule by this attribute sets the flag first on exactly the writes its
 * old rule did not name, so the refusal keeps its member set — some of them (a settings write, a removal) would be
 * refused safely; it keeps that list in a test of its own and re-decides it per tool.
 *
 * `resultIsReusedWithinTurn` (READ only; meaningless for WRITE / DELETE): an identical repeat of the read later in the
 * same owner turn, with no write since, gets the earlier result back instead of running again — the per-turn read reuse
 * ({@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationToolCallHistory::getReusablePriorReadCall()}), which
 * also drives the repeated-failed-read refusal and the read/write split of the agent context's turn texts. False on a
 * READ whose repeat must run: a generator that answers anew on every call (a reply draft, a proposal) or a tool that
 * writes its widget into the conversation again (an offer, a handover). An application that replaces a name-prefix
 * rule sets the flag to false on exactly the READ tools its old `get*` / `search*` / `list*` rule did not
 * reuse, except the pure reads, and keeps that list in a test of its own.
 *
 * `isOutwardAction` (WRITE / DELETE): the completed call acts outward or needs the owner's explicit yes — a mandate
 * grant, a replacement of something published, a publish, a message to the owner, an import. The audit lists such
 * calls separately for the judge and the supervisor
 * ({@see \DDD\Domain\AI\Services\Conversations\AIConversationAuditRenderService::renderOutwardActions()}).
 *
 * `keepsEarlierReadsReusable`: a completed call of this tool changes no account data, so an earlier read of the turn
 * stays reusable after it (the per-turn read reuse treats every other non-reusable completed call as a possible change).
 * Set on a tool that only writes a widget into its own conversation (an offer to connect an outside service).
 *
 * The supervisor, the paused-turn record, the write-less owner-turn windows, the compaction telemetry, the identical-write
 * repeat refusal, the record-change check after a completed call, the fork-join, the per-turn read reuse and the eval
 * read-only gate read this ({@see MCPToolEffectCatalog}); a test of the application requires it on every tool.
 *
 *   #[MCPTool]
 *   #[MCPToolEffect(MCPToolEffect::WRITE)]
 *   public function confirmBooking(...): ... {}
 */
#[Attribute(Attribute::TARGET_METHOD)]
readonly class MCPToolEffect
{
    public const string READ = 'READ';

    /**
     * WORKSPACE — "changes only the agent's private working area of this agent space — nothing and no one else."
     *
     * A tool has this effect only when ALL four conditions hold:
     * 1. It changes only the workspace of its agent space: files, folders, the shell session (its working directory and
     *    environment), background jobs running in it.
     * 2. It changes no domain data: no database row, no store other than the workspace store, no external service, no
     *    conversation, memory or widget.
     * 3. It makes nothing visible to anyone else: no message, mail or push, no file for partners or in the chat.
     *    Something becomes visible only when ANOTHER tool hands it out explicitly (a tool that saves a workspace file to
     *    the owner's drive — that tool is WRITE and an outward action).
     * 4. Its effect stays inside that one workspace: a call on workspace A can never change workspace B.
     *
     * Conditions 2 and 3 do not rest on the tool's discipline: the sandbox enforces them — no network, no credentials,
     * none of the agent's tools inside the shell, exactly one way out (the hand-out tool of condition 3). A tool that
     * cannot guarantee all four (one that writes a file AND books something, one whose shell can reach the network) is
     * WRITE.
     *
     * Where the line runs:
     * - running a shell command, writing a file, editing a file in the workspace: WORKSPACE;
     * - reading a file, searching the files, viewing an image of the workspace: READ;
     * - saving a workspace file to the owner's drive (or anywhere the owner, a partner or the chat sees it): WRITE,
     *   outward (`isOutwardAction: true`);
     * - copying a mounted file into the workspace: READ for the mounted source, WORKSPACE for the copy;
     * - writing an agent memory: WRITE (a memory is domain data, read by later conversations).
     *
     * Order of harm: READ < WORKSPACE < WRITE < DELETE.
     *
     * How the package treats a WORKSPACE call — never as a write of the turn, never as a pure read:
     * - neither {@see isReadOnly()} nor {@see changesState()}; {@see changesOnlyTheWorkspace()} names it and
     *   {@see changesNoSharedData()} groups it with READ;
     * - no owner confirmation and no mandate, also in a scheduled run without an operator; never an outward action;
     * - not in the write ledger, not under the identical-write repeat refusal (a repeated shell command is real work),
     *   not a supervised write, not a "saved" entry of the paused-turn record (it has its own bucket), not a change of
     *   the record a task plan's criteria read; counted on its own as "workspace changes";
     * - it ends the per-turn reuse of EVERY earlier read (the simple, safe rule: the package does not know which reads
     *   read the workspace) and the scan for an earlier final refusal (a file the refusal missed may exist now);
     * - never forked into a parallel step: two calls on the same workspace share one shell; READ calls of the step may
     *   still run in parallel;
     * - the eval read-only gate and the environment-wide write lock let it through: in an evaluation each case and
     *   repetition gets a workspace of its own ({@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance::$workspaceKey}),
     *   so a case with only READ and WORKSPACE tools runs in parallel like a read case;
     * - MCP annotations: readOnlyHint false, destructiveHint false, idempotentHint false;
     * - a per-execution write cap (a script calling tools) counts WRITE and DELETE only.
     */
    public const string WORKSPACE = 'WORKSPACE';

    public const string WRITE = 'WRITE';
    public const string DELETE = 'DELETE';

    /** @var array<string, int> The order of harm, least harmful first */
    public const array HARM_RANK_BY_EFFECT = [self::READ => 0, self::WORKSPACE => 1, self::WRITE => 2, self::DELETE => 3];

    public function __construct(
        public string $effect,
        public bool $identicalRepeatDoesNewWork = false,
        public bool $resultIsReusedWithinTurn = true,
        public bool $isOutwardAction = false,
        public bool $keepsEarlierReadsReusable = false
    ) {
    }

    /** True when the tool changes nothing outside the conversation. */
    public function isReadOnly(): bool
    {
        return $this->effect === self::READ;
    }

    /** True when the tool creates, changes, publishes, sends, starts or removes something (WRITE or DELETE). */
    public function changesState(): bool
    {
        return $this->effect === self::WRITE || $this->effect === self::DELETE;
    }

    /** True when the tool changes only the agent's own workspace ({@see self::WORKSPACE}). */
    public function changesOnlyTheWorkspace(): bool
    {
        return $this->effect === self::WORKSPACE;
    }

    /** True when the tool changes no domain data and makes nothing visible: READ or WORKSPACE. */
    public function changesNoSharedData(): bool
    {
        return $this->effect === self::READ || $this->effect === self::WORKSPACE;
    }

    /** The place of the effect in the order of harm READ (0) < WORKSPACE (1) < WRITE (2) < DELETE (3); an unknown effect ranks as DELETE. */
    public function getHarmRank(): int
    {
        return self::HARM_RANK_BY_EFFECT[$this->effect] ?? self::HARM_RANK_BY_EFFECT[self::DELETE];
    }

    /** True when an identical repeat of this READ within the owner turn gets the earlier result back instead of running (never a WORKSPACE call). */
    public function isReusedWithinTurn(): bool
    {
        return $this->isReadOnly() && $this->resultIsReusedWithinTurn;
    }

    /** True when a repeat of the call with byte-identical arguments does no new work and is refused. */
    public function refusesIdenticalRepeat(): bool
    {
        return $this->changesState() && !$this->identicalRepeatDoesNewWork;
    }
}
