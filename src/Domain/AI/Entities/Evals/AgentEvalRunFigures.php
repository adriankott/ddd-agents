<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Metrics\AIConversationMetrics;
use DDD\Domain\Base\Entities\ValueObject;
use Throwable;

/**
 * The PERFORMANCE and the SPEED of one evaluated run, computed from the driven conversation in memory (no I/O): owner
 * turns, model calls, tool calls, the seconds the agent worked per owner turn, tokens, cost, what the decision layer
 * added, and per owner turn the seconds to the first model output and to the first text the owner sees.
 *
 * Every figure is computed here; none is read from {@see AIConversationMetrics}. The metrics object reads the STORED
 * rows of the ROOT conversation only (a transient run has none), while these figures read the in-memory conversation,
 * most of them over the whole tree including the delegated conversations. Where a metrics field carries a similar
 * name, the figure's docblock says how the two differ.
 *
 * Owner turn: a row of the ROOT conversation for which {@see AIConversationMessages::isTurnInitiatorMessage()} holds —
 * a user-role row of any type (the owner's, a legacy row or an orchestrator's task), or a signal that starts a turn (a
 * work order such as a purpose kickoff, or a form submit). A turn runs from its initiator to the last row before the
 * next initiator.
 *
 * The evaluation counts a MODEL CALL in three ways, each named where it is used:
 * - over the TREE: every row of the conversation and its delegated conversations with input tokens above 0
 *   ({@see $modelCalls}, {@see $firstModelOutputSecondsPerOwnerTurn} on the root rows);
 * - over the CURRENT TURN of the root: the rows from the newest turn initiator on with input tokens above 0
 *   ({@see AgentEvalEvictionTurnFigures::$modelCalls});
 * - over the ROOT rows: every row that carries a request snapshot ({@see \DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanSize}).
 *
 * {@see toArray()} gives the figures with the keys a case row stores under `performance`.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalRunFigures extends ValueObject
{
    /**
     * @var int The owner turns of the ROOT conversation (rows for which {@see AIConversationMessages::isTurnInitiatorMessage()}
     * holds). The metrics field `ownerTurns` counts only stored owner TEXT rows and owner-authored signals: a purpose
     * kickoff counts here and not there, a widget click (which starts no turn) counts there and not here.
     */
    public int $ownerTurns = 0;

    /**
     * @var int The model calls of the TREE: rows of the conversation and its delegated conversations with input tokens
     * above 0. The metrics field `modelCalls` counts stored ROOT rows with a request snapshot: a delegated
     * conversation's calls count here only.
     */
    public int $modelCalls = 0;

    /** @var float {@see $modelCalls} divided by {@see $ownerTurns}, two decimals; 0 without an owner turn. */
    public float $modelCallsPerOwnerTurn = 0.0;

    /**
     * @var int The tool-call rows of the TREE, `load_skills` and failed calls included. The metrics field `toolCalls`
     * counts the ROOT's tool-call rows only: a delegated conversation's call counts here only.
     */
    public int $toolCalls = 0;

    /**
     * @var int The tool-call rows of the TREE without the `load_skills` and `findWidgets` meta tools. The metrics
     * subtract only `delegate` and `check_delegations` from their `toolCalls`: one `load_skills` call is 0 here and 1
     * there.
     */
    public int $workToolCalls = 0;

    /**
     * @var float The sum over the root's owner turns of (the newest row time of the turn − the time of its initiator),
     * one decimal. The metrics field `agentSeconds` sums only the time between rows the metrics count as the agent's:
     * an owner's widget click 60 s after the assistant's text adds those 60 s here (the click starts no turn) and to
     * the owner's time there.
     */
    public float $agentSeconds = 0.0;

    /**
     * @var float[] The seconds of every owner turn in order (see {@see $agentSeconds}), one decimal. The metrics carry
     * percentiles over the turns' agent time (`p50TurnAgentSeconds`, `p90TurnAgentSeconds`), not one value per turn.
     */
    public array $secondsPerOwnerTurn = [];

    /** @var array<int, float> The seconds of every owner turn keyed by the message index of its initiator row. */
    public array $secondsByInitiatorIndex = [];

    /**
     * @var float {@see $agentSeconds} divided by {@see $ownerTurns}, one decimal; 0 without an owner turn. The metrics'
     * average turn agent time divides the metrics' agent time by their execution turns.
     */
    public float $avgSecondsPerOwnerTurn = 0.0;

    /** @var float The longest owner turn of {@see $secondsPerOwnerTurn}; 0 without an owner turn. */
    public float $maxSecondsPerOwnerTurn = 0.0;

    /** @var int The input tokens of the TREE's rows. */
    public int $inputTokens = 0;

    /** @var int The cached input tokens of the TREE's rows. */
    public int $cachedInputTokens = 0;

    /** @var int The output tokens of the TREE's rows. */
    public int $outputTokens = 0;

    /** @var int {@see $inputTokens} plus {@see $outputTokens}. */
    public int $totalTokens = 0;

    /** @var float The TREE's model cost plus what its tool calls booked outside the model call, in USD, six decimals. */
    public float $costUsd = 0.0;

    /** @var int The decision records on the root's working state. */
    public int $decisionRecords = 0;

    /**
     * @var int The milliseconds the decision model worked: the records of one request share its elapsed time, so each
     * distinct (owner message index, elapsed milliseconds) pair counts once.
     */
    public int $decisionModelMs = 0;

    /** @var string[] The effective reasoning effort the reasoning-effort site decided, one per owner message, in owner message order. */
    public array $reasoningEffortsByOwnerTurn = [];

    /**
     * @var array<int, float|null> Per owner turn the seconds from its initiator to the first ROOT row of the turn with
     * input tokens above 0 (the row is written when the model's response arrives, before its tools run); null for a
     * turn without one. The metrics field `modelSeconds` measures the time spent in model calls, a different quantity.
     */
    public array $firstModelOutputSecondsPerOwnerTurn = [];

    /**
     * @var array<int, float|null> Per owner turn the seconds from its initiator to the first ROOT row the owner sees as
     * the assistant's text: an assistant TEXT or TOOL_MESSAGE row with visible text once the pre-call marker is
     * stripped; null for a turn without one. The metrics field `greetingLatencySeconds` is one value per conversation,
     * up to the first assistant TEXT row: a turn whose first visible row is a widget has a value here at that row,
     * while the metrics wait for the next TEXT.
     */
    public array $firstAnswerSecondsPerOwnerTurn = [];

    /**
     * @var int[] Per owner turn the input tokens not served from the provider's cache (input − cached) of the TREE's
     * rows, a delegated conversation's rows counted in the owner turn that spawned it.
     */
    public array $uncachedInputTokensPerOwnerTurn = [];

    /** @var int The uncached input tokens of every TREE row, rows before the first owner turn included. */
    public int $uncachedInputTokens = 0;

    /**
     * @var float|null The seconds from the earliest to the latest row time of the TREE, one decimal; null when no row
     * carries a time. The metrics field `totalWallSeconds` reads the stored root rows: a delegated conversation's row
     * written after the root's last row makes this value larger.
     */
    public ?float $conversationWallSeconds = null;

    /** The figures of the driven conversation: its root rows and the rows of its delegated conversations. */
    public static function fromConversation(AIConversation $conversation): static
    {
        $agentEvalRunFigures = static::newInstance();
        $treeMessages = $conversation->getAllMessagesIncludingDelegations();
        $agentEvalRunFigures->countTreeRows($treeMessages);
        $agentEvalRunFigures->measureOwnerTurnSeconds($conversation->messages);
        $agentEvalRunFigures->countDecisionLayer($conversation->getAgentWorkingState());
        $agentEvalRunFigures->measureOwnerTurnSpeed($conversation->messages, $treeMessages);
        return $agentEvalRunFigures;
    }

    /**
     * The figures with the keys a case row stores under `performance`, in this order.
     *
     * @return array{ownerTurns: int, modelCalls: int, modelCallsPerOwnerTurn: float, toolCalls: int, workToolCalls: int, agentSeconds: float, secondsPerOwnerTurn: float[], secondsByInitiatorIndex: array<int, float>, avgSecondsPerOwnerTurn: float, maxSecondsPerOwnerTurn: float, inputTokens: int, cachedInputTokens: int, outputTokens: int, totalTokens: int, costUsd: float, decisionRecords: int, decisionModelMs: int, reasoningEffortsByOwnerTurn: string[], firstModelOutputSecondsPerOwnerTurn: array<int, float|null>, firstAnswerSecondsPerOwnerTurn: array<int, float|null>, uncachedInputTokensPerOwnerTurn: int[], uncachedInputTokens: int, conversationWallSeconds: float|null}
     */
    public function toArray(): array
    {
        return [
            'ownerTurns' => $this->ownerTurns,
            'modelCalls' => $this->modelCalls,
            'modelCallsPerOwnerTurn' => $this->modelCallsPerOwnerTurn,
            'toolCalls' => $this->toolCalls,
            'workToolCalls' => $this->workToolCalls,
            'agentSeconds' => $this->agentSeconds,
            'secondsPerOwnerTurn' => $this->secondsPerOwnerTurn,
            'secondsByInitiatorIndex' => $this->secondsByInitiatorIndex,
            'avgSecondsPerOwnerTurn' => $this->avgSecondsPerOwnerTurn,
            'maxSecondsPerOwnerTurn' => $this->maxSecondsPerOwnerTurn,
            'inputTokens' => $this->inputTokens,
            'cachedInputTokens' => $this->cachedInputTokens,
            'outputTokens' => $this->outputTokens,
            'totalTokens' => $this->totalTokens,
            'costUsd' => $this->costUsd,
            'decisionRecords' => $this->decisionRecords,
            'decisionModelMs' => $this->decisionModelMs,
            'reasoningEffortsByOwnerTurn' => $this->reasoningEffortsByOwnerTurn,
            'firstModelOutputSecondsPerOwnerTurn' => $this->firstModelOutputSecondsPerOwnerTurn,
            'firstAnswerSecondsPerOwnerTurn' => $this->firstAnswerSecondsPerOwnerTurn,
            'uncachedInputTokensPerOwnerTurn' => $this->uncachedInputTokensPerOwnerTurn,
            'uncachedInputTokens' => $this->uncachedInputTokens,
            'conversationWallSeconds' => $this->conversationWallSeconds,
        ];
    }

    /** The tree's counts and sums: model calls, tool calls, tokens and cost. */
    protected function countTreeRows(AIConversationMessages $treeMessages): void
    {
        foreach ($treeMessages->getElements() as $message) {
            if (($message->inputTokens ?? 0) > 0) {
                $this->modelCalls++;
            }
            $this->cachedInputTokens += (int)($message->cachedInputTokens ?? 0);
        }
        $this->toolCalls = $treeMessages->buildToolCallHistory()->countToolCalls();
        $this->workToolCalls = $treeMessages->buildToolCallHistory()->countWorkToolCalls();
        $this->inputTokens = $treeMessages->getTotalInputTokens();
        $this->outputTokens = $treeMessages->getTotalOutputTokens();
        $this->totalTokens = $treeMessages->getTotalTokens();
        $this->costUsd = round($treeMessages->getTotalCostUsd(), 6);
    }

    /**
     * The owner turns of the ROOT and the seconds of each: from the initiator to the newest row time before the next
     * initiator. Needs {@see $modelCalls} for the calls per owner turn.
     */
    protected function measureOwnerTurnSeconds(AIConversationMessages $rootMessages): void
    {
        $turnStartedAt = null;
        $turnEndedAt = null;
        $turnInitiatorIndex = null;
        foreach ($rootMessages->getElements() as $message) {
            $createdAt = $this->getMessageCreatedAtSeconds($message);
            if (AIConversationMessages::isTurnInitiatorMessage($message)) {
                if ($this->ownerTurns > 0) {
                    $this->recordOwnerTurnSeconds($turnStartedAt, $turnEndedAt, (int)$turnInitiatorIndex);
                }
                $this->ownerTurns++;
                $turnStartedAt = $createdAt;
                $turnEndedAt = $createdAt;
                $turnInitiatorIndex = (int)($message->indexInConversation ?? -1);
                continue;
            }
            if ($createdAt !== null && ($turnEndedAt === null || $createdAt > $turnEndedAt)) {
                $turnEndedAt = $createdAt;
            }
        }
        if ($this->ownerTurns > 0) {
            $this->recordOwnerTurnSeconds($turnStartedAt, $turnEndedAt, (int)$turnInitiatorIndex);
        }
        $this->agentSeconds = round(array_sum($this->secondsPerOwnerTurn), 1);
        $this->modelCallsPerOwnerTurn = $this->ownerTurns > 0 ? round($this->modelCalls / $this->ownerTurns, 2) : 0.0;
        $this->avgSecondsPerOwnerTurn = $this->ownerTurns > 0 ? round($this->agentSeconds / $this->ownerTurns, 1) : 0.0;
        $this->maxSecondsPerOwnerTurn = $this->secondsPerOwnerTurn !== [] ? (float)max($this->secondsPerOwnerTurn) : 0.0;
    }

    /** One finished owner turn: its seconds (0 when a bound carries no time), in order and by its initiator's index. */
    protected function recordOwnerTurnSeconds(?float $turnStartedAt, ?float $turnEndedAt, int $turnInitiatorIndex): void
    {
        $turnSeconds = $turnStartedAt !== null && $turnEndedAt !== null ? round(max(0.0, $turnEndedAt - $turnStartedAt), 1) : 0.0;
        $this->secondsPerOwnerTurn[] = $turnSeconds;
        $this->secondsByInitiatorIndex[$turnInitiatorIndex] = $turnSeconds;
    }

    /** What the decision layer added: its records, the milliseconds of its distinct requests, the reasoning efforts. */
    protected function countDecisionLayer(?AgentWorkingState $agentWorkingState): void
    {
        $decisionRecords = $agentWorkingState->decisions ?? [];
        $elapsedMsByRequest = [];
        $reasoningEffortsByOwnerMessageIndex = [];
        foreach ($decisionRecords as $decisionRecord) {
            if (($decisionRecord->elapsedMs ?? null) !== null) {
                $elapsedMsByRequest[(int)($decisionRecord->ownerMessageIndex ?? -1) . ':' . (int)$decisionRecord->elapsedMs] = (int)$decisionRecord->elapsedMs;
            }
            if (($decisionRecord->site ?? null) === AgentDecisionModelSettings::SITE_REASONING_EFFORT
                && is_array($decisionRecord->outcome)
                && isset($decisionRecord->outcome['effectiveEffort'])
            ) {
                $reasoningEffortsByOwnerMessageIndex[(int)($decisionRecord->ownerMessageIndex ?? -1)] = (string)$decisionRecord->outcome['effectiveEffort'];
            }
        }
        ksort($reasoningEffortsByOwnerMessageIndex);
        $this->decisionRecords = count($decisionRecords);
        $this->decisionModelMs = (int)array_sum($elapsedMsByRequest);
        $this->reasoningEffortsByOwnerTurn = array_values($reasoningEffortsByOwnerMessageIndex);
    }

    /**
     * The speed figures: per owner turn of the root the seconds to the first model output and to the first visible
     * answer, the uncached input tokens per owner turn and in total, and the wall time of the tree. The tree lists a
     * delegated conversation's rows right after the root row that spawned it, so they fall into that row's owner turn.
     */
    protected function measureOwnerTurnSpeed(AIConversationMessages $rootMessages, AIConversationMessages $treeMessages): void
    {
        $rootMessageObjectIds = [];
        foreach ($rootMessages->getElements() as $rootMessage) {
            $rootMessageObjectIds[spl_object_id($rootMessage)] = true;
        }
        $turnPosition = -1;
        $turnStartedAt = null;
        $firstRowCreatedAt = null;
        $lastRowCreatedAt = null;
        foreach ($treeMessages->getElements() as $message) {
            $createdAt = $this->getMessageCreatedAtSeconds($message);
            if ($createdAt !== null) {
                $firstRowCreatedAt = $firstRowCreatedAt === null ? $createdAt : min($firstRowCreatedAt, $createdAt);
                $lastRowCreatedAt = $lastRowCreatedAt === null ? $createdAt : max($lastRowCreatedAt, $createdAt);
            }
            $isRootMessage = isset($rootMessageObjectIds[spl_object_id($message)]);
            if ($isRootMessage && AIConversationMessages::isTurnInitiatorMessage($message)) {
                $turnPosition++;
                $turnStartedAt = $createdAt;
                $this->firstModelOutputSecondsPerOwnerTurn[$turnPosition] = null;
                $this->firstAnswerSecondsPerOwnerTurn[$turnPosition] = null;
                $this->uncachedInputTokensPerOwnerTurn[$turnPosition] = 0;
                continue;
            }
            $uncachedInputTokensOfMessage = $this->getUncachedInputTokensOfMessage($message);
            $this->uncachedInputTokens += $uncachedInputTokensOfMessage;
            if ($turnPosition < 0) {
                continue; // a row before the first owner turn (a purposed kickoff) belongs to no owner turn
            }
            $this->uncachedInputTokensPerOwnerTurn[$turnPosition] += $uncachedInputTokensOfMessage;
            if (!$isRootMessage || $turnStartedAt === null || $createdAt === null) {
                continue;
            }
            $secondsSinceTurnStart = round(max(0.0, $createdAt - $turnStartedAt), 1);
            if ($this->firstModelOutputSecondsPerOwnerTurn[$turnPosition] === null && ($message->inputTokens ?? 0) > 0) {
                $this->firstModelOutputSecondsPerOwnerTurn[$turnPosition] = $secondsSinceTurnStart;
            }
            if ($this->firstAnswerSecondsPerOwnerTurn[$turnPosition] === null && $this->isAssistantTextShownToTheOwner($message)) {
                $this->firstAnswerSecondsPerOwnerTurn[$turnPosition] = $secondsSinceTurnStart;
            }
        }
        $this->conversationWallSeconds = $firstRowCreatedAt !== null && $lastRowCreatedAt !== null
            ? round($lastRowCreatedAt - $firstRowCreatedAt, 1)
            : null;
    }

    /** The input tokens of the row's model call the provider did NOT serve from its cache (0 on a row without a call). */
    protected function getUncachedInputTokensOfMessage(AIConversationMessage $message): int
    {
        return max(0, (int)($message->inputTokens ?? 0) - (int)($message->cachedInputTokens ?? 0));
    }

    /**
     * Whether the owner sees this row as the assistant's text: an assistant TEXT row, or a row a tool wrote into the
     * chat (TOOL_MESSAGE), with visible text once the pre-call marker is stripped.
     */
    protected function isAssistantTextShownToTheOwner(AIConversationMessage $message): bool
    {
        $messageType = $message->messageType ?? null;
        if (($message->role ?? null) !== AIConversationMessage::ROLE_ASSISTANT
            || ($messageType !== AIConversationMessage::MESSAGE_TEXT && $messageType !== AIConversationMessage::MESSAGE_TOOL_MESSAGE)
        ) {
            return false;
        }
        return trim(AIConversationMessage::stripPreCallMessageMarker((string)($message->message ?? ''))) !== '';
    }

    /** The row's creation instant as seconds with fractions; null when the row carries none. */
    protected function getMessageCreatedAtSeconds(AIConversationMessage $message): ?float
    {
        try {
            $createdTime = $message->getChangeHistory()->createdTime ?? null;
        } catch (Throwable) {
            return null;
        }
        return $createdTime === null ? null : (float)$createdTime->format('U.u');
    }
}
