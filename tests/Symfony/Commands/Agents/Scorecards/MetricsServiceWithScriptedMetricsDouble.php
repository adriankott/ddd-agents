<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\Scorecards;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Metrics\AIConversationMetrics;
use DDD\Domain\AI\Services\Conversations\Metrics\AIConversationMetricsService;

/** The metrics service answering with the metrics the test scripted for a conversation id (none otherwise); reads nothing. */
class MetricsServiceWithScriptedMetricsDouble extends AIConversationMetricsService
{
    /** @var array<int, AIConversationMetrics> The scripted metrics, by conversation id */
    public array $scriptedMetricsByConversationId = [];

    public function buildMetricsForConversation(AIConversation $conversation): ?AIConversationMetrics
    {
        return $this->scriptedMetricsByConversationId[(int)$conversation->id] ?? null;
    }
}
