<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Decisions\SiteOutcomes;

use DDD\Domain\AI\Services\Conversations\Agent\AgentPlanDynamicsDecisionsService;
use DDD\Domain\AI\Services\Conversations\Agent\AgentReasoningEffortDecisionsService;
use DDD\Domain\AI\Services\Conversations\Agent\AgentReplyLanguageDecisionsService;
use DDD\Domain\AI\Services\Conversations\Agent\AgentSkillPreloadDecisionsService;
use DDD\Domain\AI\Services\Conversations\Agent\AgentTurnStartDecisionBatchService;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationOwnerMessageScreeningService;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationPurposeRunsService;

/**
 * The turn-start batch service run as it is (the input order, the structural effort application, the one batch behind
 * the decision model of the test, the apply order, the request events) over the site doubles of this folder.
 */
class TurnStartDecisionBatchServiceOverSiteDoubles extends AgentTurnStartDecisionBatchService
{
    public function __construct(
        public ReplyLanguageServiceWithScriptedInputDouble $replyLanguageServiceDouble,
        public SkillPreloadServiceWithScriptedInputsDouble $skillPreloadServiceDouble,
        public ReasoningEffortServiceWithScriptedReadsDouble $reasoningEffortServiceDouble,
        public PlanDynamicsServiceWithScriptedInputDouble $planDynamicsServiceDouble,
        public OwnerMessageScreeningServiceRecordingDouble $ownerMessageScreeningServiceDouble,
        public PurposeRunsServiceWithScriptedAnswersDouble $purposeRunsServiceDouble
    ) {
    }

    protected function replyLanguageDecisionsService(): AgentReplyLanguageDecisionsService
    {
        return $this->replyLanguageServiceDouble;
    }

    protected function skillPreloadDecisionsService(): AgentSkillPreloadDecisionsService
    {
        return $this->skillPreloadServiceDouble;
    }

    protected function reasoningEffortDecisionsService(): AgentReasoningEffortDecisionsService
    {
        return $this->reasoningEffortServiceDouble;
    }

    protected function planDynamicsDecisionsService(): AgentPlanDynamicsDecisionsService
    {
        return $this->planDynamicsServiceDouble;
    }

    protected function ownerMessageScreeningService(): AIConversationOwnerMessageScreeningService
    {
        return $this->ownerMessageScreeningServiceDouble;
    }

    protected function purposeRunsService(): AIConversationPurposeRunsService
    {
        return $this->purposeRunsServiceDouble;
    }
}
