<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\RunReport;

use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Symfony\Commands\Agents\AgentRunReportCommand;

/**
 * The run-report command without a configured default account for console work (the test kernel has none to read):
 * everything else is the module's command.
 */
class AgentRunReportProbeCommand extends AgentRunReportCommand
{
    /** @var string The name of the probe */
    public const string PROBE_COMMAND_NAME = 'app:test:run-report-probe';

    public function __construct()
    {
        // a subclass without its own attribute has no name
        parent::__construct(self::PROBE_COMMAND_NAME);
    }

    protected function findDefaultAccountForConsoleWork(): ?Account
    {
        return null;
    }
}
