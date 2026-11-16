<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Discovery\AgentSourceRoot;
use DDD\Domain\AI\Entities\Discovery\AgentSourceRoots;
use DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons\ToolCallComparisonScript;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Symfony\Commands\Agents\MCPCompareToolCallsCommand;

/**
 * The comparison command over the fixture roots of `tests/Fixtures/ToolCallComparisons`: the application's root
 * `Host` first, the module's root `Addon` second; its scripts in the fixture folder `Scripts`, no account known.
 */
class MCPCompareToolCallsProbeCommand extends MCPCompareToolCallsCommand
{
    public const string PROBE_COMMAND_NAME = 'agents-test:mcp-compare-tool-calls-probe';

    /** @var string The root namespace of the fixture roots */
    public const string FIXTURE_ROOT_NAMESPACE = 'DDD\\Tests\\Agents\\Fixtures\\ToolCallComparisons';

    public function __construct()
    {
        parent::__construct(self::PROBE_COMMAND_NAME);
    }

    /** @return string the folder of the fixture roots */
    public static function getFixtureDirectory(): string
    {
        return dirname(__DIR__, 3) . '/Fixtures/ToolCallComparisons';
    }

    /** The application's root `Host`, then the module's root `Addon`. */
    public static function buildFixtureSourceRoots(): AgentSourceRoots
    {
        $agentSourceRoots = AgentSourceRoots::newInstance();
        $agentSourceRoots->addSourceRootOnce(AgentSourceRoot::forDirectory(self::FIXTURE_ROOT_NAMESPACE . '\\Host', self::getFixtureDirectory() . '/Host', true));
        $agentSourceRoots->addSourceRootOnce(AgentSourceRoot::forDirectory(self::FIXTURE_ROOT_NAMESPACE . '\\Addon', self::getFixtureDirectory() . '/Addon', false));
        return $agentSourceRoots;
    }

    protected function getMcpClassSourceRoots(): AgentSourceRoots
    {
        return static::buildFixtureSourceRoots();
    }

    protected function getScriptsDirectory(): string
    {
        return static::getFixtureDirectory() . '/Scripts';
    }

    protected function findComparisonAccount(ToolCallComparisonScript $toolCallComparisonScript): ?Account
    {
        return null;
    }
}
