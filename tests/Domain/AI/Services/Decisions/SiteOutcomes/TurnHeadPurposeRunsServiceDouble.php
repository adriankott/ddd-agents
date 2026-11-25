<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Decisions\SiteOutcomes;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentTransientMessageIds;
use DDD\Presentation\Mcp\Agents\AgentConsoleStream;

/** The purpose steps of the turn head, recorded instead of run: the variants and the purpose's turn-start notice. */
class TurnHeadPurposeRunsServiceDouble extends PurposeRunsServiceWithScriptedAnswersDouble
{
    public function __construct(public DecisionSiteWorldLog $worldLog)
    {
    }

    public function dropDoneCriteriaWithoutClassFromStoredTaskPlan(AIConversation $conversation): void
    {
    }

    public function resolveAndPersistVariants(AIConversation $conversation, ?string $completedToolName): void
    {
        $this->worldLog->record('turn head: purpose variants resolved');
    }

    public function appendPurposeTurnStartNotice(
        AgentRun $agentRun,
        AIConversation $conversation,
        AgentTransientMessageIds $transientMessageIds,
        ?AgentConsoleStream $consoleStream,
        bool $emitRealtimeSignal
    ): void {
        $this->worldLog->record('turn head: purpose turn-start notice');
    }
}
