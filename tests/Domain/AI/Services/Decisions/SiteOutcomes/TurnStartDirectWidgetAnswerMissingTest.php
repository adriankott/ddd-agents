<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Decisions\SiteOutcomes;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDirectWidgetDecision;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDirectWidgetDecisionQuestion;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentPlanDynamicsDecision;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetClasses;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentTurnStartDecision;

/**
 * The folded turn-start request carries the plan-dynamics and the direct-widget site, and the answer leaves out only
 * `direct_widget`. The batch (the one request, the breaker rule, the apply order) runs behind the decision model of the
 * test: the plan answer is applied as the model's, the direct widget is stamped `fallback:unparsable` and decides
 * nothing, and the answer still closes the breaker — one usable site is a parsable answer; a request whose every site
 * went unanswered closes nothing. The rule on the batch result alone is `AgentTurnStartBatchResultTest`'s; this test
 * pins what the two sites write.
 */
class TurnStartDirectWidgetAnswerMissingTest extends DecisionSiteOutcomeTestCase
{
    public function testOnlyTheWidgetAnswerIsMissingTheOtherSiteStandsAndTheBreakerCloses(): void
    {
        $this->decisionModelDouble->omittedQuestionNames = [ArgusAgentTurnStartDecision::QUESTION_DIRECT_WIDGET];
        $conversation = $this->ownerTurnConversation();

        $this->runTurnStart($conversation);

        $agentWorkingState = $conversation->getAgentWorkingState();
        self::assertNotNull($agentWorkingState);
        self::assertNotNull($agentWorkingState->planDynamicsDecision);
        self::assertNotNull($agentWorkingState->directWidgetDecision);
        self::assertSame(AgentPlanDynamicsDecision::DECIDED_BY_DECISION_MODEL, $agentWorkingState->planDynamicsDecision->decidedBy, 'the plan answer is applied');
        self::assertSame(AgentDirectWidgetDecision::FALLBACK_UNPARSABLE, $agentWorkingState->directWidgetDecision->decidedBy);
        self::assertSame(2, $agentWorkingState->directWidgetDecision->ownerMessageIndex, 'the owner turn is decided: a chunk resume does not ask again');
        self::assertNull($conversation->getDecidedDirectWidgetTypeOfCurrentTurn());
        self::assertSame(['request primary usable', 'breaker close'], array_values(array_filter($this->worldLog->lines, static fn (string $line): bool => str_starts_with($line, 'request ') || str_starts_with($line, 'breaker '))), 'one usable site closes the breaker');
    }

    public function testAnAnswerWithoutAnyUsableSiteClosesNothing(): void
    {
        $this->decisionModelDouble->omittedQuestionNames = [ArgusAgentTurnStartDecision::QUESTION_DIRECT_WIDGET, 'needs_working_plan'];
        $conversation = $this->ownerTurnConversation();

        $this->runTurnStart($conversation);

        self::assertSame(AgentDirectWidgetDecision::FALLBACK_UNPARSABLE, $conversation->getAgentWorkingState()?->directWidgetDecision?->decidedBy);
        self::assertSame(AgentPlanDynamicsDecision::FALLBACK_UNPARSABLE, $conversation->getAgentWorkingState()?->planDynamicsDecision?->decidedBy);
        self::assertSame(['request primary usable'], array_values(array_filter($this->worldLog->lines, static fn (string $line): bool => str_starts_with($line, 'request ') || str_starts_with($line, 'breaker '))), 'no breaker request');
    }

    protected function runTurnStart(AIConversation $conversation): void
    {
        $turnStartBatchService = $this->turnStartBatchService(['planDynamics']);
        $directWidgetServiceDouble = new DirectWidgetServiceWithScriptedInputDouble();
        $directWidgetQuestion = new AgentDirectWidgetDecisionQuestion();
        $directWidgetQuestion->mode = AgentDecisionModelSettings::MODE_ON;
        $directWidgetQuestion->initiatorMessageIndex = 2;
        $directWidgetQuestion->ownerMessage = self::OWNER_MESSAGE;
        $directWidgetQuestion->assistantTextBefore = self::ASSISTANT_TEXT_BEFORE;
        $directWidgetQuestion->widgetWhenLines = ConversationWidgetClasses::getDirectWidgetWhenLinesByWidgetType();
        $directWidgetServiceDouble->scriptedInput = $directWidgetQuestion;
        $turnStartBatchServiceWithDirectWidget = new TurnStartDecisionBatchServiceWithDirectWidgetDouble(
            $turnStartBatchService->replyLanguageServiceDouble,
            $turnStartBatchService->skillPreloadServiceDouble,
            $turnStartBatchService->reasoningEffortServiceDouble,
            $turnStartBatchService->planDynamicsServiceDouble,
            $turnStartBatchService->ownerMessageScreeningServiceDouble,
            $turnStartBatchService->purposeRunsServiceDouble
        );
        $turnStartBatchServiceWithDirectWidget->directWidgetServiceDouble = $directWidgetServiceDouble;
        $turnStartBatchServiceWithDirectWidget->decideTurnStart(AgentRun::forTurnEntry(), $conversation, null);
    }
}
