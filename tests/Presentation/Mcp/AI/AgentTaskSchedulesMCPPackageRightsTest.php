<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\AI;

use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRights;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEntitlement;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolOverlays;
use DDD\Presentation\Mcp\Client\Agents\AgentTaskSchedulesMCPPackage;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;

/**
 * The rights of the task tools: no right on the class; the tools that create or change a schedule (and
 * `startBackgroundTask`, which spends budget) require `scheduleAgentTasks`; the reads require none; the skill file names
 * no right (reads stay available to every account).
 */
class AgentTaskSchedulesMCPPackageRightsTest extends UnitTestCase
{
    public const array TOOLS_REQUIRING_THE_SCHEDULE_RIGHT = [
        'startBackgroundTask',
        'scheduleOneTimeTask',
        'createRecurringTaskSchedule',
        'updateTaskSchedule',
        'pauseTaskSchedule',
        'resumeTaskSchedule',
        'deleteTaskSchedule',
    ];

    public const array READ_TOOLS = ['getTaskSchedules', 'getTaskRuns', 'getTaskRunReport'];

    protected function setUp(): void
    {
        parent::setUp();
        MCPToolOverlays::replaceCurrent(MCPToolOverlays::fromConfigEntries([]));
    }

    protected function tearDown(): void
    {
        MCPToolOverlays::resetCurrent();
        parent::tearDown();
    }

    public function testThePackageClassCarriesNoRight(): void
    {
        self::assertSame([], MCPPackage::findRequiredAccountRightNamesOfClass(AgentTaskSchedulesMCPPackage::class));
    }

    public function testTheWritingToolsRequireTheScheduleRightAndTheReadsNone(): void
    {
        foreach (self::TOOLS_REQUIRING_THE_SCHEDULE_RIGHT as $toolName) {
            self::assertSame([AgentAccountRights::SCHEDULE_AGENT_TASKS], $this->findRequiredRightNamesOfTool($toolName), $toolName);
        }
        foreach (self::READ_TOOLS as $toolName) {
            self::assertSame([], $this->findRequiredRightNamesOfTool($toolName), $toolName);
        }
    }

    public function testTheSkillFileNamesNoRight(): void
    {
        $skillFileContent = (string)file_get_contents(dirname(__DIR__, 4) . '/config/app/AI/Skills/Agent/Tasks.md');

        self::assertStringNotContainsString('requiredAccountRights', $skillFileContent);
    }

    /** @return string[] */
    protected function findRequiredRightNamesOfTool(string $toolName): array
    {
        $reflectionClass = new ReflectionClass(AgentTaskSchedulesMCPPackage::class);
        return MCPToolEntitlement::createFromReflectionMethod($reflectionClass, $reflectionClass->getMethod($toolName))->requiredAccountRightNames;
    }
}
