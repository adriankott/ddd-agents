<?php

declare(strict_types=1);

/**
 * The context-retention defaults of the agent — the package default, read by
 * {@see \DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings} as `Config::get('AI.contextRetention')`.
 * An application replaces this file whole with its own `config/app/AI/contextRetention.php` (keys are never merged).
 * A non-empty `AGENT_UNLOAD_GRANULARITY` environment variable (`skill` / `tool`) overrides `unloadGranularity`.
 *
 * `unloadGranularity`: `skill` = a skill leaves with all its tools at once; `tool` = the per-tool cost-benefit score.
 * `skillLoadingStrategy`: `systemPromptSwap` (loaded skills render in the system content) or `conversationAppend`
 * (append-only skill rows inside a prefix epoch, Responses API only; a Chat Completions model falls back to the swap).
 * `rebuildEpochOnSituationChange`, `stateChangeDelivery` (`everyCall` / `onceWhenChanged`),
 * `appendOnlyReachableToolSetCeilingTokens` and `previousReasoningCapTokens`: see the settings class.
 * `previousReasoningCapTokensByAgentWireApi`: the cap of the carry-over `capped` per wire API (`responses`, `messages`),
 * e.g. `['messages' => 12000]`; a wire API without an entry takes `previousReasoningCapTokens`.
 * `toolDelivery` (`native` / `callTool` / `callToolJsonSchema`, append-only loading only): `callTool` declares the meta
 * tools plus one fixed `call_tool(name, arguments)` and lists a loaded skill's tools as text in its load; `native`
 * declares every tool. `dropColdSkillsAtCompactionRebase`: at the first model call after a compaction, skills unused for
 * `unloadSkillAfterUnusedTurns` owner turns are unloaded once per epoch (needs `unloadGranularity: skill`). Both are
 * stamped on a NEW conversation; a conversation stored without the stamp keeps `native` and unloads nothing.
 */
return [
    'unloadGranularity' => 'skill',
    'skillLoadingStrategy' => 'conversationAppend',
    'rebuildEpochOnSituationChange' => true,
    'stateChangeDelivery' => 'onceWhenChanged',
    'appendOnlyReachableToolSetCeilingTokens' => 55000,
    'previousReasoningCapTokens' => 4000,
    'previousReasoningCapTokensByAgentWireApi' => [],
    'toolDelivery' => 'callTool',
    'dropColdSkillsAtCompactionRebase' => true,
];
