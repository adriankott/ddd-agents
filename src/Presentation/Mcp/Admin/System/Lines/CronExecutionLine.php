<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Admin\System\Lines;

use DDD\Domain\AI\Entities\MCPPackages\LlmRendering\LlmLineRenderable;
use DDD\Domain\Common\Entities\Crons\CronExecution;
use DDD\Domain\Common\Entities\Crons\CronExecutionOverview;

/**
 * One run of a cron as a line: when it started, how long it took, how it ended, and the first line of what the
 * command printed (masked, cut). The output is text a command wrote: the tool marks these lines untrusted.
 * Example: `#881 · cron #12 "Import Feed Items" · 2026-11-20 03:00:02 UTC · 42 s · FAILED · output 1,204 chars: "Importing activities of 310 accounts"`
 */
class CronExecutionLine implements LlmLineRenderable
{
    /** @var int The characters of the output a line shows */
    public const int OUTPUT_LENGTH = 160;

    /**
     * @param CronExecutionOverview $cronExecutionOverview
     * @param bool $showsCron Whether the line names the cron (a list over every cron)
     */
    public function __construct(protected CronExecutionOverview $cronExecutionOverview, protected bool $showsCron = true)
    {
    }

    /**
     * @param CronExecutionOverview $cronExecutionOverview
     * @return string RUNNING, SUCCESSFUL, FAILED, or ENDED when a finished run stored no result
     */
    public static function describeState(CronExecutionOverview $cronExecutionOverview): string
    {
        if ($cronExecutionOverview->state === CronExecution::STATE_RUNNING) {
            return CronExecution::STATE_RUNNING;
        }
        return $cronExecutionOverview->executionState ?? $cronExecutionOverview->state;
    }

    /**
     * @return string
     */
    public function toLlmLine(): string
    {
        $cronExecutionOverview = $this->cronExecutionOverview;
        $lineParts = ['#' . $cronExecutionOverview->id];
        if ($this->showsCron) {
            $lineParts[] = 'cron #' . $cronExecutionOverview->cronId . ' "' . str_replace('"', "'", trim($cronExecutionOverview->cronName)) . '"';
        }
        $lineParts[] = SystemLineFormats::formatTime($cronExecutionOverview->startedAt, true);
        $lineParts[] = $cronExecutionOverview->durationMs !== null ? SystemLineFormats::formatDuration($cronExecutionOverview->durationMs) : 'not finished';
        $lineParts[] = static::describeState($cronExecutionOverview);
        $outputPreview = SystemLineFormats::quoteLoggedText($cronExecutionOverview->outputPreview, static::OUTPUT_LENGTH);
        $lineParts[] = $outputPreview !== ''
            ? 'output ' . number_format($cronExecutionOverview->outputLength) . ' chars: ' . $outputPreview
            : 'no output';
        return implode(' · ', $lineParts);
    }
}
