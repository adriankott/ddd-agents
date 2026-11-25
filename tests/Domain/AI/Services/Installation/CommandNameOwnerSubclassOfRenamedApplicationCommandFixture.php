<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Installation;

/** Extends an application command that has a name of its own: it takes that name, not the package command's. */
class CommandNameOwnerSubclassOfRenamedApplicationCommandFixture extends CommandNameOwnerRenamedApplicationCommandFixture
{
}
