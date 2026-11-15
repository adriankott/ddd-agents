<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Services\Conversations\Agent\AIConversationOwnerReportDeliveryService;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\AIConversationRunClaimsService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Symfony\Commands\Agents\AgentDeliverOwedReportsAndResumeOwedTurnsCommand;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The recovery cron with its services handed in, and an application pass that records its turn and adds one count to
 * the summary line.
 */
class AgentDeliverOwedReportsAndResumeOwedTurnsProbeCommand extends AgentDeliverOwedReportsAndResumeOwedTurnsCommand
{
    /** @var string The name of the probe; its own, so its lock never meets the real command's */
    public const string PROBE_COMMAND_NAME = 'app:test:deliver-owed-probe';

    public const string APPLICATION_COUNT_PHRASE = '2 widgets repaired';

    public function __construct(
        protected RecoveryPassLog $recoveryPassLog,
        protected OwedRunsConversationsServiceDouble $conversationsServiceDouble,
        protected OwedRunsAgentServiceDouble $agentServiceDouble,
        protected OwedRunsOwnerReportDeliveryServiceDouble $ownerReportDeliveryServiceDouble,
        protected OwedRunsRunClaimsServiceDouble $runClaimsServiceDouble,
        protected OwedRunsMessagesServiceDouble $messagesServiceDouble
    ) {
        parent::__construct(self::PROBE_COMMAND_NAME);
    }

    protected function runApplicationRecoveryPasses(int $batchLimit, bool $dryRun, AIConversationsService $aIConversationsService, OutputInterface $output): void
    {
        $this->recoveryPassLog->record("applicationPasses:{$batchLimit}:" . ($dryRun ? 'dry' : 'real'));
    }

    protected function describeApplicationRecoveryPassCounts(): array
    {
        return [self::APPLICATION_COUNT_PHRASE];
    }

    protected function getAIConversationsService(): AIConversationsService
    {
        return $this->conversationsServiceDouble;
    }

    protected function getAIConversationAgentService(): AIConversationAgentService
    {
        return $this->agentServiceDouble;
    }

    protected function getAIConversationOwnerReportDeliveryService(): AIConversationOwnerReportDeliveryService
    {
        return $this->ownerReportDeliveryServiceDouble;
    }

    protected function getAIConversationRunClaimsService(): AIConversationRunClaimsService
    {
        return $this->runClaimsServiceDouble;
    }

    protected function getAIConversationMessagesService(): AIConversationMessagesService
    {
        return $this->messagesServiceDouble;
    }
}
