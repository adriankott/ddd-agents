<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Wire;

use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Models\AIModel as BaseAIModel;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use Throwable;

/**
 * How the agent egress talks to the model for one run: the wire API (Chat Completions or
 * Responses), the skill-loading strategy (system-prompt swap or append-only) and whether the provider's reasoning items
 * are sent back — resolved from the model's catalog entry, the conversation's run settings and its stamped context
 * retention settings, and VALIDATED against the allowed combinations. A rejected combination carries the reason line
 * instead of being switched silently. Pure, no repository contact; the loop resolves it once per run.
 *
 * @phpstan-consistent-constructor
 */
class AgentWireConfiguration
{
    /** The Chat-Completions + append-only rejection. */
    public const string REJECTION_CONVERSATION_APPEND_NEEDS_RESPONSES = 'skillLoadingStrategy conversationAppend needs the Responses or the Messages API: Chat Completions has no mid-conversation tool addition (allowed_tools is ignored, a changed tools array loses the cached prefix)';

    /** The Responses + non-OpenAI-model rejection. */
    public const string REJECTION_RESPONSES_NEEDS_OPENAI_MODEL = 'agentWireApi responses is probed for OpenAI models only (GPT-6 Luna, GPT-6 Sol, GPT-5.6 Luna)';

    /** The Responses + LiteLLM-egress rejection. */
    public const string REJECTION_RESPONSES_NEEDS_DIRECT_OPENROUTER = 'agentWireApi responses needs the direct OpenRouter egress: the LiteLLM proxy\'s Responses route is untested';

    /**
     * The server-state arm + system-prompt swap rejection: under the swap the system content and the top-level tools move
     * with every skill load, and each move needs a fresh server-side chain (the whole input again); under the append-only
     * strategy both stay fixed for the epoch, so the chain survives the conversation.
     */
    public const string REJECTION_SERVER_STATE_NEEDS_CONVERSATION_APPEND = 'agentWireApi responsesServerState is a test arm for skillLoadingStrategy conversationAppend only: under systemPromptSwap every skill load changes the system content and the tools, which restarts the server-side chain';

    /**
     * The server-state arm + `stateChangeDelivery = onceWhenChanged` rejection:
     * the notes under a server-side chain are not built — the clock there is a per-call tail entry
     * the arm's design replaces, and nothing of the combination is measured.
     */
    public const string REJECTION_SERVER_STATE_WITH_STATE_NOTES = 'agentWireApi responsesServerState with stateChangeDelivery onceWhenChanged is not built: the state notes under a server-side chain are unmeasured';

    /** The Messages API is Anthropic's own wire: no other vendor's model is served on it. */
    public const string REJECTION_MESSAGES_NEEDS_ANTHROPIC_MODEL = 'agentWireApi messages is the Anthropic Messages API: only an Anthropic model runs on it';

    /** The Messages request (thinking binding, mid-conversation system messages and tool additions, provider pin) is probed on OpenRouter's `/messages` endpoint only. */
    public const string REJECTION_MESSAGES_NEEDS_DIRECT_OPENROUTER = 'agentWireApi messages needs the direct OpenRouter egress: no other egress is probed for the Messages API';

    /** @var string {@see AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS}, {@see AIModel::AGENT_WIRE_API_RESPONSES}, {@see AIModel::AGENT_WIRE_API_MESSAGES} or {@see RunSettings::AGENT_WIRE_API_RESPONSES_SERVER_STATE}. */
    public string $wireApi = AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS;

    /** @var string {@see ContextRetentionSettings::SKILL_LOADING_STRATEGIES}. */
    public string $skillLoadingStrategy = ContextRetentionSettings::SKILL_LOADING_STRATEGY_SYSTEM_PROMPT_SWAP;

    /**
     * @var string Which reasoning items of the previous calls go back on a call — the RESOLVED mode, never `inherit`
     *      ({@see RunSettings::PREVIOUS_REASONING_CARRY_OVER_VALUES}): `all` (every earlier step's), `currentTurn` (only
     *      the steps since the newest owner message; an earlier turn's items stay off the wire), `capped` (every earlier
     *      step's up to {@see $previousReasoningCapTokens}, then cut in one block, {@see AgentPreviousReasoningCut}) or
     *      `off` (none).
     */
    public string $previousReasoningCarryOver = RunSettings::PREVIOUS_REASONING_CARRY_OVER_OFF;

    /**
     * The built-in cap of the carry-over `capped` when the committed config names none. 4,000 — the value
     * {@see AgentPreviousReasoningCut} is laid out for: its two kept steps restart the carried block well below the cap,
     * so a cut is followed by several steps with a stable prefix. A cap the two kept steps alone exceed (at 1,000 and
     * 300–900 reasoning tokens per step at medium effort) moves the cut on almost every call of a planning turn.
     */
    public const int DEFAULT_PREVIOUS_REASONING_CAP_TOKENS = 4000;

    /**
     * @var int The cap of the carry-over `capped` in reasoning tokens — the conversation's row, else the committed default
     *      of the run's wire API ({@see resolveDefaultPreviousReasoningCapTokens()}); read only under `capped`
     *      ({@see AgentPreviousReasoningCut}).
     */
    public int $previousReasoningCapTokens = self::DEFAULT_PREVIOUS_REASONING_CAP_TOKENS;

    /** The built-in min of a cut: 0 — only the steps {@see DEFAULT_PREVIOUS_REASONING_KEPT_STEPS_AT_CUT} asks for are kept. */
    public const int DEFAULT_PREVIOUS_REASONING_KEPT_TOKENS_AT_CUT = 0;

    /** The built-in count of steps a cut keeps ({@see AgentPreviousReasoningCut::KEPT_NEWEST_STEPS_AT_A_CUT}). */
    public const int DEFAULT_PREVIOUS_REASONING_KEPT_STEPS_AT_CUT = AgentPreviousReasoningCut::KEPT_NEWEST_STEPS_AT_A_CUT;

    /** The built-in headroom after a cut: 0 — the cap alone decides the next cut. */
    public const int DEFAULT_PREVIOUS_REASONING_HEADROOM_TOKENS_AFTER_CUT = 0;

    /**
     * @var int At a cut of `capped`: walking back from the newest step, whole steps are kept until at least this many
     *      reasoning tokens (the min). Resolved like {@see $previousReasoningCapTokens}.
     */
    public int $previousReasoningKeptTokensAtCut = self::DEFAULT_PREVIOUS_REASONING_KEPT_TOKENS_AT_CUT;

    /** @var int At a cut of `capped`: at least this many of the newest steps keep their reasoning. Resolved like {@see $previousReasoningCapTokens}. */
    public int $previousReasoningKeptStepsAtCut = self::DEFAULT_PREVIOUS_REASONING_KEPT_STEPS_AT_CUT;

    /**
     * @var int After a cut of `capped`: the next cut waits until the carried reasoning exceeds the larger of the cap and
     *      the reasoning kept at that cut plus this many tokens. Resolved like {@see $previousReasoningCapTokens}.
     */
    public int $previousReasoningHeadroomTokensAfterCut = self::DEFAULT_PREVIOUS_REASONING_HEADROOM_TOKENS_AFTER_CUT;

    /** @var string|null Why this combination is not allowed; null = allowed. */
    public ?string $rejectionReason = null;

    /**
     * The configuration of a run with `$aiModel` (null = unresolved), the conversation's run settings and its stamped
     * context retention settings (a conversation without a stamped strategy takes the global default for the resolved
     * wire API, {@see ContextRetentionSettings::getSkillLoadingStrategyForAgentWireApi()}), over the direct OpenRouter
     * egress or not. The matrix:
     * Chat Completions + system-prompt swap → allowed (every model, both egresses); Chat Completions + append-only →
     * rejected; Responses + either strategy → allowed for an OpenAI model over the direct OpenRouter egress, rejected
     * otherwise; Messages + either strategy → allowed for an Anthropic model over the direct OpenRouter egress,
     * rejected otherwise. A wire API that only the model's catalog entry names falls back to Chat Completions when the
     * egress cannot serve it ({@see resolveWireApiFor()}): a catalog default never makes a model unusable.
     */
    public static function resolveFor(
        ?BaseAIModel $aiModel,
        ?RunSettings $runSettings,
        ?ContextRetentionSettings $contextRetentionSettings,
        bool $directOpenRouterEgress
    ): static {
        $agentWireConfiguration = new static();
        $agentWireConfiguration->wireApi = self::resolveWireApiFor($aiModel, $runSettings, $directOpenRouterEgress);
        // an unstamped conversation takes the global default for its wire API: append-only on Chat Completions would be
        // refused, and a default must never make a model unusable
        $agentWireConfiguration->skillLoadingStrategy = ($contextRetentionSettings ?? new ContextRetentionSettings())
            ->getSkillLoadingStrategyForAgentWireApi($agentWireConfiguration->wireApi);
        // on by default on the Responses API; on Chat Completions only when the row asks for it (the
        // A arm stays today's request, A′ = A + `all` measures the carry-over alone)
        // the server-state arm never carries reasoning items: OpenAI keeps them in the stored conversation state
        // the model's catalog value applies where `reasoning_details` reach the provider: the Responses API, or Chat
        // Completions over the direct OpenRouter egress (a proxy may drop the field)
        // the catalog's value for the wire the run actually speaks (after the run settings' row and any fallback): a
        // value may be measured on one wire only (`agenticUseCase.previousReasoningByWireApi`)
        $modelPreviousReasoning = $aiModel?->getAgenticPreviousReasoningCarryOver($agentWireConfiguration->wireApi);
        $agentWireConfiguration->previousReasoningCarryOver = self::resolvePreviousReasoningCarryOver(
            $runSettings->previousReasoningCarryOver ?? null,
            $agentWireConfiguration->wireApi,
            $directOpenRouterEgress || $agentWireConfiguration->wireApi !== AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS
                ? $modelPreviousReasoning?->getCarryOver()
                : null
        );
        // the numbers of the cut, each on its own: the conversation's row, else the model's catalog value for the
        // resolved wire, else the committed default of the wire API, else the global one
        $agentWireConfiguration->previousReasoningCapTokens = self::firstCountOfAtLeast(1, [$runSettings->previousReasoningCapTokens ?? null, $modelPreviousReasoning?->capTokens])
            ?? static::resolveDefaultPreviousReasoningCapTokens($agentWireConfiguration->wireApi);
        $agentWireConfiguration->previousReasoningKeptTokensAtCut = self::firstCountOfAtLeast(0, [$runSettings->previousReasoningKeptTokensAtCut ?? null, $modelPreviousReasoning?->keptTokensAtCut])
            ?? static::resolveDefaultPreviousReasoningKeptTokensAtCut($agentWireConfiguration->wireApi);
        $agentWireConfiguration->previousReasoningKeptStepsAtCut = self::firstCountOfAtLeast(1, [$runSettings->previousReasoningKeptStepsAtCut ?? null, $modelPreviousReasoning?->keptStepsAtCut])
            ?? static::resolveDefaultPreviousReasoningKeptStepsAtCut($agentWireConfiguration->wireApi);
        $agentWireConfiguration->previousReasoningHeadroomTokensAfterCut = self::firstCountOfAtLeast(0, [$runSettings->previousReasoningHeadroomTokensAfterCut ?? null, $modelPreviousReasoning?->headroomTokensAfterCut])
            ?? static::resolveDefaultPreviousReasoningHeadroomTokensAfterCut($agentWireConfiguration->wireApi);
        $agentWireConfiguration->rejectionReason = self::findRejectionReason(
            $agentWireConfiguration->wireApi,
            $agentWireConfiguration->skillLoadingStrategy,
            $aiModel->vendor ?? null,
            $directOpenRouterEgress
        ) ?? self::findStateChangeDeliveryRejectionReason(
            $agentWireConfiguration->wireApi,
            ($contextRetentionSettings ?? new ContextRetentionSettings())->deliversStateChangesOnce()
        );
        return $agentWireConfiguration;
    }

    /**
     * The wire API a run with `$aiModel` (null = unresolved) speaks under the conversation's run settings: the run
     * settings' row ({@see RunSettings::$agentWireApi}), else the model's catalog value as far as this agent speaks it
     * ({@see resolveWireApiOfCatalogValue()}), else Chat Completions. A
     * catalog value the egress cannot serve — the Responses or the Messages API without the direct OpenRouter egress
     * (`$directOpenRouterEgress` false; null = not known, no fallback) — becomes Chat Completions: the catalog names
     * a model's best wire, never a requirement. A row is kept as it is and refused by {@see findRejectionReason()}.
     */
    public static function resolveWireApiFor(?BaseAIModel $aiModel, ?RunSettings $runSettings, ?bool $directOpenRouterEgress = null): string
    {
        $wireApiOfRow = RunSettings::canonicalAgentWireApi($runSettings?->agentWireApi);
        if ($wireApiOfRow !== null) {
            return $wireApiOfRow;
        }
        $wireApiOfCatalog = $aiModel instanceof AIModel ? static::resolveWireApiOfCatalogValue($aiModel) : AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS;
        if ($directOpenRouterEgress === false && $wireApiOfCatalog !== AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS) {
            return AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS;
        }
        return $wireApiOfCatalog;
    }

    /**
     * The wire APIs a model's catalog entry may name for this agent. The server-state arm is no catalog value: it comes
     * from a conversation's run settings only ({@see RunSettings::AGENT_WIRE_APIS}).
     */
    public const array CATALOG_WIRE_APIS = [
        AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS,
        AIModel::AGENT_WIRE_API_RESPONSES,
        AIModel::AGENT_WIRE_API_MESSAGES,
    ];

    /** @var array<string, true> The catalog values already logged as not spoken, by model name and value (one line per process). */
    protected static array $loggedCatalogWireApisNotSpoken = [];

    /**
     * The wire API the catalog entry of `$aiModel` names, as this agent speaks it. The model catalog returns the
     * value as written ({@see AIModel::getAgentWireApi()}); it is matched here against {@see CATALOG_WIRE_APIS},
     * whatever its case and surrounding blanks. A value this agent does not speak (a wire API of a newer agent
     * version, a typing error) is Chat Completions, with one warning per model and process: a catalog default never
     * makes a model unusable.
     */
    public static function resolveWireApiOfCatalogValue(AIModel $aiModel): string
    {
        $catalogValue = $aiModel->getAgentWireApi();
        $normalizedCatalogValue = strtolower(trim($catalogValue));
        foreach (static::CATALOG_WIRE_APIS as $catalogWireApi) {
            if ($normalizedCatalogValue === strtolower($catalogWireApi)) {
                return $catalogWireApi;
            }
        }
        $logKey = ($aiModel->name ?? '') . '|' . $catalogValue;
        if (!isset(static::$loggedCatalogWireApisNotSpoken[$logKey])) {
            static::$loggedCatalogWireApisNotSpoken[$logKey] = true;
            static::logCatalogWireApiNotSpoken($aiModel->name ?? null, $catalogValue);
        }
        return AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS;
    }

    /** Logs a catalog `settings.agentWireApi` this agent does not speak; the agent speaks Chat Completions to that model. */
    protected static function logCatalogWireApiNotSpoken(?string $modelName, string $catalogValue): void
    {
        DDDService::instance()->getLogger()->warning(
            '[AgentWireConfiguration] The model catalog names a wire API this agent does not speak (settings.agentWireApi) — the agent speaks Chat Completions',
            ['model' => $modelName, 'agentWireApi' => $catalogValue, 'spoken' => static::CATALOG_WIRE_APIS]
        );
    }

    /** The reason line of a combination the matrix rejects, null for an allowed one. */
    public static function findRejectionReason(string $wireApi, string $skillLoadingStrategy, ?string $modelVendor, bool $directOpenRouterEgress): ?string
    {
        if ($wireApi === AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS) {
            return $skillLoadingStrategy === ContextRetentionSettings::SKILL_LOADING_STRATEGY_CONVERSATION_APPEND
                ? self::REJECTION_CONVERSATION_APPEND_NEEDS_RESPONSES
                : null;
        }
        if ($wireApi === AIModel::AGENT_WIRE_API_MESSAGES) {
            if ($modelVendor !== AIModel::VENDOR_ANTHROPIC) {
                return self::REJECTION_MESSAGES_NEEDS_ANTHROPIC_MODEL;
            }
            return $directOpenRouterEgress ? null : self::REJECTION_MESSAGES_NEEDS_DIRECT_OPENROUTER;
        }
        if ($modelVendor !== AIModel::VENDOR_OPENAI) {
            return self::REJECTION_RESPONSES_NEEDS_OPENAI_MODEL;
        }
        if ($wireApi === RunSettings::AGENT_WIRE_API_RESPONSES_SERVER_STATE) {
            // the arm calls OpenAI directly (OpenRouter rejects `store: true` / `previous_response_id`): the OpenRouter
            // egress does not matter to it
            return $skillLoadingStrategy === ContextRetentionSettings::SKILL_LOADING_STRATEGY_CONVERSATION_APPEND
                ? null
                : self::REJECTION_SERVER_STATE_NEEDS_CONVERSATION_APPEND;
        }
        if (!$directOpenRouterEgress) {
            return self::REJECTION_RESPONSES_NEEDS_DIRECT_OPENROUTER;
        }
        return null;
    }

    /** The reason line of a wire API the state notes do not run on yet; null when they do. */
    public static function findStateChangeDeliveryRejectionReason(string $wireApi, bool $deliversStateChangesOnce): ?string
    {
        return $deliversStateChangesOnce && $wireApi === RunSettings::AGENT_WIRE_API_RESPONSES_SERVER_STATE
            ? self::REJECTION_SERVER_STATE_WITH_STATE_NOTES
            : null;
    }

    /**
     * The error texts with which a provider refuses carried reasoning items: the error code
     * `invalid_encrypted_content` (a blob that cannot be verified — another model, a tampered item) and the organisation
     * mismatch of an item minted under another OpenAI organisation (a shared-capacity or second-key route); on the
     * Messages API Anthropic's ``Invalid `signature` in `thinking` block`` — a block bound to another conversation
     * prefix or model on a request that did not ask to drop it, or a signature that cannot be read at all (which the
     * drop setting does not cover).
     */
    public const array CARRIED_REASONING_REJECTION_ERROR_TEXTS = ['invalid_encrypted_content', 'organization_id did not match', 'in `thinking` block'];

    /**
     * Whether the provider error of a call (the JSON of the response's `error` object, {@see \DDD\Domain\AI\Entities\Conversations\Agent\ModelResponse::$providerError})
     * refuses the reasoning items the call carried — its code or message names one of
     * {@see CARRIED_REASONING_REJECTION_ERROR_TEXTS}, case-insensitive (OpenRouter forwards the upstream error in the
     * message or in `metadata.raw`).
     */
    public static function providerErrorRejectsTheCarriedReasoningItems(?string $providerError): bool
    {
        if ($providerError === null || $providerError === '') {
            return false;
        }
        $providerErrorLowercased = strtolower($providerError);
        foreach (self::CARRIED_REASONING_REJECTION_ERROR_TEXTS as $rejectionErrorText) {
            if (str_contains($providerErrorLowercased, strtolower($rejectionErrorText))) {
                return true;
            }
        }
        return false;
    }

    /**
     * The carry-over mode a call on `$wireApi` runs with, from the conversation's row (null = inherit): the row's value
     * (the legacy `on` reads as `all`), else the model's catalog value (`$modelCarryOver`,
     * {@see \DDD\Domain\AI\Entities\Models\AIModel::getAgenticPreviousReasoningCarryOver()} for the wire of the call, catalog key
     * `agenticUseCase.previousReasoning.carryOver` — e.g. `currentTurn` for a
     * reasoning model that keeps its verdicts only in its reasoning), else the per-API default — `capped` on the
     * Responses and the Messages API (cap {@see resolveDefaultPreviousReasoningCapTokens()}), `off` on Chat
     * Completions; always `off` on the server-state arm.
     */
    public static function resolvePreviousReasoningCarryOver(?string $previousReasoningCarryOverRow, string $wireApi, ?string $modelCarryOver = null): string
    {
        if ($wireApi === RunSettings::AGENT_WIRE_API_RESPONSES_SERVER_STATE) {
            return RunSettings::PREVIOUS_REASONING_CARRY_OVER_OFF;
        }
        $canonicalRow = $previousReasoningCarryOverRow !== null ? RunSettings::canonicalPreviousReasoningCarryOver($previousReasoningCarryOverRow) : null;
        if ($canonicalRow !== null && $canonicalRow !== RunSettings::PREVIOUS_REASONING_CARRY_OVER_INHERIT) {
            return $canonicalRow;
        }
        $canonicalModelCarryOver = $modelCarryOver !== null ? RunSettings::canonicalPreviousReasoningCarryOver($modelCarryOver) : null;
        if ($canonicalModelCarryOver !== null && $canonicalModelCarryOver !== RunSettings::PREVIOUS_REASONING_CARRY_OVER_INHERIT) {
            return $canonicalModelCarryOver;
        }
        return $wireApi === AIModel::AGENT_WIRE_API_RESPONSES || $wireApi === AIModel::AGENT_WIRE_API_MESSAGES
            ? RunSettings::PREVIOUS_REASONING_CARRY_OVER_CAPPED
            : RunSettings::PREVIOUS_REASONING_CARRY_OVER_OFF;
    }

    /** Whether the calls send any reasoning item of a previous call back (every mode but `off`). */
    public function carriesPreviousReasoning(): bool
    {
        return $this->previousReasoningCarryOver !== RunSettings::PREVIOUS_REASONING_CARRY_OVER_OFF;
    }

    /** The config key (below `AI.contextRetention`) of the caps per wire API. */
    public const string CONFIG_KEY_PREVIOUS_REASONING_CAP_TOKENS_BY_AGENT_WIRE_API = 'previousReasoningCapTokensByAgentWireApi';

    /**
     * The committed default cap of the carry-over `capped` on `$wireApi` (null = no wire API known): the wire API's
     * entry in `AI.contextRetention.previousReasoningCapTokensByAgentWireApi` (a positive count, keyed by the wire API,
     * e.g. `messages`), else `AI.contextRetention.previousReasoningCapTokens` (a positive count), else
     * {@see DEFAULT_PREVIOUS_REASONING_CAP_TOKENS}. A wire API whose carried reasoning is cheap to keep and costly to
     * cut (a cut rewrites the cached history once) gets its own, higher cap here. No environment variable. Never throws.
     */
    public static function resolveDefaultPreviousReasoningCapTokens(?string $wireApi = null): int
    {
        return self::resolveConfiguredCount('previousReasoningCapTokens', self::CONFIG_KEY_PREVIOUS_REASONING_CAP_TOKENS_BY_AGENT_WIRE_API, $wireApi, 1, self::DEFAULT_PREVIOUS_REASONING_CAP_TOKENS);
    }

    /** The config key (below `AI.contextRetention`) of the min of a cut per wire API. */
    public const string CONFIG_KEY_PREVIOUS_REASONING_KEPT_TOKENS_AT_CUT_BY_AGENT_WIRE_API = 'previousReasoningKeptTokensAtCutByAgentWireApi';

    /** The config key (below `AI.contextRetention`) of the steps a cut keeps per wire API. */
    public const string CONFIG_KEY_PREVIOUS_REASONING_KEPT_STEPS_AT_CUT_BY_AGENT_WIRE_API = 'previousReasoningKeptStepsAtCutByAgentWireApi';

    /** The config key (below `AI.contextRetention`) of the headroom after a cut per wire API. */
    public const string CONFIG_KEY_PREVIOUS_REASONING_HEADROOM_TOKENS_AFTER_CUT_BY_AGENT_WIRE_API = 'previousReasoningHeadroomTokensAfterCutByAgentWireApi';

    /**
     * The committed default min of a cut on `$wireApi`: `AI.contextRetention.previousReasoningKeptTokensAtCutByAgentWireApi`
     * (0 or more, keyed by the wire API), else `AI.contextRetention.previousReasoningKeptTokensAtCut`, else
     * {@see DEFAULT_PREVIOUS_REASONING_KEPT_TOKENS_AT_CUT}. Never throws.
     */
    public static function resolveDefaultPreviousReasoningKeptTokensAtCut(?string $wireApi = null): int
    {
        return self::resolveConfiguredCount('previousReasoningKeptTokensAtCut', self::CONFIG_KEY_PREVIOUS_REASONING_KEPT_TOKENS_AT_CUT_BY_AGENT_WIRE_API, $wireApi, 0, self::DEFAULT_PREVIOUS_REASONING_KEPT_TOKENS_AT_CUT);
    }

    /**
     * The committed default count of steps a cut keeps on `$wireApi`: `AI.contextRetention.previousReasoningKeptStepsAtCutByAgentWireApi`
     * (a positive count, keyed by the wire API), else `AI.contextRetention.previousReasoningKeptStepsAtCut`, else
     * {@see DEFAULT_PREVIOUS_REASONING_KEPT_STEPS_AT_CUT}. Never throws.
     */
    public static function resolveDefaultPreviousReasoningKeptStepsAtCut(?string $wireApi = null): int
    {
        return self::resolveConfiguredCount('previousReasoningKeptStepsAtCut', self::CONFIG_KEY_PREVIOUS_REASONING_KEPT_STEPS_AT_CUT_BY_AGENT_WIRE_API, $wireApi, 1, self::DEFAULT_PREVIOUS_REASONING_KEPT_STEPS_AT_CUT);
    }

    /**
     * The committed default headroom after a cut on `$wireApi`: `AI.contextRetention.previousReasoningHeadroomTokensAfterCutByAgentWireApi`
     * (0 or more, keyed by the wire API), else `AI.contextRetention.previousReasoningHeadroomTokensAfterCut`, else
     * {@see DEFAULT_PREVIOUS_REASONING_HEADROOM_TOKENS_AFTER_CUT}. Never throws.
     */
    public static function resolveDefaultPreviousReasoningHeadroomTokensAfterCut(?string $wireApi = null): int
    {
        return self::resolveConfiguredCount('previousReasoningHeadroomTokensAfterCut', self::CONFIG_KEY_PREVIOUS_REASONING_HEADROOM_TOKENS_AFTER_CUT_BY_AGENT_WIRE_API, $wireApi, 0, self::DEFAULT_PREVIOUS_REASONING_HEADROOM_TOKENS_AFTER_CUT);
    }

    /**
     * A count of `AI.contextRetention`: the wire API's entry of `$byWireApiConfigKey`, else `$configKey`, each only
     * when an integer of at least `$minimumValue`, else `$builtInDefault`. No environment variable. Never throws.
     */
    protected static function resolveConfiguredCount(string $configKey, string $byWireApiConfigKey, ?string $wireApi, int $minimumValue, int $builtInDefault): int
    {
        try {
            $configured = Config::get(ContextRetentionSettings::CONFIG_KEY);
            if (is_array($configured)) {
                $countsByWireApi = $configured[$byWireApiConfigKey] ?? null;
                $wireApiCount = $wireApi !== null && is_array($countsByWireApi) ? ($countsByWireApi[$wireApi] ?? null) : null;
                if (is_int($wireApiCount) && $wireApiCount >= $minimumValue) {
                    return $wireApiCount;
                }
                $configuredCount = $configured[$configKey] ?? null;
                if (is_int($configuredCount) && $configuredCount >= $minimumValue) {
                    return $configuredCount;
                }
            }
        } catch (Throwable) {
            // no config file — the built-in default
        }
        return $builtInDefault;
    }

    /**
     * The first of `$candidateCounts` that is an integer of at least `$minimumValue`; null when none is.
     *
     * @param array<int, int|null> $candidateCounts
     */
    protected static function firstCountOfAtLeast(int $minimumValue, array $candidateCounts): ?int
    {
        foreach ($candidateCounts as $candidateCount) {
            if (is_int($candidateCount) && $candidateCount >= $minimumValue) {
                return $candidateCount;
            }
        }
        return null;
    }

    /** Whether the earlier steps' items go back up to the cap and are then cut in one block (`capped`). */
    public function carriesCappedPreviousReasoning(): bool
    {
        return $this->previousReasoningCarryOver === RunSettings::PREVIOUS_REASONING_CARRY_OVER_CAPPED;
    }

    /** Whether only the reasoning items of the current turn's steps go back (`currentTurn`). */
    public function carriesOnlyTheCurrentTurnsReasoning(): bool
    {
        return $this->previousReasoningCarryOver === RunSettings::PREVIOUS_REASONING_CARRY_OVER_CURRENT_TURN;
    }

    /** A copy of this configuration that carries no reasoning item back (the recovery after a refused item). */
    public function withoutPreviousReasoningCarryOver(): self
    {
        $agentWireConfiguration = clone $this;
        $agentWireConfiguration->previousReasoningCarryOver = RunSettings::PREVIOUS_REASONING_CARRY_OVER_OFF;
        return $agentWireConfiguration;
    }

    /** Today's configuration: Chat Completions, system-prompt swap, carry-over off (a call made outside a run). */
    public static function chatCompletionsWithSystemPromptSwap(): static
    {
        return new static();
    }

    public function isRejected(): bool
    {
        return $this->rejectionReason !== null;
    }

    /** Whether the calls speak the Responses API — through OpenRouter, or directly at OpenAI with server state. */
    public function usesResponsesApi(): bool
    {
        return RunSettings::isResponsesAgentWireApi($this->wireApi);
    }

    /** Whether the calls speak the Anthropic Messages API (through OpenRouter's `/messages` endpoint). */
    public function usesMessagesApi(): bool
    {
        return $this->wireApi === AIModel::AGENT_WIRE_API_MESSAGES;
    }

    /** Whether the calls go directly to OpenAI with `store: true` + `previous_response_id` (the test-only server-state arm). */
    public function usesResponsesServerState(): bool
    {
        return $this->wireApi === RunSettings::AGENT_WIRE_API_RESPONSES_SERVER_STATE;
    }

    public function usesConversationAppend(): bool
    {
        return $this->skillLoadingStrategy === ContextRetentionSettings::SKILL_LOADING_STRATEGY_CONVERSATION_APPEND;
    }
}
