<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\Eval;

use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\Eval\AgentEvalJudgedRunService;
use DDD\Symfony\Commands\Agents\AgentEvalCompactionTreeCommand;
use DDD\Tests\Agents\Symfony\Commands\Agents\AgentSpacesServiceConsoleScopeDouble;

/** The compaction-tree command with its services handed in and, on demand, a default case file. */
class AgentEvalCompactionTreeProbeCommand extends AgentEvalCompactionTreeCommand
{
    /** @var string The name of the probe; its own, so it never meets the real command */
    public const string PROBE_COMMAND_NAME = 'app:test:eval-compaction-tree-probe';

    public ?string $defaultCaseFile = null;

    public function __construct(
        protected JudgedRunServiceWithScriptedTreeRunDouble $judgedRunServiceDouble,
        protected AgentSpacesServiceConsoleScopeDouble $agentSpacesServiceDouble
    ) {
        parent::__construct(self::PROBE_COMMAND_NAME);
    }

    protected function getDefaultCaseFile(): ?string
    {
        return $this->defaultCaseFile;
    }

    protected function getJudgedRunService(): AgentEvalJudgedRunService
    {
        return $this->judgedRunServiceDouble;
    }

    protected function getAgentSpacesService(): AgentSpacesService
    {
        return $this->agentSpacesServiceDouble;
    }
}
