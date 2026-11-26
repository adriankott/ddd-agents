<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffectCatalog;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Validation\Constraints\Choice;

/**
 * Per-conversation OVERRIDE of the agent-run budget knobs — how long and how expensive ONE
 * user turn may run. Every field is nullable: null = inherit the global default. Stored as a JSON column on
 * {@see \DDD\Domain\AI\Entities\Conversations\AIConversation::$runSettings} (same mechanism as
 * {@see CompactionSettings} / {@see ContextRetentionSettings}). Sibling of both, NOT a superset.
 *
 * With defaults this is behaviour-identical to the unchunked loop: one worker invocation runs at most
 * {@see \DDD\Domain\AI\Entities\Conversations\Agent\AIConversationTurnBudget::MAX_STEPS} model steps. A HIGHER
 * `maxStepsPerTurn` makes the turn a CHAIN of worker invocations (chunked execution): an invocation that exhausts
 * its per-invocation batch without a final answer YIELDS (appends a TURN_CONTINUATION signal, releases IDLE,
 * re-fires) and the next invocation continues the same user turn — picking up any compaction that landed in
 * between. "Long-running" is purely this number, never a conversation type.
 *
 * An application whose own runs need a setting of their own (the row a run writes, the period it covers) declares it
 * on a class of the same path that extends this one. The module creates run settings with {@see newInstance()}, and
 * a stored row names its class, so the application's class is the one built and read back.
 */
class RunSettings extends ValueObject
{
    /**
     * @var int Hard ceiling for {@see $maxStepsPerTurn} — enforced at BOTH the admin DTOs (`#[Range]`, clean 400)
     * AND the loop's budget derivation ({@see \DDD\Domain\AI\Entities\Conversations\Agent\AIConversationTurnBudget::$totalMaxSteps},
     * covers persisted/legacy values): an unattended task with an unvalidated huge step budget would otherwise run
     * an unbounded chunk chain.
     */
    public const int MAX_STEPS_PER_TURN_CEILING = 120;

    /**
     * @var string What an eval tool block answers after the tool's name (a blocked tool without a simulated error, a
     * write under the read-only switch): the run goes on with the skill's other steps instead of leaving them.
     */
    public const string EVAL_TOOL_REFUSAL_SENTENCE = "this step is not executed in a test run; go on with the task's other steps as the skill says";

    /**
     * @var int|null Total model-call steps ONE user turn may consume ACROSS all of its worker invocations (chunks).
     * Derived per invocation from the persisted usage-stamped messages of the current turn — no stored counter, so
     * the cap is crash/park/chunk-safe. Inherited by delegation children ({@see \DDD\Domain\AI\Entities\Conversations\AIConversation::inheritDelegationScopeFrom()}
     * — a long run's sub-tasks are equally long). Null = the global default (`MAX_STEPS`).
     */
    public ?int $maxStepsPerTurn = null;

    /**
     * @var string|null The workspace a WORKSPACE tool of this conversation works in
     * ({@see \DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect::WORKSPACE}); null = the tool derives its own (from the
     * agent space or the account). Set by an evaluation for each case and repetition
     * (`eval-<case>-r<repetition>-<runToken>`), so cases never share a workspace; inherited by delegation children (one
     * run tree, one workspace). Reaches the tool as {@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance::$workspaceKey}.
     */
    public ?string $workspaceKey = null;

    /**
     * @var float|null Cost ceiling in USD for ONE user turn: Σ of the turn's own model-call costs plus the
     * delegation children's spend carried in their settle slices ({@see \DDD\Domain\AI\Entities\Conversations\AIConversation::buildDelegationResultSlice()}).
     * The child share is enforceable only at FOLD time (the children already ran); a breach is a GRACEFUL terminal
     * (stop + honest report), never a retroactive cancellation. Deliberately NOT inherited by delegation children —
     * the parent's turn Σ already counts their spend. Null = no cost cap (interactive default).
     */
    public ?float $maxTurnCostUsd = null;

    /**
     * @var bool|null ADMIN-only SUPPORT OVERRIDE of the automation-eligibility gate: true lets
     * this admin-initiated conversation make model calls for a locked / ended scope — the one audited exemption
     * from `AIModelCallGateService::refuseModelCallForIneligibleBilledScope()`. Activated request-scoped by the loop around the drive and logged
     * on activation and on every consultation; never inherited by delegation children of a customer conversation.
     * Null/false = the gate applies. Distinct from {@see \DDD\Domain\AI\Entities\Conversations\AIConversation::$budgetBypass}
     * on purpose: eligibility is not a budget concern.
     */
    public ?bool $supportOverride = null;

    /**
     * @var bool|null EVAL MARKER: true on every conversation an eval runner creates, obtains or forks —
     * stamped at the OBTAIN seam before the first drive and persisted, so the decision statistics and the rollout
     * gates can exclude eval traffic (an eval suite can be a large share of the owner-text turns). Never inherited
     * by a customer conversation; null = a real conversation.
     */
    public ?bool $evalRun = null;

    /**
     * @var bool|null True on a conversation an eval SEEDS as the owner's history (the past chats a recall case has to
     * find). An eval RUN is kept out of the cross-conversation search — its owner is simulated —, a seed is the
     * history under test and stays findable. Set together with {@see $evalRun}; null everywhere else.
     */
    public ?bool $evalSeededHistory = null;

    /**
     * @var string|null Read on the settings of a SCHEDULE only ({@see \DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule::getRunPurposeName()}):
     * the purpose every run of the schedule starts with — a registered purpose whose class is
     * {@see \DDD\Domain\AI\Entities\Conversations\Purposes\ScheduleRunPurpose} or extends it. Null = `Schedule.Run`.
     * Kept in the settings column so a schedule table without a new column carries it. Never copied onto a run.
     */
    public ?string $runPurposeName = null;

    public const string DECISION_MODEL_SKILL_PRELOAD_INHERIT = 'inherit';
    public const string DECISION_MODEL_SKILL_PRELOAD_ON = 'on';
    public const string DECISION_MODEL_SKILL_PRELOAD_OFF = 'off';

    /**
     * @var string|null Per-conversation switch of the decision-model skill preload: `inherit` / null
     *      = the site's mode; `on` = the preload applies in this conversation (an explicit ADMIN override — under site `shadow`
     *      it turns the shadow into an apply, under site `off` it does nothing: off is the absolute veto); `off` = no
     *      preload call here at all. Written only by the admin console / the impersonation panel through the gated
     *      service mutation; never inherited by delegation children. Audit: {@see $decisionModelSkillPreloadSetByAccountId}.
     */
    #[Choice(choices: [self::DECISION_MODEL_SKILL_PRELOAD_INHERIT, self::DECISION_MODEL_SKILL_PRELOAD_ON, self::DECISION_MODEL_SKILL_PRELOAD_OFF])]
    public ?string $decisionModelSkillPreload = null;

    /** @var int|null The admin account that last set {@see $decisionModelSkillPreload}. */
    public ?int $decisionModelSkillPreloadSetByAccountId = null;

    /** @var string|null `Y-m-d H:i:s` when {@see $decisionModelSkillPreload} was last set. */
    public ?string $decisionModelSkillPreloadSetAt = null;

    public const string DECISION_MODEL_TURN_CLOSING_INHERIT = 'inherit';
    public const string DECISION_MODEL_TURN_CLOSING_OFF = 'off';
    public const string DECISION_MODEL_TURN_CLOSING_DETERMINISTIC_COUNTER = 'deterministicCounter';
    public const string DECISION_MODEL_TURN_CLOSING_DECISION_MODEL = 'decisionModel';
    public const string DECISION_MODEL_TURN_CLOSING_COUNTER_THEN_DECISION_MODEL = 'counterThenDecisionModel';

    /**
     * @var string|null Per-conversation switch of the checked-stop TURN-CLOSING mode: `inherit` / null = the
     *      config's `turnClosingMode`; `deterministicCounter` / `decisionModel` = this conversation's turn-closing mode while the
     *      `turnClosing` site is `on` (a turn-closing mode never changes the site mode — under site `shadow` the row is inert,
     *      under site `off` nothing runs); `off` = no turn-closing decision in this conversation at all. Written only
     *      by the admin console / the impersonation panel through the gated service mutation; never inherited by
     *      delegation children (they run no decision). Audit: {@see $decisionModelTurnClosingSetByAccountId}.
     */
    #[Choice(choices: [self::DECISION_MODEL_TURN_CLOSING_INHERIT, self::DECISION_MODEL_TURN_CLOSING_OFF, self::DECISION_MODEL_TURN_CLOSING_DETERMINISTIC_COUNTER, self::DECISION_MODEL_TURN_CLOSING_DECISION_MODEL, self::DECISION_MODEL_TURN_CLOSING_COUNTER_THEN_DECISION_MODEL])]
    public ?string $decisionModelTurnClosing = null;

    /** @var int|null The admin account that last set {@see $decisionModelTurnClosing}. */
    public ?int $decisionModelTurnClosingSetByAccountId = null;

    /** @var string|null `Y-m-d H:i:s` when {@see $decisionModelTurnClosing} was last set. */
    public ?string $decisionModelTurnClosingSetAt = null;

    public const string DECISION_MODEL_REASONING_EFFORT_INHERIT = 'inherit';
    public const string DECISION_MODEL_REASONING_EFFORT_OFF = 'off';
    public const string DECISION_MODEL_REASONING_EFFORT_LOW = 'low';
    public const string DECISION_MODEL_REASONING_EFFORT_MEDIUM = 'medium';
    public const string DECISION_MODEL_REASONING_EFFORT_HIGH = 'high';

    /**
     * @var string|null Per-conversation switch of the reasoning-effort site: `inherit` / null = the
     *      site decides; `off` = the model's configured effort, no decision; `low` / `medium` / `high` = a FLOOR the
     *      site may raise above but never lower (the pin applies even under site `shadow`; under site `off` the
     *      configured effort is sent). Admin-only, audited; never inherited by delegation children.
     */
    #[Choice(choices: [self::DECISION_MODEL_REASONING_EFFORT_INHERIT, self::DECISION_MODEL_REASONING_EFFORT_OFF, self::DECISION_MODEL_REASONING_EFFORT_LOW, self::DECISION_MODEL_REASONING_EFFORT_MEDIUM, self::DECISION_MODEL_REASONING_EFFORT_HIGH])]
    public ?string $decisionModelReasoningEffort = null;

    public ?int $decisionModelReasoningEffortSetByAccountId = null;

    public ?string $decisionModelReasoningEffortSetAt = null;

    /**
     * @var string|null A FIXED reasoning effort for every model call of this conversation (`low` / `medium` / `high`),
     *      clamped to the model's ceiling. It is a MEASUREMENT setting: the effort site is not asked, no pin, purpose
     *      floor or plan-item minimum raises or lowers it, and delegation children inherit it — so a run at `low`
     *      really runs at `low` and two runs differ in the effort alone. Null = the effort is resolved as always (the
     *      configured effort, the site's decision, the pin, the declared minimums). Distinct from
     *      {@see $decisionModelReasoningEffort}, whose values are a FLOOR the site may raise above.
     */
    #[Choice(choices: [self::DECISION_MODEL_REASONING_EFFORT_LOW, self::DECISION_MODEL_REASONING_EFFORT_MEDIUM, self::DECISION_MODEL_REASONING_EFFORT_HIGH])]
    public ?string $fixedReasoningEffort = null;

    /**
     * @var bool|null A MEASUREMENT setting (the eval arm `--store-snapshot`): every model call of this
     *      conversation stores its FULL sent payload and the raw response on its request snapshot, as
     *      {@see ContextRetentionSettings::$storeRequestSnapshot} does — carried on the run settings because they reach
     *      every conversation an eval run creates (deterministic, judged, purposed). Null = the retention setting
     *      decides.
     */
    public ?bool $storeRequestSnapshot = null;

    public const string DECISION_MODEL_STALL_RECOVERY_INHERIT = 'inherit';
    public const string DECISION_MODEL_STALL_RECOVERY_OFF = 'off';
    public const string DECISION_MODEL_STALL_RECOVERY_ON = 'on';

    /**
     * @var string|null Per-conversation switch of the stall-recovery site: `inherit` / null = the
     *      site's mode; `on` = the move is applied here (lifts `shadow`; under site `off` nothing runs); `off` = no
     *      stall decision here, today's ladder. Admin-only, audited; never inherited by delegation children.
     */
    #[Choice(choices: [self::DECISION_MODEL_STALL_RECOVERY_INHERIT, self::DECISION_MODEL_STALL_RECOVERY_OFF, self::DECISION_MODEL_STALL_RECOVERY_ON])]
    public ?string $decisionModelStallRecovery = null;

    public ?int $decisionModelStallRecoverySetByAccountId = null;

    public ?string $decisionModelStallRecoverySetAt = null;

    public const string DECISION_MODEL_REPLY_LANGUAGE_INHERIT = 'inherit';
    public const string DECISION_MODEL_REPLY_LANGUAGE_OFF = 'off';
    public const string DECISION_MODEL_REPLY_LANGUAGE_ON = 'on';

    /**
     * @var string|null Per-conversation switch of the reply-language site: `inherit` / null = the
     *      site's mode; `on` = the verdict is applied here (lifts `shadow`; under site `off` nothing runs); `off` = no
     *      language decision here, the prompt's own language rules. Admin-only, audited; never inherited by delegation children.
     */
    #[Choice(choices: [self::DECISION_MODEL_REPLY_LANGUAGE_INHERIT, self::DECISION_MODEL_REPLY_LANGUAGE_OFF, self::DECISION_MODEL_REPLY_LANGUAGE_ON])]
    public ?string $decisionModelReplyLanguage = null;

    public ?int $decisionModelReplyLanguageSetByAccountId = null;

    public ?string $decisionModelReplyLanguageSetAt = null;

    /**
     * @var bool|null Switch 1 of the plan-dynamics site (two switches instead of a mode word):
     *      is the decision model ASKED whether this turn needs a working plan (and its verdict recorded)? null = inherit
     *      the environment; false = never asked here (the chat model decides alone, today's behaviour); true = asked.
     *      Admin-only, audited; never inherited by delegation children.
     */
    public ?bool $planDynamicsAskDecisionModel = null;

    /**
     * @var bool|null Switch 2 of the plan-dynamics site: does the CHAT MODEL RECEIVE the verdict (the plan directive in its context, the
     *      plan retirement, the tick-with-delivery rule, the rewritten trigger)? null = inherit; false = record only
     *      (observe); true = guide. Meaningful only while switch 1 asks — "not asked but receives" is treated as not asked.
     */
    public ?bool $planDynamicsModelReceivesVerdict = null;

    public ?int $planDynamicsSetByAccountId = null;

    public ?string $planDynamicsSetAt = null;

    /**
     * @var bool|null The KILL SWITCH: true = every decision site (the deterministic plan-item preload
     *      included) is off for this conversation, whatever the environment and the per-site rows say. Null / false
     *      = the per-site rules apply. Admin-only, audited like the per-site rows.
     */
    public ?bool $decisionModelOff = null;

    /** @var int|null The admin account that last set {@see $decisionModelOff}. */
    public ?int $decisionModelOffSetByAccountId = null;

    /** @var string|null `Y-m-d H:i:s` when {@see $decisionModelOff} was last set. */
    public ?string $decisionModelOffSetAt = null;

    /**
     * @var string|null EVAL-ONLY stage boundary: the name of a tool
     *      after whose COMPLETED call the current owner turn ends with the platform's closing step — exactly the way the
     *      step budget ends a turn — so a replay fixture can inject the owner's next message between two build stages
     *      (for example: the brief is written, THEN the rules arrive, THEN the pages are written). Read only while
     *      {@see $evalRun} is true ({@see evalStopAfterToolApplies()}): a production drive never sets the marker, so the
     *      name is inert everywhere else. Null = no stage boundary.
     */
    public ?string $evalStopAfterToolName = null;

    /**
     * @var string[]|null EVAL-ONLY tool block: the case's `blockedTools`, persisted on the conversation so EVERY process
     *      that runs one of its tool calls refuses them — the console that drives the case and a worker that resumes the
     *      conversation (a background signal, a sub-agent run end) alike. Read only while {@see $evalRun} is true
     *      ({@see describeEvalToolBlock()}); the executor reads it from the ROOT conversation, so a sub-agent inherits it.
     *      Null = nothing blocked.
     */
    public ?array $evalBlockedToolNames = null;

    /**
     * @var array<string, string>|null EVAL-ONLY: per blocked tool the case's simulated error text
     *      (`blockedToolSimulatedErrors`), answered instead of the generic refusal. Null = the generic refusal.
     */
    public ?array $evalBlockedToolSimulatedErrors = null;

    /**
     * @var bool|null EVAL-ONLY: true for a case that may only READ its space — every tool that is not a declared read
     *      is refused in every process, like {@see $evalBlockedToolNames}. Null = writes allowed.
     */
    public ?bool $evalEveryWriteToolBlocked = null;

    public const string AGENT_WIRE_API_INHERIT = 'inherit';
    public const string AGENT_WIRE_API_CHAT_COMPLETIONS = 'chatCompletions';
    public const string AGENT_WIRE_API_RESPONSES = 'responses';
    /** The Anthropic Messages API ({@see AIModel::AGENT_WIRE_API_MESSAGES}): the native wire of the Claude models. */
    public const string AGENT_WIRE_API_MESSAGES = 'messages';
    /**
     * TEST-ONLY: the Responses API called directly at OpenAI with `store: true` and `previous_response_id` — OpenAI keeps
     * the conversation state, each call sends only the items after the previous response. Selectable per conversation
     * (run settings, eval arm), never from the model catalog ({@see \DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentWireConfiguration::CATALOG_WIRE_APIS}).
     */
    public const string AGENT_WIRE_API_RESPONSES_SERVER_STATE = 'responsesServerState';

    /** @var string[] The wire APIs a conversation's run settings may name: the catalog's three plus the server-state arm. */
    public const array AGENT_WIRE_APIS = [
        self::AGENT_WIRE_API_CHAT_COMPLETIONS,
        self::AGENT_WIRE_API_RESPONSES,
        self::AGENT_WIRE_API_MESSAGES,
        self::AGENT_WIRE_API_RESPONSES_SERVER_STATE,
    ];

    /**
     * @var string|null The wire API this conversation's agent calls speak — `chatCompletions` /
     *      `responses` / `messages` (Anthropic Messages API) / `responsesServerState` (test-only arm: direct OpenAI,
     *      `store: true` + `previous_response_id`);
     *      null (`inherit`) = the model catalog's `agentWireApi` ({@see AIModel::getAgentWireApi()}).
     *      Written by the admin run-settings override, the admin start and the eval arm `--agent-wire-api`; inherited by
     *      delegation children (an arm measures the whole tree). Never an environment variable. Audit:
     *      {@see $agentWireApiSetByAccountId}.
     */
    #[Choice(choices: self::AGENT_WIRE_APIS)]
    public ?string $agentWireApi = null;

    /** @var int|null The admin account that last set {@see $agentWireApi}. */
    public ?int $agentWireApiSetByAccountId = null;

    /** @var string|null `Y-m-d H:i:s` when {@see $agentWireApi} was last set. */
    public ?string $agentWireApiSetAt = null;

    public const string PREVIOUS_REASONING_CARRY_OVER_INHERIT = 'inherit';
    /** The reasoning items of every earlier step go back. */
    public const string PREVIOUS_REASONING_CARRY_OVER_ALL = 'all';
    public const string PREVIOUS_REASONING_CARRY_OVER_OFF = 'off';
    /** Only the reasoning items of the steps since the newest owner message (the current turn) go back. */
    public const string PREVIOUS_REASONING_CARRY_OVER_CURRENT_TURN = 'currentTurn';
    /**
     * Every earlier step's items go back until they exceed {@see $previousReasoningCapTokens}; then the older ones are
     * dropped in one block and only the newest steps' stay (the cut rule: {@see \DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentPreviousReasoningCut}).
     */
    public const string PREVIOUS_REASONING_CARRY_OVER_CAPPED = 'capped';

    /**
     * The spelling of {@see PREVIOUS_REASONING_CARRY_OVER_ALL} before the setting was renamed
     * (`on`): still accepted as an input value and canonicalised to `all`.
     */
    public const string PREVIOUS_REASONING_CARRY_OVER_ALL_LEGACY_SPELLING = 'on';

    /** The values of {@see $previousReasoningCarryOver}. */
    public const array PREVIOUS_REASONING_CARRY_OVER_VALUES = [self::PREVIOUS_REASONING_CARRY_OVER_ALL, self::PREVIOUS_REASONING_CARRY_OVER_CURRENT_TURN, self::PREVIOUS_REASONING_CARRY_OVER_CAPPED, self::PREVIOUS_REASONING_CARRY_OVER_OFF];

    /**
     * @var string|null Whether the provider's opaque reasoning items of the PREVIOUS calls are sent back
     *      on the later calls of this conversation (Responses `reasoning` items, Chat-Completions `reasoning_details`) —
     *      `all` (the items of every earlier step), `currentTurn` (only the items of the steps since the newest owner
     *      message), `capped` (every earlier step's until they exceed {@see $previousReasoningCapTokens}, then only the
     *      newest steps' — a stable cut, moved once per crossing) / `off`; null (`inherit`) = the model catalog's value,
     *      else the default per wire API: `capped` for `responses` and `messages`, `off` for `chatCompletions` and
     *      `responsesServerState`
     *      ({@see \DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentWireConfiguration::resolveFor()}).
     *      Written by the admin run-settings override, the console option and the eval arm
     *      `--previous-reasoning-carry-over`; inherited by delegation children.
     */
    #[Choice(choices: [self::PREVIOUS_REASONING_CARRY_OVER_ALL, self::PREVIOUS_REASONING_CARRY_OVER_CURRENT_TURN, self::PREVIOUS_REASONING_CARRY_OVER_CAPPED, self::PREVIOUS_REASONING_CARRY_OVER_OFF])]
    public ?string $previousReasoningCarryOver = null;

    /** @var int|null The admin account that last set {@see $previousReasoningCarryOver}. */
    public ?int $previousReasoningCarryOverSetByAccountId = null;

    /** @var string|null `Y-m-d H:i:s` when {@see $previousReasoningCarryOver} was last set. */
    public ?string $previousReasoningCarryOverSetAt = null;

    /**
     * @var int|null The cap of the carry-over `capped`, in reasoning tokens: while the reasoning items the call would send
     *      back stay at or below it, all of them go; above it the older ones are dropped in one block. Null = the
     *      model's catalog value (`agenticUseCase.previousReasoning.capTokens`), else the committed default of the run's
     *      wire API (`AI.contextRetention.previousReasoningCapTokensByAgentWireApi`, else
     *      `AI.contextRetention.previousReasoningCapTokens`)
     *      ({@see \DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentWireConfiguration::resolveDefaultPreviousReasoningCapTokens()}).
     *      Inert under every other mode. Written by the admin run-settings override, the console option and the eval
     *      arm `--previous-reasoning-cap-tokens`; inherited by delegation children.
     */
    public ?int $previousReasoningCapTokens = null;

    /** @var int|null The admin account that last set {@see $previousReasoningCapTokens}. */
    public ?int $previousReasoningCapTokensSetByAccountId = null;

    /** @var string|null `Y-m-d H:i:s` when {@see $previousReasoningCapTokens} was last set. */
    public ?string $previousReasoningCapTokensSetAt = null;

    /**
     * @var int|null At a cut of the carry-over `capped`: walking back from the newest step, whole steps keep their
     *      reasoning until at least this many reasoning tokens are kept (the min of the cut; 0 = only the steps
     *      {@see $previousReasoningKeptStepsAtCut} asks for). Null = the model's catalog value
     *      (`agenticUseCase.previousReasoning.keptTokensAtCut`), else the committed default of the run's wire API
     *      ({@see \DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentWireConfiguration::resolveDefaultPreviousReasoningKeptTokensAtCut()}).
     *      Inert under every other mode. Written by the console option and the eval arm
     *      `--previous-reasoning-kept-tokens-at-cut`; inherited by delegation children.
     */
    public ?int $previousReasoningKeptTokensAtCut = null;

    /** @var int|null The admin account that last set {@see $previousReasoningKeptTokensAtCut}. */
    public ?int $previousReasoningKeptTokensAtCutSetByAccountId = null;

    /** @var string|null `Y-m-d H:i:s` when {@see $previousReasoningKeptTokensAtCut} was last set. */
    public ?string $previousReasoningKeptTokensAtCutSetAt = null;

    /**
     * @var int|null At a cut of the carry-over `capped`: at least this many of the newest steps keep their reasoning
     *      (at least 1). Null = the model's catalog value, else the committed default of the run's wire API
     *      ({@see \DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentWireConfiguration::resolveDefaultPreviousReasoningKeptStepsAtCut()}).
     *      Written by the console option and the eval arm `--previous-reasoning-kept-steps-at-cut`; inherited by
     *      delegation children.
     */
    public ?int $previousReasoningKeptStepsAtCut = null;

    /** @var int|null The admin account that last set {@see $previousReasoningKeptStepsAtCut}. */
    public ?int $previousReasoningKeptStepsAtCutSetByAccountId = null;

    /** @var string|null `Y-m-d H:i:s` when {@see $previousReasoningKeptStepsAtCut} was last set. */
    public ?string $previousReasoningKeptStepsAtCutSetAt = null;

    /**
     * @var int|null After a cut of the carry-over `capped`: the next cut waits until the carried reasoning exceeds the
     *      larger of the cap and the reasoning kept at that cut plus this many tokens (0 = the cap alone). Null = the
     *      model's catalog value, else the committed default of the run's wire API
     *      ({@see \DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentWireConfiguration::resolveDefaultPreviousReasoningHeadroomTokensAfterCut()}).
     *      Written by the console option and the eval arm `--previous-reasoning-headroom-tokens-after-cut`; inherited
     *      by delegation children.
     */
    public ?int $previousReasoningHeadroomTokensAfterCut = null;

    /** @var int|null The admin account that last set {@see $previousReasoningHeadroomTokensAfterCut}. */
    public ?int $previousReasoningHeadroomTokensAfterCutSetByAccountId = null;

    /** @var string|null `Y-m-d H:i:s` when {@see $previousReasoningHeadroomTokensAfterCut} was last set. */
    public ?string $previousReasoningHeadroomTokensAfterCutSetAt = null;

    /**
     * @var string|null The skill-loading strategy this conversation is ASKED to start with
     *      (`systemPromptSwap` / `conversationAppend`) — read ONCE, by the creation stamp, which writes the resolved
     *      strategy onto {@see ContextRetentionSettings::$skillLoadingStrategy}; never read afterwards. The carrier of
     *      the eval arm `--skill-loading-strategy` through the obtain seams that pass run settings only (a
     *      simulator's purposed start). Null = the purpose's or the global default.
     */
    #[Choice(choices: [ContextRetentionSettings::SKILL_LOADING_STRATEGY_SYSTEM_PROMPT_SWAP, ContextRetentionSettings::SKILL_LOADING_STRATEGY_CONVERSATION_APPEND])]
    public ?string $requestedSkillLoadingStrategy = null;

    /**
     * @var bool|null The {@see ContextRetentionSettings::$rebuildEpochOnSituationChange} this
     *      conversation is ASKED to start with — read ONCE by the creation stamp, never afterwards; the carrier of the
     *      eval arm `--rebuild-epoch-on-situation-change` through the obtain seams that pass run settings only. Null =
     *      the purpose's or the global default.
     */
    public ?bool $requestedRebuildEpochOnSituationChange = null;

    /**
     * @var string|null The {@see ContextRetentionSettings::$stateChangeDelivery} this conversation is ASKED
     *      to start with — read ONCE by the creation stamp; the carrier of the eval arm `--state-change-delivery`. Null =
     *      the purpose's or the global default.
     */
    #[Choice(choices: [ContextRetentionSettings::STATE_CHANGE_DELIVERY_EVERY_CALL, ContextRetentionSettings::STATE_CHANGE_DELIVERY_ONCE_WHEN_CHANGED])]
    public ?string $requestedStateChangeDelivery = null;

    /** Effective total step budget for one user turn (falls back to the global default when unset). */
    public function getMaxStepsPerTurn(int $globalDefault): int
    {
        return $this->maxStepsPerTurn ?? $globalDefault;
    }

    /**
     * Whether the EVAL stage boundary ends the turn once `$completedToolName` completed (pure): only an eval conversation
     * ({@see $evalRun} true) with a named tool, and only for that tool. A real conversation answers false whatever the
     * name says — the production safety of the seam.
     */
    public function evalStopAfterToolApplies(string $completedToolName): bool
    {
        return $this->evalRun === true
            && $this->evalStopAfterToolName !== null
            && $this->evalStopAfterToolName !== ''
            && $this->evalStopAfterToolName === $completedToolName;
    }

    /**
     * Stamps an eval case's tool block on these run settings: the blocked names, their simulated error texts and the
     * read-only switch, each stored as null when empty so a case without a block leaves the row untouched.
     *
     * @param string[] $blockedToolNames
     * @param array<string, string> $blockedToolSimulatedErrors
     */
    public function setEvalToolBlock(array $blockedToolNames, array $blockedToolSimulatedErrors, bool $everyWriteToolBlocked): static
    {
        $this->evalBlockedToolNames = $blockedToolNames !== [] ? array_values($blockedToolNames) : null;
        $this->evalBlockedToolSimulatedErrors = $blockedToolSimulatedErrors !== [] ? $blockedToolSimulatedErrors : null;
        $this->evalEveryWriteToolBlocked = $everyWriteToolBlocked ? true : null;
        return $this;
    }

    /**
     * The refusal text when the eval tool block of this conversation refuses `$toolName`, else null. Only an eval
     * conversation ({@see $evalRun} true) blocks anything — a real conversation answers null whatever the rows say.
     */
    public function describeEvalToolBlock(string $toolName): ?string
    {
        if ($this->evalRun !== true) {
            return null;
        }
        return self::describeEvalToolRefusal(
            $toolName,
            $this->evalBlockedToolNames ?? [],
            $this->evalBlockedToolSimulatedErrors ?? [],
            $this->evalEveryWriteToolBlocked === true
        );
    }

    /**
     * The refusal text of an eval tool block for `$toolName` (pure), else null: a blocked name answers its simulated
     * error or the tool name and {@see EVAL_TOOL_REFUSAL_SENTENCE}; under the read-only switch every tool that is not a
     * declared read answers the same. The one wording for the console's process guard and the conversation's persisted block.
     *
     * @param string[] $blockedToolNames
     * @param array<string, string> $blockedToolSimulatedErrors
     */
    public static function describeEvalToolRefusal(string $toolName, array $blockedToolNames, array $blockedToolSimulatedErrors, bool $everyWriteToolBlocked): ?string
    {
        if (in_array($toolName, $blockedToolNames, true)) {
            return $blockedToolSimulatedErrors[$toolName] ?? "Tool '{$toolName}': " . static::EVAL_TOOL_REFUSAL_SENTENCE;
        }
        // a WORKSPACE tool changes only the case's own workspace: let through like a read
        if ($everyWriteToolBlocked && !MCPToolEffectCatalog::changesNoSharedDataForToolName($toolName)) {
            return "Tool '{$toolName}': " . static::EVAL_TOOL_REFUSAL_SENTENCE;
        }
        return null;
    }

    /**
     * Applies the admin's switch rows ({@see RunSettingsOverride}) to THESE run settings, in place, and returns them:
     * each row a value of its own list (`inherit` clears the row back to the site's mode), stamped with who set it and
     * when (`$setAt`, written as server-local `Y-m-d H:i:s`). A value outside its list throws — the rows before it stay
     * applied. The gates (admin session, a persisted conversation) and the write belong to
     * {@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::applyAdminRunSettingsOverride()}.
     *
     * @throws BadRequestException
     */
    public function applyOverride(RunSettingsOverride $override, ?int $setByAccountId, DateTime $setAt): RunSettings
    {
        $setAtText = $setAt->format('Y-m-d H:i:s');
        if ($override->decisionModelSkillPreload !== null) {
            $value = strtolower(trim($override->decisionModelSkillPreload));
            if (!in_array($value, [RunSettings::DECISION_MODEL_SKILL_PRELOAD_INHERIT, RunSettings::DECISION_MODEL_SKILL_PRELOAD_ON, RunSettings::DECISION_MODEL_SKILL_PRELOAD_OFF], true)) {
                throw new BadRequestException('decisionModelSkillPreload must be one of inherit, on, off');
            }
            $this->decisionModelSkillPreload = $value === RunSettings::DECISION_MODEL_SKILL_PRELOAD_INHERIT ? null : $value;
            $this->decisionModelSkillPreloadSetByAccountId = $setByAccountId;
            $this->decisionModelSkillPreloadSetAt = $setAtText;
        }
        if ($override->decisionModelTurnClosing !== null) {
            // the turn-closing row: `inherit` clears it, `off` silences the site, `deterministicCounter` / `decisionModel` select the
            // turn-closing mode while the site is `on` (a row never changes the site mode).
            // case-insensitive in, the canonical constant out (the values are camel-case)
            $canonicalValues = [RunSettings::DECISION_MODEL_TURN_CLOSING_INHERIT, RunSettings::DECISION_MODEL_TURN_CLOSING_OFF, RunSettings::DECISION_MODEL_TURN_CLOSING_DETERMINISTIC_COUNTER, RunSettings::DECISION_MODEL_TURN_CLOSING_DECISION_MODEL, RunSettings::DECISION_MODEL_TURN_CLOSING_COUNTER_THEN_DECISION_MODEL];
            $value = null;
            foreach ($canonicalValues as $canonicalValue) {
                if (strtolower(trim($override->decisionModelTurnClosing)) === strtolower($canonicalValue)) {
                    $value = $canonicalValue;
                }
            }
            if ($value === null) {
                throw new BadRequestException('decisionModelTurnClosing must be one of inherit, off, deterministicCounter, decisionModel, counterThenDecisionModel');
            }
            $this->decisionModelTurnClosing = $value === RunSettings::DECISION_MODEL_TURN_CLOSING_INHERIT ? null : $value;
            $this->decisionModelTurnClosingSetByAccountId = $setByAccountId;
            $this->decisionModelTurnClosingSetAt = $setAtText;
        }
        if ($override->decisionModelReasoningEffort !== null) {
            // the effort row: `inherit` clears it, `off` = the configured effort, a value pins the floor
            $value = strtolower(trim($override->decisionModelReasoningEffort));
            $allowedValues = [RunSettings::DECISION_MODEL_REASONING_EFFORT_INHERIT, RunSettings::DECISION_MODEL_REASONING_EFFORT_OFF, RunSettings::DECISION_MODEL_REASONING_EFFORT_LOW, RunSettings::DECISION_MODEL_REASONING_EFFORT_MEDIUM, RunSettings::DECISION_MODEL_REASONING_EFFORT_HIGH];
            if (!in_array($value, $allowedValues, true)) {
                throw new BadRequestException('decisionModelReasoningEffort must be one of inherit, off, low, medium, high');
            }
            $this->decisionModelReasoningEffort = $value === RunSettings::DECISION_MODEL_REASONING_EFFORT_INHERIT ? null : $value;
            $this->decisionModelReasoningEffortSetByAccountId = $setByAccountId;
            $this->decisionModelReasoningEffortSetAt = $setAtText;
        }
        if ($override->decisionModelStallRecovery !== null) {
            // the stall-recovery row: `inherit` clears it, `on` lifts shadow here, `off` silences the site here
            $value = strtolower(trim($override->decisionModelStallRecovery));
            if (!in_array($value, [RunSettings::DECISION_MODEL_STALL_RECOVERY_INHERIT, RunSettings::DECISION_MODEL_STALL_RECOVERY_OFF, RunSettings::DECISION_MODEL_STALL_RECOVERY_ON], true)) {
                throw new BadRequestException('decisionModelStallRecovery must be one of inherit, off, on');
            }
            $this->decisionModelStallRecovery = $value === RunSettings::DECISION_MODEL_STALL_RECOVERY_INHERIT ? null : $value;
            $this->decisionModelStallRecoverySetByAccountId = $setByAccountId;
            $this->decisionModelStallRecoverySetAt = $setAtText;
        }
        if ($override->decisionModelReplyLanguage !== null) {
            // the language row: `inherit` clears it, `on` lifts shadow here, `off` silences the site here
            $value = strtolower(trim($override->decisionModelReplyLanguage));
            if (!in_array($value, [RunSettings::DECISION_MODEL_REPLY_LANGUAGE_INHERIT, RunSettings::DECISION_MODEL_REPLY_LANGUAGE_OFF, RunSettings::DECISION_MODEL_REPLY_LANGUAGE_ON], true)) {
                throw new BadRequestException('decisionModelReplyLanguage must be one of inherit, off, on');
            }
            $this->decisionModelReplyLanguage = $value === RunSettings::DECISION_MODEL_REPLY_LANGUAGE_INHERIT ? null : $value;
            $this->decisionModelReplyLanguageSetByAccountId = $setByAccountId;
            $this->decisionModelReplyLanguageSetAt = $setAtText;
        }
        if ($override->planDynamicsAskDecisionModel !== null) {
            // switch 1: `inherit` clears it, yes / no set it for this conversation
            $switchRow = RunSettingsOverride::parseSwitchRow($override->planDynamicsAskDecisionModel);
            if (!$switchRow->isValid) {
                throw new BadRequestException('planDynamicsAskDecisionModel must be one of inherit, yes, no');
            }
            $this->planDynamicsAskDecisionModel = $switchRow->storedValue;
            $this->planDynamicsSetByAccountId = $setByAccountId;
            $this->planDynamicsSetAt = $setAtText;
        }
        if ($override->planDynamicsModelReceivesVerdict !== null) {
            // switch 2: inert while switch 1 says no (the mode mapping treats "not asked" as off)
            $switchRow = RunSettingsOverride::parseSwitchRow($override->planDynamicsModelReceivesVerdict);
            if (!$switchRow->isValid) {
                throw new BadRequestException('planDynamicsModelReceivesVerdict must be one of inherit, yes, no');
            }
            $this->planDynamicsModelReceivesVerdict = $switchRow->storedValue;
            $this->planDynamicsSetByAccountId = $setByAccountId;
            $this->planDynamicsSetAt = $setAtText;
        }
        if ($override->agentWireApi !== null) {
            // the wire-API row: `inherit` clears it, a value pins this conversation's API
            $canonicalValue = strtolower(trim($override->agentWireApi)) === RunSettings::AGENT_WIRE_API_INHERIT
                ? RunSettings::AGENT_WIRE_API_INHERIT
                : self::canonicalAgentWireApi($override->agentWireApi);
            if ($canonicalValue === null) {
                throw new BadRequestException('agentWireApi must be one of inherit, ' . implode(', ', self::AGENT_WIRE_APIS));
            }
            $this->agentWireApi = $canonicalValue === RunSettings::AGENT_WIRE_API_INHERIT ? null : $canonicalValue;
            $this->agentWireApiSetByAccountId = $setByAccountId;
            $this->agentWireApiSetAt = $setAtText;
        }
        if ($override->previousReasoningCarryOver !== null) {
            // the reasoning carry-over row: `inherit` clears it, a value of the list sets it here
            $value = self::canonicalPreviousReasoningCarryOver($override->previousReasoningCarryOver);
            if ($value === null) {
                throw new BadRequestException('previousReasoningCarryOver must be one of inherit, ' . implode(', ', self::PREVIOUS_REASONING_CARRY_OVER_VALUES));
            }
            $this->previousReasoningCarryOver = $value === RunSettings::PREVIOUS_REASONING_CARRY_OVER_INHERIT ? null : $value;
            $this->previousReasoningCarryOverSetByAccountId = $setByAccountId;
            $this->previousReasoningCarryOverSetAt = $setAtText;
        }
        if ($override->previousReasoningCapTokens !== null) {
            // the cap of the carry-over `capped`: 0 clears it (the committed default), a positive count sets it here
            if ($override->previousReasoningCapTokens < 0) {
                throw new BadRequestException('previousReasoningCapTokens must be 0 (inherit) or a positive number of reasoning tokens');
            }
            $this->previousReasoningCapTokens = $override->previousReasoningCapTokens === 0 ? null : $override->previousReasoningCapTokens;
            $this->previousReasoningCapTokensSetByAccountId = $setByAccountId;
            $this->previousReasoningCapTokensSetAt = $setAtText;
        }
        if ($override->previousReasoningKeptTokensAtCut !== null) {
            // the min of a cut: a negative value clears it (inherit), 0 or a positive count sets it here
            $this->previousReasoningKeptTokensAtCut = $override->previousReasoningKeptTokensAtCut < 0 ? null : $override->previousReasoningKeptTokensAtCut;
            $this->previousReasoningKeptTokensAtCutSetByAccountId = $setByAccountId;
            $this->previousReasoningKeptTokensAtCutSetAt = $setAtText;
        }
        if ($override->previousReasoningKeptStepsAtCut !== null) {
            // the steps a cut keeps: a negative value clears it (inherit), a positive count sets it here
            if ($override->previousReasoningKeptStepsAtCut === 0) {
                throw new BadRequestException('previousReasoningKeptStepsAtCut must be -1 (inherit) or a positive number of steps');
            }
            $this->previousReasoningKeptStepsAtCut = $override->previousReasoningKeptStepsAtCut < 0 ? null : $override->previousReasoningKeptStepsAtCut;
            $this->previousReasoningKeptStepsAtCutSetByAccountId = $setByAccountId;
            $this->previousReasoningKeptStepsAtCutSetAt = $setAtText;
        }
        if ($override->previousReasoningHeadroomTokensAfterCut !== null) {
            // the headroom after a cut: a negative value clears it (inherit), 0 or a positive count sets it here
            $this->previousReasoningHeadroomTokensAfterCut = $override->previousReasoningHeadroomTokensAfterCut < 0 ? null : $override->previousReasoningHeadroomTokensAfterCut;
            $this->previousReasoningHeadroomTokensAfterCutSetByAccountId = $setByAccountId;
            $this->previousReasoningHeadroomTokensAfterCutSetAt = $setAtText;
        }
        if ($override->decisionModelOff !== null) {
            // the kill switch; false lifts it (stored as null so an untouched row stays byte-identical)
            $this->decisionModelOff = $override->decisionModelOff ? true : null;
            $this->decisionModelOffSetByAccountId = $setByAccountId;
            $this->decisionModelOffSetAt = $setAtText;
        }
        return $this;
    }

    /**
     * The canonical spelling of a wire API a run setting may name.
     *
     * @param string|null $value `chatCompletions` / `responses` / `messages` / `responsesServerState` in any case, with surrounding blanks
     * @return string|null The constant of {@see self::AGENT_WIRE_APIS}; null for anything else
     */
    public static function canonicalAgentWireApi(?string $value): ?string
    {
        $normalized = strtolower(trim((string)$value));
        foreach (static::AGENT_WIRE_APIS as $agentWireApi) {
            if ($normalized === strtolower($agentWireApi)) {
                return $agentWireApi;
            }
        }
        return null;
    }

    /**
     * Whether a wire API speaks the Responses API: through the gateway, or directly at OpenAI with server state.
     *
     * @param string|null $agentWireApi
     * @return bool
     */
    public static function isResponsesAgentWireApi(?string $agentWireApi): bool
    {
        return $agentWireApi === self::AGENT_WIRE_API_RESPONSES || $agentWireApi === self::AGENT_WIRE_API_RESPONSES_SERVER_STATE;
    }

    /**
     * Whether a wire API can add tools and instructions in the middle of a conversation, which append-only skill
     * loading needs: the Responses API (an `additional_tools` item, a `developer` message) and the Anthropic Messages
     * API (a `tool_addition` block, a `role: "system"` message). Chat Completions cannot.
     *
     * @param string|null $agentWireApi
     * @return bool
     */
    public static function isAppendOnlyCapableAgentWireApi(?string $agentWireApi): bool
    {
        return self::isResponsesAgentWireApi($agentWireApi) || $agentWireApi === self::AGENT_WIRE_API_MESSAGES;
    }

    /**
     * The canonical spelling of a carry-over row value — `inherit` or one of {@see PREVIOUS_REASONING_CARRY_OVER_VALUES},
     * any case, trimmed; the legacy `on` reads as `all`; null for anything else.
     */
    public static function canonicalPreviousReasoningCarryOver(string $previousReasoningCarryOver): ?string
    {
        $normalized = strtolower(trim($previousReasoningCarryOver));
        if ($normalized === self::PREVIOUS_REASONING_CARRY_OVER_ALL_LEGACY_SPELLING) {
            return self::PREVIOUS_REASONING_CARRY_OVER_ALL;
        }
        foreach ([self::PREVIOUS_REASONING_CARRY_OVER_INHERIT, ...self::PREVIOUS_REASONING_CARRY_OVER_VALUES] as $previousReasoningCarryOverValue) {
            if ($normalized === strtolower($previousReasoningCarryOverValue)) {
                return $previousReasoningCarryOverValue;
            }
        }
        return null;
    }

    /**
     * The carry-over row set to `off` by the platform after the provider refused the carried reasoning items
     * (`invalid_encrypted_content`): no admin account in the audit fields, the moment of the refusal
     * as its time. Every later model call of the conversation sends no reasoning item.
     */
    public function switchPreviousReasoningCarryOverOffAfterRejection(DateTime $rejectedAt): static
    {
        $this->previousReasoningCarryOver = self::PREVIOUS_REASONING_CARRY_OVER_OFF;
        $this->previousReasoningCarryOverSetByAccountId = null;
        $this->previousReasoningCarryOverSetAt = $rejectedAt->format('Y-m-d H:i:s');
        return $this;
    }
}
