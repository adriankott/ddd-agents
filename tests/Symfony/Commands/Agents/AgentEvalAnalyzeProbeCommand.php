<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Services\Eval\AgentEvalResultFilesService;
use DDD\Symfony\Commands\Agents\AgentEvalAnalyzeCommand;

/**
 * The failure analysis on the test's results folder.
 */
class AgentEvalAnalyzeProbeCommand extends AgentEvalAnalyzeCommand
{
    /** @var string The name the command runs under (a probe class carries no command attribute of its own) */
    public const string PROBE_COMMAND_NAME = 'app:agent:eval-analyze';

    public AgentEvalResultFilesServiceInFolderDouble $resultFilesServiceDouble;

    public function __construct()
    {
        $this->resultFilesServiceDouble = new AgentEvalResultFilesServiceInFolderDouble();
        parent::__construct(self::PROBE_COMMAND_NAME);
    }

    protected function getAgentEvalResultFilesService(): AgentEvalResultFilesService
    {
        return $this->resultFilesServiceDouble;
    }
}
