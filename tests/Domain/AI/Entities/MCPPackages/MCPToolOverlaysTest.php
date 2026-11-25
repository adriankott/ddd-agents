<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\MCPPackages;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRunReport;
use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRights;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackageDocumentation;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEntitlement;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolOverlays;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Infrastructure\Libs\Config;
use DDD\Presentation\Mcp\Client\Agents\AgentTaskSchedulesMCPPackage;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;

/**
 * The application's overlay by tool NAME (`AI.toolOverlays`): its rights replace the attributes' (also "no right"),
 * its texts replace the description, the summary, a parameter description and the owner-facing subject, and the schema
 * caches follow its fingerprint. Keys the overlay does not know are kept for the installation check.
 */
class MCPToolOverlaysTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (Config::getEnv('LAZYLOAD_DEFAULT_REPO_TYPE') === null) {
            LazyLoadRepo::setDafaultRepoType(LazyLoadRepo::DB);
        }
    }

    protected function tearDown(): void
    {
        MCPToolOverlays::resetCurrent();
        parent::tearDown();
    }

    public function testTheShippedConfigHasNoOverlay(): void
    {
        self::assertSame([], require dirname(__DIR__, 5) . '/config/app/AI/toolOverlays.php');
    }

    public function testOverlayRightsReplaceTheAttributesAndAnEmptyListMeansNoRight(): void
    {
        MCPToolOverlays::replaceCurrent(MCPToolOverlays::fromConfigEntries([
            'getTaskSchedules' => ['accountRights' => ['manageTasks']],
            'scheduleOneTimeTask' => ['accountRights' => []],
        ]));

        self::assertSame(['manageTasks'], $this->findRequiredRightNamesOfTool('getTaskSchedules'));
        self::assertSame([], $this->findRequiredRightNamesOfTool('scheduleOneTimeTask'));
        self::assertSame([AgentAccountRights::SCHEDULE_AGENT_TASKS], $this->findRequiredRightNamesOfTool('pauseTaskSchedule'));
    }

    public function testOverlayTextsReplaceDescriptionSummaryParameterAndSubject(): void
    {
        MCPToolOverlays::replaceCurrent(MCPToolOverlays::fromConfigEntries([
            'getTaskSchedules' => [
                'description' => 'Lists the jobs of the shop.',
                'summary' => 'Lists the jobs',
                'parameters' => ['agentSpaceId' => 'The shop whose jobs are listed.'],
            ],
            'pauseTaskSchedule' => ['ownerFacingSubject' => 'Job'],
        ]));

        $toolDocumentation = $this->findToolDocumentation('getTaskSchedules');
        self::assertSame('Lists the jobs of the shop.', $toolDocumentation->description);
        self::assertSame('Lists the jobs', $toolDocumentation->summary);
        self::assertSame('The shop whose jobs are listed.', $toolDocumentation->inputSchema->properties['agentSpaceId']->description);
        self::assertSame('Job', AgentRunReport::ownerFacingTitleForWriteTool('pauseTaskSchedule'));
    }

    public function testTheFingerprintFollowsTheOverlaysAndUnknownKeysAreKept(): void
    {
        MCPToolOverlays::replaceCurrent(MCPToolOverlays::fromConfigEntries([]));
        $fingerprintWithoutOverlays = MCPToolOverlays::getSchemaConfigurationFingerprint();
        MCPToolOverlays::replaceCurrent(MCPToolOverlays::fromConfigEntries(['getTaskRuns' => ['summary' => 'Runs', 'colour' => 'red']]));

        self::assertNotSame($fingerprintWithoutOverlays, MCPToolOverlays::getSchemaConfigurationFingerprint());
        self::assertSame(['getTaskRuns'], MCPToolOverlays::getApplicationOverlays()->getToolNames());
        self::assertSame(['colour'], MCPToolOverlays::getApplicationOverlays()->findOverlayOfTool('getTaskRuns')->unknownKeys);
        self::assertFalse(MCPToolOverlays::getApplicationOverlays()->findOverlayOfTool('getTaskRuns')->replacesAccountRights());
    }

    /** @return string[] */
    protected function findRequiredRightNamesOfTool(string $toolName): array
    {
        $reflectionClass = new ReflectionClass(AgentTaskSchedulesMCPPackage::class);
        return MCPToolEntitlement::createFromReflectionMethod($reflectionClass, $reflectionClass->getMethod($toolName))->requiredAccountRightNames;
    }

    protected function findToolDocumentation(string $toolName): object
    {
        foreach (MCPPackageDocumentation::createFromClassName(AgentTaskSchedulesMCPPackage::class)->functions as $toolDocumentation) {
            if ($toolDocumentation->name === $toolName) {
                return $toolDocumentation;
            }
        }
        self::fail("no tool {$toolName}");
    }
}
