<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Installation;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

/** Stands for a command a package ships. */
#[AsCommand(name: 'fixture:package-command', aliases: ['fixture:package-command-alias'])]
class CommandNameOwnerPackageCommandFixture extends Command
{
}
