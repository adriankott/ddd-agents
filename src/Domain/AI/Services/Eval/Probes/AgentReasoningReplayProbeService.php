<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Eval\Probes;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentModelInput;
use DDD\Domain\AI\Entities\Conversations\Agent\Context\AgentContextSegmentType;
use DDD\Domain\AI\Entities\Conversations\Agent\Context\AgentModelInputTailEntry;
use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentAppendOnlyCall;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Evals\Probes\ReasoningReplayProbeCall;
use DDD\Domain\AI\Entities\Evals\Probes\ReasoningReplayProbeResult;
use DDD\Domain\AI\Entities\Evals\Probes\ReasoningReplayProbeRun;
use DDD\Domain\AI\Entities\Evals\Probes\ReasoningReplayProbeStep;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Repo\Argus\Conversations\Wire\AgentChatCompletionsRequestComposer;
use DDD\Domain\AI\Repo\Argus\Conversations\Wire\AgentResponsesInputItems;
use DDD\Domain\AI\Repo\Argus\Conversations\Wire\AgentResponsesRequestComposer;
use DDD\Domain\AI\Repo\Argus\Conversations\Wire\AgentWireRequestParameters;
use DDD\Domain\AI\Services\AIModelsService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * The live probe behind `app:ai-agent:probe-reasoning-replay`: a small synthetic tool conversation (built in memory,
 * nothing persisted) sent to OpenRouter with the agent's own composers ({@see AgentChatCompletionsRequestComposer},
 * {@see AgentResponsesRequestComposer} over {@see AgentResponsesInputItems}), the model's catalog provider filters and
 * the agent's key. Each scenario asks one question the reasoning-replay concept needs answered:
 *
 * - `clean`: call 1 uses a tool and returns reasoning items; call 2 replays them over an unchanged prefix (must pass).
 * - `edit-history` / `edit-tools` / `edit-system`: call 2 replays them after the harness edited the prefix the
 *   reasoning was produced over (an earlier message re-rendered, a tool added, the system content changed) — a refused
 *   call 2 is the provider enforcing the thinking binding.
 * - `model-switch`: call 2 goes to `--switch-model` with call 1's reasoning items.
 * - `forced-tool-choice`: one call with the named-function pin the agent's delivery step sends, then the 0.1.41 form of
 *   the same step (`auto` plus the `[SIGNAL:tool-expected]` tail line).
 * - `append-only` (Responses only): a tool call, then a skill load the append-only way (an `additional_tools` item and
 *   a developer message appended, the top-level tools unchanged) with the reasoning carried, then a third call that
 *   carries the reasoning of both — the cheap path to append-only loading plus carry-over without a new wire.
 *
 * The probe books nothing on the AI usage of an account: it reports the cost OpenRouter returns per call.
 */
class AgentReasoningReplayProbeService extends Service
{
    public const string SCENARIO_CLEAN = 'clean';
    public const string SCENARIO_EDIT_HISTORY = 'edit-history';
    public const string SCENARIO_EDIT_TOOLS = 'edit-tools';
    public const string SCENARIO_EDIT_SYSTEM = 'edit-system';
    public const string SCENARIO_MODEL_SWITCH = 'model-switch';
    public const string SCENARIO_FORCED_TOOL_CHOICE = 'forced-tool-choice';
    public const string SCENARIO_APPEND_ONLY = 'append-only';

    public const array SCENARIOS = [
        self::SCENARIO_CLEAN,
        self::SCENARIO_EDIT_HISTORY,
        self::SCENARIO_EDIT_TOOLS,
        self::SCENARIO_EDIT_SYSTEM,
        self::SCENARIO_MODEL_SWITCH,
        self::SCENARIO_FORCED_TOOL_CHOICE,
        self::SCENARIO_APPEND_ONLY,
    ];

    /** The scenarios whose second call replays reasoning over an edited prefix: a refusal there is a finding */
    public const array PREFIX_EDIT_SCENARIOS = [self::SCENARIO_EDIT_HISTORY, self::SCENARIO_EDIT_TOOLS, self::SCENARIO_EDIT_SYSTEM];

    public const array WIRE_APIS = [RunSettings::AGENT_WIRE_API_CHAT_COMPLETIONS, RunSettings::AGENT_WIRE_API_RESPONSES];

    public const int MAX_OUTPUT_TOKENS = 4000;

    public const float TEMPERATURE = 1.0;

    public const string TOOL_WEATHER = 'getWeather';
    public const string TOOL_TIME = 'getTime';
    public const string TOOL_AIR_QUALITY = 'getAirQuality';

    public const string OWNER_QUESTION = 'What is the weather in Berlin right now? Call getWeather first, then answer in one short sentence.';

    /** Appended to the owner's question by `edit-history`: an earlier message re-rendered, as the image marker swap does */
    public const string HISTORY_EDIT_SUFFIX = ' [pictures removed after this step; your notes about them are above]';

    /** Appended to the system content by `edit-system`: a skill catalog line that changed mid-turn */
    public const string SYSTEM_EDIT_SUFFIX = "\n\n# Skill catalog (changed)\n- airQuality: reads the air quality of a city.";

    /**
     * Runs one scenario and returns its verdict.
     *
     * @throws BadRequestException An unknown model, scenario or wire API, or a combination the scenario does not allow
     */
    public function runScenario(
        string $modelName,
        string $scenario,
        string $wireApi,
        bool $requestsDropBlock,
        ?string $switchModelName,
        string $reasoningEffort,
        int $timeoutSeconds
    ): ReasoningReplayProbeResult {
        $aiModel = $this->requireModel($modelName);
        if (!in_array($scenario, self::SCENARIOS, true)) {
            throw new BadRequestException('Unknown scenario ' . $scenario . '; one of ' . implode(', ', self::SCENARIOS));
        }
        if (!in_array($wireApi, self::WIRE_APIS, true)) {
            throw new BadRequestException('Unknown wire ' . $wireApi . '; one of ' . implode(', ', self::WIRE_APIS));
        }
        if ($scenario === self::SCENARIO_APPEND_ONLY && $wireApi !== RunSettings::AGENT_WIRE_API_RESPONSES) {
            throw new BadRequestException('The append-only scenario needs --wire=responses: Chat Completions has no additional_tools item');
        }
        $switchModel = null;
        if ($scenario === self::SCENARIO_MODEL_SWITCH) {
            if ($switchModelName === null || $switchModelName === '') {
                throw new BadRequestException('The model-switch scenario needs --switch-model=<catalog name>');
            }
            $switchModel = $this->requireModel($switchModelName);
        }
        $reasoningReplayProbeResult = new ReasoningReplayProbeResult();
        $reasoningReplayProbeResult->scenario = $scenario;
        $reasoningReplayProbeResult->wireApi = $wireApi;
        $reasoningReplayProbeResult->modelName = $modelName;
        $reasoningReplayProbeResult->switchModelName = $switchModel?->name;
        $reasoningReplayProbeResult->requestsDropBlock = $requestsDropBlock;
        $reasoningReplayProbeResult->bypassesResponsesRejection = $wireApi === RunSettings::AGENT_WIRE_API_RESPONSES
            && $aiModel->vendor !== AIModel::VENDOR_OPENAI;
        $probeRun = new ReasoningReplayProbeRun();
        $probeRun->aiModel = $aiModel;
        $probeRun->wireApi = $wireApi;
        $probeRun->requestsDropBlock = $requestsDropBlock;
        $probeRun->reasoningEffort = $reasoningEffort;
        $probeRun->timeoutSeconds = $timeoutSeconds;
        match ($scenario) {
            self::SCENARIO_FORCED_TOOL_CHOICE => $this->runForcedToolChoiceScenario($probeRun, $reasoningReplayProbeResult),
            self::SCENARIO_APPEND_ONLY => $this->runAppendOnlyScenario($probeRun, $reasoningReplayProbeResult),
            default => $this->runReplayScenario($probeRun, $reasoningReplayProbeResult, $switchModel),
        };
        return $reasoningReplayProbeResult;
    }

    /**
     * Call 1 with tools, then call 2 replaying call 1's reasoning after the scenario's edit (or none).
     */
    protected function runReplayScenario(ReasoningReplayProbeRun $probeRun, ReasoningReplayProbeResult $reasoningReplayProbeResult, ?AIModel $switchModel): void
    {
        $isEditScenario = in_array($reasoningReplayProbeResult->scenario, self::PREFIX_EDIT_SCENARIOS, true);
        $reasoningReplayProbeResult->expectation = match (true) {
            $reasoningReplayProbeResult->scenario === self::SCENARIO_CLEAN => 'call 2 replays call 1\'s reasoning over an unchanged prefix and is accepted',
            $isEditScenario => 'call 2 replays reasoning over an edited prefix: accepted (blocks tolerated or dropped) or refused with the provider\'s binding error — the probe records which',
            default => 'call 2 sends call 1\'s reasoning to another model: accepted (foreign blocks ignored or dropped) or refused — the probe records which',
        };
        $messages = $this->buildBaseMessages($probeRun->aiModel);
        $tools = [$this->buildToolSchema(self::TOOL_WEATHER), $this->buildToolSchema(self::TOOL_TIME)];
        $firstStep = $this->sendCall($probeRun, 'call 1: tools', $probeRun->aiModel, $messages, $tools, 'auto', 0, false);
        $firstCall = $firstStep->call;
        $reasoningReplayProbeResult->calls->add($firstCall);
        if ($this->failsTheFirstCall($probeRun, $reasoningReplayProbeResult, $firstCall, true)) {
            return;
        }
        $messages[] = $firstStep->buildAssistantEntry();
        foreach ($firstStep->buildToolResultEntries() as $toolResultEntry) {
            $messages[] = $toolResultEntry;
        }
        $secondModel = $probeRun->aiModel;
        match ($reasoningReplayProbeResult->scenario) {
            self::SCENARIO_EDIT_HISTORY => $messages[1]['content'] = self::OWNER_QUESTION . self::HISTORY_EDIT_SUFFIX,
            self::SCENARIO_EDIT_SYSTEM => $messages[0] = $this->buildSystemEntry($probeRun->aiModel, self::SYSTEM_EDIT_SUFFIX),
            self::SCENARIO_EDIT_TOOLS => $tools[] = $this->buildToolSchema(self::TOOL_AIR_QUALITY),
            default => null,
        };
        if ($switchModel !== null) {
            $secondModel = $switchModel;
        }
        $secondStep = $this->sendCall($probeRun, 'call 2: replay' . ($reasoningReplayProbeResult->scenario === self::SCENARIO_CLEAN ? '' : ' after ' . $reasoningReplayProbeResult->scenario), $secondModel, $messages, $tools, 'auto', $firstStep->countReasoningItems(), true);
        $secondCall = $secondStep->call;
        $reasoningReplayProbeResult->calls->add($secondCall);
        if ($secondCall->isAccepted) {
            $reasoningReplayProbeResult->verdict = ReasoningReplayProbeResult::VERDICT_PASS;
            $reasoningReplayProbeResult->verdictReason = 'call 2 accepted with ' . $secondCall->replayedReasoningItemCount . ' replayed reasoning item(s)'
                . ($secondCall->inputTransformationsJson !== null ? '; input_transformations: ' . $secondCall->inputTransformationsJson : '');
            return;
        }
        if ($reasoningReplayProbeResult->scenario === self::SCENARIO_CLEAN) {
            $reasoningReplayProbeResult->verdict = ReasoningReplayProbeResult::VERDICT_FAIL;
            $reasoningReplayProbeResult->verdictReason = 'the clean replay was refused: ' . ($secondCall->errorText ?? 'no error text');
            return;
        }
        $reasoningReplayProbeResult->verdict = ReasoningReplayProbeResult::VERDICT_FINDING;
        $reasoningReplayProbeResult->verdictReason = 'call 2 refused (HTTP ' . ($secondCall->httpStatus ?? 'none') . '): ' . ($secondCall->errorText ?? 'no error text');
    }

    /**
     * The agent's delivery-step shape (a named-function pin), then the 0.1.41 form of the same step (`auto` plus the
     * `[SIGNAL:tool-expected]` tail line).
     */
    protected function runForcedToolChoiceScenario(ReasoningReplayProbeRun $probeRun, ReasoningReplayProbeResult $reasoningReplayProbeResult): void
    {
        $reasoningReplayProbeResult->expectation = 'the forced pin is accepted, or refused with the provider\'s tool_choice 400; the auto + tool-expected form is accepted';
        $messages = $this->buildBaseMessages($probeRun->aiModel);
        $tools = [$this->buildToolSchema(self::TOOL_WEATHER), $this->buildToolSchema(self::TOOL_TIME)];
        $namedPin = ['type' => 'function', 'function' => ['name' => self::TOOL_WEATHER]];
        $forcedStep = $this->sendCall($probeRun, 'call 1: forced pin ' . self::TOOL_WEATHER, $probeRun->aiModel, $messages, $tools, $namedPin, 0, false);
        $forcedCall = $forcedStep->call;
        $reasoningReplayProbeResult->calls->add($forcedCall);
        $noteMessages = $messages;
        $toolExpectedEntry = AgentModelInputTailEntry::ofText(
            AgentModelInputTailEntry::KIND_TOOL_EXPECTED,
            AgentContextSegmentType::SIGNAL,
            AgentModelInput::buildToolExpectedTailHint($namedPin)
        );
        $noteMessages[] = $toolExpectedEntry->toWireMessage();
        $noteStep = $this->sendCall($probeRun, 'call 2: auto + tool-expected line', $probeRun->aiModel, $noteMessages, $tools, 'auto', 0, false);
        $noteCall = $noteStep->call;
        $reasoningReplayProbeResult->calls->add($noteCall);
        if (!$noteCall->isAccepted) {
            $reasoningReplayProbeResult->verdict = ReasoningReplayProbeResult::VERDICT_FAIL;
            $reasoningReplayProbeResult->verdictReason = 'the auto + tool-expected form was refused: ' . ($noteCall->errorText ?? 'no error text');
            return;
        }
        $noteFollowed = in_array(self::TOOL_WEATHER, $noteCall->calledToolNames, true) ? 'the model called ' . self::TOOL_WEATHER : 'the model did not call ' . self::TOOL_WEATHER;
        if ($forcedCall->isAccepted) {
            $reasoningReplayProbeResult->verdict = ReasoningReplayProbeResult::VERDICT_PASS;
            $reasoningReplayProbeResult->verdictReason = 'the forced pin was accepted on this route; with auto + tool-expected ' . $noteFollowed;
            return;
        }
        $reasoningReplayProbeResult->verdict = ReasoningReplayProbeResult::VERDICT_FINDING;
        $reasoningReplayProbeResult->verdictReason = 'the forced pin was refused (HTTP ' . ($forcedCall->httpStatus ?? 'none') . ': ' . ($forcedCall->errorText ?? 'no error text')
            . '); with auto + tool-expected ' . $noteFollowed;
    }

    /**
     * Responses only: a tool call, a skill load appended (an `additional_tools` item and a developer message, top-level
     * tools unchanged) with call 1's reasoning carried, then a third call carrying the reasoning of both.
     */
    protected function runAppendOnlyScenario(ReasoningReplayProbeRun $probeRun, ReasoningReplayProbeResult $reasoningReplayProbeResult): void
    {
        $reasoningReplayProbeResult->expectation = 'all three calls accepted, the reasoning items signed or encrypted and carried, the cache share rising with the appended prefix';
        $messages = $this->buildBaseMessages($probeRun->aiModel);
        $tools = [$this->buildToolSchema(self::TOOL_WEATHER), $this->buildToolSchema(self::TOOL_TIME)];
        $firstStep = $this->sendCall($probeRun, 'call 1: tools', $probeRun->aiModel, $messages, $tools, 'auto', 0, false);
        $firstCall = $firstStep->call;
        $reasoningReplayProbeResult->calls->add($firstCall);
        if ($this->failsTheFirstCall($probeRun, $reasoningReplayProbeResult, $firstCall, true)) {
            return;
        }
        $messages[] = $firstStep->buildAssistantEntry();
        foreach ($firstStep->buildToolResultEntries() as $toolResultEntry) {
            $messages[] = $toolResultEntry;
        }
        $messages[] = ['type' => 'additional_tools', 'role' => 'developer', 'tools' => [$this->buildToolSchema(self::TOOL_AIR_QUALITY)]];
        $messages[] = ['role' => 'developer', 'content' => AgentAppendOnlyCall::HEADER_SKILLS_LOADED . "\n\n## airQuality\nCall " . self::TOOL_AIR_QUALITY . ' for the same city now, then answer with weather and air quality in one sentence.'];
        $secondStep = $this->sendCall($probeRun, 'call 2: skill appended (additional_tools)', $probeRun->aiModel, $messages, $tools, 'auto', $firstStep->countReasoningItems(), true);
        $secondCall = $secondStep->call;
        $reasoningReplayProbeResult->calls->add($secondCall);
        if (!$secondCall->isAccepted) {
            $reasoningReplayProbeResult->verdict = ReasoningReplayProbeResult::VERDICT_FINDING;
            $reasoningReplayProbeResult->verdictReason = 'the append-only skill load was refused (HTTP ' . ($secondCall->httpStatus ?? 'none') . '): ' . ($secondCall->errorText ?? 'no error text');
            return;
        }
        $messages[] = $secondStep->buildAssistantEntry();
        foreach ($secondStep->buildToolResultEntries() as $toolResultEntry) {
            $messages[] = $toolResultEntry;
        }
        $carriedItemCount = $firstStep->countReasoningItems() + $secondStep->countReasoningItems();
        $thirdStep = $this->sendCall($probeRun, 'call 3: reasoning of both carried', $probeRun->aiModel, $messages, $tools, 'auto', $carriedItemCount, true);
        $thirdCall = $thirdStep->call;
        $reasoningReplayProbeResult->calls->add($thirdCall);
        if (!$thirdCall->isAccepted) {
            $reasoningReplayProbeResult->verdict = ReasoningReplayProbeResult::VERDICT_FINDING;
            $reasoningReplayProbeResult->verdictReason = 'the reasoning-carry call was refused (HTTP ' . ($thirdCall->httpStatus ?? 'none') . '): ' . ($thirdCall->errorText ?? 'no error text');
            return;
        }
        $reasoningReplayProbeResult->verdict = ReasoningReplayProbeResult::VERDICT_PASS;
        $reasoningReplayProbeResult->verdictReason = 'all three calls accepted; ' . $carriedItemCount . ' reasoning item(s) carried'
            . ($firstCall->hasSignedReasoning ? ', signed/encrypted' : ', NOT signed or encrypted')
            . (in_array(self::TOOL_AIR_QUALITY, $secondCall->calledToolNames, true) ? '; the appended tool was called' : '; the appended tool was NOT called');
    }

    /**
     * Sets the verdict when call 1 cannot carry the scenario: refused (a FINDING on the Responses route of a model the
     * agent refuses there, else a FAIL), no tool call, or no reasoning items to replay.
     */
    protected function failsTheFirstCall(ReasoningReplayProbeRun $probeRun, ReasoningReplayProbeResult $reasoningReplayProbeResult, ReasoningReplayProbeCall $firstCall, bool $needsReasoning): bool
    {
        if (!$firstCall->isAccepted) {
            $reasoningReplayProbeResult->verdict = $reasoningReplayProbeResult->bypassesResponsesRejection
                ? ReasoningReplayProbeResult::VERDICT_FINDING
                : ReasoningReplayProbeResult::VERDICT_FAIL;
            $reasoningReplayProbeResult->verdictReason = 'call 1 refused (HTTP ' . ($firstCall->httpStatus ?? 'none') . '): ' . ($firstCall->errorText ?? 'no error text');
            return true;
        }
        if ($firstCall->calledToolNames === []) {
            $reasoningReplayProbeResult->verdict = ReasoningReplayProbeResult::VERDICT_FAIL;
            $reasoningReplayProbeResult->verdictReason = 'call 1 called no tool, so there is no tool step to continue';
            return true;
        }
        if ($needsReasoning && $firstCall->reasoningItemCount === 0) {
            $reasoningReplayProbeResult->verdict = ReasoningReplayProbeResult::VERDICT_FAIL;
            $reasoningReplayProbeResult->verdictReason = 'call 1 returned no reasoning items on ' . $probeRun->wireApi . ', so nothing can be replayed';
            return true;
        }
        return false;
    }

    /**
     * Composes, sends and reads one call.
     *
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $tools
     * @param string|array<string, mixed> $toolChoice
     */
    protected function sendCall(
        ReasoningReplayProbeRun $probeRun,
        string $label,
        AIModel $aiModel,
        array $messages,
        array $tools,
        string|array $toolChoice,
        int $replayedReasoningItemCount,
        bool $replaysReasoning
    ): ReasoningReplayProbeStep {
        $body = $this->composeBody($probeRun, $aiModel, $messages, $tools, $toolChoice, $replaysReasoning);
        $agentReasoningReplayProbeTransportService = $this->getTransportService();
        $url = $probeRun->wireApi === RunSettings::AGENT_WIRE_API_RESPONSES
            ? AgentReasoningReplayProbeTransportService::RESPONSES_URL
            : AgentReasoningReplayProbeTransportService::CHAT_COMPLETIONS_URL;
        $reasoningReplayProbeHttpAnswer = $agentReasoningReplayProbeTransportService->postJson($url, $body, $probeRun->timeoutSeconds);
        $probeStep = ReasoningReplayProbeStep::fromAnswer($probeRun->wireApi, $label, (string)$aiModel->name, $reasoningReplayProbeHttpAnswer);
        $reasoningReplayProbeCall = $probeStep->call;
        $reasoningReplayProbeCall->replayedReasoningItemCount = $replaysReasoning ? $replayedReasoningItemCount : 0;
        $reasoningReplayProbeCall->sentToolChoice = is_array($toolChoice) ? (string)json_encode($toolChoice, JSON_UNESCAPED_SLASHES) : $toolChoice;
        return $probeStep;
    }

    /**
     * The request body of one call through the agent's own composers. Earlier assistant entries carry their reasoning
     * items as `reasoning_details` (the Responses converter turns them into input items); a call that replays and asks
     * for it carries `thinking.block_binding.prefix_mismatch_behavior: drop_block`.
     *
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $tools
     * @param string|array<string, mixed> $toolChoice
     * @return array<string, mixed>
     */
    public function composeBody(ReasoningReplayProbeRun $probeRun, AIModel $aiModel, array $messages, array $tools, string|array $toolChoice, bool $replaysReasoning): array
    {
        $agentWireRequestParameters = new AgentWireRequestParameters();
        $agentWireRequestParameters->modelId = (string)($aiModel->openRouterExternalId ?? $aiModel->externalId ?? $aiModel->name);
        $agentWireRequestParameters->maxOutputTokens = self::MAX_OUTPUT_TOKENS;
        $agentWireRequestParameters->temperature = self::TEMPERATURE;
        $agentWireRequestParameters->chatCompletionsMessages = $messages;
        $agentWireRequestParameters->chatCompletionsTools = $tools;
        $agentWireRequestParameters->chatCompletionsToolChoice = $toolChoice;
        $agentWireRequestParameters->reasoningEffort = $probeRun->reasoningEffort;
        $agentWireRequestParameters->sendsParallelToolCalls = false;
        $agentWireRequestParameters->providerPreference = $aiModel->providerFilters !== null && $aiModel->providerFilters->hasAnyDirective()
            ? $aiModel->providerFilters->applyToProviderBlock([])
            : null;
        if ($probeRun->wireApi === RunSettings::AGENT_WIRE_API_RESPONSES) {
            $agentWireRequestParameters->includesEncryptedReasoning = true;
            $body = AgentResponsesRequestComposer::composeBody($agentWireRequestParameters);
        } else {
            $body = AgentChatCompletionsRequestComposer::composeBody($agentWireRequestParameters);
        }
        if ($replaysReasoning && $probeRun->requestsDropBlock) {
            $body['thinking'] = ['block_binding' => ['prefix_mismatch_behavior' => 'drop_block']];
        }
        return $body;
    }

    /**
     * System and owner question. For a model whose catalog sets Anthropic `cache_control` the system text carries the
     * marker the agent places (0.1.35), so the cache share of a replaying call is measurable.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildBaseMessages(AIModel $aiModel): array
    {
        return [
            $this->buildSystemEntry($aiModel, ''),
            ['role' => 'user', 'content' => self::OWNER_QUESTION],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildSystemEntry(AIModel $aiModel, string $suffix): array
    {
        $systemText = self::buildSystemText() . $suffix;
        if ($aiModel->getPromptCacheMode() === AIModel::PROMPT_CACHE_MODE_ANTHROPIC_CACHE_CONTROL) {
            return ['role' => 'system', 'content' => [['type' => 'text', 'text' => $systemText, 'cache_control' => ['type' => 'ephemeral']]]];
        }
        return ['role' => 'system', 'content' => $systemText];
    }

    /**
     * A deterministic system text long enough (about 2,000 tokens) for every provider's minimum cacheable prefix.
     */
    public static function buildSystemText(): string
    {
        $lines = ['You are a weather assistant inside a reasoning-replay probe. Use the tools; never invent a reading.'];
        for ($ruleNumber = 1; $ruleNumber <= 60; $ruleNumber++) {
            $lines[] = 'Rule ' . $ruleNumber . ': when a tool answers, quote its numbers exactly, name the city, and keep the answer to one sentence; '
                . 'if a tool fails, say so plainly and do not guess a value for the city in question.';
        }
        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildToolSchema(string $toolName): array
    {
        $descriptions = [
            self::TOOL_WEATHER => 'The current weather of a city (temperature in °C and conditions).',
            self::TOOL_TIME => 'The current local time of a city.',
            self::TOOL_AIR_QUALITY => 'The current air quality index of a city.',
        ];
        return [
            'type' => 'function',
            'function' => [
                'name' => $toolName,
                'description' => $descriptions[$toolName] ?? $toolName,
                'parameters' => [
                    'type' => 'object',
                    'properties' => ['city' => ['type' => 'string', 'description' => 'The city name']],
                    'required' => ['city'],
                ],
            ],
        ];
    }

    /** The transport of the probe's calls (tests return a scripted one) */
    protected function getTransportService(): AgentReasoningReplayProbeTransportService
    {
        /** @var AgentReasoningReplayProbeTransportService $agentReasoningReplayProbeTransportService */
        $agentReasoningReplayProbeTransportService = DDDService::instance()->getService(AgentReasoningReplayProbeTransportService::class);
        return $agentReasoningReplayProbeTransportService;
    }

    /** The catalog model of a name, null when the catalog has none (tests return their own) */
    protected function findModel(string $modelName): ?AIModel
    {
        /** @var AIModelsService $aiModelsService */
        $aiModelsService = DDDService::instance()->getService(AIModelsService::class);
        return $aiModelsService->getAIModelByName($modelName);
    }

    /**
     * @throws BadRequestException
     */
    protected function requireModel(string $modelName): AIModel
    {
        $aiModel = $this->findModel($modelName);
        if ($aiModel === null) {
            throw new BadRequestException('Unknown model ' . $modelName . ' (a catalog name such as ' . AIModel::MODEL_ANTHROPIC_CLAUDE_OPUS_5_5 . ')');
        }
        return $aiModel;
    }
}
