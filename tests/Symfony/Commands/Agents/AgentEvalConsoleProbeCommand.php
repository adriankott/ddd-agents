<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Symfony\Commands\Agents\AgentEvalConsoleCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A concrete evaluation command on {@see AgentEvalConsoleCommand}: it prints one line, reports one progress line,
 * reads the fallback space of its cases and the arguments a child would get, and records them with the stop signals
 * it received.
 */
class AgentEvalConsoleProbeCommand extends AgentEvalConsoleCommand
{
    public const string PROBE_COMMAND_NAME = 'agents-test:eval-probe';

    public const string PRINTED_LINE = '{"total":1}';

    public const string PROGRESS_LINE = 'restore capture done';

    public bool $stopsGracefully = false;

    public ?int $fallbackAgentSpaceId = null;

    public ?int $fallbackAccountId = null;

    public ?bool $rightsAppliedDuringScopeRead = null;

    /** @var string[] */
    public array $childArguments = [];

    /** @var int[] */
    public array $receivedStopSignals = [];

    public function __construct(
        protected AgentSpacesServiceConsoleScopeDouble $agentSpacesServiceDouble,
        protected RecordingUsageEnvelopesServiceDouble $usageEnvelopesServiceDouble
    ) {
        parent::__construct(self::PROBE_COMMAND_NAME);
    }

    protected function configure(): void
    {
        $this->configureBudgetOption();
        $this->configureEvalScopeOptions();
    }

    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(self::PRINTED_LINE);
        $this->getProgressReporter()->reportProgress(self::PROGRESS_LINE);
        $this->fallbackAgentSpaceId = $this->resolveFallbackAgentSpaceId($input);
        $this->fallbackAccountId = $this->resolveFallbackAccountId($input);
        $this->childArguments = array_merge(
            $this->buildChildConsoleCommandLine($this->getChildCommandName()),
            $this->buildScopeArgumentsForChild($input),
            $this->buildBudgetArgumentsForChild($input)
        );
        return Command::SUCCESS;
    }

    protected function handlesStopSignals(): bool
    {
        return $this->stopsGracefully;
    }

    protected function onStopSignal(int $signal): void
    {
        $this->receivedStopSignals[] = $signal;
    }

    protected function getUsageEnvelopesService(): AIUsageEnvelopesService
    {
        return $this->usageEnvelopesServiceDouble;
    }

    protected function getAgentSpacesService(): AgentSpacesService
    {
        $this->rightsAppliedDuringScopeRead = DBEntity::getApplyRightsRestrictions();
        return $this->agentSpacesServiceDouble;
    }
}
