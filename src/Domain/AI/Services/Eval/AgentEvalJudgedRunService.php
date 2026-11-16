<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentModelInput;
use DDD\Domain\AI\Entities\Conversations\Agent\Prompts\AgentAIPromptNames;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNode;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\Entities\Conversations\SignalSources\ConversationSignalSources;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlock;
use DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlocks;
use DDD\Domain\AI\Entities\Evals\AgentEvalCase;
use DDD\Domain\AI\Entities\Evals\AgentEvalCompactionTreeRunResult;
use DDD\Domain\AI\Entities\Evals\AgentEvalJudgedRunOptions;
use DDD\Domain\AI\Entities\Evals\AgentEvalJudgeTraceMessageLines;
use DDD\Domain\AI\Entities\Evals\AgentEvalJudgeVerdict;
use DDD\Domain\AI\Entities\Evals\AgentEvalRunCounts;
use DDD\Domain\AI\Entities\Evals\AgentEvalRunOptions;
use DDD\Domain\AI\Entities\Evals\AgentEvalScope;
use DDD\Domain\AI\Entities\Evals\AgentEvalSimCase;
use DDD\Domain\AI\Entities\Evals\AgentEvalSimRunResult;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Prompts\AIPrompt;
use DDD\Domain\AI\Entities\UsageLogs\Enums\AIUsageInitiator;
use DDD\Domain\AI\Exceptions\Evals\AgentEvalHarnessModelCallFailedException;
use DDD\Domain\AI\Exceptions\Evals\AgentEvalUnknownModelNameException;
use DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIAgentConversation;
use DDD\Domain\AI\Services\AIModelsService;
use DDD\Domain\AI\Services\AIPromptsService;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationModelCallsService;
use DDD\Domain\AI\Services\Conversations\AIConversationAuditRenderService;
use DDD\Domain\AI\Services\Conversations\ConversationCompactionNodesService;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Infrastructure\Services\DDDService;
use LogicException;
use RuntimeException;

/**
 * The judged run of an evaluation case: a second model plays the owner (the driver) and chats with the agent toward the
 * case's goal; when the owner turns end, a third model (the judge) reads the full trace of the run and returns a
 * verdict. Beside it the compaction-tree run, whose driver keeps a long conversation going and whose judge rates the
 * compaction tree the run built.
 *
 * Staged visibility: the driver reads only what the owner sees ({@see getUserVisibleTranscript()}), the judge reads the
 * whole run ({@see getFullTraceText()}): every message with its stored index, every tool call with its arguments and
 * its result as the model received it, the task plan, the write ledger, the outward actions, the widgets the owner saw
 * and the machine counts. The conversation is assembled like every evaluated conversation
 * ({@see AgentEvalRunnerService::assembleConversation()}) and driven turn by turn with the runner's parts; the driver's
 * and the judge's model calls run on throwaway conversations inside the evaluation envelope, each with its own usage
 * scope ({@see callModel()}).
 *
 * The judge and the driver run on the model they are named: a name the model catalog does not know is refused before
 * any call ({@see refuseUnknownModelName()}), never answered by the agent's default model. A harness call the provider
 * refused is an evaluation error with the provider's message ({@see AgentEvalHarnessModelCallFailedException}): the
 * judge's becomes the ERROR verdict ({@see AgentEvalJudgeVerdict::forFailedJudgeCall()}), the driver's ends the case.
 *
 * What an application adds, each with a neutral default:
 * - {@see applyCaseFixtures()} — the case's fixtures of the application on the new conversation;
 * - {@see buildHostEndStateTextForJudge()} — the end state the judge reads after the run;
 * - {@see buildTextOfHostWidgetShownToOwner()} — a widget block of the application as the owner reads it;
 * - {@see applyHostSectionsToVerdictPrompt()} — the application's rules and sections of the verdict prompt;
 * - {@see isHostOwnerReplyRow()} — a signal row of the application that stands for an owner reply in the counts;
 * - the texts {@see getEmptyDriverReplyText()}, {@see getEmptyCompactionTreeDriverReplyText()},
 *   {@see getDefaultCompactionTreeRubricText()}, {@see getTruncatedTwiceVerdictReasons()} and the default models
 *   {@see getDefaultJudgeModelName()}, {@see getDefaultDriverModelName()}.
 *
 * The prompts are the files of {@see AgentAIPromptNames::APP_AI_AGENT_EVALUATION_DRIVER} and its three siblings; an
 * application replaces a file whole by one of the same path.
 *
 * Makes real model and tool calls; it is driven by the console, never by a test of the package.
 */
class AgentEvalJudgedRunService extends AgentEvalRunnerService
{
    /** @var int The owner turns a case runs when it names no `maxTurns` */
    public const int DEFAULT_MAX_TURNS = 6;

    /** @var string The driver's answer when the owner's goal is reached */
    public const string DRIVER_DONE_MARKER = '[DONE]';

    /** @var string The driver's answer when the agent does not help */
    public const string DRIVER_GIVE_UP_MARKER = '[GIVE-UP]';

    /**
     * @var string The system prompt of the driver's and the judge's model calls: the role and the output format are
     * the user message's, never the agent's identity
     */
    public const string EVAL_HARNESS_SYSTEM_PROMPT = 'You are part of an automated evaluation harness. The user '
        . 'message defines your exact role and required output format for this single completion — follow it '
        . 'precisely. You are NOT the assistant under test.';

    /** @var string The label of an owner message in the driver's transcript */
    public const string TRANSCRIPT_OWNER_LABEL = 'OWNER: ';

    /** @var string The label of an assistant message in the driver's transcript */
    public const string TRANSCRIPT_ASSISTANT_LABEL = 'ASSISTANT: ';

    /** @var string The note of an owner action in a widget in the driver's transcript; `%s` = the signal's text */
    public const string TRANSCRIPT_OWNER_WIDGET_ACTION_NOTE = '[Owner action in the widget: %s]';

    /** @var string The tree text before the first compaction */
    public const string TREE_EMPTY_TEXT = '(tree empty — nothing compacted yet)';

    /** @var string The headline of the tree after one owner turn; `%d` = the turn */
    public const string TREE_SNAPSHOT_HEADLINE = '── after turn %d ──';

    /** @var string The line of the cover; `%s` = the ranges, `%s` = the covered edge */
    public const string TREE_COVER_LINE = 'COVER (what the model receives): %s · edge=msg%s';

    /** @var string The headline of the rendered cover */
    public const string TREE_RENDERED_COVER_HEADLINE = 'RENDERED COVER (the exact model input):';

    /** @var int The longest summary preview of one tree node */
    public const int TREE_NODE_PREVIEW_MAX_LENGTH = 70;

    /** @var string The refusal of a model name the catalog does not know; `%s` = the name */
    public const string UNKNOWN_MODEL_NAME_MESSAGE = 'The model catalog knows no model "%s" (neither a model nor a retired name of one); the known names are the keys of the configuration AI.models and of AIModel::RETIRED_MODEL_SUCCESSORS.';

    /**
     * The model that returns the verdict when the options name none.
     *
     * @return string
     */
    public function getDefaultJudgeModelName(): string
    {
        return AIModel::MODEL_ANTHROPIC_CLAUDE_OPUS_5_5;
    }

    /**
     * The model that plays the owner when the options name none: a short role-play that runs once per owner turn, so a
     * smaller model than the judge's.
     *
     * @return string
     */
    public function getDefaultDriverModelName(): string
    {
        return AIModel::MODEL_ANTHROPIC_CLAUDE_SONNET_5;
    }

    /**
     * Runs one judged case on a new conversation of the scope and returns the judge's verdict on it.
     *
     * The case's settings blocks reach the conversation first ({@see AgentEvalSimCase::applyCompactionSettingsTo()} and
     * its two siblings), then it is stored when the options store conversations (a schedule firing stays transient: its
     * evaluation schedule id has no row), it gets the case's report channel and schedule firing, its run identity is
     * written, and the application's fixtures are applied ({@see applyCaseFixtures()}). The opening prompt is the first
     * owner turn; a `purposeName` (or, for a schedule firing or a report channel, the schedule-run purpose) is applied on
     * the first owner message. The case's scripted follow-ups come next, one per turn after their idle time; then the
     * driver writes each next owner message until it answers `[DONE]` or `[GIVE-UP]`, the conversation is closed, or
     * the case's turns are used up. The case's tool block (its `blockedTools`, `allowExternalWrites`,
     * `blockedToolSimulatedErrors`, read through {@see buildRunCaseOfSimCase()}) guards the agent's run and is lifted
     * when the run ends.
     *
     * A judge or driver model the catalog does not know is refused before the first turn; a failed driver call ends the
     * case with the provider's message, a failed judge call is the ERROR verdict.
     *
     * @param AgentEvalScope $agentEvalScope
     * @param AgentEvalSimCase $agentEvalSimCase the judged case
     * @param AgentEvalJudgedRunOptions $agentEvalJudgedRunOptions
     * @return AgentEvalSimRunResult
     * @throws AgentEvalUnknownModelNameException when the judge or driver model of the run is unknown to the catalog
     * @throws AgentEvalHarnessModelCallFailedException when a driver call failed
     */
    public function runSim(AgentEvalScope $agentEvalScope, AgentEvalSimCase $agentEvalSimCase, AgentEvalJudgedRunOptions $agentEvalJudgedRunOptions): AgentEvalSimRunResult
    {
        $this->authenticateOwnerOfScope($agentEvalScope);
        $this->refuseUnknownModelNamesOfRun($agentEvalJudgedRunOptions);
        $this->applyToolBlockOfCase($this->buildRunCaseOfSimCase($agentEvalSimCase), $agentEvalJudgedRunOptions);
        try {
            $conversation = $this->prepareConversationOfSimCase($agentEvalScope, $agentEvalSimCase, $agentEvalJudgedRunOptions);
            return $this->driveOwnerTurnsAndJudge($conversation, $agentEvalScope, $agentEvalSimCase, $agentEvalJudgedRunOptions);
        } finally {
            $this->liftToolBlockOfCase($agentEvalJudgedRunOptions);
        }
    }

    /**
     * {@see runSim()} inside the restore bracket of the case's `restore` block: the preparation steps and the capture
     * before the run, then — also when the run threw — the revert write, the `after` steps, the removals and the
     * release ({@see openRestoreBracket()}, {@see closeRestoreBracket()}). Only the run in the middle is the agent under
     * evaluation.
     *
     * @param AgentEvalScope $agentEvalScope
     * @param AgentEvalSimCase $agentEvalSimCase a case with a `restore` block
     * @param AgentEvalJudgedRunOptions $agentEvalJudgedRunOptions
     * @return AgentEvalSimRunResult
     */
    public function runSimWithRestore(AgentEvalScope $agentEvalScope, AgentEvalSimCase $agentEvalSimCase, AgentEvalJudgedRunOptions $agentEvalJudgedRunOptions): AgentEvalSimRunResult
    {
        $agentEvalRestoreBracket = $this->openRestoreBracket($agentEvalScope, $this->buildRunCaseOfSimCase($agentEvalSimCase), $agentEvalJudgedRunOptions);
        try {
            return $this->runSim($agentEvalScope, $agentEvalSimCase, $agentEvalJudgedRunOptions);
        } finally {
            $this->closeRestoreBracket($agentEvalRestoreBracket, $agentEvalScope, $agentEvalJudgedRunOptions);
        }
    }

    /**
     * The judged case read as a run case: the keys the runner reads of a case — the tool block (`blockedTools`,
     * `allowExternalWrites`, `blockedToolSimulatedErrors`) and the `restore` block — are the judged case's own keys.
     *
     * @param AgentEvalSimCase $agentEvalSimCase
     * @return AgentEvalCase
     */
    protected function buildRunCaseOfSimCase(AgentEvalSimCase $agentEvalSimCase): AgentEvalCase
    {
        return AgentEvalCase::fromArray($agentEvalSimCase->toArray());
    }

    /**
     * The conversation of a judged case before its first owner turn (see {@see runSim()}).
     *
     * @param AgentEvalScope $agentEvalScope
     * @param AgentEvalSimCase $agentEvalSimCase
     * @param AgentEvalRunOptions $agentEvalRunOptions
     * @return AIConversation
     */
    protected function prepareConversationOfSimCase(AgentEvalScope $agentEvalScope, AgentEvalSimCase $agentEvalSimCase, AgentEvalRunOptions $agentEvalRunOptions): AIConversation
    {
        $conversation = $this->buildSimConversation($agentEvalScope, $agentEvalRunOptions);
        // a case with low compaction values compacts within its few turns
        $agentEvalSimCase->applyCompactionSettingsTo($conversation);
        $agentEvalSimCase->applyContextRetentionSettingsTo($conversation);
        $agentEvalSimCase->applyRunSettingsTo($conversation);
        // after the case's settings, so the row carries them; the replay of a schedule firing stays transient
        if (!$agentEvalSimCase->isScheduledRun()) {
            $this->persistConversationIfRequested($conversation, $agentEvalScope, $agentEvalRunOptions);
        }
        $reportChannel = $agentEvalSimCase->getReportChannel();
        if ($reportChannel !== null) {
            $conversation->reportChannel = $reportChannel;
            $conversation->initiatorType = AIConversation::INITIATOR_TYPE_ADMIN_TASK;
        }
        if ($agentEvalSimCase->isScheduledRun()) {
            $conversation->markAsEvalScheduledRun();
        }
        $this->persistRunIdentityIfPersisted($conversation);
        $this->applyCaseFixtures($conversation, $agentEvalSimCase, $agentEvalScope);
        return $conversation;
    }

    /**
     * A new, empty conversation of the scope, assembled like every evaluated conversation (the run's settings, the
     * arms' creation stamps, the budget lift) and recorded on the options for the teardown.
     *
     * @param AgentEvalScope $agentEvalScope
     * @param AgentEvalRunOptions $agentEvalRunOptions
     * @return AIConversation
     */
    protected function buildSimConversation(AgentEvalScope $agentEvalScope, AgentEvalRunOptions $agentEvalRunOptions): AIConversation
    {
        return $this->assembleConversation($agentEvalScope, $agentEvalRunOptions);
    }

    /**
     * The purpose a judged case runs in: its `purposeName`; for a schedule firing or a report channel without one, the
     * schedule-run purpose; null otherwise.
     *
     * @param AgentEvalSimCase $agentEvalSimCase
     * @return string|null
     */
    protected function resolvePurposeNameOfSimCase(AgentEvalSimCase $agentEvalSimCase): ?string
    {
        $purposeName = $agentEvalSimCase->getPurposeName();
        if ($purposeName === null && ($agentEvalSimCase->isScheduledRun() || $agentEvalSimCase->getReportChannel() !== null)) {
            return AIConversationPurpose::SCHEDULE_RUN;
        }
        return $purposeName;
    }

    /**
     * The owner turns of a judged case and the judge's verdict on the run (see {@see runSim()}). A stored run is
     * judged on what was stored ({@see reloadIfPersisted()}).
     *
     * @param AIConversation $conversation the prepared conversation
     * @param AgentEvalScope $agentEvalScope
     * @param AgentEvalSimCase $agentEvalSimCase
     * @param AgentEvalJudgedRunOptions $agentEvalJudgedRunOptions
     * @return AgentEvalSimRunResult
     */
    protected function driveOwnerTurnsAndJudge(
        AIConversation $conversation,
        AgentEvalScope $agentEvalScope,
        AgentEvalSimCase $agentEvalSimCase,
        AgentEvalJudgedRunOptions $agentEvalJudgedRunOptions
    ): AgentEvalSimRunResult {
        $maxTurns = $agentEvalSimCase->resolveMaxTurns(static::DEFAULT_MAX_TURNS);
        $purposeName = $this->resolvePurposeNameOfSimCase($agentEvalSimCase);
        $scriptedFollowUpPrompts = $agentEvalSimCase->getFollowUpPrompts();
        $scriptedFollowUpIdleMinutes = $agentEvalSimCase->getFollowUpIdleMinutes();
        $ownerMessageText = $agentEvalSimCase->getOpeningPrompt();
        $idleMinutesBeforeTurn = 0;
        $stopReason = AgentEvalSimRunResult::STOP_REASON_MAX_TURNS;
        for ($turn = 1; $turn <= $maxTurns; $turn++) {
            $this->driveOwnerTurn($conversation, $agentEvalScope, $agentEvalJudgedRunOptions, $ownerMessageText, $turn, $idleMinutesBeforeTurn, $purposeName);
            $idleMinutesBeforeTurn = 0;
            // a purpose that closed its conversation on the settled turn ends the case: no later owner message is answered
            $closedStopReason = $this->stopReasonWhenConversationIsClosed($conversation);
            if ($closedStopReason !== null) {
                $stopReason = $closedStopReason;
                break;
            }
            // the case's scripted owner words come first; the driver takes over once they are used up
            if ($scriptedFollowUpPrompts !== []) {
                $ownerMessageText = (string)array_shift($scriptedFollowUpPrompts);
                $idleMinutesBeforeTurn = (int)(array_shift($scriptedFollowUpIdleMinutes) ?? 0);
                continue;
            }
            $driverReply = $this->askDriverForNextOwnerMessage($conversation, $agentEvalScope, $agentEvalSimCase, $agentEvalJudgedRunOptions);
            $driverStopReason = $this->findStopReasonOfDriverReply($driverReply);
            if ($driverStopReason !== null) {
                $stopReason = $driverStopReason;
                break;
            }
            $ownerMessageText = $driverReply !== '' ? $driverReply : $this->getEmptyDriverReplyText();
        }
        $conversation = $this->reloadIfPersisted($conversation)->getConversation();
        $agentEvalJudgeVerdict = $this->judgeVerdict(
            $agentEvalScope,
            $agentEvalJudgedRunOptions->resolveJudgeModelName($this->getDefaultJudgeModelName()),
            $this->buildVerdictPrompt($conversation, $agentEvalSimCase, $this->getFullTraceText($conversation))
        );
        return AgentEvalSimRunResult::forRun($agentEvalJudgeVerdict, $stopReason, min($turn, $maxTurns), $conversation);
    }

    /**
     * One owner turn of a judged case: the database connection is healed after the idle phases of the model calls, the
     * owner's idle time is simulated, the owner message is written like a live one, the purpose is applied on the first
     * owner message, a later turn starts on fresh process state, and the agent is driven.
     *
     * @param AIConversation $conversation
     * @param AgentEvalScope $agentEvalScope
     * @param AgentEvalRunOptions $agentEvalRunOptions
     * @param string $ownerMessageText
     * @param int $turn the owner turn, from 1
     * @param int $idleMinutesBeforeTurn how long the owner was away before this turn
     * @param string|null $purposeName the purpose applied on the first owner message; null = none
     * @return void
     */
    protected function driveOwnerTurn(
        AIConversation $conversation,
        AgentEvalScope $agentEvalScope,
        AgentEvalRunOptions $agentEvalRunOptions,
        string $ownerMessageText,
        int $turn,
        int $idleMinutesBeforeTurn,
        ?string $purposeName
    ): void {
        $this->healIdleDatabaseConnections();
        $this->simulateOwnerIdleBeforeNextTurn($conversation, $idleMinutesBeforeTurn);
        $this->appendOwnerMessage($conversation, $ownerMessageText, $agentEvalRunOptions);
        if ($turn === 1 && $purposeName !== null) {
            $this->applyEvalPurpose($conversation, $purposeName, $conversation->messages->last(), $agentEvalRunOptions);
        }
        if ($turn > 1) {
            $this->startNextOwnerTurnOnFreshState($conversation);
        }
        $this->driveAgent($conversation, $agentEvalScope, $agentEvalRunOptions);
    }

    /**
     * The driver's next owner message, trimmed: it reads the owner's goal, facts and the transcript the owner sees.
     *
     * @param AIConversation $conversation
     * @param AgentEvalScope $agentEvalScope
     * @param AgentEvalSimCase $agentEvalSimCase
     * @param AgentEvalJudgedRunOptions $agentEvalJudgedRunOptions
     * @return string
     * @throws AgentEvalHarnessModelCallFailedException when the driver call failed: the case ends, never with an empty
     *                                                  owner turn
     */
    protected function askDriverForNextOwnerMessage(
        AIConversation $conversation,
        AgentEvalScope $agentEvalScope,
        AgentEvalSimCase $agentEvalSimCase,
        AgentEvalJudgedRunOptions $agentEvalJudgedRunOptions
    ): string {
        $driverPrompt = $this->buildDriverPrompt($conversation, $agentEvalSimCase);
        return trim($this->callModel(
            $agentEvalScope,
            $agentEvalJudgedRunOptions->resolveDriverModelName($this->getDefaultDriverModelName()),
            $driverPrompt->getPromptTextWithParametersApplied(),
            AgentAIUsageScope::AI_AGENT_SIMULATE_OWNER
        ));
    }

    /**
     * The stop reason a driver's answer carries: DONE for `[DONE]`, GIVE_UP for `[GIVE-UP]`; null for an owner message.
     *
     * @param string $driverReply
     * @return string|null
     */
    protected function findStopReasonOfDriverReply(string $driverReply): ?string
    {
        if (str_contains($driverReply, static::DRIVER_DONE_MARKER)) {
            return AgentEvalSimRunResult::STOP_REASON_DONE;
        }
        if (str_contains($driverReply, static::DRIVER_GIVE_UP_MARKER)) {
            return AgentEvalSimRunResult::STOP_REASON_GIVE_UP;
        }
        return null;
    }

    /**
     * DONE when the conversation is closed (a purpose closed it on the settled turn, and the run claim needs an idle
     * conversation, so an owner message posted now would never be answered); null while it accepts owner input.
     *
     * @param AIConversation $conversation
     * @return string|null
     */
    protected function stopReasonWhenConversationIsClosed(AIConversation $conversation): ?string
    {
        return $conversation->isClosed() ? AgentEvalSimRunResult::STOP_REASON_DONE : null;
    }

    /**
     * Touches the database connection at an owner-turn boundary: a judged turn idles for minutes in the model calls,
     * the server drops an idle connection, and the next query would fail mid-case. Getting the entity manager pings
     * the connection and recreates a dead one.
     *
     * @return void
     */
    protected function healIdleDatabaseConnections(): void
    {
        EntityManagerFactory::getInstance();
    }

    /**
     * The driver's prompt: the case's goal and owner facts, then the transcript the owner sees, set last.
     *
     * @param AIConversation $conversation
     * @param AgentEvalSimCase $agentEvalSimCase
     * @return AIPrompt
     */
    public function buildDriverPrompt(AIConversation $conversation, AgentEvalSimCase $agentEvalSimCase): AIPrompt
    {
        $driverPrompt = $this->findPromptByName(AgentAIPromptNames::APP_AI_AGENT_EVALUATION_DRIVER);
        $driverPrompt->setParameter('GOAL', $agentEvalSimCase->getGoal());
        $driverPrompt->setParameter('FACTS', $agentEvalSimCase->getOwnerFacts());
        $driverPrompt->setParameter('TRANSCRIPT', $this->getUserVisibleTranscript($conversation));
        return $driverPrompt;
    }

    /**
     * The verdict prompt of a judged run: the case's goal, opening prompt and rubric; the application's rules and
     * sections, empty unless {@see applyHostSectionsToVerdictPrompt()} sets them; the trace, set last so that a
     * placeholder quoted in the trace is never replaced.
     *
     * @param AIConversation $conversation the judged conversation
     * @param AgentEvalSimCase $agentEvalSimCase
     * @param string $traceText {@see getFullTraceText()}
     * @return AIPrompt
     */
    public function buildVerdictPrompt(AIConversation $conversation, AgentEvalSimCase $agentEvalSimCase, string $traceText): AIPrompt
    {
        $verdictPrompt = $this->findPromptByName(AgentAIPromptNames::APP_AI_AGENT_EVALUATION_VERDICT);
        $verdictPrompt->setParameter('GOAL', $agentEvalSimCase->getGoal());
        $verdictPrompt->setParameter('OPENING', $agentEvalSimCase->getOpeningPrompt());
        $verdictPrompt->setParameter('RUBRIC', $agentEvalSimCase->getSuccessRubric());
        $verdictPrompt->setParameter('HOST_RULES', '');
        $verdictPrompt->setParameter('HOST_SECTIONS', '');
        $this->applyHostSectionsToVerdictPrompt($verdictPrompt, $conversation, $agentEvalSimCase);
        $verdictPrompt->setParameter('TRACE', $traceText);
        return $verdictPrompt;
    }

    /**
     * The prompt of `$promptName`.
     *
     * @param string $promptName
     * @return AIPrompt
     * @throws RuntimeException when no prompt file of that name exists
     */
    protected function findPromptByName(string $promptName): AIPrompt
    {
        /** @var AIPromptsService $aIPromptsService */
        $aIPromptsService = DDDService::instance()->getService(AIPromptsService::class);
        $aIPrompt = $aIPromptsService->getAIPromptByName($promptName);
        if (!$aIPrompt instanceof AIPrompt) {
            throw new RuntimeException("The prompt {$promptName} is not configured");
        }
        return $aIPrompt;
    }

    /**
     * One plain completion of `$aiModelName` on `$promptText` (no tools), for the driver or the judge: the prompt is the
     * one owner message of a throwaway conversation of the scope (never stored, never recorded as the run's
     * conversation), sent with the harness system prompt ({@see EVAL_HARNESS_SYSTEM_PROMPT}), inside the evaluation
     * envelope billed to the scope's owner and booked under `$aiUsageScope`. A model name the catalog does not know is
     * refused before the envelope opens.
     *
     * @param AgentEvalScope $agentEvalScope
     * @param string $aiModelName
     * @param string $promptText
     * @param string $aiUsageScope {@see AgentAIUsageScope::AI_AGENT_SIMULATE_OWNER} or {@see AgentAIUsageScope::AI_AGENT_JUDGE_EVALUATION}
     * @return string the model's answer
     * @throws AgentEvalUnknownModelNameException when the catalog does not know `$aiModelName`
     * @throws AgentEvalHarnessModelCallFailedException when the provider refused the call or it failed
     */
    public function callModel(AgentEvalScope $agentEvalScope, string $aiModelName, string $promptText, string $aiUsageScope): string
    {
        $this->refuseUnknownModelName($aiModelName);
        $aIUsageEnvelopesService = $this->getUsageEnvelopesService();
        $evaluationEnvelopeContext = $aIUsageEnvelopesService->openEnvelope(
            $agentEvalScope->getOwnerAccountId(),
            AgentAIUsageScope::ENVELOPE_AI_AGENT_EVALUATION,
            AIUsageInitiator::CLI
        );
        try {
            $promptConversation = $this->buildHarnessPromptConversation($agentEvalScope, $promptText);
            $promptMessage = $promptConversation->messages->last();
            if (!$promptMessage instanceof AIConversationMessage) {
                throw new LogicException('The harness prompt needs its message before the model call.');
            }
            $argusAIAgentConversation = new ArgusAIAgentConversation();
            $argusAIAgentConversation->fromEntity($promptConversation);
            /** @var AIConversation $promptConversation fromEntity() takes it by reference; it stays the same object */
            $argusAIAgentConversation->toolSchemas = [];
            $argusAIAgentConversation->capturedToolCalls = null;
            $argusAIAgentConversation->aiModelNameOverride = $aiModelName;
            $argusAIAgentConversation->setAIUsageScope($aiUsageScope);
            $argusAIAgentConversation->modelInput = $this->buildHarnessModelInput($promptMessage);
            return $this->loadHarnessCompletion($argusAIAgentConversation);
        } finally {
            $aIUsageEnvelopesService->closeEnvelope($evaluationEnvelopeContext);
        }
    }

    /**
     * Refuses a judge or driver model name the model catalog does not know: without the refusal the call would run on
     * the agent's default model and the verdict would be given by another model. A retired name passes: the catalog
     * resolves it to its successor ({@see AIModelsService::getAIModelByName()}).
     *
     * @param string $aiModelName
     * @return void
     * @throws AgentEvalUnknownModelNameException
     */
    public function refuseUnknownModelName(string $aiModelName): void
    {
        /** @var AIModelsService $aIModelsService */
        $aIModelsService = DDDService::instance()->getService(AIModelsService::class);
        $throwErrorsBeforeLookup = $aIModelsService->disableThrowErrors();
        try {
            $aIModel = $aIModelsService->getAIModelByName($aiModelName);
        } finally {
            $aIModelsService->restoreThrowErrors($throwErrorsBeforeLookup);
        }
        if ($aIModel === null) {
            throw new AgentEvalUnknownModelNameException($aiModelName, sprintf(static::UNKNOWN_MODEL_NAME_MESSAGE, $aiModelName));
        }
    }

    /**
     * Refuses the judge and the driver model of a run (the ones set, else the defaults) before its first turn
     * ({@see refuseUnknownModelName()}).
     *
     * @param AgentEvalJudgedRunOptions $agentEvalJudgedRunOptions
     * @return void
     * @throws AgentEvalUnknownModelNameException
     */
    public function refuseUnknownModelNamesOfRun(AgentEvalJudgedRunOptions $agentEvalJudgedRunOptions): void
    {
        $this->refuseUnknownModelName($agentEvalJudgedRunOptions->resolveJudgeModelName($this->getDefaultJudgeModelName()));
        $this->refuseUnknownModelName($agentEvalJudgedRunOptions->resolveDriverModelName($this->getDefaultDriverModelName()));
    }

    /**
     * The model input of a harness model call: the harness system prompt ({@see EVAL_HARNESS_SYSTEM_PROMPT}) and the
     * prompt as the one owner message — none of the platform's sections of an agent call (who the agent talks to, the
     * skill catalog, the memories, the product, the clock): the driver and the judge are no agent of the platform.
     *
     * @param AIConversationMessage $promptMessage
     * @return AgentModelInput
     */
    protected function buildHarnessModelInput(AIConversationMessage $promptMessage): AgentModelInput
    {
        $agentModelInput = AgentModelInput::newInstance();
        $agentModelInput->systemPrompt = static::EVAL_HARNESS_SYSTEM_PROMPT;
        $agentModelInput->messages = [$promptMessage];
        return $agentModelInput;
    }

    /**
     * Sends the prepared completion request of a harness model call and returns the model's answer (the one network
     * call of {@see callModel()}). A failed load never throws and answers an empty text; the provider's refusal is in
     * its failure reason, which this call turns into {@see AgentEvalHarnessModelCallFailedException}.
     *
     * @param ArgusAIAgentConversation $argusAIAgentConversation the request, its model, usage scope and input set
     * @return string
     * @throws AgentEvalHarnessModelCallFailedException when the provider refused the call or it failed
     */
    protected function loadHarnessCompletion(ArgusAIAgentConversation $argusAIAgentConversation): string
    {
        $argusAIAgentConversation->argusLoad();
        $loadFailureReason = $argusAIAgentConversation->getLastLoadFailureReason();
        if ($loadFailureReason !== null) {
            throw new AgentEvalHarnessModelCallFailedException(
                (string)$argusAIAgentConversation->aiModelNameOverride,
                (string)$argusAIAgentConversation->getAIUsageScope(),
                $loadFailureReason
            );
        }
        return (string)$argusAIAgentConversation->getAiGeneratedResponse();
    }

    /**
     * The throwaway conversation of a harness model call: owned by the scope's owner, attached to the scope without
     * writing, holding the prompt as its one owner message (without the run's simulated owner device: the prompt is
     * no owner message of the case).
     *
     * @param AgentEvalScope $agentEvalScope
     * @param string $promptText
     * @return AIConversation
     */
    protected function buildHarnessPromptConversation(AgentEvalScope $agentEvalScope, string $promptText): AIConversation
    {
        $promptConversation = AIConversation::newInstance();
        $promptConversation->accountId = $agentEvalScope->getOwnerAccountId();
        $this->getAgentEvalRunHooksService()->attachNewConversationToScope($promptConversation, $agentEvalScope);
        $promptConversation->messages = AIConversationMessages::newInstance();
        $promptConversation->appendMessage(
            AIConversationMessage::newInstance(),
            AIConversationMessage::MESSAGE_TEXT,
            $promptText,
            role: AIConversationMessage::ROLE_USER,
            authorType: AIConversationMessage::AUTHOR_USER
        );
        return $promptConversation;
    }

    /**
     * The judge's verdict on `$verdictPrompt`. An unparseable answer (an empty one included) and a failed judge call are
     * asked once more before they count; a second failed call is the ERROR verdict with the provider's message
     * ({@see AgentEvalJudgeVerdict::forFailedJudgeCall()}), never a FAIL. A verdict cut off by the output limit carries the verdict scalars but no criteria, so the run is judged
     * once more; a second cut-off verdict is an evaluation error ({@see AgentEvalJudgeVerdict::VERDICT_ERROR}), never
     * the salvaged verdict.
     *
     * @param AgentEvalScope $agentEvalScope the scope the judge's call is billed to
     * @param string $judgeModelName
     * @param AIPrompt $verdictPrompt
     * @return AgentEvalJudgeVerdict
     */
    public function judgeVerdict(AgentEvalScope $agentEvalScope, string $judgeModelName, AIPrompt $verdictPrompt): AgentEvalJudgeVerdict
    {
        $verdictPromptText = $verdictPrompt->getPromptTextWithParametersApplied();
        $agentEvalJudgeVerdict = $this->askJudgeWithOneRetryOnAnUnparseableAnswer($agentEvalScope, $judgeModelName, $verdictPromptText);
        if (!$agentEvalJudgeVerdict->isTruncated()) {
            return $agentEvalJudgeVerdict;
        }
        $agentEvalJudgeVerdict = $this->askJudgeWithOneRetryOnAnUnparseableAnswer($agentEvalScope, $judgeModelName, $verdictPromptText);
        if ($agentEvalJudgeVerdict->isTruncated()) {
            $agentEvalJudgeVerdict->markAsEvaluationError($this->getTruncatedTwiceVerdictReasons());
        }
        return $agentEvalJudgeVerdict;
    }

    /**
     * The judge's verdict read from its answer; an unparseable answer and a failed call are asked once more, and a
     * second one stays the unparseable FAIL or the failed-call ERROR.
     *
     * @param AgentEvalScope $agentEvalScope
     * @param string $judgeModelName
     * @param string $verdictPromptText
     * @return AgentEvalJudgeVerdict
     */
    protected function askJudgeWithOneRetryOnAnUnparseableAnswer(AgentEvalScope $agentEvalScope, string $judgeModelName, string $verdictPromptText): AgentEvalJudgeVerdict
    {
        $agentEvalJudgeVerdict = $this->askJudgeOnce($agentEvalScope, $judgeModelName, $verdictPromptText);
        if (!$agentEvalJudgeVerdict->isUnparseable() && !$agentEvalJudgeVerdict->isJudgeCallFailed()) {
            return $agentEvalJudgeVerdict;
        }
        return $this->askJudgeOnce($agentEvalScope, $judgeModelName, $verdictPromptText);
    }

    /**
     * One judge call read as a verdict; a call the provider refused or that failed is the failed-call verdict (ERROR)
     * with the provider's message.
     *
     * @param AgentEvalScope $agentEvalScope
     * @param string $judgeModelName
     * @param string $verdictPromptText
     * @return AgentEvalJudgeVerdict
     */
    protected function askJudgeOnce(AgentEvalScope $agentEvalScope, string $judgeModelName, string $verdictPromptText): AgentEvalJudgeVerdict
    {
        try {
            return AgentEvalJudgeVerdict::fromJudgeOutput(
                $this->callModel($agentEvalScope, $judgeModelName, $verdictPromptText, AgentAIUsageScope::AI_AGENT_JUDGE_EVALUATION)
            );
        } catch (AgentEvalHarnessModelCallFailedException $judgeCallFailure) {
            return AgentEvalJudgeVerdict::forFailedJudgeCall($judgeCallFailure->getMessage());
        }
    }

    /**
     * The `reasons` of a verdict that was cut off twice.
     *
     * @return string
     */
    protected function getTruncatedTwiceVerdictReasons(): string
    {
        return 'Judge output truncated twice — evaluation error, not a verdict.';
    }

    /**
     * What the driver reads: the owner's and the assistant's texts as the owner sees them, the widget rows a tool or the
     * platform wrote (the owner sees them like assistant messages), and one note per owner action in a widget (a click
     * is invisible on the wire, and without the note the driver would answer a question the owner already closed). No
     * tool call, no result, no skill.
     *
     * @param AIConversation $conversation
     * @return string
     */
    public function getUserVisibleTranscript(AIConversation $conversation): string
    {
        $transcriptLines = [];
        foreach (($this->findMessagesShownToTheOwner($conversation)?->getElements() ?? []) as $message) {
            $transcriptLine = $this->buildTranscriptLineOfMessage($conversation, $message);
            if ($transcriptLine !== null) {
                $transcriptLines[] = $transcriptLine;
            }
        }
        return implode("\n\n", $transcriptLines);
    }

    /**
     * The line of one row in the driver's transcript; null for a row the owner does not read.
     *
     * @param AIConversation $conversation
     * @param AIConversationMessage $message
     * @return string|null
     */
    protected function buildTranscriptLineOfMessage(AIConversation $conversation, AIConversationMessage $message): ?string
    {
        $messageType = $message->messageType ?? null;
        $messageText = trim((string)($message->message ?? ''));
        if ($messageType === AIConversationMessage::MESSAGE_SIGNAL) {
            $carriesAnOwnerStatement = ConversationSignalSources::tryFrom((string)($message->signalSource ?? ''))?->carriesAnOwnerStatement() === true;
            return $carriesAnOwnerStatement && $messageText !== '' ? sprintf(static::TRANSCRIPT_OWNER_WIDGET_ACTION_NOTE, $messageText) : null;
        }
        if (($messageType !== AIConversationMessage::MESSAGE_TEXT && $messageType !== AIConversationMessage::MESSAGE_TOOL_MESSAGE) || $messageText === '') {
            return null;
        }
        $role = $message->role ?? null;
        if ($role === AIConversationMessage::ROLE_USER) {
            return static::TRANSCRIPT_OWNER_LABEL . $messageText;
        }
        if ($role === AIConversationMessage::ROLE_ASSISTANT) {
            return static::TRANSCRIPT_ASSISTANT_LABEL . $this->buildAssistantTextShownToOwner($conversation, $messageText);
        }
        return null;
    }

    /**
     * An assistant text as the owner reads it: each widget block the application renders as text
     * ({@see buildTextOfHostWidgetShownToOwner()}) replaced by that text, its line ending kept. The blocks are spliced
     * from the last to the first, so the positions of the earlier ones stay valid; every other block and every text
     * without one stay as written.
     *
     * @param AIConversation $conversation
     * @param string $assistantText
     * @return string
     */
    protected function buildAssistantTextShownToOwner(AIConversation $conversation, string $assistantText): string
    {
        $textShownToOwner = $assistantText;
        foreach (array_reverse(WidgetBlocks::fromMarkdown($assistantText)->getElements()) as $widgetBlock) {
            $hostWidgetText = $this->buildTextOfHostWidgetShownToOwner($conversation, $widgetBlock);
            if ($hostWidgetText === null) {
                continue;
            }
            $blockText = substr($assistantText, $widgetBlock->start, $widgetBlock->length);
            $blockLineEnding = substr($blockText, strlen(rtrim($blockText, "\r\n")));
            $textShownToOwner = substr_replace($textShownToOwner, $hostWidgetText . $blockLineEnding, $widgetBlock->start, $widgetBlock->length);
        }
        return $textShownToOwner;
    }

    /**
     * The rows of the conversation as the owner's screen shows them. A stored conversation is read fresh from the
     * database: a tool call the loop ran in a child process writes its widget rows there and in the child's set only,
     * and the set in memory would lack them. A transient conversation has only its set in memory; a read that returns
     * nothing falls back to it too.
     *
     * @param AIConversation $conversation
     * @return AIConversationMessages|null
     */
    protected function findMessagesShownToTheOwner(AIConversation $conversation): ?AIConversationMessages
    {
        $messagesInMemory = $conversation->messages ?? null;
        if (!isset($conversation->id)) {
            return $messagesInMemory;
        }
        return $this->findFreshMessagesOfStoredConversation($conversation) ?? $messagesInMemory;
    }

    /**
     * Every stored row of the conversation, read past the entity registry.
     *
     * @param AIConversation $conversation
     * @return AIConversationMessages|null
     */
    protected function findFreshMessagesOfStoredConversation(AIConversation $conversation): ?AIConversationMessages
    {
        return $this->getMessagesService()->findAllMessagesForConversationInOrder($conversation, useEntityRegistrCache: false);
    }

    /**
     * The full trace the judge reads: the agent's system prompt of its first call (the base data of the space it
     * carries is grounded), the lines of the messages ({@see AgentEvalJudgeTraceMessageLines}), then the task plan
     * with its provenance, the write ledger, the outward actions, the widgets the owner saw, the machine counts, and
     * the application's end state when it renders one ({@see buildHostEndStateTextForJudge()}).
     *
     * @param AIConversation $conversation
     * @return string
     */
    public function getFullTraceText(AIConversation $conversation): string
    {
        $traceLines = [];
        $systemPrompt = $this->extractSystemPrompt($conversation);
        if ($systemPrompt !== null && $systemPrompt !== '') {
            $traceLines[] = AgentEvalJudgeTraceMessageLines::buildSystemPromptSection($systemPrompt);
        }
        $messages = $conversation->messages ?? null;
        if ($messages instanceof AIConversationMessages) {
            foreach (AgentEvalJudgeTraceMessageLines::fromMessages($messages)->getLines() as $messageLine) {
                $traceLines[] = $messageLine;
            }
        }
        foreach ($this->buildEndSectionsForJudge($conversation) as $endSection) {
            $traceLines[] = $endSection;
        }
        return implode("\n", $traceLines);
    }

    /**
     * The sections after the messages: the task plan with its provenance (when the conversation has one), the write
     * ledger, the outward actions, the widgets the owner saw, the counts, and the application's end state.
     *
     * @param AIConversation $conversation
     * @return string[]
     */
    protected function buildEndSectionsForJudge(AIConversation $conversation): array
    {
        /** @var AIConversationAuditRenderService $aIConversationAuditRenderService */
        $aIConversationAuditRenderService = DDDService::instance()->getService(AIConversationAuditRenderService::class);
        $endSections = [];
        $taskPlanText = $aIConversationAuditRenderService->renderTaskPlanWithProvenance($conversation);
        if ($taskPlanText !== null) {
            $endSections[] = "\n" . $taskPlanText;
        }
        $endSections[] = "\n" . $aIConversationAuditRenderService->renderWriteLedger($conversation);
        $endSections[] = "\n" . $aIConversationAuditRenderService->renderOutwardActions($conversation);
        $endSections[] = "\n" . $aIConversationAuditRenderService->renderWidgetInventory($conversation);
        $endSections[] = $this->buildRunCountsOfConversation($conversation)->toJudgeText();
        $hostEndStateText = $this->buildHostEndStateTextForJudge($conversation);
        if ($hostEndStateText !== null) {
            $endSections[] = $hostEndStateText;
        }
        return $endSections;
    }

    /**
     * The machine counts of the conversation's loaded rows; a signal row the application declares an owner reply
     * ({@see isHostOwnerReplyRow()}) counts as one.
     *
     * @param AIConversation $conversation
     * @return AgentEvalRunCounts
     */
    public function buildRunCountsOfConversation(AIConversation $conversation): AgentEvalRunCounts
    {
        $agentEvalRunCounts = AgentEvalRunCounts::newInstance();
        foreach (($conversation->messages?->getElements() ?? []) as $message) {
            $isHostOwnerReplyRow = ($message->messageType ?? null) === AIConversationMessage::MESSAGE_SIGNAL && $this->isHostOwnerReplyRow($message);
            $agentEvalRunCounts->addMessage($message, $isHostOwnerReplyRow);
        }
        return $agentEvalRunCounts;
    }

    /**
     * The agent's system prompt of its first model call, rebuilt for the first message that carries a request
     * snapshot (as of that call); null when no turn made a model call.
     *
     * @param AIConversation $conversation
     * @return string|null
     */
    protected function extractSystemPrompt(AIConversation $conversation): ?string
    {
        /** @var AIConversationModelCallsService $aIConversationModelCallsService */
        $aIConversationModelCallsService = DDDService::instance()->getService(AIConversationModelCallsService::class);
        $baseSystemPrompt = $aIConversationModelCallsService->resolveBaseSystemPrompt($conversation);
        foreach (($conversation->messages?->getElements() ?? []) as $message) {
            if (!isset($message->requestSnapshot)) {
                continue;
            }
            return $message->buildModelInputThatProducedThisMessage($conversation, $baseSystemPrompt)->composeSystemContent();
        }
        return null;
    }

    /**
     * Runs the compaction-tree evaluation: the driver keeps a long conversation going, a new topic per turn, while the
     * case's compaction settings make the conversation compact often; the tree is written down after every owner turn,
     * and the judge rates the tree's growth, its recency-graded cover and whether planted facts survive the roll-ups.
     * The driver and the judge read the case's compaction parameters. The conversation is stored when the options store
     * conversations: the run then reads the tree the way a turn does — every snapshot from the whole stored tree — and
     * is judged on the conversation as read back after the last turn. Each step reports one progress line.
     *
     * @param AgentEvalScope $agentEvalScope
     * @param AgentEvalSimCase $agentEvalSimCase a case with `compactionSettings` and `compactionParamsText`
     * @param AgentEvalJudgedRunOptions $agentEvalJudgedRunOptions
     * @return AgentEvalCompactionTreeRunResult
     * @throws AgentEvalUnknownModelNameException when the judge or driver model of the run is unknown to the catalog
     * @throws AgentEvalHarnessModelCallFailedException when a driver call failed
     */
    public function runCompactionTreeEval(AgentEvalScope $agentEvalScope, AgentEvalSimCase $agentEvalSimCase, AgentEvalJudgedRunOptions $agentEvalJudgedRunOptions): AgentEvalCompactionTreeRunResult
    {
        $this->authenticateOwnerOfScope($agentEvalScope);
        $this->refuseUnknownModelNamesOfRun($agentEvalJudgedRunOptions);
        $maxTurns = $agentEvalSimCase->resolveMaxTurns(static::DEFAULT_MAX_TURNS);
        $driverModelName = $agentEvalJudgedRunOptions->resolveDriverModelName($this->getDefaultDriverModelName());
        $judgeModelName = $agentEvalJudgedRunOptions->resolveJudgeModelName($this->getDefaultJudgeModelName());
        $conversation = $this->buildSimConversation($agentEvalScope, $agentEvalJudgedRunOptions);
        $agentEvalSimCase->applyCompactionSettingsTo($conversation);
        $agentEvalSimCase->applyContextRetentionSettingsTo($conversation);
        $agentEvalSimCase->applyRunSettingsTo($conversation);
        $this->persistConversationIfRequested($conversation, $agentEvalScope, $agentEvalJudgedRunOptions);
        $agentEvalJudgedRunOptions->reportProgress(sprintf(
            'eval start — driving up to %d turns (agent=%s, driver=%s, judge=%s)',
            $maxTurns,
            $agentEvalJudgedRunOptions->aiModelName ?? 'default',
            $driverModelName,
            $judgeModelName
        ));
        $ownerMessageText = $agentEvalSimCase->getOpeningPrompt();
        $stopReason = AgentEvalSimRunResult::STOP_REASON_MAX_TURNS;
        $treeSnapshots = [];
        for ($turn = 1; $turn <= $maxTurns; $turn++) {
            $treeSnapshots[$turn] = $this->driveCompactionTreeTurn($conversation, $agentEvalScope, $agentEvalJudgedRunOptions, $ownerMessageText, $turn, $maxTurns);
            $driverReply = $this->askCompactionTreeDriverForNextOwnerMessage($conversation, $agentEvalScope, $agentEvalSimCase, $driverModelName, $turn, $agentEvalJudgedRunOptions);
            $driverStopReason = $this->findStopReasonOfDriverReply($driverReply);
            if ($driverStopReason !== null) {
                $stopReason = $driverStopReason;
                break;
            }
            $ownerMessageText = $driverReply !== '' ? $driverReply : $this->getEmptyCompactionTreeDriverReplyText();
        }
        // a stored run is judged on what was stored: the rows a worker or a child process wrote and the whole tree
        $conversation = $this->reloadIfPersisted($conversation)->getConversation();
        $finalTree = $this->renderTreeSnapshot($conversation);
        $agentEvalJudgedRunOptions->reportProgress('all turns done — calling judge…');
        $judgeStartedAt = microtime(true);
        $agentEvalJudgeVerdict = $this->judgeVerdict(
            $agentEvalScope,
            $judgeModelName,
            $this->buildCompactionTreeVerdictPrompt($conversation, $agentEvalSimCase, $this->buildTreeEvolutionText($treeSnapshots), $finalTree)
        );
        $agentEvalJudgedRunOptions->reportProgress(sprintf('judge done (%.1fs)', microtime(true) - $judgeStartedAt));
        return AgentEvalCompactionTreeRunResult::forTreeRun($agentEvalJudgeVerdict, $stopReason, min($turn, $maxTurns), $treeSnapshots, $finalTree, $conversation);
    }

    /**
     * One owner turn of the compaction-tree run: the owner message, the agent's turn, and the tree as text after it.
     *
     * @param AIConversation $conversation
     * @param AgentEvalScope $agentEvalScope
     * @param AgentEvalJudgedRunOptions $agentEvalJudgedRunOptions
     * @param string $ownerMessageText
     * @param int $turn
     * @param int $maxTurns
     * @return string the tree after the turn
     */
    protected function driveCompactionTreeTurn(
        AIConversation $conversation,
        AgentEvalScope $agentEvalScope,
        AgentEvalJudgedRunOptions $agentEvalJudgedRunOptions,
        string $ownerMessageText,
        int $turn,
        int $maxTurns
    ): string {
        $this->appendOwnerMessage($conversation, $ownerMessageText, $agentEvalJudgedRunOptions);
        $agentStartedAt = microtime(true);
        $agentEvalJudgedRunOptions->reportProgress(sprintf('turn %d/%d: driving agent…', $turn, $maxTurns));
        $this->driveAgent($conversation, $agentEvalScope, $agentEvalJudgedRunOptions);
        $treeSnapshot = $this->renderTreeSnapshot($conversation);
        $agentEvalJudgedRunOptions->reportProgress(sprintf(
            'turn %d: agent done (%.1fs), tree=%d nodes — calling driver…',
            $turn,
            microtime(true) - $agentStartedAt,
            isset($conversation->compactionNodes) ? $conversation->compactionNodes->count() : 0
        ));
        return $treeSnapshot;
    }

    /**
     * The compaction-tree driver's next owner message, trimmed.
     *
     * @param AIConversation $conversation
     * @param AgentEvalScope $agentEvalScope
     * @param AgentEvalSimCase $agentEvalSimCase
     * @param string $driverModelName
     * @param int $turn
     * @param AgentEvalJudgedRunOptions $agentEvalJudgedRunOptions
     * @return string
     */
    protected function askCompactionTreeDriverForNextOwnerMessage(
        AIConversation $conversation,
        AgentEvalScope $agentEvalScope,
        AgentEvalSimCase $agentEvalSimCase,
        string $driverModelName,
        int $turn,
        AgentEvalJudgedRunOptions $agentEvalJudgedRunOptions
    ): string {
        $driverStartedAt = microtime(true);
        $driverPrompt = $this->findPromptByName(AgentAIPromptNames::APP_AI_AGENT_EVALUATION_COMPACTION_TREE_DRIVER);
        $driverPrompt->setParameter('GOAL', $agentEvalSimCase->getGoal());
        $driverPrompt->setParameter('FACTS', $agentEvalSimCase->getOwnerFacts());
        $driverPrompt->setParameter('COMPACTION_PARAMETERS', $agentEvalSimCase->getCompactionParamsText());
        $driverPrompt->setParameter('TRANSCRIPT', $this->getUserVisibleTranscript($conversation));
        $driverReply = trim($this->callModel(
            $agentEvalScope,
            $driverModelName,
            $driverPrompt->getPromptTextWithParametersApplied(),
            AgentAIUsageScope::AI_AGENT_SIMULATE_OWNER
        ));
        $agentEvalJudgedRunOptions->reportProgress(sprintf('turn %d: driver done (%.1fs) → "%s"', $turn, microtime(true) - $driverStartedAt, mb_substr($driverReply, 0, 60)));
        return $driverReply;
    }

    /**
     * The verdict prompt of a compaction-tree run: the compaction parameters, the goal, the rubric (the default rubric
     * when the case has none), the tree after every turn, the final tree, and the trace, set last.
     *
     * @param AIConversation $conversation
     * @param AgentEvalSimCase $agentEvalSimCase
     * @param string $treeEvolutionText {@see buildTreeEvolutionText()}
     * @param string $finalTree
     * @return AIPrompt
     */
    public function buildCompactionTreeVerdictPrompt(AIConversation $conversation, AgentEvalSimCase $agentEvalSimCase, string $treeEvolutionText, string $finalTree): AIPrompt
    {
        $rubric = $agentEvalSimCase->getSuccessRubric();
        $verdictPrompt = $this->findPromptByName(AgentAIPromptNames::APP_AI_AGENT_EVALUATION_COMPACTION_TREE_VERDICT);
        $verdictPrompt->setParameter('COMPACTION_PARAMETERS', $agentEvalSimCase->getCompactionParamsText());
        $verdictPrompt->setParameter('GOAL', $agentEvalSimCase->getGoal());
        $verdictPrompt->setParameter('RUBRIC', $rubric !== '' ? $rubric : $this->getDefaultCompactionTreeRubricText());
        $verdictPrompt->setParameter('TREE_EVOLUTION', $treeEvolutionText);
        $verdictPrompt->setParameter('FINAL_TREE', $finalTree);
        $verdictPrompt->setParameter('TRACE', $this->getFullTraceText($conversation));
        return $verdictPrompt;
    }

    /**
     * The tree after each owner turn, each under its headline, in turn order.
     *
     * @param string[] $treeSnapshots the tree as text by turn number
     * @return string
     */
    protected function buildTreeEvolutionText(array $treeSnapshots): string
    {
        $treeEvolutionText = '';
        foreach ($treeSnapshots as $turn => $treeSnapshot) {
            $treeEvolutionText .= sprintf(static::TREE_SNAPSHOT_HEADLINE, (int)$turn) . "\n" . $treeSnapshot . "\n\n";
        }
        return $treeEvolutionText;
    }

    /**
     * The conversation's compaction tree as text: one line per node (the highest level first, then by covered range)
     * with its level, range, size, kind, summary preview and the facts it preserves verbatim; then the cover the model
     * receives and the rendered cover as the model reads it — what fact survival is judged against. A stored
     * conversation's tree is read whole first ({@see loadStoredCompactionTree()}).
     *
     * @param AIConversation $conversation
     * @return string
     */
    protected function renderTreeSnapshot(AIConversation $conversation): string
    {
        $this->loadStoredCompactionTree($conversation);
        $compactionNodes = isset($conversation->compactionNodes) ? $conversation->compactionNodes->getElements() : [];
        if ($compactionNodes === []) {
            return static::TREE_EMPTY_TEXT;
        }
        usort($compactionNodes, static function (AIConversationCompactionNode $firstNode, AIConversationCompactionNode $secondNode): int {
            $byLevel = ($secondNode->level ?? 0) <=> ($firstNode->level ?? 0);
            return $byLevel !== 0 ? $byLevel : (($firstNode->coveredFromMessageIndex ?? 0) <=> ($secondNode->coveredFromMessageIndex ?? 0));
        });
        $nodeLines = [];
        foreach ($compactionNodes as $compactionNode) {
            $nodeLines[] = $this->buildTreeNodeLine($compactionNode);
        }
        $coverRanges = implode(' ', array_map(
            static fn (AIConversationCompactionNode $coverNode): string => sprintf('L%d[%d-%d]', $coverNode->level ?? 0, $coverNode->coveredFromMessageIndex ?? 0, $coverNode->coveredToMessageIndex ?? 0),
            $conversation->buildCompactionCover()->getCoverNodes()
        ));
        $coveredUpToIndex = $conversation->getCompactionCoveredUpToIndex();
        $renderedCover = $conversation->buildCompactionSummaryText();
        return implode("\n", $nodeLines)
            . "\n" . sprintf(static::TREE_COVER_LINE, $coverRanges !== '' ? $coverRanges : '—', $coveredUpToIndex !== null ? (string)$coveredUpToIndex : '—')
            . ($renderedCover !== null ? "\n\n" . static::TREE_RENDERED_COVER_HEADLINE . "\n" . $renderedCover : '');
    }

    /**
     * Puts the whole stored tree of a stored conversation on it: the nodes in memory are those the turns of this
     * process built, a round the platform ran elsewhere wrote its nodes to the database only. A transient conversation
     * keeps its tree in memory.
     *
     * @param AIConversation $conversation
     * @return void
     */
    protected function loadStoredCompactionTree(AIConversation $conversation): void
    {
        if (!isset($conversation->id)) {
            return;
        }
        /** @var ConversationCompactionNodesService $conversationCompactionNodesService */
        $conversationCompactionNodesService = DDDService::instance()->getService(ConversationCompactionNodesService::class);
        $storedCompactionNodes = $conversationCompactionNodesService->findAllNodesForConversation($conversation);
        if ($storedCompactionNodes !== null) {
            $conversation->compactionNodes = $storedCompactionNodes;
        }
    }

    /**
     * The line of one tree node.
     *
     * @param AIConversationCompactionNode $compactionNode
     * @return string
     */
    protected function buildTreeNodeLine(AIConversationCompactionNode $compactionNode): string
    {
        $preservedIdentifiers = $compactionNode->preservedFacts->identifiers ?? [];
        return sprintf(
            'L%d [%d–%d] %dmsg %s: %s%s',
            $compactionNode->level ?? 0,
            $compactionNode->coveredFromMessageIndex ?? 0,
            $compactionNode->coveredToMessageIndex ?? 0,
            $compactionNode->coveredMessageCount ?? 0,
            ($compactionNode->fromRawMessages ?? false) ? 'leaf' : 'rollup',
            $this->previewText($compactionNode->summaryText ?? '', static::TREE_NODE_PREVIEW_MAX_LENGTH),
            $preservedIdentifiers !== [] ? ' {preserved: ' . implode(' | ', $preservedIdentifiers) . '}' : ''
        );
    }

    /**
     * The text on one line, cut to `$maxLength` characters with an ellipsis.
     *
     * @param string $text
     * @param int $maxLength
     * @return string
     */
    protected function previewText(string $text, int $maxLength): string
    {
        $oneLineText = (string)preg_replace('/\s+/', ' ', trim($text));
        return mb_strlen($oneLineText) > $maxLength ? mb_substr($oneLineText, 0, $maxLength - 1) . '…' : $oneLineText;
    }

    /**
     * Applies the application's own fixtures of the case to the new conversation (a case key the application reads
     * with {@see AgentEvalSimCase::getApplicationValue()}), after the run identity is stored and before the first
     * owner turn.
     *
     * The default: nothing.
     *
     * @param AIConversation $conversation
     * @param AgentEvalSimCase $agentEvalSimCase
     * @param AgentEvalScope $agentEvalScope
     * @return void
     */
    protected function applyCaseFixtures(AIConversation $conversation, AgentEvalSimCase $agentEvalSimCase, AgentEvalScope $agentEvalScope): void
    {
    }

    /**
     * The section of the trace that shows what the application really stored after the run (read fresh, past every
     * cache), headline included; the judge holds the chat's claims against it. Null = no such section.
     *
     * The default: null.
     *
     * @param AIConversation $conversation the judged conversation
     * @return string|null
     */
    protected function buildHostEndStateTextForJudge(AIConversation $conversation): ?string
    {
        return null;
    }

    /**
     * The text the owner reads in place of a widget block of an assistant text, when the block alone does not show
     * what the owner sees (a widget written with ids only, whose names the owner sees). Asked per block of every
     * assistant text of the driver's transcript; the block's line ending is kept. Null = the block stays as written.
     *
     * The default: null.
     *
     * @param AIConversation $conversation
     * @param WidgetBlock $widgetBlock
     * @return string|null
     */
    protected function buildTextOfHostWidgetShownToOwner(AIConversation $conversation, WidgetBlock $widgetBlock): ?string
    {
        return null;
    }

    /**
     * Sets the application's parts of the verdict prompt: `HOST_RULES` (further lines of the minor list) and
     * `HOST_SECTIONS` (further sections after the trace). Both are set to empty before this is asked.
     *
     * The default: nothing.
     *
     * @param AIPrompt $verdictPrompt
     * @param AIConversation $conversation the judged conversation
     * @param AgentEvalSimCase $agentEvalSimCase
     * @return void
     */
    protected function applyHostSectionsToVerdictPrompt(AIPrompt $verdictPrompt, AIConversation $conversation, AgentEvalSimCase $agentEvalSimCase): void
    {
    }

    /**
     * Whether a signal row of the application stands for an owner reply in the counts (a widget whose submit replaces
     * a typed answer).
     *
     * The default: false.
     *
     * @param AIConversationMessage $message a SIGNAL row
     * @return bool
     */
    protected function isHostOwnerReplyRow(AIConversationMessage $message): bool
    {
        return false;
    }

    /**
     * The owner message sent when the driver answered nothing.
     *
     * @return string
     */
    protected function getEmptyDriverReplyText(): string
    {
        return 'Please go on.';
    }

    /**
     * The owner message of the compaction-tree run sent when its driver answered nothing.
     *
     * @return string
     */
    protected function getEmptyCompactionTreeDriverReplyText(): string
    {
        return 'Tell me more about that.';
    }

    /**
     * The rubric of a compaction-tree case that names none.
     *
     * @return string
     */
    protected function getDefaultCompactionTreeRubricText(): string
    {
        return 'The tree is GOOD when all criteria below hold.';
    }
}
