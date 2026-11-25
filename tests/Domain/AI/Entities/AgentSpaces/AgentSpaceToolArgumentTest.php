<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\AgentSpaces;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpaceToolArgument;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackageDocumentation;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolOverlays;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Mcp\Client\Agents\AgentTaskSchedulesMCPPackage;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The space argument as the application names it (`AI.agentSpaceToolArgument`): the schema carries the configured name
 * and description in the place of `agentSpaceId`, and the resolver reads the configured name first, `agentSpaceId`
 * after it. The shipped config keeps `agentSpaceId`.
 */
class AgentSpaceToolArgumentTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (Config::getEnv('LAZYLOAD_DEFAULT_REPO_TYPE') === null) {
            LazyLoadRepo::setDafaultRepoType(LazyLoadRepo::DB);
        }
        MCPToolOverlays::replaceCurrent(MCPToolOverlays::fromConfigEntries([]));
    }

    protected function tearDown(): void
    {
        AgentSpaceToolArgument::resetCurrent();
        MCPToolOverlays::resetCurrent();
        parent::tearDown();
    }

    public function testTheShippedConfigKeepsTheDefaultName(): void
    {
        $agentSpaceToolArgument = AgentSpaceToolArgument::fromConfigValue(require dirname(__DIR__, 5) . '/config/app/AI/agentSpaceToolArgument.php');

        self::assertSame(AgentSpaceToolArgument::DTO_PROPERTY_NAME, $agentSpaceToolArgument->name);
        self::assertFalse($agentSpaceToolArgument->changesSchema());
    }

    public function testAnInvalidNameKeepsTheDefault(): void
    {
        self::assertSame(AgentSpaceToolArgument::DTO_PROPERTY_NAME, AgentSpaceToolArgument::fromConfigValue(['name' => 'shop id'])->name);
        self::assertSame(AgentSpaceToolArgument::DTO_PROPERTY_NAME, AgentSpaceToolArgument::fromConfigValue('shopId')->name);
    }

    public function testTheSchemaCarriesTheConfiguredNameAndDescription(): void
    {
        AgentSpaceToolArgument::replaceCurrent(AgentSpaceToolArgument::fromConfigValue(['name' => 'shopId', 'description' => 'The shop the call works for.']));

        $schemaProperties = $this->findInputPropertiesOfTool('getTaskSchedules');
        self::assertArrayHasKey('shopId', $schemaProperties);
        self::assertArrayNotHasKey(AgentSpaceToolArgument::DTO_PROPERTY_NAME, $schemaProperties);
        self::assertSame('The shop the call works for.', $schemaProperties['shopId']->description);
    }

    public function testTheResolverReadsTheConfiguredNameFirst(): void
    {
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        AgentSpaceToolArgument::replaceCurrent(AgentSpaceToolArgument::fromConfigValue(['name' => 'shopId']));

        self::assertSame(7, $agentSpacesService->findRequestedAgentSpaceIdOfToolArguments(['shopId' => 7, 'agentSpaceId' => 3]));
        self::assertSame(3, $agentSpacesService->findRequestedAgentSpaceIdOfToolArguments(['agentSpaceId' => 3]));
        self::assertSame(0, $agentSpacesService->findRequestedAgentSpaceIdOfToolArguments([]));
    }

    /** @return array<string, object> */
    protected function findInputPropertiesOfTool(string $toolName): array
    {
        foreach (MCPPackageDocumentation::createFromClassName(AgentTaskSchedulesMCPPackage::class)->functions as $toolDocumentation) {
            if ($toolDocumentation->name === $toolName) {
                return $toolDocumentation->inputSchema->properties;
            }
        }
        self::fail("no tool {$toolName}");
    }
}
