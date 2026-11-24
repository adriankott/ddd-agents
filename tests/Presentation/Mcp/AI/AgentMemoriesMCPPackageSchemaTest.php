<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\AI;

use DDD\Domain\AI\Entities\MCPPackages\MCPPackageDocumentation;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolWritesAgentMemoryOnly;
use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Infrastructure\Libs\Config;
use DDD\Presentation\Mcp\Client\Agents\AgentMemoriesMCPPackage;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionMethod;

/**
 * The memory tools' schema builds, the package exposes the five tool names (the same names as earlier application
 * memory packages, so a host can switch to it), every tool is described, the three writes refresh the standing
 * directives within a run, and the package's default binding offers it under `Agent.Memory`.
 */
class AgentMemoriesMCPPackageSchemaTest extends UnitTestCase
{
    public const array TOOL_NAMES = ['searchMemory', 'listMemories', 'remember', 'updateMemory', 'forget'];

    public const array WRITE_TOOL_NAMES = ['remember', 'updateMemory', 'forget'];

    protected function setUp(): void
    {
        parent::setUp();
        if (Config::getEnv('LAZYLOAD_DEFAULT_REPO_TYPE') === null) {
            LazyLoadRepo::setDafaultRepoType(LazyLoadRepo::DB);
        }
    }

    public function testTheSchemaBuildsAndExposesTheFiveTools(): void
    {
        $packageDocumentation = MCPPackageDocumentation::createFromClassName(AgentMemoriesMCPPackage::class);

        $toolNames = [];
        foreach ($packageDocumentation->functions as $toolDocumentation) {
            $toolNames[] = $toolDocumentation->name;
            self::assertNotSame('', trim((string)$toolDocumentation->description), $toolDocumentation->name);
        }
        sort($toolNames);
        $expectedToolNames = self::TOOL_NAMES;
        sort($expectedToolNames);
        self::assertSame($expectedToolNames, $toolNames);
        self::assertSame('agent.memory', $packageDocumentation->name);
    }

    public function testTheSchemaCarriesNoUnparsedGenericArrayType(): void
    {
        $serializedDocumentation = json_encode(MCPPackageDocumentation::createFromClassName(AgentMemoriesMCPPackage::class)->toObject(forPersistence: false));

        self::assertIsString($serializedDocumentation);
        self::assertStringNotContainsString('array<', $serializedDocumentation);
    }

    public function testEveryWriteDeclaresThatItWritesMemoryOnly(): void
    {
        foreach (self::WRITE_TOOL_NAMES as $writeToolName) {
            $attributes = (new ReflectionMethod(AgentMemoriesMCPPackage::class, $writeToolName))->getAttributes(MCPToolWritesAgentMemoryOnly::class);
            self::assertCount(1, $attributes, $writeToolName);
        }
    }

    public function testThePackagesBindingOffersTheToolsUnderAgentMemory(): void
    {
        $packageBinding = require dirname(__DIR__, 4) . '/config/app/AI/skillPackages.php';

        self::assertSame([AgentMemoriesMCPPackage::class], $packageBinding[AISkill::AGENT_MEMORY] ?? null);
        self::assertFileExists(dirname(__DIR__, 4) . '/config/app/AI/Skills/Agent/Memory.md');
    }
}
