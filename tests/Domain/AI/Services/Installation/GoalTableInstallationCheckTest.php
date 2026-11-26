<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Installation;

use DDD\Domain\AI\Entities\Installation\AgentInstallationFinding;
use DDD\Domain\AI\Entities\Installation\AgentInstallationFindings;
use DDD\Domain\AI\Services\Conversations\Goals\AgentGoalStoreService;
use DDD\Domain\AI\Services\Installation\AgentFeatureTableConditionProviderService;
use DDD\Domain\AI\Services\Installation\AgentInstallationCheckService;
use DDD\Infrastructure\Libs\Config;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Goals\AgentGoalsSwitch;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Goals\HostBackedAgentGoalStoreService;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use ReflectionProperty;

/**
 * Code before schema: the goal table is asked for only while goals are on and the package's own store answers them,
 * and then a missing table is a WARNING (the tools answer "not available"), never blocking. While goals are off, or a
 * host store keeps the goals in rows of its own, the check does not name the table at all.
 */
class GoalTableInstallationCheckTest extends IntegrationTestCase
{
    public const string GOAL_TABLE_NAME = 'AgentGoals';

    protected AgentGoalsSwitch $agentGoalsSwitch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agentGoalsSwitch = new AgentGoalsSwitch();
    }

    protected function onTearDown(): void
    {
        $this->agentGoalsSwitch->restore();
    }

    public function testWhileGoalsAreOnAMissingGoalTableIsAWarning(): void
    {
        $this->agentGoalsSwitch->switchGoals(true);

        $goalTableFindings = $this->findGoalTableFindings();

        self::assertSame(1, $goalTableFindings->count());
        self::assertSame(AgentInstallationFinding::SEVERITY_WARNING, $goalTableFindings->first()->severity);
        self::assertStringContainsString('the code works without it', $goalTableFindings->first()->message);
    }

    public function testWhileGoalsAreOffTheGoalTableIsNotNamed(): void
    {
        $this->agentGoalsSwitch->switchGoals(false);

        self::assertSame(0, $this->findGoalTableFindings()->count());
    }

    public function testAHostStoreKeepsTheCheckAwayFromThePackageGoalTable(): void
    {
        $this->replaceService(AgentGoalStoreService::class, new HostBackedAgentGoalStoreService());
        $this->agentGoalsSwitch->switchGoals(true);

        self::assertSame(0, $this->findGoalTableFindings()->count());
    }

    /** The findings of the missing-tables check (no live table) about the goal table */
    protected function findGoalTableFindings(): AgentInstallationFindings
    {
        $configTree = new ReflectionProperty(Config::class, 'configTree');
        $configValues = (array)$configTree->getValue();
        $configValues['AI']['installationCheck'] = [
            'skippedChecks' => array_values(array_diff(array_keys(AgentInstallationCheckService::BUILT_IN_CHECK_METHOD_NAMES), [AgentInstallationCheckService::CHECK_MISSING_TABLES])),
        ];
        $configTree->setValue(null, $configValues);
        $agentInstallationCheckService = new AgentInstallationCheckWithFixtureTablesDouble();
        $agentInstallationCheckService->fixtureLiveTableNames = [];
        $agentInstallationCheckService->setAgentFeatureTableConditionProviders([new AgentFeatureTableConditionProviderService()]);
        $agentInstallationCheckService->setAgentInstallationChecks([]);
        $goalTableFindings = new AgentInstallationFindings();
        foreach ($agentInstallationCheckService->findInstallationFindings(null)->getElements() as $agentInstallationFinding) {
            if ($agentInstallationFinding->subject === static::GOAL_TABLE_NAME) {
                $goalTableFindings->add($agentInstallationFinding);
            }
        }
        return $goalTableFindings;
    }
}
