<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Decisions\SiteOutcomes;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentPlanDynamicsDecisionQuestion;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentTurnStartDecision;
use DDD\Domain\AI\Services\Conversations\Agent\AgentPlanDynamicsDecisionsService;

/**
 * The plan-dynamics site with its turn-start request INPUT given by the test and its application run as it is; every
 * turn-start call is a world line.
 */
class PlanDynamicsServiceWithScriptedInputDouble extends AgentPlanDynamicsDecisionsService
{
    /** The question the site contributes this turn (null = it does not run). */
    public ?AgentPlanDynamicsDecisionQuestion $scriptedInput = null;

    public function __construct(public DecisionSiteWorldLog $worldLog)
    {
    }

    public function buildRequestInput(AIConversation $conversation, AgentDecisionModelSettings $decisionModelSettings, bool $conversationWorksAutonomously, bool $conversationSeedsATaskPlan): ?AgentPlanDynamicsDecisionQuestion
    {
        $this->worldLog->record('site build planDynamics autonomous=' . json_encode($conversationWorksAutonomously) . ' seedsPlan=' . json_encode($conversationSeedsATaskPlan) . ' → ' . ($this->scriptedInput === null ? 'null' : 'input'));
        return $this->scriptedInput;
    }

    public function applyDecision(
        AIConversation $conversation,
        AgentPlanDynamicsDecisionQuestion $planDynamicsQuestion,
        ?ArgusAgentTurnStartDecision $argusDecision,
        ?string $requestDecidedBy,
        ?string $runToken,
        ?int $elapsedMs,
        ?string $transportFailureReason
    ): void {
        $this->worldLog->record('site apply planDynamics batchDecidedBy=' . ($requestDecidedBy ?? 'null') . ' primary=' . ($argusDecision === null ? 'none' : 'sent') . ' failure=' . ($transportFailureReason ?? 'null'));
        parent::applyDecision($conversation, $planDynamicsQuestion, $argusDecision, $requestDecidedBy, $runToken, $elapsedMs, $transportFailureReason);
    }
}
