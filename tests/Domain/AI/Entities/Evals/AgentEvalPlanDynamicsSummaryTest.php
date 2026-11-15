<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionRecord;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentPlanDynamicsDecision;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlans;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversations;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Evals\AgentEvalArms;
use DDD\Domain\AI\Entities\Evals\AgentEvalPlanDynamicsSummary;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The plan-dynamics summary of a run: the first owner turn's band from the decision log, the retirement from the
 * record or from the working state, the plan calls the chat model made over the conversation and its delegated
 * conversations (refused calls left out), the open plan at the end, and the verdict against `expectPlan`.
 */
class AgentEvalPlanDynamicsSummaryTest extends UnitTestCase
{
    public function testARunWithPlanCallsOnADirectCaseIsAPlanOnDirect(): void
    {
        $summary = AgentEvalPlanDynamicsSummary::fromConversation($this->conversationWithPlanCalls(), 'direct', AgentEvalArms::PLAN_DYNAMICS_ARM_OBSERVE);

        self::assertSame($this->expectedSummaryWithPlanCalls(), $summary->toArray());
    }

    public function testTheVerdictFollowsTheExpectation(): void
    {
        $verdictsByExpectation = [];
        foreach (['direct', 'required', 'either', 'continue'] as $expectPlan) {
            $verdictsByExpectation[$expectPlan] = [
                AgentEvalPlanDynamicsSummary::fromConversation($this->conversationWithPlanCalls(), $expectPlan, null)->toArray()['verdict'],
                AgentEvalPlanDynamicsSummary::fromConversation($this->conversationWithoutPlan(), $expectPlan, null)->toArray()['verdict'],
            ];
        }
        $verdictsByExpectation['none'] = [
            AgentEvalPlanDynamicsSummary::fromConversation($this->conversationWithPlanCalls(), null, null)->toArray()['verdict'],
            AgentEvalPlanDynamicsSummary::fromConversation($this->conversationWithoutPlan(), null, null)->toArray()['verdict'],
        ];

        self::assertSame($this->expectedVerdictsByExpectation(), $verdictsByExpectation);
    }

    public function testARunWithoutWorkingStateOrPlanCallsReportsEmptyFigures(): void
    {
        $summary = AgentEvalPlanDynamicsSummary::fromConversation($this->conversationWithoutPlan(), 'required', AgentEvalArms::PLAN_DYNAMICS_ARM_GUIDE);

        self::assertSame($this->expectedSummaryWithoutPlan(), $summary->toArray());
    }

    /**
     * Plan calls on the root and in a delegated conversation, refused and failed calls among them; the decision log
     * with two turn-start records and a retirement record; an open active plan and one finished plan; the working
     * state's plan-dynamics decision retired a finished plan.
     */
    protected function conversationWithPlanCalls(): AIConversation
    {
        $childConversation = $this->conversation([
            $this->ownerText(),
            $this->toolCall('set_task_status'),
        ]);
        $delegateCall = $this->toolCall('delegate');
        $childConversations = new AIConversations();
        $childConversations->add($childConversation);
        $delegateCall->delegatedConversations = $childConversations;
        $refusedAsDuplicate = $this->toolCall('set_task_status');
        $refusedAsDuplicate->toolCall->wasRefusedAsDuplicate = true;
        $refusedByGate = $this->toolCall('update_task_plan');
        $refusedByGate->toolCall->wasRefusedByGate = true;
        $conversation = $this->conversation([
            $this->ownerText(),
            $this->toolCall('update_task_plan'),
            $this->toolCall('set_task_status'),
            $this->toolCall('set_task_status'),
            $refusedAsDuplicate,
            $refusedByGate,
            $this->toolCall('update_task_plan', AIConversationMessage::STATUS_FAILED),
            $delegateCall,
            $this->ownerText(),
            $this->toolCall('listReports'),
        ]);
        $agentWorkingState = new AgentWorkingState();
        $agentWorkingState->decisions = [
            $this->decisionRecord(AgentDecisionModelSettings::SITE_PLAN_DYNAMICS, 0, 'decisionModel', true, 310, ['subject' => 'turnStart', 'band' => 'direct', 'needsWorkingPlanProbability' => 0.2]),
            $this->decisionRecord(AgentDecisionModelSettings::SITE_PLAN_DYNAMICS, 8, 'decisionModel', true, 290, ['subject' => 'turnStart', 'band' => 'plan', 'needsWorkingPlanProbability' => 0.8]),
            $this->decisionRecord(AgentDecisionModelSettings::SITE_PLAN_DYNAMICS, 8, 'decisionModel', false, 120, ['subject' => 'retirement', 'retiredFinishedPlan' => false, 'wouldRetireFinishedPlan' => true]),
            $this->decisionRecord(AgentDecisionModelSettings::SITE_PLAN_DYNAMICS, 8, 'decisionModel', false, 100, null),
            $this->decisionRecord(AgentDecisionModelSettings::SITE_SKILL_PRELOAD, 0, 'decisionModel', true, 310, ['band' => 'ignored']),
        ];
        $agentWorkingState->taskPlan = $this->plan([['text' => 'Answer the open questions', 'status' => 'open']]);
        $agentWorkingState->completedTaskPlans = new TaskPlans();
        $finishedTaskPlan = $this->plan([['text' => 'Collect the open questions', 'status' => 'done']]);
        $agentWorkingState->completedTaskPlans->add($finishedTaskPlan);
        $planDynamicsDecision = new AgentPlanDynamicsDecision();
        $planDynamicsDecision->retiredFinishedPlan = true;
        $agentWorkingState->planDynamicsDecision = $planDynamicsDecision;
        $conversation->agentWorkingState = $agentWorkingState;
        return $conversation;
    }

    /** One owner turn answered without a plan call and without a working state. */
    protected function conversationWithoutPlan(): AIConversation
    {
        return $this->conversation([
            $this->ownerText(),
            $this->toolCall('listReports'),
        ]);
    }

    /** @return array<string, mixed> the expected summary of this conversation */
    protected function expectedSummaryWithPlanCalls(): array
    {
        return [
            'arm' => 'observe',
            'expectPlan' => 'direct',
            'band' => 'direct',
            'needsWorkingPlanProbability' => 0.2,
            'turnStartDecidedBy' => 'decisionModel',
            'retiredFinishedPlan' => true,
            'wouldRetireFinishedPlan' => true,
            'plansCreated' => 1,
            'ticks' => 3,
            'planCalls' => 4,
            'endedWithOpenPlan' => true,
            'finishedPlans' => 1,
            'verdict' => 'planOnDirect',
        ];
    }

    /** @return array<string, mixed> the expected summary of this conversation */
    protected function expectedSummaryWithoutPlan(): array
    {
        return [
            'arm' => 'guide',
            'expectPlan' => 'required',
            'band' => null,
            'needsWorkingPlanProbability' => null,
            'turnStartDecidedBy' => null,
            'retiredFinishedPlan' => false,
            'wouldRetireFinishedPlan' => false,
            'plansCreated' => 0,
            'ticks' => 0,
            'planCalls' => 0,
            'endedWithOpenPlan' => false,
            'finishedPlans' => 0,
            'verdict' => 'missingOnRequired',
        ];
    }

    /** @return array<string, string[]> the expected verdicts with and without plan calls, by expectation */
    protected function expectedVerdictsByExpectation(): array
    {
        return [
            'direct' => ['planOnDirect', 'ok'],
            'required' => ['ok', 'missingOnRequired'],
            'either' => ['notJudged', 'notJudged'],
            'continue' => ['notJudged', 'notJudged'],
            'none' => ['notJudged', 'notJudged'],
        ];
    }

    /** @param array<int, array<string, mixed>> $items */
    protected function plan(array $items): TaskPlan
    {
        $taskPlan = new TaskPlan();
        $taskPlan->mapFromRepository(['items' => $items]);
        return $taskPlan;
    }

    /** @param AIConversationMessage[] $messageList */
    protected function conversation(array $messageList): AIConversation
    {
        $conversation = AIConversation::newInstance();
        $conversation->messages = new AIConversationMessages();
        foreach ($messageList as $index => $message) {
            $message->indexInConversation = $index;
            $conversation->messages->add($message);
        }
        return $conversation;
    }

    protected function ownerText(): AIConversationMessage
    {
        $message = new AIConversationMessage();
        $message->role = AIConversationMessage::ROLE_USER;
        $message->authorType = AIConversationMessage::AUTHOR_USER;
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->message = 'Please help';
        return $message;
    }

    protected function toolCall(string $toolName, string $status = AIConversationMessage::STATUS_COMPLETED): AIConversationMessage
    {
        $message = new AIConversationMessage();
        $message->role = AIConversationMessage::ROLE_ASSISTANT;
        $message->authorType = AIConversationMessage::AUTHOR_ASSISTANT;
        $message->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        $message->message = '';
        $toolCall = new ToolCall();
        $toolCall->name = $toolName;
        $toolCall->arguments = [];
        $toolCall->status = $status;
        $message->toolCall = $toolCall;
        return $message;
    }

    /** @param array<string, mixed>|null $outcome */
    protected function decisionRecord(string $site, int $ownerMessageIndex, string $decidedBy, bool $applied, int $elapsedMs, ?array $outcome): AgentDecisionRecord
    {
        $decisionRecord = new AgentDecisionRecord();
        $decisionRecord->site = $site;
        $decisionRecord->ownerMessageIndex = $ownerMessageIndex;
        $decisionRecord->decidedBy = $decidedBy;
        $decisionRecord->applied = $applied;
        $decisionRecord->elapsedMs = $elapsedMs;
        $decisionRecord->outcome = $outcome;
        return $decisionRecord;
    }
}
