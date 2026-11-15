<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Prompts;

/**
 * The prompt names (config paths under `AI.Prompts.`) the agent core reads. The package ships a neutral default file
 * for each under its `config/app/AI/Prompts/…` (read by ddd-ai's AIPromptsService through `Config`); an application's
 * file at the same path replaces it whole. An application's own prompt catalog may reference these constants so each
 * name has one source.
 */
class AgentAIPromptNames
{
    /** The customer-facing activity lines a small model writes for the tool calls of a running turn. */
    public const string APP_AI_AGENT_ACTIVITY_LINES = 'AI.Agent.ActivityLines';

    /** @var string The agentic base prompt for the MCP tool-use flow (load_skills → call tools → answer). */
    public const string APP_AI_AGENT_AGENTIC = 'AI.Agent.Agentic';

    /**
     * @var string The SHARED identity core — WHO the agent is (initiative, informal address and everyday forms by
     * default, mirroring, drive, humour with a source, the frustration protocol, honesty), medium-neutral; the owner's
     * preferences override the voice only. Injected into the chat base prompt (the {%AGENT_CHARACTER%} placeholder in
     * {@see self::APP_AI_AGENT_AGENTIC}, persona names filled) and available to other channels (an application's phone
     * instructions), so the channels cannot diverge in personality. Channel machinery stays in the per-channel prompts.
     */
    public const string APP_AI_AGENT_CHARACTER = 'AI.Agent.Character';

    /**
     * @var string The simulated owner of a judged compaction-tree run: one owner message per turn that opens a new
     * topic, so the compaction tree grows. Placeholders, filled in this order: GOAL, FACTS, COMPACTION_PARAMETERS,
     * TRANSCRIPT.
     */
    public const string APP_AI_AGENT_EVALUATION_COMPACTION_TREE_DRIVER = 'AI.Agent.Evaluation.CompactionTreeDriver';

    /**
     * @var string The judge of a compaction-tree run: one JSON verdict on the tree's growth, cover and fact survival.
     * Placeholders, filled in this order: COMPACTION_PARAMETERS, GOAL, RUBRIC, TREE_EVOLUTION, FINAL_TREE, TRACE.
     */
    public const string APP_AI_AGENT_EVALUATION_COMPACTION_TREE_VERDICT = 'AI.Agent.Evaluation.CompactionTreeVerdict';

    /**
     * @var string The simulated owner of a judged run: the next owner message, or `[DONE]` / `[GIVE-UP]`.
     * Placeholders, filled in this order: GOAL, FACTS, TRANSCRIPT.
     */
    public const string APP_AI_AGENT_EVALUATION_DRIVER = 'AI.Agent.Evaluation.Driver';

    /**
     * @var string The judge of a judged run: one JSON verdict on the trace against the case's rubric. Placeholders,
     * filled in this order: GOAL, OPENING, RUBRIC, HOST_RULES, HOST_SECTIONS, TRACE — HOST_RULES (further lines of the
     * minor list) and HOST_SECTIONS (further sections after the trace) are the application's and empty by default.
     */
    public const string APP_AI_AGENT_EVALUATION_VERDICT = 'AI.Agent.Evaluation.Verdict';

    /** @var string Base prompt for the AI-extensions / specialist flow (no skills/MCP tools). */
    public const string APP_AI_AGENT_GENERAL = 'AI.Agent.General';

    /** The owner-consent decision behind the five tools that need the owner's personal yes — a decision-model call, cost keys only. */
    public const string APP_AI_AGENT_OWNER_CONSENT_DECISION = 'AI.Agent.OwnerConsentDecision';

    /** The injection check of the current owner message at the turn start — a decision-model call, cost keys only. */
    public const string APP_AI_AGENT_OWNER_MESSAGE_INJECTION_DECISION = 'AI.Agent.OwnerMessageInjectionDecision';

    /**
     * The rules of the security gate the injection check of an owner message applies — a prompt text the check's one
     * question carries; an application replaces it with rules that name its own assistant and its owners' data.
     */
    public const string APP_AI_AGENT_OWNER_MESSAGE_INJECTION_GATE_RULES = 'AI.Agent.OwnerMessageInjectionGateRules';

    /** The probe decision of an application's command (questions from a fixture, never wired into the loop) — cost keys only. */
    public const string APP_AI_AGENT_PROBE_DECISION = 'AI.Agent.ProbeDecision';

    /** The stall-recovery decision at the second stall (a registry name for cost booking; no text is sent). */
    public const string APP_AI_AGENT_STALL_RECOVERY_DECISION = 'AI.Agent.StallRecoveryDecision';

    /** @var string Appended to the base prompt for DELEGATION (sub-agent) conversations — the child's isolation directive. */
    public const string APP_AI_AGENT_SUB_AGENT_DIRECTIVE = 'AI.Agent.SubAgentDirective';

    /** The injection screening of an untrusted tool result in the background — a decision-model call, cost keys only. */
    public const string APP_AI_AGENT_TOOL_RESULT_INJECTION_DECISION = 'AI.Agent.ToolResultInjectionDecision';

    public const string APP_AI_AGENT_TURN_CLOSING_DECISION = 'AI.Agent.TurnClosingDecision';

    /** @var string The agent's reply-language DECISION — a System One decision-model call, not a prompt: the
     *      registry entry names it for cost booking + usage rows; the questions live in code
     *      (ArgusAgentTurnStartDecision::buildQuestions()). Counted against the usage cap like every op. */
    public const string APP_AI_AGENT_TURN_START_DECISION = 'AI.Agent.TurnStartDecision';

    /** @var string Compact (context-engineering) summary of a conversation's history up to an anchor (recall-first, fixed schema). */
    public const string APP_AI_CONVERSATION_COMPACTION = 'AI.Conversation.Compaction';

    /** @var string Summarize AI Conversations */
    public const string APP_AI_CONVERSATION_SUMMARY = 'AI.Conversation.Summary';

    /** @var string Detect the language the USER writes their conversation messages in, against the closed set of
     *      active app languages (the automatic interface-language confirmation of a purposed run) — a light
     *      classification routed via FAST_CHEAP, budget-exempt like the schedule judgment. */
    public const string APP_AI_CONVERSATION_USER_LANGUAGE_DETECTION = 'AI.Conversation.UserLanguageDetection';

    /** @var string Schedule doneCriteria judgment: decide from a completed run's final answer
     *      whether the recurring goal is FULFILLED (schedule → DONE) — a light classification routed via FAST_CHEAP. */
    public const string APP_AI_SCHEDULE_DONE_CRITERIA_JUDGMENT = 'AI.Schedules.DoneCriteriaJudgment';

    /** @var string Search-result rerank: scores N short candidates for relevance to a query (the LLM
     *      precision stage; routed via the FAST_CHEAP scope, a light classification, latency-first). */
    public const string APP_AI_SEARCH_RERANK = 'AI.Search.Rerank';

    /** @var string Serves as system prompt for public-facing AI operations */
    public const string APP_AI_SYSTEM_PROMPT_PUBLIC_FACING = 'AI.SystemPrompts.PublicFacing';

    /** @var string Mandate approval check — verify against the server-pulled dialogue window that the CUSTOMER
     *      actually granted the requested mandate (action class + scope), extracting verbatim customer quotes as
     *      the evidence excerpt; quantity-based grants are rejected with a conversion instruction. */
    public const string APP_AI_MANDATE_APPROVAL_CHECK = 'AI.Mandates.ApprovalCheck';
}
