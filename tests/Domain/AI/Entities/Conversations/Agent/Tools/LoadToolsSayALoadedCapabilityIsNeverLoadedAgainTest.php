<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\AI\Entities\Conversations\Agent\Tools\LoadSkillsTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\LoadToolTool;
use DDD\Domain\AI\Entities\MCPPackages\ToolDefinitions;
use DDD\Domain\AI\Entities\Skills\AISkills;
use PHPUnit\Framework\TestCase;

/**
 * The descriptions of `load_skills` and `load_tool` say that a loaded skill or tool is never loaded again (they said
 * "callable again at any step", which models read as an invitation to load again), and a later repeated `load_skills`
 * of a turn — followed by the step without tools — is answered with the loaded skills and their tools, never with the
 * generic text about a plan.
 */
class LoadToolsSayALoadedCapabilityIsNeverLoadedAgainTest extends TestCase
{
    public function testTheLoadSkillsDescriptionSaysALoadedSkillIsNeverLoadedAgain(): void
    {
        self::assertSame(
            'Load one or more skills to gain their tools — pick from the "Available skills" list in the system prompt or from '
                . 'the sub-skills a loaded skill lists. Call when the request needs capabilities not yet loaded. A skill already '
                . 'loaded is never loaded again — its tools are in your tool list: call them.',
            LoadSkillsTool::forCatalog(new AISkills())->toOpenAiToolSchema()['function']['description']
        );
    }

    public function testTheLoadToolDescriptionSaysALoadedToolIsNeverLoadedAgain(): void
    {
        $description = (string)LoadToolTool::forAvailableTools(new ToolDefinitions())->toOpenAiToolSchema()['function']['description'];

        self::assertStringStartsWith(
            'Load one or more tools from a skill you already have, to gain their full parameters. Call when a task needs a tool '
                . 'listed below that is not yet available. A tool already in your tool list is never loaded again — call it. '
                . "Available tools:\n",
            $description
        );
        self::assertStringNotContainsString('callable again', $description);
    }

    public function testALaterRepeatedSkillLoadBeforeTheStepWithoutToolsNamesTheLoadedTools(): void
    {
        self::assertSame(
            'NOT executed: you have called `load_skills` with these exact arguments 4 times and it loads nothing more. Loaded right '
                . 'now: Media.Images — their tools are ALREADY in your tool list (generateImage). Your next step has no tools: write '
                . 'your answer now from what this conversation already holds. If the request needed one of these tools and you did '
                . 'not call it, say plainly that this part is not done yet — never that you lack access or that it is unavailable.',
            LoadSkillsTool::buildResultOfRepeatedCallBeforeAStepWithoutTools(4, ['Media.Images'], ['generateImage'])
        );
        self::assertNull(LoadSkillsTool::buildResultOfRepeatedCallBeforeAStepWithoutTools(4, [], []), 'no loaded skill: the repeat guard\'s own text applies');
        self::assertNull(LoadSkillsTool::buildResultOfRepeatedCallBeforeAStepWithoutTools(4, ['Media.Images'], []), 'no callable tool: the repeat guard\'s own text applies');
    }
}
