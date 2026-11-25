<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Skills;

use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Services\Skills\AISkillFilesLintService;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * `subSkillOf` in a sub-skill's own frontmatter: the skill is a sub-skill, and a registered root lists it in its
 * `subSkillNames` beside its own entries; a root that is not registered gets nothing, and the lint reports a key that
 * names the skill itself, a root without a file or a contradicting `isSubSkill: false`.
 */
class SubSkillOfFrontmatterTest extends TestCase
{
    protected mixed $bindingBefore = null;

    protected function setUp(): void
    {
        parent::setUp();
        $bindingProperty = new ReflectionProperty(AISkill::class, 'packageClassesBySkillName');
        $this->bindingBefore = $bindingProperty->getValue();
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(AISkill::class, 'packageClassesBySkillName'))->setValue(null, $this->bindingBefore);
        parent::tearDown();
    }

    /** @param string[] $registeredSkillNames */
    protected function registerSkills(array $registeredSkillNames): void
    {
        (new ReflectionProperty(AISkill::class, 'packageClassesBySkillName'))->setValue(null, array_fill_keys($registeredSkillNames, []));
    }

    protected function buildSkillsService(): AISkillsServiceWithRawSkillFilesDouble
    {
        return new AISkillsServiceWithRawSkillFilesDouble([
            'Research.Web' => "---\ndescription: root\nsubSkillNames: [Research.Notes]\n---\nRoot body",
            'Research.Deep' => "---\ndescription: deep\nsubSkillOf: 'Research.Web'\n---\nDeep body",
            'Research.Sources' => "---\ndescription: sources\nsubSkillOf: [Research.Web, Other.Root]\n---\nSources body",
            'Research.Notes' => "---\ndescription: notes\nisSubSkill: true\n---\nNotes body",
        ]);
    }

    public function testASubSkillNamingARegisteredRootIsAttachedToIt(): void
    {
        $this->registerSkills(['Research.Web', 'Research.Deep', 'Research.Sources', 'Research.Notes']);
        $aISkillsService = $this->buildSkillsService();

        $rootSkill = $aISkillsService->getAISkillByName('Research.Web');
        self::assertSame(['Research.Notes', 'Research.Deep', 'Research.Sources'], $rootSkill->subSkillNames);
        self::assertFalse($rootSkill->isSubSkill);

        $subSkill = $aISkillsService->getAISkillByName('Research.Deep');
        self::assertTrue($subSkill->isSubSkill);
        self::assertSame(['Research.Web'], $subSkill->subSkillOf);
        self::assertSame(['Research.Web', 'Other.Root'], $aISkillsService->getAISkillByName('Research.Sources')->subSkillOf);
    }

    public function testNothingIsAttachedWhenTheRootOrTheSubSkillIsNotRegistered(): void
    {
        $this->registerSkills(['Research.Web', 'Research.Notes']);
        self::assertSame(['Research.Notes'], $this->buildSkillsService()->getAISkillByName('Research.Web')->subSkillNames);

        $this->registerSkills(['Research.Deep']);
        $aISkillsService = $this->buildSkillsService();
        self::assertSame([], $aISkillsService->getSubSkillNamesByRootName());
        self::assertTrue($aISkillsService->getAISkillByName('Research.Deep')->isSubSkill);
    }

    public function testTheLintReportsASelfReferenceAMissingRootAndAContradictingMarker(): void
    {
        $this->registerSkills(['Research.Web']);
        $aISkillsService = $this->buildSkillsService();
        $aISkillFilesLintService = new AISkillFilesLintService();

        self::assertSame([], $aISkillFilesLintService->findSubSkillOfProblems('Deep.md', 'Research.Deep', ['subSkillOf' => 'Research.Web'], $aISkillsService));
        $selfProblems = $aISkillFilesLintService->findSubSkillOfProblems('Deep.md', 'Research.Deep', ['subSkillOf' => 'Research.Deep'], $aISkillsService);
        self::assertCount(1, $selfProblems);
        self::assertStringContainsString('names the skill itself', $selfProblems[0]);
        $missingRootProblems = $aISkillFilesLintService->findSubSkillOfProblems('Deep.md', 'Research.Deep', ['subSkillOf' => ['No.Such']], $aISkillsService);
        self::assertStringContainsString("root 'No.Such' resolves to no skill file", $missingRootProblems[0]);
        $markerProblems = $aISkillFilesLintService->findSubSkillOfProblems('Deep.md', 'Research.Deep', ['subSkillOf' => 'Research.Web', 'isSubSkill' => false], $aISkillsService);
        self::assertStringContainsString("'isSubSkill: false'", $markerProblems[0]);
        self::assertNotSame([], $aISkillFilesLintService->findSubSkillOfProblems('Deep.md', 'Research.Deep', ['subSkillOf' => 7], $aISkillsService));
    }
}
