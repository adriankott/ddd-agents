<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Installation;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

/** Stands for a command that took a package command's name without extending it. */
#[AsCommand(name: 'fixture:foreign-command')]
class CommandNameOwnerForeignCommandFixture extends Command
{
}
