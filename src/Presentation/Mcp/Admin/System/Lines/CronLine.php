<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Admin\System\Lines;

use DDD\Domain\AI\Entities\MCPPackages\LlmRendering\LlmLineRenderable;
use DDD\Domain\Common\Entities\Crons\CronOverview;

/**
 * One cron as a line: its state, schedule (evaluated in UTC), the name of its command, its last and next run and the
 * runs of the last 24 hours. The arguments of the command are no part of the line (the cron's card shows them masked).
 * Example: `#12 "Import Feed Items" · OK · 0 3 * * * · app:feeds:import-items · last run 2026-11-20 03:00 UTC (SUCCESSFUL, 42 s) · next 2026-11-21 03:00 UTC · 24 h: 1 run, 0 failed`
 */
class CronLine implements LlmLineRenderable
{
    /** @var string[] What a state means, for the states a name does not explain */
    public const array STATUS_EXPLANATIONS = [
        CronOverview::STATUS_FAILING => 'FAILING (last run failed)',
        CronOverview::STATUS_OVERDUE => 'OVERDUE (planned start passed, did not run)',
        CronOverview::STATUS_MISSING_COMMAND => 'MISSING_COMMAND (its command no longer exists)',
        CronOverview::STATUS_PAUSED => 'PAUSED (switched off)',
    ];

    /**
     * @param CronOverview $cronOverview
     */
    public function __construct(protected CronOverview $cronOverview)
    {
    }

    /**
     * @return string
     */
    public function toLlmLine(): string
    {
        $cronOverview = $this->cronOverview;
        $lineParts = [
            '#' . $cronOverview->id . ' "' . str_replace('"', "'", trim($cronOverview->name)) . '"',
            static::STATUS_EXPLANATIONS[$cronOverview->status] ?? $cronOverview->status,
            $cronOverview->schedule,
            SystemLineFormats::formatCommandName($cronOverview->commandName),
        ];
        $lastExecution = $cronOverview->lastExecution;
        if ($lastExecution !== null) {
            $lineParts[] = 'last run ' . SystemLineFormats::formatTime($lastExecution->startedAt) . ' (' . CronExecutionLine::describeState($lastExecution)
                . ($lastExecution->durationMs !== null ? ', ' . SystemLineFormats::formatDuration($lastExecution->durationMs) : '') . ')';
        } else {
            $lineParts[] = 'last run ' . SystemLineFormats::formatTime($cronOverview->lastExecutionStartedAt);
        }
        if ($cronOverview->isActive) {
            $lineParts[] = 'next ' . SystemLineFormats::formatTime($cronOverview->nextExecutionScheduledAt);
        }
        $lineParts[] = '24 h: ' . SystemLineFormats::countOf($cronOverview->stats24h->runCount, 'run') . ', ' . $cronOverview->stats24h->failureCount . ' failed';
        return implode(' · ', $lineParts);
    }
}
