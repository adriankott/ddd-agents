<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Domain\AI\Services\Eval\AgentEvalResultFilesService;
use DDD\Domain\AI\Services\Eval\AgentEvalRunnerService;
use DDD\Domain\AI\Services\Eval\AgentEvalSuitePlanService;

/**
 * The services of a probe of an evaluation command: the doubles the test hands in ({@see $agentSpacesServiceDouble}
 * must be set before the command's `configure()` runs, so a probe sets it before calling the parent constructor).
 */
trait AgentEvalCaseCommandServicesTrait
{
    public AgentSpacesServiceConsoleScopeDouble $agentSpacesServiceDouble;

    public RecordingUsageEnvelopesServiceDouble $usageEnvelopesServiceDouble;

    public AgentEvalCaseCommandsRunnerDouble $runnerServiceDouble;

    public AgentEvalCaseCommandsPlanServiceDouble $planServiceDouble;

    public AgentEvalResultFilesServiceInFolderDouble $resultFilesServiceDouble;

    /** Sets the doubles; call it before the parent constructor. */
    protected function setServiceDoubles(): void
    {
        $this->agentSpacesServiceDouble = new AgentSpacesServiceConsoleScopeDouble();
        $this->usageEnvelopesServiceDouble = new RecordingUsageEnvelopesServiceDouble();
        $this->runnerServiceDouble = new AgentEvalCaseCommandsRunnerDouble();
        $this->planServiceDouble = new AgentEvalCaseCommandsPlanServiceDouble();
        $this->resultFilesServiceDouble = new AgentEvalResultFilesServiceInFolderDouble();
    }

    protected function getAgentSpacesService(): AgentSpacesService
    {
        return $this->agentSpacesServiceDouble;
    }

    protected function getUsageEnvelopesService(): AIUsageEnvelopesService
    {
        return $this->usageEnvelopesServiceDouble;
    }

    protected function getAgentEvalRunnerService(): AgentEvalRunnerService
    {
        return $this->runnerServiceDouble;
    }

    protected function getAgentEvalSuitePlanService(): AgentEvalSuitePlanService
    {
        return $this->planServiceDouble;
    }

    protected function getAgentEvalResultFilesService(): AgentEvalResultFilesService
    {
        return $this->resultFilesServiceDouble;
    }
}
