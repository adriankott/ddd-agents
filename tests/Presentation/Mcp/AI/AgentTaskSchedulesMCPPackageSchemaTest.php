<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\AI;

use DDD\Domain\AI\Entities\MCPPackages\MCPPackageDocumentation;
use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Infrastructure\Libs\Config;
use DDD\Presentation\Mcp\Client\AI\AgentTaskSchedulesMCPPackage;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The schedule tools' schema builds (every request and response class documents without a type error), the package
 * exposes the ten tool names the run purpose and the skill file refer to, every tool is described, and the package's
 * default binding offers it under `Assistant.Tasks`.
 */
class AgentTaskSchedulesMCPPackageSchemaTest extends UnitTestCase
{
    public const array TOOL_NAMES = [
        'startBackgroundTask',
        'scheduleOneTimeTask',
        'createRecurringTaskSchedule',
        'getTaskSchedules',
        'getTaskRuns',
        'getTaskRunReport',
        'pauseTaskSchedule',
        'resumeTaskSchedule',
        'deleteTaskSchedule',
        'updateTaskSchedule',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // an entity reads the default lazy-load repo type on construction; the borrowed test environment may not set it
        if (Config::getEnv('LAZYLOAD_DEFAULT_REPO_TYPE') === null) {
            LazyLoadRepo::setDafaultRepoType(LazyLoadRepo::DB);
        }
    }

    public function testTheSchemaBuildsAndExposesTheTenTools(): void
    {
        $packageDocumentation = MCPPackageDocumentation::createFromClassName(AgentTaskSchedulesMCPPackage::class);

        $toolNames = [];
        foreach ($packageDocumentation->functions as $toolDocumentation) {
            $toolNames[] = $toolDocumentation->name;
            self::assertNotSame('', trim((string)$toolDocumentation->description), $toolDocumentation->name);
        }
        sort($toolNames);
        $expectedToolNames = self::TOOL_NAMES;
        sort($expectedToolNames);
        self::assertSame($expectedToolNames, $toolNames);
        self::assertSame('agent.taskSchedules', $packageDocumentation->name);
    }

    public function testTheSchemaCarriesNoUnparsedGenericArrayType(): void
    {
        $serializedDocumentation = json_encode(MCPPackageDocumentation::createFromClassName(AgentTaskSchedulesMCPPackage::class)->toObject(forPersistence: false));

        self::assertIsString($serializedDocumentation);
        self::assertStringNotContainsString('array<', $serializedDocumentation);
    }

    public function testThePackagesBindingOffersTheToolsUnderAssistantTasks(): void
    {
        $packageBinding = require dirname(__DIR__, 4) . '/config/app/AI/skillPackages.php';

        self::assertSame(['agent.taskSchedules'], $packageBinding[AISkill::ASSISTANT_TASKS] ?? null);
        self::assertFileExists(dirname(__DIR__, 4) . '/config/app/AI/Skills/Assistant/Tasks.md');
    }
}
