<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Installation;

use Symfony\Component\Console\Attribute\AsCommand;

/** Stands for an application's subclass of a package command with a name of its own: a second command. */
#[AsCommand(name: 'fixture:renamed-application-command')]
class CommandNameOwnerRenamedApplicationCommandFixture extends CommandNameOwnerPackageCommandFixture
{
}
