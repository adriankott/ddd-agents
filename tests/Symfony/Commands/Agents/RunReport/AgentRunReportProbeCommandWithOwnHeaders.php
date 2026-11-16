<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\RunReport;

/**
 * The probe command of an application that names the decision model's cost column with its own word: it redeclares
 * the run table's headers, and the command reads them through `static::`.
 */
class AgentRunReportProbeCommandWithOwnHeaders extends AgentRunReportProbeCommand
{
    /** @var string The application's header of the decision model's cost column */
    public const string DECISION_COST_HEADER = 'judge model $ (calls)';

    /** @var string[] The headers of the run table, the decision model's cost column named by the application */
    public const array RUN_TABLE_HEADERS = ['run', 'case', 'judge', 'wall s', 'owner turns', 'model calls', 'tool calls', 'refused', 'repeats', 'agent s/turn', 'model s', 'overhead s', 'tool s Σ', 'tool s wall', 'driver s', 'ticks', 'loads', 'non-blocking', 'model $', self::DECISION_COST_HEADER, 'variants (final)', 'plan nodes', 'plan depth', 'plan tail tok (max / Σ)'];
}
