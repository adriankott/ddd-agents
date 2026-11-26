<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\AI;

use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRights;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackageDocumentation;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolOwnerFacingSubject;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolParallelSafe;
use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Services\Skills\AISkillFilesLintService;
use DDD\Domain\AI\Services\Skills\AISkillsService;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Mcp\Client\Agents\AgentGoalBlueprintMCPPackage;
use DDD\Presentation\Mcp\Client\Agents\AgentGoalsMCPPackage;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use ReflectionMethod;

/**
 * The goal tools and their skill as the package ships them: the two packages expose four described tools (the tree
 * in a package of its own, offered only with a provider), reads are parallel-safe, writes name the owner-facing
 * subject, both packages require `useAgentGoals` (an everyday right), the default binding offers both under
 * `Agent.Goals`, and the skill file passes the skill lint, requires the packages' right, names only tools and arguments
 * of the packages and names the agent only through the persona placeholder.
 */
class AgentGoalsSkillAndSchemaTest extends IntegrationTestCase
{
    /** @var array<string, array<string, string>> The tools of each package class, with their effect */
    public const array TOOL_EFFECTS_BY_PACKAGE_CLASS = [
        AgentGoalsMCPPackage::class => ['getGoals' => MCPToolEffect::READ, 'addGoal' => MCPToolEffect::WRITE, 'updateGoalStatus' => MCPToolEffect::WRITE],
        AgentGoalBlueprintMCPPackage::class => ['getGoalBlueprint' => MCPToolEffect::READ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        if (Config::getEnv('LAZYLOAD_DEFAULT_REPO_TYPE') === null) {
            LazyLoadRepo::setDafaultRepoType(LazyLoadRepo::DB);
        }
    }

    public static function getSkillsDirectory(): string
    {
        return dirname(__DIR__, 4) . '/config/app/AI/Skills';
    }

    public static function getSkillFile(): string
    {
        return static::getSkillsDirectory() . '/' . str_replace('.', '/', AISkill::AGENT_GOALS) . '.md';
    }

    public function testTheSchemaBuildsAndExposesTheFourDescribedTools(): void
    {
        $packageNamesByClass = [];
        foreach (static::TOOL_EFFECTS_BY_PACKAGE_CLASS as $packageClass => $toolEffects) {
            $packageDocumentation = MCPPackageDocumentation::createFromClassName($packageClass);
            $packageNamesByClass[$packageClass] = $packageDocumentation->name;
            $toolNames = [];
            foreach ($packageDocumentation->functions as $toolDocumentation) {
                $toolNames[] = $toolDocumentation->name;
                self::assertNotSame('', trim((string)$toolDocumentation->description), $toolDocumentation->name);
            }
            sort($toolNames);
            $expectedToolNames = array_keys($toolEffects);
            sort($expectedToolNames);
            self::assertSame($expectedToolNames, $toolNames);
            self::assertStringNotContainsString('array<', (string)json_encode($packageDocumentation->toObject(forPersistence: false)));
        }

        self::assertSame([AgentGoalsMCPPackage::class => 'agent.goals', AgentGoalBlueprintMCPPackage::class => 'agent.goals.blueprint'], $packageNamesByClass);
    }

    public function testReadsAreParallelSafeAndWritesNameTheOwnerFacingSubject(): void
    {
        foreach (static::TOOL_EFFECTS_BY_PACKAGE_CLASS as $packageClass => $toolEffects) {
            foreach ($toolEffects as $toolName => $expectedEffect) {
                $toolMethod = new ReflectionMethod($packageClass, $toolName);
                $effectAttributes = $toolMethod->getAttributes(MCPToolEffect::class);
                self::assertCount(1, $effectAttributes, $toolName);
                self::assertSame($expectedEffect, $effectAttributes[0]->newInstance()->effect, $toolName);
                $isRead = $expectedEffect === MCPToolEffect::READ;
                self::assertSame($isRead, $toolMethod->getAttributes(MCPToolParallelSafe::class) !== [], $toolName . ' parallel-safe');
                self::assertSame(!$isRead, $toolMethod->getAttributes(MCPToolOwnerFacingSubject::class) !== [], $toolName . ' owner-facing subject');
            }
        }
    }

    public function testBothPackagesRequireTheEverydayGoalRight(): void
    {
        self::assertSame([AgentAccountRights::USE_AGENT_GOALS], MCPPackage::findRequiredAccountRightNamesOfClass(AgentGoalsMCPPackage::class));
        self::assertSame([AgentAccountRights::USE_AGENT_GOALS], MCPPackage::findRequiredAccountRightNamesOfClass(AgentGoalBlueprintMCPPackage::class));
        self::assertContains(AgentAccountRights::USE_AGENT_GOALS, AgentAccountRights::EVERYDAY_RIGHTS);
    }

    public function testThePackagesBindingOffersBothPackagesUnderAgentGoals(): void
    {
        $packageBinding = require dirname(__DIR__, 4) . '/config/app/AI/skillPackages.php';

        self::assertSame('Agent.Goals', AISkill::AGENT_GOALS);
        self::assertSame([AgentGoalsMCPPackage::class, AgentGoalBlueprintMCPPackage::class], $packageBinding[AISkill::AGENT_GOALS] ?? null);
        self::assertFileExists(static::getSkillFile());
        self::assertSame(['enabled' => false], require dirname(__DIR__, 4) . '/config/app/AI/agentGoals.php', 'goals are off until a host switches them on');
    }

    public function testTheSkillFileParsesPassesTheLintAndRequiresThePackagesRight(): void
    {
        $skillFrontmatterAndBody = AISkillsService::splitFrontmatter((string)file_get_contents(static::getSkillFile()));
        /** @var AISkillFilesLintService $aISkillFilesLintService */
        $aISkillFilesLintService = DDDService::instance()->getService(AISkillFilesLintService::class);

        self::assertSame(['requiredAccountRights', 'decisionCriteria', 'shortDescription', 'description'], array_keys($skillFrontmatterAndBody->frontmatter));
        self::assertSame(MCPPackage::findRequiredAccountRightNamesOfClass(AgentGoalsMCPPackage::class), $skillFrontmatterAndBody->frontmatter['requiredAccountRights']);
        self::assertStringStartsWith('# The owner\'s goals', trim($skillFrontmatterAndBody->body));
        self::assertSame([], $aISkillFilesLintService->findSkillFileProblems([static::getSkillsDirectory()]));
        self::assertSame([], $aISkillFilesLintService->findSkillNamingProblems([static::getSkillsDirectory()]));
    }

    public function testTheSkillNamesOnlyToolsAndArgumentsOfThePackagesAndTheAgentOnlyThroughThePersona(): void
    {
        $skillText = (string)file_get_contents(static::getSkillFile());
        $packageToolNames = [];
        $packageArgumentNames = [];
        foreach (array_keys(static::TOOL_EFFECTS_BY_PACKAGE_CLASS) as $packageClass) {
            foreach (MCPPackageDocumentation::createFromClassName($packageClass)->functions as $toolDocumentation) {
                $packageToolNames[] = $toolDocumentation->name;
                $inputSchema = json_decode((string)json_encode($toolDocumentation->inputSchema->toObject(forPersistence: false)), true);
                array_push($packageArgumentNames, ...array_keys($inputSchema['properties'] ?? []));
            }
        }

        foreach ($packageToolNames as $toolName) {
            self::assertStringContainsString('`' . $toolName . '`', $skillText, $toolName);
        }
        preg_match_all('/`([a-z][a-z0-9]*[A-Z][A-Za-z0-9]*)/', $skillText, $backtickedIdentifierMatches);
        self::assertSame([], array_values(array_diff(array_unique($backtickedIdentifierMatches[1]), $packageToolNames, $packageArgumentNames)));
        preg_match_all('/\{%[A-Z_]+%\}/', $skillText, $placeholderMatches);
        self::assertSame([AISkillsService::PLACEHOLDER_AGENT_NAME], array_values(array_unique($placeholderMatches[0])));
    }
}
