<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\RunReports;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanSize;
use DDD\Domain\AI\Entities\Conversations\Metrics\AIConversationMetrics;
use DDD\Domain\AI\Entities\Conversations\Metrics\AIConversationMetricsTimeIntervals;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The standard per-run report of ONE conversation: wall seconds, owner turns, model and tool calls, the time split with
 * the parallel gain, refusals and identical repeats, the slowest tools, the agent model's cost and the decision model's
 * cost, the skill-variants timeline and the plan size.
 *
 * {@see buildFromRows()} is pure over the conversation's message rows (index order, the arrays
 * `AIConversationMetricsService::findMessageRowsForConversation()` returns, each with its plan snapshot under
 * `taskPlanSnapshot` when it stamped one). The time split comes from {@see AIConversationMetrics}, kept on
 * {@see $metrics}. The decision model's cost is not on the rows; the report service reads it and sets it
 * ({@see \DDD\Domain\AI\Services\Conversations\RunReports\AIConversationRunReportService}).
 *
 * @phpstan-consistent-constructor
 */
class AIConversationRunReport extends ValueObject
{
    /** @var int The tools the slowest-tools list keeps per run */
    public const int SLOWEST_TOOLS_SHOWN = 6;

    /** @var string The tool whose calls count as plan ticks */
    public const string PLAN_TICK_TOOL_NAME = 'set_task_status';

    /** @var string The tool whose calls count as skill loads */
    public const string SKILL_LOAD_TOOL_NAME = 'load_skills';

    /** @var int The conversation the report is of; 0 when the report was built from rows alone */
    public int $aiConversationId = 0;

    /** @var float The active wall seconds ({@see AIConversationMetrics::$activeWallSeconds}) */
    public float $wallSeconds = 0.0;

    /** @var int Owner turns ({@see AIConversationMetrics::$ownerTurns}) */
    public int $ownerTurns = 0;

    /** @var int Execution turns: owner rows and machine work signals ({@see AIConversationMetrics::$executionTurns}) */
    public int $executionTurns = 0;

    /** @var int Rows with a request snapshot ({@see AIConversationMetrics::$modelCalls}) */
    public int $modelCalls = 0;

    /** @var int Tool-call rows */
    public int $toolCalls = 0;

    /** @var int Tool calls the platform refused or whose execution failed */
    public int $refusedToolCalls = 0;

    /** @var int Tool calls with the same tool and arguments as an earlier call */
    public int $identicalRepeats = 0;

    /** @var float Seconds the platform worked ({@see AIConversationMetrics::$agentSeconds}) */
    public float $agentSeconds = 0.0;

    /** @var float Agent seconds per execution turn */
    public float $agentSecondsPerTurn = 0.0;

    /** @var float Seconds of the model calls (egress) */
    public float $modelSeconds = 0.0;

    /** @var float Agent seconds left after model, tool wall and delegation wait; negative = a stamp is missing */
    public float $platformOverheadSeconds = 0.0;

    /** @var float The agent model's cost: the rows' cost summed */
    public float $modelCostInUSD = 0.0;

    /** @var float The decision model's cost, read from a usage log */
    public float $decisionModelCostInUSD = 0.0;

    /** @var int The decision model's calls, read from a usage log */
    public int $decisionModelCalls = 0;

    /** @var string|null The class of the exception the read of the decision model's cost ended in; null when it worked */
    public ?string $decisionModelCostReadFailure = null;

    /** @var float Blocking tool seconds summed ({@see AIConversationMetrics::$toolSeconds}) */
    public float $toolSeconds = 0.0;

    /** @var float Tool seconds with overlapping executions counted once ({@see AIConversationMetrics::$toolWallSeconds}) */
    public float $toolWallSeconds = 0.0;

    /** @var float Seconds the owner (or an evaluation's simulated owner) read and typed */
    public float $ownerSeconds = 0.0;

    /** @var int Calls of {@see self::PLAN_TICK_TOOL_NAME} */
    public int $planTicks = 0;

    /** @var int Calls of {@see self::SKILL_LOAD_TOOL_NAME} */
    public int $skillLoads = 0;

    /** @var int Tool calls that do not block the turn ({@see AIConversationMetrics::$nonBlockingToolCalls}) */
    public int $nonBlockingToolCalls = 0;

    /** @var float Seconds of the non-blocking tool calls summed */
    public float $nonBlockingToolSeconds = 0.0;

    /** @var int Input tokens of the model calls summed (cached and written ones included) */
    public int $inputTokens = 0;

    /** @var int Input tokens the model calls read from the prompt cache */
    public int $cachedInputTokens = 0;

    /** @var int Input tokens the model calls wrote to the prompt cache */
    public int $cacheWriteTokens = 0;

    /** @var int Output tokens of the model calls summed (reasoning included) */
    public int $outputTokens = 0;

    /** @var int Reasoning (thinking) tokens the provider reported for the model calls whose snapshot stored a count */
    public int $reasoningTokens = 0;

    /** @var int Thinking blocks the provider dropped from the requests of the model calls (the Messages API) */
    public int $droppedThinkingBlocks = 0;

    /** @var AIConversationRunReportToolTimes The slowest tools, at most {@see self::SLOWEST_TOOLS_SHOWN}, slowest first */
    public AIConversationRunReportToolTimes $slowestTools;

    /** @var string The last variants vector as `dimension=token` pairs, evidence stripped; empty without variants */
    public string $variantsFinal = '';

    /** @var int How often the vector (evidence stripped) changed between two rows that carry one */
    public int $variantTransitions = 0;

    /** @var AIConversationRunReportVariantsTimeline The variants timeline and its initial → final delta */
    public AIConversationRunReportVariantsTimeline $variantsTimeline;

    /** @var TaskPlanSize|null The plan size; null when no row carries a plan snapshot */
    public ?TaskPlanSize $taskPlanSize = null;

    /** @var string|null The class of the exception the read of the plan snapshots ended in; null when it worked */
    public ?string $taskPlanSnapshotReadFailure = null;

    /** @var AIConversationMetrics The run metrics the time split comes from */
    public AIConversationMetrics $metrics;

    public function __construct()
    {
        parent::__construct();
        $this->slowestTools = AIConversationRunReportToolTimes::newInstance();
        $this->variantsTimeline = AIConversationRunReportVariantsTimeline::newInstance();
    }

    /** The key of the report of a conversation in its set. */
    public static function uniqueKeyForConversationId(int $aiConversationId): string
    {
        return self::class . '_' . $aiConversationId;
    }

    /** A report of a conversation is keyed by the conversation; one built from rows alone keeps the key of its object. */
    public function uniqueKey(): string
    {
        return $this->aiConversationId > 0 ? static::uniqueKeyForConversationId($this->aiConversationId) : parent::uniqueKey();
    }

    /**
     * Takes the decision model's cost of the run.
     *
     * @param AIConversationRunReportModelCost $decisionModelCost
     * @return void
     */
    public function applyDecisionModelCost(AIConversationRunReportModelCost $decisionModelCost): void
    {
        $this->decisionModelCostInUSD = $decisionModelCost->costInUSD;
        $this->decisionModelCalls = $decisionModelCost->calls;
        $this->decisionModelCostReadFailure = $decisionModelCost->readFailure;
    }

    /**
     * Why the decision model's cost of the run was not read: `decision cost not read: <exception class>` when the read
     * of the usage log failed; null when it worked or the run has no usage log to read.
     */
    public function getDecisionCostNotReadNote(): ?string
    {
        return $this->decisionModelCostReadFailure !== null ? 'decision cost not read: ' . $this->decisionModelCostReadFailure : null;
    }

    /**
     * Why the plan size of the run was not measured: `plan size not measured: <exception class>` when the read of the
     * plan snapshots failed; null when it worked.
     */
    public function getPlanSizeNotMeasuredNote(): ?string
    {
        return $this->taskPlanSnapshotReadFailure !== null ? 'plan size not measured: ' . $this->taskPlanSnapshotReadFailure : null;
    }

    /**
     * The report of the message rows; null when the rows give no metrics (no rows). A failed read of the rows' plan
     * snapshots is carried over ({@see getPlanSizeNotMeasuredNote()}).
     *
     * @param AIConversationRunReportMessageRows $rows the message rows in index order
     * @param AIConversationMetricsTimeIntervals|null $delegationBatchIntervals the delegation batches the run forked
     * @param float $childModelSeconds the egress seconds of the delegated conversations' model calls
     * @param string $skillSectionIdPrefixToDrop a prefix dropped from the skill section ids of the variants timeline
     * @return static|null
     */
    public static function buildFromRows(
        AIConversationRunReportMessageRows $rows,
        ?AIConversationMetricsTimeIntervals $delegationBatchIntervals = null,
        float $childModelSeconds = 0.0,
        string $skillSectionIdPrefixToDrop = ''
    ): ?static {
        $metrics = AIConversationMetrics::buildFromRows($rows->toArrays(), $delegationBatchIntervals, $childModelSeconds);
        if ($metrics === null) {
            return null;
        }
        $runReport = static::newInstance();
        $runReport->applyMetrics($metrics);
        $runReport->applyToolCallRows($rows->buildToolCallRows(), $metrics);
        $runReport->modelCostInUSD = $rows->sumCostInUSD();
        foreach ($rows->getElements() as $row) {
            $runReport->inputTokens += $row->getInputTokens();
            $runReport->cachedInputTokens += $row->getCachedInputTokens();
            $runReport->cacheWriteTokens += $row->getCacheWriteTokens();
            $runReport->outputTokens += $row->getOutputTokens();
            $runReport->reasoningTokens += $row->getReasoningTokens();
            $runReport->droppedThinkingBlocks += $row->countDroppedThinkingBlocks();
        }
        $runReport->applyFinalVariants($rows);
        $runReport->variantsTimeline = AIConversationRunReportVariantsTimeline::buildFromRows($rows, $skillSectionIdPrefixToDrop);
        $runReport->taskPlanSize = static::measureTaskPlanSize($rows);
        $runReport->taskPlanSnapshotReadFailure = $rows->taskPlanSnapshotReadFailure;
        return $runReport;
    }

    /**
     * The plan size of a run over its rows in index order: a row is a model call when it carries a request snapshot
     * (`snap`), and moves the plan in force when it carries a plan snapshot. Null when no row carries a plan.
     */
    public static function measureTaskPlanSize(AIConversationRunReportMessageRows $rows): ?TaskPlanSize
    {
        $taskPlanSize = TaskPlanSize::newInstance();
        foreach ($rows->getElements() as $row) {
            $taskPlanSize->recordMessage($row->isModelCall(), $row->taskPlanSnapshot?->buildTaskPlan());
        }
        return $taskPlanSize->finish() ? $taskPlanSize : null;
    }

    /**
     * Takes the time split and the counts the metrics computed.
     *
     * @param AIConversationMetrics $metrics
     * @return void
     */
    protected function applyMetrics(AIConversationMetrics $metrics): void
    {
        $this->metrics = $metrics;
        $this->wallSeconds = $metrics->activeWallSeconds;
        $this->ownerTurns = $metrics->ownerTurns;
        $this->executionTurns = $metrics->executionTurns;
        $this->modelCalls = $metrics->modelCalls;
        $this->agentSeconds = $metrics->agentSeconds;
        $this->agentSecondsPerTurn = $metrics->getAverageTurnAgentSeconds();
        $this->modelSeconds = $metrics->modelSeconds;
        $this->platformOverheadSeconds = $metrics->platformOverheadSeconds;
        $this->toolSeconds = $metrics->toolSeconds;
        $this->toolWallSeconds = $metrics->toolWallSeconds;
        $this->ownerSeconds = $metrics->ownerSeconds;
        $this->nonBlockingToolCalls = $metrics->nonBlockingToolCalls;
    }

    /**
     * Counts the tool calls: refused or failed calls (the metrics' failure test), identical repeats (same tool, same
     * arguments), the seconds per tool, the non-blocking seconds, plan ticks and skill loads; keeps the slowest tools.
     *
     * @param AIConversationRunReportMessageRows $toolCallRows
     * @param AIConversationMetrics $metrics the metrics object, whose class decides failures and non-blocking tools
     * @return void
     */
    protected function applyToolCallRows(AIConversationRunReportMessageRows $toolCallRows, AIConversationMetrics $metrics): void
    {
        $this->toolCalls = $toolCallRows->count();
        $seenCallSignatures = [];
        $toolTimes = AIConversationRunReportToolTimes::newInstance();
        foreach ($toolCallRows->getElements() as $toolCallRow) {
            $toolName = $toolCallRow->getToolName();
            $durationSeconds = $toolCallRow->getDurationSeconds();
            if ($toolCallRow->isFailedToolCallOf($metrics)) {
                $this->refusedToolCalls++;
            }
            $callSignature = $toolName . '|' . $toolCallRow->getArgumentsText();
            if (isset($seenCallSignatures[$callSignature])) {
                $this->identicalRepeats++;
            }
            $seenCallSignatures[$callSignature] = true;
            $toolTimes->addCallOfTool($toolName, $durationSeconds);
            if (in_array($toolName, $metrics::NON_BLOCKING_TOOL_NAMES, true)) {
                $this->nonBlockingToolSeconds += $durationSeconds;
            }
            if ($toolName === static::PLAN_TICK_TOOL_NAME) {
                $this->planTicks++;
            }
            if ($toolName === static::SKILL_LOAD_TOOL_NAME) {
                $this->skillLoads++;
            }
        }
        $toolTimes->sortBySecondsDescending();
        $this->slowestTools = $toolTimes->getElementsSlice(0, static::SLOWEST_TOOLS_SHOWN);
    }

    /**
     * The final variants vector (evidence stripped) and how often it changed between two rows that carry one.
     *
     * @param AIConversationRunReportMessageRows $rows
     * @return void
     */
    protected function applyFinalVariants(AIConversationRunReportMessageRows $rows): void
    {
        $previousVariantsJson = null;
        foreach ($rows->getElements() as $row) {
            $variantsJson = $row->findVariantsJson();
            if ($variantsJson === null) {
                continue;
            }
            // a moved evidence index is no transition
            $variantsJson = AIConversationRunReportVariantsTimeline::stripEvidenceIndex($variantsJson);
            if ($previousVariantsJson !== null && $variantsJson !== $previousVariantsJson) {
                $this->variantTransitions++;
            }
            $previousVariantsJson = $variantsJson;
        }
        if ($previousVariantsJson === null) {
            return;
        }
        $decodedVariants = json_decode($previousVariantsJson, true);
        if (is_array($decodedVariants)) {
            $this->variantsFinal = AIConversationRunReportVariantsTimeline::describeVector(array_map('strval', $decodedVariants));
        }
    }
}
