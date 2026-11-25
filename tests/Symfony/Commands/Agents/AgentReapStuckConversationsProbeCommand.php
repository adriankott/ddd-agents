<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationNotificationsService;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Domain\AI\Services\Conversations\AIConversationDelegationBatchesService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallsService;
use DDD\Symfony\Commands\Agents\AgentReapStuckConversationsCommand;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The reaper with its services handed in and its dispatch recorded instead of sent: the methods overridden are the
 * command's own accessors, its dispatch and the channel-session hook (which records its turn in the pass log, throws
 * when the test asks for it, and then runs the module's own sweep as an application's override does).
 */
class AgentReapStuckConversationsProbeCommand extends AgentReapStuckConversationsCommand
{
    /** @var string The name of the probe; its own, so it never meets the real command */
    public const string PROBE_COMMAND_NAME = 'app:test:reap-probe';

    public const string CHANNEL_SESSIONS_FAILURE_MESSAGE = 'the channel provider is down';

    public bool $channelSessionsSweepThrows = false;

    /** @var string[] The purposes the settle pass sweeps */
    public array $purposeNamesClosedWithoutAnOwnerTurn = [];

    /** @var AgentVoiceCallsService|null The voice calls service of the stale voice-call sweep; null = the container's */
    public ?AgentVoiceCallsService $agentVoiceCallsServiceDouble = null;

    public function __construct(
        protected RecoveryPassLog $recoveryPassLog,
        protected ReaperConversationsServiceDouble $conversationsServiceDouble,
        protected ReaperDelegationBatchesServiceDouble $delegationBatchesServiceDouble,
        protected ReaperSettlingAgentServiceDouble $agentServiceDouble,
        protected ReaperDelegationNotificationsServiceDouble $delegationNotificationsServiceDouble
    ) {
        parent::__construct(self::PROBE_COMMAND_NAME);
    }

    protected function closeStaleChannelSessions(AIConversationsService $aIConversationsService, OutputInterface $output): void
    {
        $this->recoveryPassLog->record('channel-sessions');
        if ($this->channelSessionsSweepThrows) {
            throw new RuntimeException(self::CHANNEL_SESSIONS_FAILURE_MESSAGE);
        }
        parent::closeStaleChannelSessions($aIConversationsService, $output);
    }

    protected function getAgentVoiceCallsService(): AgentVoiceCallsService
    {
        return $this->agentVoiceCallsServiceDouble ?? parent::getAgentVoiceCallsService();
    }

    protected function dispatchResumeOfConversation(int $conversationId): void
    {
        $this->recoveryPassLog->record("resume:{$conversationId}");
    }

    protected function getPurposeNamesClosedWithoutAnOwnerTurn(): array
    {
        return $this->purposeNamesClosedWithoutAnOwnerTurn;
    }

    protected function getAIConversationsService(): AIConversationsService
    {
        return $this->conversationsServiceDouble;
    }

    protected function getAIConversationDelegationBatchesService(): AIConversationDelegationBatchesService
    {
        return $this->delegationBatchesServiceDouble;
    }

    protected function getAIConversationAgentService(): AIConversationAgentService
    {
        return $this->agentServiceDouble;
    }

    protected function getAIConversationDelegationNotificationsService(): AIConversationDelegationNotificationsService
    {
        return $this->delegationNotificationsServiceDouble;
    }
}
