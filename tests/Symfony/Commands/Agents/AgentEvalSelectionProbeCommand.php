<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Services\Eval\AgentEvalSuiteRunsService;
use DDD\Symfony\Commands\Agents\AgentEvalSelectionCommand;
use DDD\Tests\Agents\Domain\AI\Services\Eval\AgentEvalSuiteRunsServiceWithoutDatabaseDouble;

/**
 * The selection command on the test's services: no model call, no database.
 */
class AgentEvalSelectionProbeCommand extends AgentEvalSelectionCommand
{
    use AgentEvalCaseCommandServicesTrait;

    /** @var string The name the command runs under (a probe class carries no command attribute of its own) */
    public const string PROBE_COMMAND_NAME = 'app:ai-agent:eval-selection';

    public AgentEvalSuiteRunsServiceWithoutDatabaseDouble $suiteRunsServiceDouble;

    public function __construct()
    {
        $this->setServiceDoubles();
        $this->suiteRunsServiceDouble = new AgentEvalSuiteRunsServiceWithoutDatabaseDouble();
        parent::__construct(self::PROBE_COMMAND_NAME);
    }

    protected function getAgentEvalSuiteRunsService(): AgentEvalSuiteRunsService
    {
        return $this->suiteRunsServiceDouble;
    }
}
