<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Symfony\Commands\Agents\AgentReapStuckConversationsCommand;
use Symfony\Component\Console\Attribute\AsCommand;

/** An application's replacement of a package command that declares a name of its own. */
#[AsCommand(name: 'host:agent:reap', description: 'The host\'s own reaper name')]
class ReapCommandReplacementWithOwnAttribute extends AgentReapStuckConversationsCommand
{
}
