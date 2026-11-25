<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Installation;

use DDD\Domain\AI\Entities\Installation\AgentInstallationFindings;
use DDD\Domain\AI\Services\Installation\AgentInstallationCheckService;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;

/**
 * Runs `consoleCommands` over one fixture package command ({@see CommandNameOwnerPackageCommandFixture}) in a console
 * the test builds, without source roots: the console lists the command's name lazily and its factory throws, as a
 * command with a missing constructor argument does.
 */
class AgentInstallationCheckWithFixturePackageCommandDouble extends AgentInstallationCheckService
{
    /** A console that lists the fixture command's name and alias and cannot build the command. */
    public function buildConsoleListingAnUnbuildableCommand(): Application
    {
        $consoleApplication = new Application();
        $consoleApplication->setAutoExit(false);
        $consoleApplication->addCommand(new LazyCommand(
            'fixture:package-command',
            ['fixture:package-command-alias'],
            'A package command that cannot be built',
            false,
            $this->buildCommandOrThrow(...)
        ));
        return $consoleApplication;
    }

    /** The factory of the lazily listed command: fails as a command with a missing constructor argument does. */
    public function buildCommandOrThrow(): Command
    {
        throw new RuntimeException("Cannot autowire service \"fixture:package-command\": argument \"\$missing\" has no value.\nSecond line of the reason.");
    }

    /** The `consoleCommands` findings of the fixture command in the given console. */
    public function findConsoleCommandFindings(Application $consoleApplication): AgentInstallationFindings
    {
        $agentInstallationFindings = AgentInstallationFindings::newInstance();
        $this->consoleApplication = $consoleApplication;
        try {
            $this->addConsoleCommandFindings($agentInstallationFindings);
        } finally {
            $this->consoleApplication = null;
        }
        return $agentInstallationFindings;
    }

    /** @return array<string, string[]> */
    protected function findPackageCommandNamesByCommandClass(): array
    {
        return [CommandNameOwnerPackageCommandFixture::class => ['fixture:package-command', 'fixture:package-command-alias']];
    }

    /**
     * @param string[] $packageCommandClassNames
     * @return array<string, string[]>
     */
    protected function findApplicationCommandClassesTakingPackageCommandNames(array $packageCommandClassNames): array
    {
        return [];
    }
}
