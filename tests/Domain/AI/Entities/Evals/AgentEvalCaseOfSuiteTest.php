<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Evals\AgentEvalCase;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteTask;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * What a suite reads from a case: the label of its expected tool, the expected preload per owner turn, the expected
 * skill of a selection case, its declared access, exclusivity and lane, and the access and resource it is planned with.
 */
class AgentEvalCaseOfSuiteTest extends UnitTestCase
{
    public function testTheExpectedToolLabelAndPreloadSkills(): void
    {
        self::assertSame('getNotes', AgentEvalCase::fromArray(['expect' => ['tool' => 'getNotes']])->getExpectedToolLabel());
        self::assertSame('getNotes|listNotes', AgentEvalCase::fromArray(['expect' => ['tool' => ['getNotes', 'listNotes']]])->getExpectedToolLabel());
        self::assertSame('?', AgentEvalCase::fromArray([])->getExpectedToolLabel());

        self::assertSame(['Notes.A', '', 'none'], AgentEvalCase::fromArray(['expect' => ['preloadSkills' => ['Notes.A', '', 'none'], 'skill' => 'Notes.B']])->getExpectedPreloadSkillNamesByTurn());
        self::assertSame(['Notes.B'], AgentEvalCase::fromArray(['expect' => ['skill' => 'Notes.B']])->getExpectedPreloadSkillNamesByTurn(), 'the expected skill for the first turn only');
        self::assertSame([], AgentEvalCase::fromArray([])->getExpectedPreloadSkillNamesByTurn());
    }

    public function testTheKeysASuitePlansACaseBy(): void
    {
        $agentEvalCase = AgentEvalCase::fromArray(['access' => ' Exclusive', 'exclusive' => true, 'lane' => ' openingHours ', 'expectedSkill' => 'Notes.A']);

        self::assertSame('exclusive', $agentEvalCase->getDeclaredSuiteAccess());
        self::assertTrue($agentEvalCase->isDeclaredExclusive());
        self::assertSame('openingHours', $agentEvalCase->getDeclaredLane());
        self::assertSame('Notes.A', $agentEvalCase->getExpectedSkillName());
        self::assertFalse(AgentEvalCase::fromArray(['exclusive' => 'yes'])->isDeclaredExclusive(), 'only true counts');
        self::assertSame('', AgentEvalCase::fromArray(['access' => 'none'])->getDeclaredSuiteAccess());
        self::assertNull(AgentEvalCase::fromArray([])->getExpectedSkillName());
    }

    public function testAccessAndResourceOfACase(): void
    {
        $loopToolNames = ['load_skills', 'update_task_plan'];

        self::assertSame(AgentEvalSuiteTask::ACCESS_READ, AgentEvalCase::fromArray(['expect' => ['tool' => 'load_skills']])->resolveSuiteAccess(true, $loopToolNames));
        self::assertSame(AgentEvalSuiteTask::ACCESS_EXCLUSIVE, AgentEvalCase::fromArray(['sequential' => true])->resolveSuiteAccess(true, $loopToolNames), 'a writing case of an exclusive fixture');
        self::assertSame(AgentEvalSuiteTask::ACCESS_WRITE, AgentEvalCase::fromArray(['sequential' => true])->resolveSuiteAccess(false, $loopToolNames));
        self::assertSame('group', AgentEvalCase::fromArray([])->resolveResourceKey('Notes.A', ['Notes.A' => 'group']));
        self::assertSame('Notes.B', AgentEvalCase::fromArray([])->resolveResourceKey('Notes.B', ['Notes.A' => 'group']));
        self::assertSame(['id' => 'a', 'lane' => 'x'], AgentEvalCase::fromArray(['id' => 'a', 'lane' => 'x'])->toArray(), 'the case keeps its keys');
    }
}
