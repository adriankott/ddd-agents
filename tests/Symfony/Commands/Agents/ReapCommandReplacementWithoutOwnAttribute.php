<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Symfony\Commands\Agents\AgentReapStuckConversationsCommand;

/** An application's replacement of a package command that declares no `#[AsCommand]` of its own. */
class ReapCommandReplacementWithoutOwnAttribute extends AgentReapStuckConversationsCommand
{
}
