<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;
use DDD\Symfony\Commands\Agents\AgentCloseSchedulesForInactiveAgentSpacesCommand;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * An application's state sweep whose cron rows call an older name: it declares the module's name and its own in one
 * attribute, and prints the scope of a schedule in its own words.
 */
#[AsCommand(
    name: 'app:ai-schedule:close-for-inactive-agent-spaces|app:ai-schedule:close-for-inactive-shops',
    description: 'Close the AI schedules of closed shops and accounts.',
)]
class ApplicationCloseSchedulesUnderBothNamesCommandDouble extends AgentCloseSchedulesForInactiveAgentSpacesCommand
{
    public const string OLD_COMMAND_NAME = 'app:ai-schedule:close-for-inactive-shops';

    /** @var StateSweepSchedulesServiceDouble|null The schedules service the test hands in */
    public ?StateSweepSchedulesServiceDouble $schedulesServiceDouble = null;

    protected function describeScheduleScope(AIConversationSchedule $schedule): string
    {
        return "account {$schedule->accountId}, shop {$schedule->agentSpaceId}";
    }

    protected function getAIConversationSchedulesService(): AIConversationSchedulesService
    {
        return $this->schedulesServiceDouble ?? parent::getAIConversationSchedulesService();
    }
}
