<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Discovery\AgentSourceRoot;
use DDD\Domain\AI\Entities\Discovery\AgentSourceRoots;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;
use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Symfony\Commands\Agents\MCPDocumentationCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * The documentation command over the fixture source root `tests/Fixtures/MCPClassCatalog`, with an agent-spaces
 * service, a tool executor and the stored accounts handed in instead of the container's. It records the calls of the
 * measurement points with the count of tool calls the executor had seen at that moment, prints a line when it reports,
 * and resolves the space of a call by throwing the error a test hands it.
 */
class MCPDocumentationProbeCommand extends MCPDocumentationCommand
{
    public const string PROBE_COMMAND_NAME = 'agents-test:mcp-documentation-probe';

    /** @var string The root namespace of the fixture source root */
    public const string FIXTURE_ROOT_NAMESPACE = 'DDD\\Tests\\Agents\\Fixtures\\MCPClassCatalog';

    /** @var string The line the report of the measurement prints */
    public const string MEASUREMENT_REPORT_LINE = 'measurement of the probe';

    /** @var string[] The measurement points called, as `<point>:<tool>@<tool calls seen by the executor>` */
    public array $measurementEvents = [];

    /** @var Throwable|null What resolving the space of a call throws; null = the command's resolution */
    public ?Throwable $scopeErrorToThrow = null;

    /**
     * @param AgentSpacesServiceConsoleScopeDouble $agentSpacesServiceDouble
     * @param RecordingToolExecutorDouble $toolExecutorDouble
     * @param Account[] $storedAccountsById
     */
    public function __construct(
        protected AgentSpacesServiceConsoleScopeDouble $agentSpacesServiceDouble,
        protected RecordingToolExecutorDouble $toolExecutorDouble,
        protected array $storedAccountsById = []
    ) {
        parent::__construct(self::PROBE_COMMAND_NAME);
    }

    /** @return string the fixture source root */
    public static function getFixtureSourceDirectory(): string
    {
        return dirname(__DIR__, 3) . '/Fixtures/MCPClassCatalog';
    }

    protected function getMcpClassSourceRoots(): AgentSourceRoots
    {
        $agentSourceRoots = AgentSourceRoots::newInstance();
        $agentSourceRoots->addSourceRootOnce(AgentSourceRoot::forDirectory(self::FIXTURE_ROOT_NAMESPACE, self::getFixtureSourceDirectory(), true));
        return $agentSourceRoots;
    }

    protected function getAgentSpacesService(): AgentSpacesService
    {
        return $this->agentSpacesServiceDouble;
    }

    protected function getMCPToolExecutorService(): MCPToolExecutorService
    {
        return $this->toolExecutorDouble;
    }

    protected function resolveToolCallAgentSpace(InputInterface $input): ?AgentSpace
    {
        if ($this->scopeErrorToThrow !== null) {
            throw $this->scopeErrorToThrow;
        }
        return parent::resolveToolCallAgentSpace($input);
    }

    protected function startToolCallMeasurement(string $toolName): void
    {
        $this->measurementEvents[] = "start:{$toolName}@{$this->toolExecutorDouble->callCount}";
    }

    protected function reportToolCallMeasurement(string $toolName, OutputInterface $output): void
    {
        $this->measurementEvents[] = "report:{$toolName}@{$this->toolExecutorDouble->callCount}";
        $output->writeln(self::MEASUREMENT_REPORT_LINE);
    }

    protected function findAccountWithEntityRightsLifted(int $accountId): ?Account
    {
        return $this->storedAccountsById[$accountId] ?? null;
    }
}
