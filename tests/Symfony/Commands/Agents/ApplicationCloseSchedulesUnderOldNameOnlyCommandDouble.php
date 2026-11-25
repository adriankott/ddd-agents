<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Symfony\Commands\Agents\AgentCloseSchedulesForInactiveAgentSpacesCommand;
use Symfony\Component\Console\Attribute\AsCommand;

/** An application's state sweep that declares only its older name — as a subclass it still replaces the module's command and keeps the module's names. */
#[AsCommand(
    name: 'app:ai-schedule:close-for-inactive-shops',
    description: 'Close the AI schedules of closed shops and accounts.',
)]
class ApplicationCloseSchedulesUnderOldNameOnlyCommandDouble extends AgentCloseSchedulesForInactiveAgentSpacesCommand
{
}
