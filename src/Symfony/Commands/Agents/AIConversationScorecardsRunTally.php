<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * What one run of {@see AIConversationScorecardsCommand} counted and collected: the conversations written or
 * dispatched, the conversations skipped, the analysis outcomes by outcome and, for `--print`, the printed rows.
 *
 * A plain object of one run; no container service.
 */
#[Exclude]
class AIConversationScorecardsRunTally
{
    /** @var int The conversations whose scorecard was written in this process or dispatched to the workers */
    public int $writtenCount = 0;

    /** @var int The conversations left out (not scored, or an eval run without `--eval-runs`) */
    public int $skippedCount = 0;

    /** @var array<string, int> How often each analysis outcome came back, by outcome, in the order of first sight */
    public array $analysisOutcomeCounts = [];

    /** @var array<int, string[]> The rows of the `--print` table, in the order of the conversations */
    public array $printedRows = [];

    /** @return void */
    public function countWritten(): void
    {
        $this->writtenCount++;
    }

    /** @return void */
    public function countSkipped(): void
    {
        $this->skippedCount++;
    }

    /**
     * Counts one analysis outcome; null (no analysis ran) counts nothing.
     *
     * @param string|null $analysisOutcome
     * @return void
     */
    public function countAnalysisOutcome(?string $analysisOutcome): void
    {
        if ($analysisOutcome === null) {
            return;
        }
        $this->analysisOutcomeCounts[$analysisOutcome] = ($this->analysisOutcomeCounts[$analysisOutcome] ?? 0) + 1;
    }

    /**
     * @param string[] $printedRow
     * @return void
     */
    public function addPrintedRow(array $printedRow): void
    {
        $this->printedRows[] = $printedRow;
    }

    /**
     * The analysis outcomes as ` Analysis: <outcome> <count>, ….` (with its leading space), or the empty string when no
     * analysis ran.
     *
     * @return string
     */
    public function describeAnalysisOutcomes(): string
    {
        if ($this->analysisOutcomeCounts === []) {
            return '';
        }
        $outcomeTexts = [];
        foreach ($this->analysisOutcomeCounts as $analysisOutcome => $outcomeCount) {
            $outcomeTexts[] = "{$analysisOutcome} {$outcomeCount}";
        }
        return ' Analysis: ' . implode(', ', $outcomeTexts) . '.';
    }
}
