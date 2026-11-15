<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\Scorecards;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\Metrics\AIConversationMetricsService;
use DDD\Domain\AI\Services\Conversations\Quality\AgentConversationScorecardsService;
use DDD\Symfony\Commands\Agents\AIConversationScorecardsCommand;
use DDD\Tests\Agents\Symfony\Commands\Agents\RecordingUsageEnvelopesServiceDouble;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * The scorecard command with its services handed in instead of the container's (the methods it overrides are the
 * command's own accessors), no default account, and the dispatch recorded instead of sent.
 */
class AIConversationScorecardsProbeCommand extends AIConversationScorecardsCommand
{
    /** @var string The name of the probe */
    public const string PROBE_COMMAND_NAME = 'app:test:scorecards-probe';

    public ConversationsServiceWithScriptedConversationsByIdDouble $conversationsServiceDouble;

    public MetricsServiceWithScriptedMetricsDouble $metricsServiceDouble;

    public ScorecardsServiceRecordingCallsDouble $scorecardsServiceDouble;

    public RecordingUsageEnvelopesServiceDouble $usageEnvelopesServiceDouble;

    /** @var array<int, bool> The conversations dispatched to the worker, with the force flag, by id in order */
    public array $forceAnalysisByDispatchedConversationId = [];

    public function __construct(?TransportInterface $scorecardTransport = null)
    {
        parent::__construct($scorecardTransport);
        // a subclass without its own attribute has no name
        $this->setName(self::PROBE_COMMAND_NAME);
        $this->conversationsServiceDouble = new ConversationsServiceWithScriptedConversationsByIdDouble();
        $this->metricsServiceDouble = new MetricsServiceWithScriptedMetricsDouble();
        $this->scorecardsServiceDouble = new ScorecardsServiceRecordingCallsDouble();
        $this->usageEnvelopesServiceDouble = new RecordingUsageEnvelopesServiceDouble();
    }

    protected function usesDefaultAccountForConsoleWork(): bool
    {
        return false;
    }

    protected function dispatchScorecardMessage(AIConversation $conversation, bool $forceAnalysis): void
    {
        $this->forceAnalysisByDispatchedConversationId[(int)$conversation->id] = $forceAnalysis;
    }

    protected function getAIConversationsService(): AIConversationsService
    {
        return $this->conversationsServiceDouble;
    }

    protected function getMetricsService(): AIConversationMetricsService
    {
        return $this->metricsServiceDouble;
    }

    protected function getScorecardsService(): AgentConversationScorecardsService
    {
        return $this->scorecardsServiceDouble;
    }

    protected function getUsageEnvelopesService(): AIUsageEnvelopesService
    {
        return $this->usageEnvelopesServiceDouble;
    }
}
