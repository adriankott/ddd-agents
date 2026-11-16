<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The evaluation probe that prints its standard output into a buffer of the test (a console output writes its
 * standard stream to the process), while its error output is the console output's.
 */
class AgentEvalConsoleProbeCommandWithBufferedStandardOutput extends AgentEvalConsoleProbeCommand
{
    public function __construct(
        AgentSpacesServiceConsoleScopeDouble $agentSpacesServiceDouble,
        RecordingUsageEnvelopesServiceDouble $usageEnvelopesServiceDouble,
        protected BufferedOutput $standardOutput
    ) {
        parent::__construct($agentSpacesServiceDouble, $usageEnvelopesServiceDouble);
    }

    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        return parent::executeConsoleWork($input, $this->standardOutput);
    }
}
