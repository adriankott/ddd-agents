<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Decisions\SiteOutcomes;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Services\Conversations\Agent\AgentSkillPreloadDecisionsService;

/** The plan-item preload of a step (no model call) recorded instead of run. */
class TurnHeadSkillPreloadServiceDouble extends AgentSkillPreloadDecisionsService
{
    public function __construct(public DecisionSiteWorldLog $worldLog)
    {
    }

    public function admitSkillsForNextPlanItem(AIConversation $conversation, ?string $runToken, ?string $claimedRunToken, ?AgentRun $agentRun = null): void
    {
        $this->worldLog->record('turn head: plan-item skill preload (no model call)');
    }
}
