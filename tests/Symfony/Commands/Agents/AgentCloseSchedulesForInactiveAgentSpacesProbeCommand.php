<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;
use DDD\Symfony\Commands\Agents\AgentCloseSchedulesForInactiveAgentSpacesCommand;

/** The state sweep with a schedules service handed in instead of the container's. */
class AgentCloseSchedulesForInactiveAgentSpacesProbeCommand extends AgentCloseSchedulesForInactiveAgentSpacesCommand
{
    /** @var string The name of the probe; its own, so its lock never meets the real command's */
    public const string PROBE_COMMAND_NAME = 'app:test:close-schedules-probe';

    public function __construct(protected AIConversationSchedulesService $schedulesServiceDouble)
    {
        parent::__construct(self::PROBE_COMMAND_NAME);
    }

    protected function getAIConversationSchedulesService(): AIConversationSchedulesService
    {
        return $this->schedulesServiceDouble;
    }
}
