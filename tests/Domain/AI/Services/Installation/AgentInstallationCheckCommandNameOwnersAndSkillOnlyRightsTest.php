<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Installation;

use DDD\Domain\AI\Entities\Installation\AgentInstallationFinding;
use DDD\Domain\AI\Services\Installation\AgentInstallationCheckService;
use PHPUnit\Framework\TestCase;

/**
 * Two rules of the installation check that need no installation to be shown: which class runs under the names of one
 * package command (`consoleCommands`), and which rights of a skill no tool requires (`skillOnlyRights`).
 */
class AgentInstallationCheckCommandNameOwnersAndSkillOnlyRightsTest extends TestCase
{
    public function testEveryNameRunningThePackageCommandIsNoFinding(): void
    {
        $findings = (new AgentInstallationCheckExposingCommandNameOwnersDouble())->findCommandNameOwnerFindings(
            CommandNameOwnerPackageCommandFixture::class,
            ['fixture:package-command' => CommandNameOwnerPackageCommandFixture::class, 'fixture:package-command-alias' => CommandNameOwnerPackageCommandFixture::class]
        );

        self::assertCount(0, $findings->getElements());
    }

    public function testEveryNameRunningTheApplicationsSubclassIsNoFinding(): void
    {
        $findings = (new AgentInstallationCheckExposingCommandNameOwnersDouble())->findCommandNameOwnerFindings(
            CommandNameOwnerPackageCommandFixture::class,
            ['fixture:package-command' => CommandNameOwnerApplicationCommandFixture::class, 'fixture:package-command-alias' => CommandNameOwnerApplicationCommandFixture::class]
        );

        self::assertCount(0, $findings->getElements());
    }

    public function testNamesOfOnePackageCommandRunningDifferentClassesAreOneFindingNamingBoth(): void
    {
        $findings = (new AgentInstallationCheckExposingCommandNameOwnersDouble())->findCommandNameOwnerFindings(
            CommandNameOwnerPackageCommandFixture::class,
            ['fixture:package-command' => CommandNameOwnerPackageCommandFixture::class, 'fixture:package-command-alias' => CommandNameOwnerApplicationCommandFixture::class]
        )->getElements();

        self::assertCount(1, $findings);
        self::assertSame(AgentInstallationCheckService::CHECK_CONSOLE_COMMANDS, $findings[0]->checkName);
        self::assertSame(AgentInstallationFinding::SEVERITY_WARNING, $findings[0]->severity);
        self::assertSame(CommandNameOwnerPackageCommandFixture::class, $findings[0]->subject);
        self::assertStringContainsString('fixture:package-command → ' . CommandNameOwnerPackageCommandFixture::class, $findings[0]->message);
        self::assertStringContainsString('fixture:package-command-alias → ' . CommandNameOwnerApplicationCommandFixture::class, $findings[0]->message);
    }

    public function testANameRunningAClassThatIsNoSubclassOfThePackageCommandIsAFindingOfThatName(): void
    {
        $findings = (new AgentInstallationCheckExposingCommandNameOwnersDouble())->findCommandNameOwnerFindings(
            CommandNameOwnerPackageCommandFixture::class,
            ['fixture:package-command' => CommandNameOwnerForeignCommandFixture::class]
        )->getElements();

        self::assertCount(1, $findings);
        self::assertSame('fixture:package-command', $findings[0]->subject);
        self::assertStringContainsString('is no subclass of it', $findings[0]->message);
    }

    public function testAnApplicationCommandWithoutANameOfItsOwnTakesTheNamesOfTheNearestPackageCommand(): void
    {
        $agentInstallationCheckService = new AgentInstallationCheckService();
        $packageCommandClassNames = [CommandNameOwnerPackageCommandFixture::class];

        self::assertSame(
            CommandNameOwnerPackageCommandFixture::class,
            $agentInstallationCheckService->findPackageCommandWhoseNamesAClassTakes(CommandNameOwnerApplicationCommandFixture::class, $packageCommandClassNames)
        );
        // a name of its own: a second command; an abstract base: no command; below a renamed command: that command's name
        self::assertNull($agentInstallationCheckService->findPackageCommandWhoseNamesAClassTakes(CommandNameOwnerRenamedApplicationCommandFixture::class, $packageCommandClassNames));
        self::assertNull($agentInstallationCheckService->findPackageCommandWhoseNamesAClassTakes(CommandNameOwnerAbstractApplicationCommandFixture::class, $packageCommandClassNames));
        self::assertNull($agentInstallationCheckService->findPackageCommandWhoseNamesAClassTakes(CommandNameOwnerSubclassOfRenamedApplicationCommandFixture::class, $packageCommandClassNames));
        self::assertNull($agentInstallationCheckService->findPackageCommandWhoseNamesAClassTakes(CommandNameOwnerForeignCommandFixture::class, $packageCommandClassNames));
        self::assertNull($agentInstallationCheckService->findPackageCommandWhoseNamesAClassTakes(CommandNameOwnerPackageCommandFixture::class, $packageCommandClassNames));
    }

    public function testAnApplicationCommandThatTakesTheNamesAndRunsUnderNoneOfThemIsOneFindingOfThatCommand(): void
    {
        $findings = (new AgentInstallationCheckExposingCommandNameOwnersDouble())->findCommandNameOwnerFindings(
            CommandNameOwnerPackageCommandFixture::class,
            ['fixture:package-command' => CommandNameOwnerPackageCommandFixture::class, 'fixture:package-command-alias' => CommandNameOwnerPackageCommandFixture::class],
            [CommandNameOwnerApplicationCommandFixture::class]
        )->getElements();

        self::assertCount(1, $findings);
        self::assertSame(AgentInstallationCheckService::CHECK_CONSOLE_COMMANDS, $findings[0]->checkName);
        self::assertSame(CommandNameOwnerApplicationCommandFixture::class, $findings[0]->subject);
        self::assertStringContainsString('fixture:package-command → ' . CommandNameOwnerPackageCommandFixture::class, $findings[0]->message);
        self::assertStringContainsString('fixture:package-command-alias → ' . CommandNameOwnerPackageCommandFixture::class, $findings[0]->message);
    }

    public function testAnApplicationCommandThatRunsUnderOnlySomeOfTheNamesIsOneFindingNamingTheOthers(): void
    {
        $findings = (new AgentInstallationCheckExposingCommandNameOwnersDouble())->findCommandNameOwnerFindings(
            CommandNameOwnerPackageCommandFixture::class,
            ['fixture:package-command' => CommandNameOwnerApplicationCommandFixture::class, 'fixture:package-command-alias' => CommandNameOwnerPackageCommandFixture::class],
            [CommandNameOwnerApplicationCommandFixture::class]
        )->getElements();

        self::assertCount(1, $findings);
        self::assertStringNotContainsString('fixture:package-command → ', $findings[0]->message);
        self::assertStringContainsString('fixture:package-command-alias → ' . CommandNameOwnerPackageCommandFixture::class, $findings[0]->message);
    }

    public function testAnApplicationCommandThatRunsUnderEveryNameIsNoFinding(): void
    {
        $findings = (new AgentInstallationCheckExposingCommandNameOwnersDouble())->findCommandNameOwnerFindings(
            CommandNameOwnerPackageCommandFixture::class,
            ['fixture:package-command' => CommandNameOwnerApplicationCommandFixture::class, 'fixture:package-command-alias' => CommandNameOwnerApplicationCommandFixture::class],
            [CommandNameOwnerApplicationCommandFixture::class]
        );

        self::assertCount(0, $findings->getElements());
    }

    public function testTwoApplicationCommandsThatTakeTheNamesOfOnePackageCommandAreOneFinding(): void
    {
        $findings = (new AgentInstallationCheckExposingCommandNameOwnersDouble())->findCommandNameOwnerFindings(
            CommandNameOwnerPackageCommandFixture::class,
            ['fixture:package-command' => CommandNameOwnerApplicationCommandFixture::class],
            [CommandNameOwnerApplicationCommandFixture::class, CommandNameOwnerSubclassOfRenamedApplicationCommandFixture::class]
        )->getElements();

        self::assertCount(1, $findings);
        self::assertSame(CommandNameOwnerPackageCommandFixture::class, $findings[0]->subject);
        self::assertStringContainsString('only one command can run under its names', $findings[0]->message);
    }

    public function testANameTheConsoleListsAndCannotBuildIsABlockingFindingWithTheFirstLineOfTheReason(): void
    {
        $agentInstallationCheckDouble = new AgentInstallationCheckWithFixturePackageCommandDouble();
        $findings = $agentInstallationCheckDouble->findConsoleCommandFindings(
            $agentInstallationCheckDouble->buildConsoleListingAnUnbuildableCommand()
        )->getElements();

        self::assertCount(2, $findings); // the name and the alias
        foreach ($findings as $finding) {
            self::assertSame(AgentInstallationCheckService::CHECK_CONSOLE_COMMANDS, $finding->checkName);
            self::assertSame(AgentInstallationFinding::SEVERITY_BLOCKING, $finding->severity);
            self::assertStringContainsString('cannot be built: Cannot autowire service', $finding->message);
            self::assertStringNotContainsString('Second line', $finding->message);
        }
        self::assertSame(['fixture:package-command', 'fixture:package-command-alias'], [$findings[0]->subject, $findings[1]->subject]);
    }

    public function testARightOfASkillThatNoToolRequiresIsNamedOnceInTheSkillsOrder(): void
    {
        $agentInstallationCheckService = new AgentInstallationCheckService();

        self::assertSame([], $agentInstallationCheckService->findRightNamesNoToolRequires(['manageTasks'], ['manageTasks', 'useWeb']));
        self::assertSame([], $agentInstallationCheckService->findRightNamesNoToolRequires([], ['manageTasks']));
        self::assertSame(
            ['manageTask', 'viewReports'],
            $agentInstallationCheckService->findRightNamesNoToolRequires(['manageTask', 'useWeb', 'viewReports', 'manageTask'], ['manageTasks', 'useWeb'])
        );
    }
}
