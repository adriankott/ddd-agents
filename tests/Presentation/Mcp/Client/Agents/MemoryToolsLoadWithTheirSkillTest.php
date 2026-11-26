<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\Client\Agents;

use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilities;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapability;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilityOrigin;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedSkillToolDefinitions;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackageDocumentation;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolAlwaysLoad;
use DDD\Domain\AI\Entities\MCPPackages\ToolDefinitions;
use DDD\Domain\AI\Entities\Skills\RequestedAISkills;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Infrastructure\Libs\Config;
use DDD\Presentation\Mcp\Client\Agents\AgentMandatesMCPPackage;
use DDD\Presentation\Mcp\Client\Agents\AgentMemoriesMCPPackage;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;

/**
 * The memory tools load with their skill: the package class carries `MCPToolAlwaysLoad`, so every one of its tools
 * reports always-load and a skill load provisions them even when the conversation loads tools lazily (a tool loads on
 * first use). A package without the attribute keeps its tools lazy. Among the packages the package binds to its skills,
 * the memory tools are the only ones that carry the attribute on the class.
 */
class MemoryToolsLoadWithTheirSkillTest extends UnitTestCase
{
    /** @var string[] The tools of {@see AgentMemoriesMCPPackage} */
    public const array MEMORY_TOOL_NAMES = ['searchMemory', 'listMemories', 'remember', 'updateMemory', 'forget'];

    protected function setUp(): void
    {
        parent::setUp();
        if (Config::getEnv('LAZYLOAD_DEFAULT_REPO_TYPE') === null) {
            LazyLoadRepo::setDafaultRepoType(LazyLoadRepo::DB);
        }
    }

    public function testEveryMemoryToolReportsAlwaysLoad(): void
    {
        $alwaysLoadByToolName = [];
        foreach (MCPPackageDocumentation::createFromClassName(AgentMemoriesMCPPackage::class)->functions as $mcpTool) {
            $alwaysLoadByToolName[$mcpTool->name] = $mcpTool->isAlwaysLoad;
        }
        $toolDefinitionAlwaysLoadByToolName = [];
        foreach (ToolDefinitions::forPackages([AgentMemoriesMCPPackage::class])->getElements() as $toolDefinition) {
            $toolDefinitionAlwaysLoadByToolName[$toolDefinition->name] = $toolDefinition->isAlwaysLoad;
        }

        self::assertSame(array_fill_keys(self::MEMORY_TOOL_NAMES, true), $alwaysLoadByToolName);
        self::assertSame(array_fill_keys(self::MEMORY_TOOL_NAMES, true), $toolDefinitionAlwaysLoadByToolName);
    }

    public function testAToolOfAPackageWithoutTheAttributeDoesNotReportAlwaysLoad(): void
    {
        $alwaysLoadOfMandateTools = [];
        foreach (ToolDefinitions::forPackages([AgentMandatesMCPPackage::class])->getElements() as $toolDefinition) {
            $alwaysLoadOfMandateTools[] = $toolDefinition->isAlwaysLoad;
        }

        self::assertNotSame([], $alwaysLoadOfMandateTools);
        self::assertSame([false], array_values(array_unique($alwaysLoadOfMandateTools)));
    }

    public function testALazySkillLoadProvisionsTheMemoryToolsAndLeavesTheOtherToolsAvailable(): void
    {
        $requestedAISkills = $this->buildRequestOfMemoryAndMandatesSkills();
        $loadedSkillToolDefinitions = LoadedSkillToolDefinitions::fromRequestedSkills($requestedAISkills, null);

        $lazilyLoadedCapabilities = (new LoadedCapabilities())->withSkillsPromoted($requestedAISkills, $loadedSkillToolDefinitions, false, LoadedCapabilityOrigin::atTurn(0, 0));
        $eagerlyLoadedCapabilities = (new LoadedCapabilities())->withSkillsPromoted($requestedAISkills, $loadedSkillToolDefinitions, true, LoadedCapabilityOrigin::atTurn(0, 0));

        self::assertSame(self::MEMORY_TOOL_NAMES, $this->findToolNamesOf($lazilyLoadedCapabilities));
        $eagerlyLoadedToolNames = $this->findToolNamesOf($eagerlyLoadedCapabilities);
        self::assertSame(self::MEMORY_TOOL_NAMES, array_slice($eagerlyLoadedToolNames, 0, 5));
        self::assertGreaterThan(5, count($eagerlyLoadedToolNames), 'eager loading also brings the tools of the mandates skill');
    }

    public function testOnlyTheMemoryPackageOfTheBoundPackagesCarriesTheAttributeOnItsClass(): void
    {
        $packageClassesBySkillName = require dirname(__DIR__, 5) . '/config/app/AI/skillPackages.php';
        $packageClassesWithTheAttribute = [];
        foreach ($packageClassesBySkillName as $packageClasses) {
            foreach ($packageClasses as $packageClass) {
                if (!is_subclass_of($packageClass, MCPPackage::class)) {
                    continue;
                }
                if (MCPPackage::findClassAttributesOfPackageHierarchy(new ReflectionClass($packageClass), MCPToolAlwaysLoad::class) !== []) {
                    $packageClassesWithTheAttribute[$packageClass] = true;
                }
            }
        }

        self::assertSame([AgentMemoriesMCPPackage::class], array_keys($packageClassesWithTheAttribute));
    }

    protected function buildRequestOfMemoryAndMandatesSkills(): RequestedAISkills
    {
        $memorySkill = new AISkillOfPackagesDouble();
        $memorySkill->name = 'Agent.Memory';
        $memorySkill->packageClasses = [AgentMemoriesMCPPackage::class];
        $mandatesSkill = new AISkillOfPackagesDouble();
        $mandatesSkill->name = 'Agent.Mandates';
        $mandatesSkill->packageClasses = [AgentMandatesMCPPackage::class];
        $requestedAISkills = new RequestedAISkills();
        $requestedAISkills->requestedSkillNames = ['Agent.Memory', 'Agent.Mandates'];
        $requestedAISkills->addResolvedSkill($memorySkill);
        $requestedAISkills->addResolvedSkill($mandatesSkill);
        return $requestedAISkills;
    }

    /** @return string[] the names of the loaded tools, in load order */
    protected function findToolNamesOf(LoadedCapabilities $loadedCapabilities): array
    {
        $toolNames = [];
        foreach ($loadedCapabilities->getElements() as $loadedCapability) {
            if ($loadedCapability->type === LoadedCapability::TYPE_TOOL) {
                $toolNames[] = $loadedCapability->name;
            }
        }
        return $toolNames;
    }
}
