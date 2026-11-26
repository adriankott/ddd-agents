<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Skills;

use DDD\Domain\AI\Services\Skills\AISkillFilesLintService;
use PHPUnit\Framework\TestCase;

/**
 * The skill-file lint over the package's own root (clean), and what it reports for a broken file: invalid YAML (an
 * unescaped inner double quote), an unknown key, a list gate that is no list, an unresolvable sub-skill, a missing
 * purpose class.
 */
class AISkillFilesLintServiceTest extends TestCase
{
    protected string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryDirectory = sys_get_temp_dir() . '/ddd-agents-skill-lint-' . bin2hex(random_bytes(4));
        mkdir($this->temporaryDirectory . '/Skills/Broken', 0777, true);
        mkdir($this->temporaryDirectory . '/Purposes/Broken', 0777, true);
        file_put_contents($this->temporaryDirectory . '/Skills/Broken/Yaml.md', "---\ndescription: \"a \"quoted\" word\"\n---\nbody");
        file_put_contents($this->temporaryDirectory . '/Skills/Broken/Keys.md', "---\ndescription: d\nrequiredFeatureFlag: [X]\nrequiredFeatureFlags: X\nturnMinimumSteps: many\nsubSkillNames: [No.Such.Skill]\n---\nbody");
        file_put_contents($this->temporaryDirectory . '/Purposes/Broken/Purpose.md', "---\ndescription: d\n---\nbody");
    }

    protected function tearDown(): void
    {
        foreach (['/Skills/Broken/Yaml.md', '/Skills/Broken/Keys.md', '/Purposes/Broken/Purpose.md'] as $relativeFile) {
            @unlink($this->temporaryDirectory . $relativeFile);
        }
        foreach (['/Skills/Broken', '/Skills', '/Purposes/Broken', '/Purposes', ''] as $relativeDirectory) {
            @rmdir($this->temporaryDirectory . $relativeDirectory);
        }
        parent::tearDown();
    }

    public function testThePackagesOwnSkillAndPurposeFilesAreClean(): void
    {
        $packageConfigDirectory = dirname(__DIR__, 5) . '/config/app/AI';
        $aISkillFilesLintService = new AISkillFilesLintService();
        self::assertSame([], $aISkillFilesLintService->findSkillFileProblems([$packageConfigDirectory . '/Skills']));
        self::assertSame([], $aISkillFilesLintService->findPurposeFileProblems([$packageConfigDirectory . '/Purposes']));
    }

    public function testThePackagesOwnSkillNamesFollowTheNamingRules(): void
    {
        $packageConfigDirectory = dirname(__DIR__, 5) . '/config/app/AI';
        self::assertSame([], (new AISkillFilesLintService())->findSkillNamingProblems([$packageConfigDirectory . '/Skills']));
    }

    public function testASkillNameIsTopicAndFeatureInUpperCamelCaseWithoutARepeatedTopicOrAnAnd(): void
    {
        self::assertSame([], AISkillFilesLintService::findSkillNameProblems('Agent.Web.Search'));
        self::assertSame([], AISkillFilesLintService::findSkillNameProblems('Agent.UI'));
        self::assertNotSame([], AISkillFilesLintService::findSkillNameProblems('Agent.Web.Search.Deep'));
        self::assertNotSame([], AISkillFilesLintService::findSkillNameProblems('Accounts.Accounts'));
        self::assertNotSame([], AISkillFilesLintService::findSkillNameProblems('System.WorkersAndCrons'));
        self::assertNotSame([], AISkillFilesLintService::findSkillNameProblems('admin.screen'));
        self::assertSame([], AISkillFilesLintService::findSkillNameProblems('Devices.Android'));
    }

    public function testAPackageIdCarriesItsProviderAndTheWordsOfItsOnlySkill(): void
    {
        self::assertSame([], AISkillFilesLintService::findPackageIdProblems('agent.web.search', null, 'Agent.Web.Search'));
        self::assertSame([], AISkillFilesLintService::findPackageIdProblems('agent.ui', null, 'Agent.UI'));
        self::assertSame([], AISkillFilesLintService::findPackageIdProblems('ddd.system.health', null, 'System.Health'));
        self::assertSame([], AISkillFilesLintService::findPackageIdProblems('rb.challenges.campaigns', 'rb', 'Challenges.Campaigns'));
        self::assertSame([], AISkillFilesLintService::findPackageIdProblems('agent.tasks', null, 'Agent.Tasks'));
        // a further package of the same skill adds one feature word
        self::assertSame([], AISkillFilesLintService::findPackageIdProblems('agent.goals.blueprint', null, 'Agent.Goals'));
        self::assertSame([], AISkillFilesLintService::findPackageIdProblems('agent.ui.window', null, 'Agent.UI'));
        self::assertSame([], AISkillFilesLintService::findPackageIdProblems('rb.challenges.campaigns.reports', 'rb', 'Challenges.Campaigns'));
        self::assertNotSame([], AISkillFilesLintService::findPackageIdProblems('agent.goalsBlueprint', null, 'Agent.Goals'));
        self::assertNotSame([], AISkillFilesLintService::findPackageIdProblems('admin.challenges.campaigns', null, null));
        self::assertNotSame([], AISkillFilesLintService::findPackageIdProblems('rc.challenges.campaigns', 'rb', null));
        self::assertNotSame([], AISkillFilesLintService::findPackageIdProblems('agent.memories', null, 'Agent.Memory'));
        self::assertNotSame([], AISkillFilesLintService::findPackageIdProblems('Agent.Memory', null, null));
    }

    public function testASkillFileBesideAFolderOfTheSameNameIsReported(): void
    {
        mkdir($this->temporaryDirectory . '/NamingSkills/Website/Build', 0777, true);
        file_put_contents($this->temporaryDirectory . '/NamingSkills/Website/Build.md', "---\ndescription: x\n---\nBody\n");
        file_put_contents($this->temporaryDirectory . '/NamingSkills/Website/Build/Orchestrate.md', "---\ndescription: x\n---\nBody\n");
        $problems = (new AISkillFilesLintService())->findSkillNamingProblems([$this->temporaryDirectory . '/NamingSkills']);
        @unlink($this->temporaryDirectory . '/NamingSkills/Website/Build/Orchestrate.md');
        @unlink($this->temporaryDirectory . '/NamingSkills/Website/Build.md');
        @rmdir($this->temporaryDirectory . '/NamingSkills/Website/Build');
        @rmdir($this->temporaryDirectory . '/NamingSkills/Website');
        @rmdir($this->temporaryDirectory . '/NamingSkills');
        $besideFolderProblems = [];
        foreach ($problems as $problem) {
            if (str_contains($problem, 'lies beside the folder')) {
                $besideFolderProblems[] = $problem;
            }
        }
        self::assertCount(1, $besideFolderProblems);
    }

    public function testABrokenSkillFileIsReportedWithItsCause(): void
    {
        $problems = (new AISkillFilesLintService())->findSkillFileProblems([$this->temporaryDirectory . '/Skills']);
        $problemsText = implode("\n", $problems);
        self::assertStringContainsString('Yaml.md: the frontmatter is no valid YAML', $problemsText);
        self::assertStringContainsString("Keys.md: unknown frontmatter key 'requiredFeatureFlag'", $problemsText);
        self::assertStringContainsString("Keys.md: 'requiredFeatureFlags' is not a list of strings", $problemsText);
        self::assertStringContainsString("Keys.md: 'turnMinimumSteps' is not a positive whole number", $problemsText);
        self::assertStringContainsString("Keys.md: sub-skill 'No.Such.Skill' resolves to no skill file", $problemsText);
        self::assertCount(5, $problems);
    }

    public function testAPurposeFileWithoutAPurposeClassIsReported(): void
    {
        $problems = (new AISkillFilesLintService())->findPurposeFileProblems([$this->temporaryDirectory . '/Purposes']);
        self::assertCount(1, $problems);
        self::assertStringContainsString('Purpose.md: no valid purposeClass', $problems[0]);
    }

    public function testOnlyAListOfStringsIsAList(): void
    {
        $aISkillFilesLintService = new AISkillFilesLintService();
        self::assertTrue($aISkillFilesLintService->isListOfStrings(['A', 'B']));
        self::assertTrue($aISkillFilesLintService->isListOfStrings([]));
        self::assertFalse($aISkillFilesLintService->isListOfStrings('A'));
        self::assertFalse($aISkillFilesLintService->isListOfStrings(['a' => 'A']));
        self::assertFalse($aISkillFilesLintService->isListOfStrings([1]));
    }
}
