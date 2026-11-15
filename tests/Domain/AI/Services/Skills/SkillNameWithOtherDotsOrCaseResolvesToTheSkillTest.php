<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Skills;

use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A skill name the model wrote with a dot too many or in another case names the ONE skill it equals once the dots and
 * the case are ignored; a name that matches no skill, or more than one, names none. The names that name no skill are
 * what makes a `load_skills` call one that named an unknown skill.
 */
class SkillNameWithOtherDotsOrCaseResolvesToTheSkillTest extends UnitTestCase
{
    public function testANameDifferingOnlyInDotsOrCaseResolvesToTheOneSkill(): void
    {
        $aISkillsService = new AISkillsServiceWithFixedSkillNames(['Posts.Series', 'Reviews']);

        self::assertSame('Posts.Series', $aISkillsService->resolveSkillNameIgnoringDotsAndCase('Posts.Series'));
        self::assertSame('Posts.Series', $aISkillsService->resolveSkillNameIgnoringDotsAndCase('Posts.Series.'));
        self::assertSame('Posts.Series', $aISkillsService->resolveSkillNameIgnoringDotsAndCase('Posts.Se.ries'));
        self::assertSame('Posts.Series', $aISkillsService->resolveSkillNameIgnoringDotsAndCase('posts.series'));
        self::assertNull($aISkillsService->resolveSkillNameIgnoringDotsAndCase('Posts.Serie'));
        self::assertNull($aISkillsService->resolveSkillNameIgnoringDotsAndCase('...'));
    }

    public function testANameMatchingTwoSkillsResolvesToNone(): void
    {
        $aISkillsService = new AISkillsServiceWithFixedSkillNames(['Ab.Cd', 'A.BCd']);

        self::assertNull($aISkillsService->resolveSkillNameIgnoringDotsAndCase('abcd'));
        self::assertSame('Ab.Cd', $aISkillsService->resolveSkillNameIgnoringDotsAndCase('Ab.Cd'), 'an exact name is never ambiguous');
    }

    public function testTheNamesThatAreNoSkillAreTheOnesThatResolveToNone(): void
    {
        $aISkillsService = new AISkillsServiceWithFixedSkillNames(['Posts.Series', 'Reviews']);

        self::assertSame(['Ratings', '5'], $aISkillsService->findSkillNamesThatAreNoSkill(['Posts.Series', 'posts.series.', 'Ratings', 5]));
        self::assertSame([], $aISkillsService->findSkillNamesThatAreNoSkill([]));
    }
}
