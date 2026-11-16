<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Symfony\Commands\Agents\AgentEvalRecallCommand;

/**
 * The recall command on the test's services: no model call, no database.
 */
class AgentEvalRecallProbeCommand extends AgentEvalRecallCommand
{
    use AgentEvalCaseCommandServicesTrait;

    /** @var string The name the command runs under (a probe class carries no command attribute of its own) */
    public const string PROBE_COMMAND_NAME = 'app:ai-agent:eval-recall';

    public function __construct()
    {
        $this->setServiceDoubles();
        parent::__construct(self::PROBE_COMMAND_NAME);
    }
}
