<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Decisions\SiteOutcomes;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Presentation\Mcp\Agents\AgentConsoleStream;

/** The message steps of the turn head: the speculative cleanup recorded instead of run; no binding to resolve. */
class TurnHeadMessagesServiceDouble extends AIConversationMessagesService
{
    public function __construct(public DecisionSiteWorldLog $worldLog)
    {
    }

    public function cleanupUnusedSpeculativeCapabilitiesOfRun(AgentRun $agentRun, AIConversation $conversation, ?AgentConsoleStream $consoleStream = null, bool $emitRealtimeSignal = false): void
    {
        $this->worldLog->record('turn head: speculative capability cleanup');
    }

    public function resolvePendingComposedViewBindingsAtTurnBoundary(AIConversation $conversation): void
    {
    }
}
