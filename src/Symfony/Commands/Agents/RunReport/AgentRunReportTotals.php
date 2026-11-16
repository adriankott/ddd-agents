<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\RunReport;

use DDD\Domain\AI\Entities\Conversations\RunReports\AIConversationRunReport;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The totals over the reported runs of one printout: the sums of the report figures, the judged runs, the passes and
 * the summed scores. {@see getPrintedRow()} is the last row of the run table (`Σ / Ø`).
 *
 * A plain object of one printout; no container service.
 */
#[Exclude]
class AgentRunReportTotals
{
    /** @var int Runs with a report */
    public int $reportedRuns = 0;

    public float $wallSeconds = 0.0;

    public int $ownerTurns = 0;

    public int $modelCalls = 0;

    public int $toolCalls = 0;

    public int $refusedToolCalls = 0;

    public int $identicalRepeats = 0;

    public float $agentSeconds = 0.0;

    public int $executionTurns = 0;

    public float $modelSeconds = 0.0;

    public float $platformOverheadSeconds = 0.0;

    public float $toolSeconds = 0.0;

    public float $toolWallSeconds = 0.0;

    public float $ownerSeconds = 0.0;

    public int $planTicks = 0;

    public int $skillLoads = 0;

    public float $modelCostInUSD = 0.0;

    public float $decisionModelCostInUSD = 0.0;

    public int $decisionModelCalls = 0;

    /** @var int Runs with a judge verdict */
    public int $judgedRuns = 0;

    /** @var int Judged runs that passed */
    public int $passedRuns = 0;

    /** @var int The scores of the judged runs, summed */
    public int $scorePercentSum = 0;

    /**
     * Adds one reported run and its verdict.
     *
     * @param AIConversationRunReport $runReport
     * @param AgentRunReportVerdict $runVerdict the empty verdict when no log names the run
     * @return void
     */
    public function addRun(AIConversationRunReport $runReport, AgentRunReportVerdict $runVerdict): void
    {
        $this->reportedRuns++;
        $this->wallSeconds += $runReport->wallSeconds;
        $this->ownerTurns += $runReport->ownerTurns;
        $this->modelCalls += $runReport->modelCalls;
        $this->toolCalls += $runReport->toolCalls;
        $this->refusedToolCalls += $runReport->refusedToolCalls;
        $this->identicalRepeats += $runReport->identicalRepeats;
        $this->agentSeconds += $runReport->agentSeconds;
        $this->executionTurns += $runReport->executionTurns;
        $this->modelSeconds += $runReport->modelSeconds;
        $this->platformOverheadSeconds += $runReport->platformOverheadSeconds;
        $this->toolSeconds += $runReport->toolSeconds;
        $this->toolWallSeconds += $runReport->toolWallSeconds;
        $this->ownerSeconds += $runReport->ownerSeconds;
        $this->planTicks += $runReport->planTicks;
        $this->skillLoads += $runReport->skillLoads;
        $this->modelCostInUSD += $runReport->modelCostInUSD;
        $this->decisionModelCostInUSD += $runReport->decisionModelCostInUSD;
        $this->decisionModelCalls += $runReport->decisionModelCalls;
        if ($runVerdict->hasVerdict()) {
            $this->judgedRuns++;
            $this->passedRuns += $runVerdict->isPass() ? 1 : 0;
            $this->scorePercentSum += (int)($runVerdict->scorePercent ?? 0);
        }
    }

    /**
     * The totals row of the run table: averages per reported run (at least one), sums of the seconds and costs, the
     * agent seconds per execution turn, the pass count and the average score of the judged runs.
     *
     * @return string[] one cell per column of the run table
     */
    public function getPrintedRow(): array
    {
        $runs = max(1, $this->reportedRuns);
        return [
            'Σ / Ø',
            $this->judgedRuns > 0 ? $this->passedRuns . '/' . $this->judgedRuns . ' pass' : '',
            $this->judgedRuns > 0 ? 'Ø ' . round($this->scorePercentSum / $this->judgedRuns) . ' %' : '',
            'Ø ' . round($this->wallSeconds / $runs),
            'Ø ' . number_format($this->ownerTurns / $runs, 1),
            'Ø ' . number_format($this->modelCalls / $runs, 1),
            'Ø ' . number_format($this->toolCalls / $runs, 1),
            (string)$this->refusedToolCalls,
            (string)$this->identicalRepeats,
            'Ø ' . number_format($this->agentSeconds / max(1, $this->executionTurns), 1),
            'Σ ' . round($this->modelSeconds),
            'Σ ' . round($this->platformOverheadSeconds),
            'Σ ' . round($this->toolSeconds),
            'Σ ' . round($this->toolWallSeconds),
            'Σ ' . round($this->ownerSeconds),
            (string)$this->planTicks,
            (string)$this->skillLoads,
            '',
            'Σ ' . number_format($this->modelCostInUSD, 3) . ' / Ø ' . number_format($this->modelCostInUSD / $runs, 3),
            'Σ ' . number_format($this->decisionModelCostInUSD, 4) . ' (' . $this->decisionModelCalls . ')',
            '',
            '',
            '',
            '',
        ];
    }
}
