<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Decisions\SiteOutcomes;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentTurnStartDecisionsInFlight;
use DDD\Domain\AI\Services\Conversations\Agent\AgentTurnStartDecisionBatchService;

/** The turn-start decisions as recorded turn-head steps: sent, advanced, applied; keeps the run the loop handed over. */
class TurnHeadDecisionBatchServiceDouble extends AgentTurnStartDecisionBatchService
{
    /** The run the loop handed the turn start. */
    public ?AgentRun $agentRun = null;

    public function __construct(public DecisionSiteWorldLog $worldLog)
    {
    }

    public function sendTurnStartDecisions(AgentRun $agentRun, AIConversation $conversation, ?string $runToken = null): ?AgentTurnStartDecisionsInFlight
    {
        $this->agentRun = $agentRun;
        $this->worldLog->record('turn head: turn-start decisions sent');
        return null;
    }

    public function advanceTurnStartDecisions(?AgentTurnStartDecisionsInFlight $agentTurnStartDecisionsInFlight): void
    {
        $this->worldLog->record('turn head: turn-start decisions advanced');
    }

    public function applyTurnStartDecisions(?AgentTurnStartDecisionsInFlight $agentTurnStartDecisionsInFlight): void
    {
        $this->worldLog->record('turn head: turn-start decisions applied');
    }

    public function receiveTurnStartDecisionsNotApplied(?AgentTurnStartDecisionsInFlight $agentTurnStartDecisionsInFlight): void
    {
        $this->worldLog->record('turn head: turn-start decisions not applied are received');
    }
}
