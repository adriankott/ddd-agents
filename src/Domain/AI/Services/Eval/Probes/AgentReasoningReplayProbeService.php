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
use DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIAgentConversation;
use DDD\Domain\AI\Repo\Argus\Conversations\Wire\AgentChatCompletionsRequestComposer;
use DDD\Domain\AI\Repo\Argus\Conversations\Wire\AgentMessagesRequestComposer;
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
 * - `append-only` (Responses and Messages): a tool call, then a skill load the append-only way (an `additional_tools`
 *   item and a developer message appended, the top-level tools unchanged) with the reasoning carried, then a third
 *   call that carries the reasoning of both.
 * - `capped-cut` (Messages only): several steps, then a call that sends the older steps without their thinking blocks
 *   and keeps the newest two steps' blocks — what the carry-over `capped` does at a cut.
 * - `current-turn-strip` (Messages only): a whole turn, then the next owner message with the first turn's thinking
 *   stripped — what the carry-over `currentTurn` does at a turn start.
 *
 * On `--wire=messages` the probe speaks the Anthropic Messages API as the agent does
 * ({@see AgentMessagesRequestComposer}): adaptive thinking with `drop_block` on every call, each call ending on a
 * `role: "system"` note with `clear_at` that later calls keep in place, stored answers sent back verbatim, and a task
 * with dependent lookups (the weather question draws no thinking block). A replay the provider accepts but answers
 * with dropped blocks (`input_transformations`) is a FAIL over an unchanged history and a FINDING after an edit.
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
    public const string SCENARIO_CAPPED_CUT = 'capped-cut';
    public const string SCENARIO_CURRENT_TURN_STRIP = 'current-turn-strip';

    public const array SCENARIOS = [
        self::SCENARIO_CLEAN,
        self::SCENARIO_EDIT_HISTORY,
        self::SCENARIO_EDIT_TOOLS,
        self::SCENARIO_EDIT_SYSTEM,
        self::SCENARIO_MODEL_SWITCH,
        self::SCENARIO_FORCED_TOOL_CHOICE,
        self::SCENARIO_APPEND_ONLY,
        self::SCENARIO_CAPPED_CUT,
        self::SCENARIO_CURRENT_TURN_STRIP,
    ];

    /** The scenarios of the Messages API only: they strip thinking blocks the way the carry-over modes do */
    public const array MESSAGES_ONLY_SCENARIOS = [self::SCENARIO_CAPPED_CUT, self::SCENARIO_CURRENT_TURN_STRIP];

    /** The wire APIs with mid-conversation tool additions (the append-only scenario) */
    public const array APPEND_ONLY_WIRE_APIS = [RunSettings::AGENT_WIRE_API_RESPONSES, RunSettings::AGENT_WIRE_API_MESSAGES];

    /** The scenarios whose second call replays reasoning over an edited prefix: a refusal there is a finding */
    public const array PREFIX_EDIT_SCENARIOS = [self::SCENARIO_EDIT_HISTORY, self::SCENARIO_EDIT_TOOLS, self::SCENARIO_EDIT_SYSTEM];

    public const array WIRE_APIS = [RunSettings::AGENT_WIRE_API_CHAT_COMPLETIONS, RunSettings::AGENT_WIRE_API_RESPONSES, RunSettings::AGENT_WIRE_API_MESSAGES];

    public const int MAX_OUTPUT_TOKENS = 4000;

    /** The output limit of a call on the Messages API: the thinking counts against it */
    public const int MESSAGES_MAX_OUTPUT_TOKENS = 8000;

    /** The steps a multi-step scenario runs at most before its closing call */
    public const int MAX_STEPS_OF_A_TURN = 6;

    /** The tool steps the capped-cut scenario runs before the call that strips the older steps' thinking */
    public const int CAPPED_CUT_STEPS = 4;

    /** The newest steps whose thinking the capped-cut call keeps (the agent's cut keeps the same number) */
    public const int CAPPED_CUT_KEPT_STEPS = 2;

    public const float TEMPERATURE = 1.0;

    public const string TOOL_WEATHER = 'getWeather';
    public const string TOOL_TIME = 'getTime';
    public const string TOOL_AIR_QUALITY = 'getAirQuality';

    public const string OWNER_QUESTION = 'What is the weather in Berlin right now? Call getWeather first, then answer in one short sentence.';

    public const string TOOL_ORDER = 'getOrder';
    public const string TOOL_CUSTOMER = 'getCustomer';
    public const string TOOL_INVOICE = 'getInvoice';

    /**
     * The task of the Messages API: several dependent lookups, so the model thinks before each step (the weather
     * question draws no thinking block from a model with adaptive thinking, and nothing can be replayed).
     */
    public const string ORDER_TASK_QUESTION = 'Order 4711 has a problem with its delivery. Find out in which city the customer of order 4711 lives, and whether order 4712 goes to a customer in the same city. Work step by step, one tool call per step.';

    /** The owner's message of the second turn in the current-turn-strip scenario */
    public const string ORDER_TASK_SECOND_TURN_QUESTION = 'Thanks. Now check order 4713 the same way: in which city does its customer live? One tool call per step.';

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
        if ($scenario === self::SCENARIO_APPEND_ONLY && !in_array($wireApi, self::APPEND_ONLY_WIRE_APIS, true)) {
            throw new BadRequestException('The append-only scenario needs --wire=responses or --wire=messages: Chat Completions has no mid-conversation tool addition');
        }
        if (in_array($scenario, self::MESSAGES_ONLY_SCENARIOS, true) && $wireApi !== RunSettings::AGENT_WIRE_API_MESSAGES) {
            throw new BadRequestException('The ' . $scenario . ' scenario needs --wire=messages: it strips thinking blocks from stored answers of the Messages API');
        }
        if ($wireApi === RunSettings::AGENT_WIRE_API_MESSAGES && $aiModel->vendor !== AIModel::VENDOR_ANTHROPIC) {
            throw new BadRequestException('--wire=messages is the Anthropic Messages API: ' . $modelName . ' is no Anthropic model');
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
        // every request of the Messages API asks for the drop (the agent's composer sets it)
        $requestsDropBlock = $requestsDropBlock || $wireApi === RunSettings::AGENT_WIRE_API_MESSAGES;
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
            self::SCENARIO_CAPPED_CUT => $this->runCappedCutScenario($probeRun, $reasoningReplayProbeResult),
            self::SCENARIO_CURRENT_TURN_STRIP => $this->runCurrentTurnStripScenario($probeRun, $reasoningReplayProbeResult),
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
        $messages = $this->buildBaseMessagesOfRun($probeRun);
        $tools = $this->buildToolSchemasOfRun($probeRun);
        $firstStep = $this->sendCall($probeRun, 'call 1: tools', $probeRun->aiModel, $messages, $tools, 'auto', 0, false);
        $firstCall = $firstStep->call;
        $reasoningReplayProbeResult->calls->add($firstCall);
        if ($this->failsTheFirstCall($probeRun, $reasoningReplayProbeResult, $firstCall, true)) {
            return;
        }
        $messages = [...$messages, ...$firstStep->buildHistoryEntries()];
        $secondModel = $probeRun->aiModel;
        match ($reasoningReplayProbeResult->scenario) {
            self::SCENARIO_EDIT_HISTORY => $messages[1]['content'] = $this->getOwnerQuestionOfRun($probeRun) . self::HISTORY_EDIT_SUFFIX,
            self::SCENARIO_EDIT_SYSTEM => $messages[0] = $this->buildSystemEntryOfRun($probeRun, self::SYSTEM_EDIT_SUFFIX),
            self::SCENARIO_EDIT_TOOLS => $tools[] = $this->buildToolSchema($this->getAppendedToolNameOfRun($probeRun)),
            default => null,
        };
        if ($switchModel !== null) {
            $secondModel = $switchModel;
        }
        $secondStep = $this->sendCall($probeRun, 'call 2: replay' . ($reasoningReplayProbeResult->scenario === self::SCENARIO_CLEAN ? '' : ' after ' . $reasoningReplayProbeResult->scenario), $secondModel, $messages, $tools, 'auto', $firstStep->countReasoningItems(), true);
        $secondCall = $secondStep->call;
        $reasoningReplayProbeResult->calls->add($secondCall);
        $droppedThinkingBlockCountOfSecondCall = $secondStep->call->droppedThinkingBlockCount;
        if ($secondStep->call->isAccepted && $droppedThinkingBlockCountOfSecondCall > 0) {
            // the provider accepted the call and dropped replayed blocks: a failure over an unchanged prefix, else the
            // binding at work
            $reasoningReplayProbeResult->verdict = $reasoningReplayProbeResult->scenario === self::SCENARIO_CLEAN
                ? ReasoningReplayProbeResult::VERDICT_FAIL
                : ReasoningReplayProbeResult::VERDICT_FINDING;
            $reasoningReplayProbeResult->verdictReason = 'call 2 accepted, ' . $droppedThinkingBlockCountOfSecondCall . ' of ' . $secondStep->call->replayedReasoningItemCount
                . ' replayed thinking block(s) dropped by the provider; input_transformations: ' . $secondStep->call->inputTransformationsJson;
            return;
        }
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
        $messages = $this->buildBaseMessagesOfRun($probeRun);
        $tools = $this->buildToolSchemasOfRun($probeRun);
        $pinnedToolName = (string)$tools[0]['function']['name'];
        $namedPin = ['type' => 'function', 'function' => ['name' => $pinnedToolName]];
        $forcedStep = $this->sendCall($probeRun, 'call 1: forced pin ' . $pinnedToolName, $probeRun->aiModel, $messages, $tools, $namedPin, 0, false);
        $forcedCall = $forcedStep->call;
        $reasoningReplayProbeResult->calls->add($forcedCall);
        $noteMessages = $messages;
        $toolExpectedEntry = AgentModelInputTailEntry::ofText(
            AgentModelInputTailEntry::KIND_TOOL_EXPECTED,
            AgentContextSegmentType::SIGNAL,
            AgentModelInput::buildToolExpectedTailHint($namedPin)
        );
        if (!$probeRun->usesMessagesApi()) {
            $noteMessages[] = $toolExpectedEntry->toWireMessage();
        }
        // (on the Messages API the line is the note the call ends on: a `role: "system"` message with `clear_at`)
        $noteStep = $this->sendCall($probeRun, 'call 2: auto + tool-expected line', $probeRun->aiModel, $noteMessages, $tools, 'auto', 0, false, $toolExpectedEntry->text);
        $noteCall = $noteStep->call;
        $reasoningReplayProbeResult->calls->add($noteCall);
        if (!$noteCall->isAccepted) {
            $reasoningReplayProbeResult->verdict = ReasoningReplayProbeResult::VERDICT_FAIL;
            $reasoningReplayProbeResult->verdictReason = 'the auto + tool-expected form was refused: ' . ($noteCall->errorText ?? 'no error text');
            return;
        }
        $noteFollowed = in_array($pinnedToolName, $noteCall->calledToolNames, true) ? 'the model called ' . $pinnedToolName : 'the model did not call ' . $pinnedToolName;
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
        $reasoningReplayProbeResult->expectation = 'all three calls accepted, the reasoning items signed or encrypted and carried, no thinking block dropped, the cache share rising with the appended prefix';
        $messages = $this->buildBaseMessagesOfRun($probeRun);
        $tools = $this->buildToolSchemasOfRun($probeRun);
        $appendedToolName = $this->getAppendedToolNameOfRun($probeRun);
        $firstStep = $this->sendCall($probeRun, 'call 1: tools', $probeRun->aiModel, $messages, $tools, 'auto', 0, false);
        $firstCall = $firstStep->call;
        $reasoningReplayProbeResult->calls->add($firstCall);
        if ($this->failsTheFirstCall($probeRun, $reasoningReplayProbeResult, $firstCall, true)) {
            return;
        }
        $messages = [...$messages, ...$firstStep->buildHistoryEntries()];
        $messages[] = ['type' => 'additional_tools', 'role' => 'developer', 'tools' => [$this->buildToolSchema($appendedToolName)]];
        $messages[] = ['role' => 'developer', 'content' => AgentAppendOnlyCall::HEADER_SKILLS_LOADED . "\n\n## " . $appendedToolName . "\nCall " . $appendedToolName . ' now (the same city or order as before), then go on and name its result in your answer.'];
        $secondStep = $this->sendCall($probeRun, 'call 2: skill appended (additional_tools)', $probeRun->aiModel, $messages, $tools, 'auto', $firstStep->countReasoningItems(), true);
        $secondCall = $secondStep->call;
        $reasoningReplayProbeResult->calls->add($secondCall);
        if (!$secondCall->isAccepted) {
            $reasoningReplayProbeResult->verdict = ReasoningReplayProbeResult::VERDICT_FINDING;
            $reasoningReplayProbeResult->verdictReason = 'the append-only skill load was refused (HTTP ' . ($secondCall->httpStatus ?? 'none') . '): ' . ($secondCall->errorText ?? 'no error text');
            return;
        }
        $messages = [...$messages, ...$secondStep->buildHistoryEntries()];
        $carriedItemCount = $firstStep->countReasoningItems() + $secondStep->countReasoningItems();
        $thirdStep = $this->sendCall($probeRun, 'call 3: reasoning of both carried', $probeRun->aiModel, $messages, $tools, 'auto', $carriedItemCount, true);
        $thirdCall = $thirdStep->call;
        $reasoningReplayProbeResult->calls->add($thirdCall);
        if (!$thirdCall->isAccepted) {
            $reasoningReplayProbeResult->verdict = ReasoningReplayProbeResult::VERDICT_FINDING;
            $reasoningReplayProbeResult->verdictReason = 'the reasoning-carry call was refused (HTTP ' . ($thirdCall->httpStatus ?? 'none') . '): ' . ($thirdCall->errorText ?? 'no error text');
            return;
        }
        $droppedThinkingBlockCount = $secondStep->call->droppedThinkingBlockCount + $thirdStep->call->droppedThinkingBlockCount;
        if ($droppedThinkingBlockCount > 0) {
            $reasoningReplayProbeResult->verdict = ReasoningReplayProbeResult::VERDICT_FINDING;
            $reasoningReplayProbeResult->verdictReason = 'all three calls accepted, but the provider dropped ' . $droppedThinkingBlockCount . ' thinking block(s) after the appended skill load: '
                . ($secondStep->call->inputTransformationsJson ?? $thirdStep->call->inputTransformationsJson);
            return;
        }
        $reasoningReplayProbeResult->verdict = ReasoningReplayProbeResult::VERDICT_PASS;
        $reasoningReplayProbeResult->verdictReason = 'all three calls accepted; ' . $carriedItemCount . ' reasoning item(s) carried'
            . ($firstCall->hasSignedReasoning ? ', signed/encrypted' : ', NOT signed or encrypted')
            . (in_array($appendedToolName, $secondCall->calledToolNames, true) ? '; the appended tool was called' : '; the appended tool was NOT called');
    }

    /**
     * Messages API only, the carry-over `capped` at a cut: {@see CAPPED_CUT_STEPS} tool steps with every thinking block
     * carried, then one call that sends the stored answers of the older steps WITHOUT their thinking blocks and keeps
     * the blocks of the newest {@see CAPPED_CUT_KEPT_STEPS} steps. Removing the blocks of a leading run of steps must
     * leave the kept ones valid: the closing call is accepted and nothing is dropped.
     */
    protected function runCappedCutScenario(ReasoningReplayProbeRun $probeRun, ReasoningReplayProbeResult $reasoningReplayProbeResult): void
    {
        $reasoningReplayProbeResult->expectation = 'every step accepted with all thinking carried; the closing call strips the thinking of the older steps, keeps the newest ' . self::CAPPED_CUT_KEPT_STEPS . ' steps\' blocks and nothing is dropped';
        $messages = $this->buildBaseMessagesOfRun($probeRun);
        $tools = $this->buildToolSchemasOfRun($probeRun);
        $steps = $this->runToolSteps($probeRun, $reasoningReplayProbeResult, $messages, $tools, self::CAPPED_CUT_STEPS, 'step');
        if ($steps === null) {
            return;
        }
        if (count($steps) <= self::CAPPED_CUT_KEPT_STEPS) {
            $reasoningReplayProbeResult->verdict = ReasoningReplayProbeResult::VERDICT_FAIL;
            $reasoningReplayProbeResult->verdictReason = 'the model finished after ' . count($steps) . ' tool step(s): no older step is left to strip';
            return;
        }
        $strippedSteps = array_slice($steps, 0, count($steps) - self::CAPPED_CUT_KEPT_STEPS);
        $keptSteps = array_slice($steps, -self::CAPPED_CUT_KEPT_STEPS);
        $strippedBlockCount = array_sum(array_map(static fn (ReasoningReplayProbeStep $step): int => $step->countReasoningItems(), $strippedSteps));
        $keptBlockCount = array_sum(array_map(static fn (ReasoningReplayProbeStep $step): int => $step->countReasoningItems(), $keptSteps));
        if ($strippedBlockCount === 0 || $keptBlockCount === 0) {
            $reasoningReplayProbeResult->verdict = ReasoningReplayProbeResult::VERDICT_FAIL;
            $reasoningReplayProbeResult->verdictReason = 'the steps returned ' . $strippedBlockCount . ' thinking block(s) to strip and ' . $keptBlockCount . ' to keep: the cut needs both (raise --effort)';
            return;
        }
        $cutMessages = $this->withoutThinkingOfSteps($messages, $strippedSteps);
        $cutStep = $this->sendCall($probeRun, 'closing call: older steps without thinking, newest ' . self::CAPPED_CUT_KEPT_STEPS . ' kept', $probeRun->aiModel, $cutMessages, $tools, 'auto', $keptBlockCount, true);
        $callOfTheCut = $cutStep->call;
        $reasoningReplayProbeResult->calls->add($callOfTheCut);
        $this->setVerdictOfStrippingCall($reasoningReplayProbeResult, $cutStep->call, $strippedBlockCount . ' thinking block(s) of ' . count($strippedSteps) . ' older step(s) stripped, ' . $keptBlockCount . ' kept');
    }

    /**
     * Messages API only, the carry-over `currentTurn` at a turn start: a whole turn with every thinking block carried,
     * then the owner's next message with the stored answers of the first turn WITHOUT their thinking blocks, and a
     * second step of the new turn that carries the new turn's thinking. Removing every earlier block must be accepted,
     * and the new turn's blocks must stay valid.
     */
    protected function runCurrentTurnStripScenario(ReasoningReplayProbeRun $probeRun, ReasoningReplayProbeResult $reasoningReplayProbeResult): void
    {
        $reasoningReplayProbeResult->expectation = 'turn 1 runs with all thinking carried; turn 2 starts with turn 1\'s thinking stripped and carries its own: both calls accepted, nothing dropped';
        $messages = $this->buildBaseMessagesOfRun($probeRun);
        $tools = $this->buildToolSchemasOfRun($probeRun);
        $firstTurnSteps = $this->runToolSteps($probeRun, $reasoningReplayProbeResult, $messages, $tools, self::MAX_STEPS_OF_A_TURN, 'turn 1 step', true);
        if ($firstTurnSteps === null) {
            return;
        }
        $strippedBlockCount = array_sum(array_map(static fn (ReasoningReplayProbeStep $step): int => $step->countReasoningItems(), $firstTurnSteps));
        if ($strippedBlockCount === 0) {
            $reasoningReplayProbeResult->verdict = ReasoningReplayProbeResult::VERDICT_FAIL;
            $reasoningReplayProbeResult->verdictReason = 'turn 1 returned no thinking block, so nothing is stripped (raise --effort)';
            return;
        }
        $messages = $this->withoutThinkingOfSteps($messages, $firstTurnSteps);
        $messages[] = ['role' => 'user', 'content' => self::ORDER_TASK_SECOND_TURN_QUESTION];
        $turnStartStep = $this->sendCall($probeRun, 'turn 2 step 1: turn 1 without thinking', $probeRun->aiModel, $messages, $tools, 'auto', 0, true);
        $callOfTheTurnStart = $turnStartStep->call;
        $reasoningReplayProbeResult->calls->add($callOfTheTurnStart);
        if (!$turnStartStep->call->isAccepted || $turnStartStep->call->droppedThinkingBlockCount > 0 || !$turnStartStep->hasToolCalls()) {
            $this->setVerdictOfStrippingCall($reasoningReplayProbeResult, $turnStartStep->call, $strippedBlockCount . ' thinking block(s) of turn 1 stripped');
            if ($turnStartStep->call->isAccepted && $turnStartStep->call->droppedThinkingBlockCount === 0) {
                $reasoningReplayProbeResult->verdictReason .= '; turn 2 called no tool, so its own thinking was not carried once more';
            }
            return;
        }
        $messages = [...$messages, ...$turnStartStep->buildHistoryEntries()];
        $secondStep = $this->sendCall($probeRun, 'turn 2 step 2: turn 2 thinking carried', $probeRun->aiModel, $messages, $tools, 'auto', $turnStartStep->countReasoningItems(), true);
        $callOfTheSecondStep = $secondStep->call;
        $reasoningReplayProbeResult->calls->add($callOfTheSecondStep);
        $this->setVerdictOfStrippingCall($reasoningReplayProbeResult, $secondStep->call, $strippedBlockCount . ' thinking block(s) of turn 1 stripped, ' . $turnStartStep->countReasoningItems() . ' of turn 2 carried');
    }

    /**
     * Runs tool steps from `$messages` on, every call carrying all thinking so far, until `$maxSteps` steps called a
     * tool or (`$runsToTheAnswer`) the model answered without a call; `$messages` grows by every step's entries. Null
     * with the verdict set when a call was refused, the first call cannot carry the scenario, or the provider dropped
     * a block although nothing before it changed.
     *
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $tools
     * @return ReasoningReplayProbeStep[]|null the steps whose entries joined `$messages`, in order
     */
    protected function runToolSteps(
        ReasoningReplayProbeRun $probeRun,
        ReasoningReplayProbeResult $reasoningReplayProbeResult,
        array &$messages,
        array $tools,
        int $maxSteps,
        string $labelPrefix,
        bool $runsToTheAnswer = false
    ): ?array {
        $steps = [];
        $carriedBlockCount = 0;
        for ($stepNumber = 1; $stepNumber <= $maxSteps; $stepNumber++) {
            $step = $this->sendCall($probeRun, $labelPrefix . ' ' . $stepNumber, $probeRun->aiModel, $messages, $tools, 'auto', $carriedBlockCount, $stepNumber > 1);
            $callOfTheStep = $step->call;
            $reasoningReplayProbeResult->calls->add($callOfTheStep);
            if ($stepNumber === 1 && $this->failsTheFirstCall($probeRun, $reasoningReplayProbeResult, $step->call, false)) {
                return null;
            }
            if (!$step->call->isAccepted) {
                $reasoningReplayProbeResult->verdict = ReasoningReplayProbeResult::VERDICT_FAIL;
                $reasoningReplayProbeResult->verdictReason = $step->call->label . ' refused (HTTP ' . ($step->call->httpStatus ?? 'none') . '): ' . ($step->call->errorText ?? 'no error text');
                return null;
            }
            if ($step->call->droppedThinkingBlockCount > 0) {
                $reasoningReplayProbeResult->verdict = ReasoningReplayProbeResult::VERDICT_FAIL;
                $reasoningReplayProbeResult->verdictReason = $step->call->label . ': the provider dropped ' . $step->call->droppedThinkingBlockCount . ' thinking block(s) over an unchanged history: ' . $step->call->inputTransformationsJson;
                return null;
            }
            if (!$step->hasToolCalls() && !$runsToTheAnswer) {
                break;
            }
            $messages = [...$messages, ...$step->buildHistoryEntries()];
            $steps[] = $step;
            $carriedBlockCount += $step->countReasoningItems();
            if (!$step->hasToolCalls()) {
                break;
            }
        }
        return $steps;
    }

    /**
     * `$messages` with the assistant entries of `$steps` marked to go back without their thinking blocks.
     *
     * @param array<int, array<string, mixed>> $messages
     * @param ReasoningReplayProbeStep[] $steps
     * @return array<int, array<string, mixed>>
     */
    protected function withoutThinkingOfSteps(array $messages, array $steps): array
    {
        $storedContentOfSteps = [];
        foreach ($steps as $step) {
            $storedContentOfSteps[] = $step->buildAssistantEntry()[AgentMessagesRequestComposer::KEY_ASSISTANT_CONTENT_JSON] ?? null;
        }
        foreach ($messages as $position => $message) {
            $storedContent = $message[AgentMessagesRequestComposer::KEY_ASSISTANT_CONTENT_JSON] ?? null;
            if ($storedContent !== null && in_array($storedContent, $storedContentOfSteps, true)) {
                $messages[$position][AgentMessagesRequestComposer::KEY_ASSISTANT_CONTENT_WITHOUT_THINKING] = true;
            }
        }
        return $messages;
    }

    /**
     * The verdict of a call that sent a history with thinking blocks stripped: PASS when it was accepted and nothing
     * was dropped, a FINDING when the provider dropped blocks or refused the call.
     */
    protected function setVerdictOfStrippingCall(ReasoningReplayProbeResult $reasoningReplayProbeResult, ReasoningReplayProbeCall $strippingCall, string $whatWasStripped): void
    {
        if (!$strippingCall->isAccepted) {
            $reasoningReplayProbeResult->verdict = ReasoningReplayProbeResult::VERDICT_FINDING;
            $reasoningReplayProbeResult->verdictReason = $strippingCall->label . ' refused (HTTP ' . ($strippingCall->httpStatus ?? 'none') . '): ' . ($strippingCall->errorText ?? 'no error text');
            return;
        }
        if ($strippingCall->droppedThinkingBlockCount > 0) {
            $reasoningReplayProbeResult->verdict = ReasoningReplayProbeResult::VERDICT_FINDING;
            $reasoningReplayProbeResult->verdictReason = $whatWasStripped . '; the provider dropped ' . $strippingCall->droppedThinkingBlockCount . ' of the kept block(s): ' . $strippingCall->inputTransformationsJson;
            return;
        }
        $reasoningReplayProbeResult->verdict = ReasoningReplayProbeResult::VERDICT_PASS;
        $reasoningReplayProbeResult->verdictReason = $whatWasStripped . '; accepted, nothing dropped';
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
        bool $replaysReasoning,
        ?string $additionalNoteText = null
    ): ReasoningReplayProbeStep {
        $probeRun->sentCallCount++;
        $noteText = null;
        if ($probeRun->usesMessagesApi()) {
            // every call of the Messages API ends on a note, as the agent's calls do: a `role: "system"` message with
            // `clear_at` that later calls keep at its place
            $noteText = '[SIGNAL:clock] It is 14:' . sprintf('%02d', min(59, $probeRun->sentCallCount)) . ' now.'
                . ($additionalNoteText !== null ? "\n\n" . $additionalNoteText : '');
            $messages[] = ArgusAIAgentConversation::buildMessagesWireNoteEntry([$noteText]);
        }
        $body = $this->composeBody($probeRun, $aiModel, $messages, $tools, $toolChoice, $replaysReasoning);
        $agentReasoningReplayProbeTransportService = $this->getTransportService();
        $url = match ($probeRun->wireApi) {
            RunSettings::AGENT_WIRE_API_RESPONSES => AgentReasoningReplayProbeTransportService::RESPONSES_URL,
            RunSettings::AGENT_WIRE_API_MESSAGES => AgentReasoningReplayProbeTransportService::MESSAGES_URL,
            default => AgentReasoningReplayProbeTransportService::CHAT_COMPLETIONS_URL,
        };
        $reasoningReplayProbeHttpAnswer = $agentReasoningReplayProbeTransportService->postJson(
            $url,
            $body,
            $probeRun->timeoutSeconds,
            $probeRun->usesMessagesApi() ? AgentMessagesRequestComposer::buildHeaders() : []
        );
        $probeStep = ReasoningReplayProbeStep::fromAnswer($probeRun->wireApi, $label, (string)$aiModel->name, $reasoningReplayProbeHttpAnswer);
        $probeStep->noteText = $noteText;
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
        if ($probeRun->usesMessagesApi()) {
            // the agent's request of this wire: adaptive thinking with the drop behaviour, no temperature, the Anthropic
            // provider pin, and a cache marker on the newest message before the call's note
            $agentWireRequestParameters->maxOutputTokens = self::MESSAGES_MAX_OUTPUT_TOKENS;
            $agentWireRequestParameters->providerPreference = array_merge($agentWireRequestParameters->providerPreference ?? [], ArgusAIAgentConversation::ANTHROPIC_PROVIDER_PIN);
            $agentWireRequestParameters->chatCompletionsMessages = self::withCacheMarkerOnTheNewestStableMessage($messages);
            return AgentMessagesRequestComposer::composeBody($agentWireRequestParameters);
        }
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
     * `$messages` with Anthropic's cache marker on the newest entry before the call's note that carries a plain text
     * (a tool result or an owner message), so the next call reads the history up to it from the cache.
     *
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    public static function withCacheMarkerOnTheNewestStableMessage(array $messages): array
    {
        for ($position = count($messages) - 1; $position >= 1; $position--) {
            $message = $messages[$position];
            if (isset($message[AgentMessagesRequestComposer::KEY_CLEAR_AT]) || !in_array($message['role'] ?? null, ['user', 'tool'], true)) {
                continue;
            }
            if (is_string($message['content'] ?? null) && $message['content'] !== '') {
                $messages[$position]['content'] = [['type' => 'text', 'text' => $message['content'], 'cache_control' => ArgusAIAgentConversation::ANTHROPIC_CACHE_CONTROL]];
                break;
            }
        }
        return $messages;
    }

    /**
     * The system entry and the owner's question of a run: the order task with a long system text on the Messages API,
     * the weather question elsewhere.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildBaseMessagesOfRun(ReasoningReplayProbeRun $probeRun): array
    {
        return [
            $this->buildSystemEntryOfRun($probeRun, ''),
            ['role' => 'user', 'content' => $this->getOwnerQuestionOfRun($probeRun)],
        ];
    }

    public function getOwnerQuestionOfRun(ReasoningReplayProbeRun $probeRun): string
    {
        return $probeRun->usesMessagesApi() ? self::ORDER_TASK_QUESTION : self::OWNER_QUESTION;
    }

    /**
     * The tools a run offers from its first call on.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildToolSchemasOfRun(ReasoningReplayProbeRun $probeRun): array
    {
        return $probeRun->usesMessagesApi()
            ? [$this->buildToolSchema(self::TOOL_ORDER), $this->buildToolSchema(self::TOOL_CUSTOMER)]
            : [$this->buildToolSchema(self::TOOL_WEATHER), $this->buildToolSchema(self::TOOL_TIME)];
    }

    /** The tool a run adds later (`edit-tools`, `append-only`). */
    public function getAppendedToolNameOfRun(ReasoningReplayProbeRun $probeRun): string
    {
        return $probeRun->usesMessagesApi() ? self::TOOL_INVOICE : self::TOOL_AIR_QUALITY;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildSystemEntryOfRun(ReasoningReplayProbeRun $probeRun, string $suffix): array
    {
        if (!$probeRun->usesMessagesApi()) {
            return $this->buildSystemEntry($probeRun->aiModel, $suffix);
        }
        return ['role' => 'system', 'content' => [['type' => 'text', 'text' => self::buildOrderTaskSystemText() . $suffix, 'cache_control' => ArgusAIAgentConversation::ANTHROPIC_CACHE_CONTROL]]];
    }

    /**
     * The system text of the order task: asks for reasoning before every call, and is long enough (about 6,000 tokens)
     * for the minimum cacheable prefix of every Claude model.
     */
    public static function buildOrderTaskSystemText(): string
    {
        $lines = ['You are a back-office assistant of a bicycle shop inside a reasoning-replay probe. Work with the tools; never guess data. Before each tool call, reason carefully about which call comes next and why. Call one tool per step.'];
        for ($ruleNumber = 1; $ruleNumber <= 120; $ruleNumber++) {
            $lines[] = 'Policy ' . $ruleNumber . ': orders are read before customers and customers before invoices; a result is quoted exactly; an unknown id is reported as unknown and never replaced by a similar one; '
                . 'a total is given in euro with two decimals; a city is given with its postal code when the record holds one.';
        }
        return implode("\n", $lines);
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
            self::TOOL_ORDER => 'Reads one order by its id. Returns the customer id and the items.',
            self::TOOL_CUSTOMER => 'Reads one customer by its id. Returns the name and the city.',
            self::TOOL_INVOICE => 'Reads the invoice of one order by the order id. Returns the total.',
        ];
        $idParameters = [self::TOOL_ORDER => 'orderId', self::TOOL_CUSTOMER => 'customerId', self::TOOL_INVOICE => 'orderId'];
        if (isset($idParameters[$toolName])) {
            return [
                'type' => 'function',
                'function' => [
                    'name' => $toolName,
                    'description' => $descriptions[$toolName],
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [$idParameters[$toolName] => ['type' => 'string']],
                        'required' => [$idParameters[$toolName]],
                    ],
                ],
            ];
        }
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
