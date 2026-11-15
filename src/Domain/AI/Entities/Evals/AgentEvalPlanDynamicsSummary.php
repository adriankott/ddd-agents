<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEvent;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SetTaskStatusTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\UpdateTaskPlanTool;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The plan-dynamics summary of one evaluated run: what the decision model answered (the first owner turn's band and
 * probability, the retirement of a finished plan) beside what the CHAT MODEL did (plans created, ticks, whether the
 * run ended with an open plan), and the verdict against the case's `expectPlan` (`direct` = a plan is a defect,
 * `required` = a missing plan is a defect, any other value = never judged). Read off the decision log, the working
 * state and the messages of the conversation and its delegated conversations. No I/O.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalPlanDynamicsSummary extends ValueObject
{
    /** @var string `expectPlan`: the case is to be answered without a plan */
    public const string EXPECT_PLAN_DIRECT = 'direct';

    /** @var string `expectPlan`: the case needs a plan */
    public const string EXPECT_PLAN_REQUIRED = 'required';

    /** @var string Verdict: the expectation holds */
    public const string VERDICT_OK = 'ok';

    /** @var string Verdict: a plan was created on a case to be answered directly */
    public const string VERDICT_PLAN_ON_DIRECT = 'planOnDirect';

    /** @var string Verdict: no plan was created on a case that needs one */
    public const string VERDICT_MISSING_ON_REQUIRED = 'missingOnRequired';

    /** @var string Verdict: the case's `expectPlan` is not judged */
    public const string VERDICT_NOT_JUDGED = 'notJudged';

    /** @var string|null The plan-dynamics arm of the run ({@see AgentEvalArms::$planDynamicsArm}); null = the platform default */
    public ?string $arm = null;

    /** @var string|null The case's `expectPlan` */
    public ?string $expectPlan = null;

    /** @var mixed The band the decision model gave the first owner turn; null without a turn-start decision */
    public mixed $band = null;

    /** @var mixed The probability the first owner turn needs a working plan; null without a turn-start decision */
    public mixed $needsWorkingPlanProbability = null;

    /** @var string|null Who decided the first owner turn's band */
    public ?string $turnStartDecidedBy = null;

    /** @var bool A finished plan was retired (by the recorded retirement or by the plan-dynamics decision of the working state) */
    public bool $retiredFinishedPlan = false;

    /** @var bool A finished plan would have been retired (by the recorded retirement or by the plan-dynamics decision of the working state) */
    public bool $wouldRetireFinishedPlan = false;

    /** @var int Completed plan-writing calls of the conversation and its delegated conversations, refused calls left out */
    public int $plansCreated = 0;

    /** @var int Completed status-setting calls of the conversation and its delegated conversations, refused calls left out */
    public int $ticks = 0;

    /** @var bool The active plan still has an open item */
    public bool $endedWithOpenPlan = false;

    /** @var int The finished plans the working state keeps */
    public int $finishedPlans = 0;

    /** @var string One of the VERDICT_* constants */
    public string $verdict = self::VERDICT_NOT_JUDGED;

    /**
     * The summary of a driven conversation.
     *
     * @param string|null $expectPlan the case's `expectPlan`
     * @param string|null $planDynamicsArm the plan-dynamics arm the run ran with ({@see AgentEvalArms::$planDynamicsArm})
     */
    public static function fromConversation(AIConversation $conversation, ?string $expectPlan, ?string $planDynamicsArm): static
    {
        $agentEvalPlanDynamicsSummary = static::newInstance();
        $agentEvalPlanDynamicsSummary->arm = $planDynamicsArm;
        $agentEvalPlanDynamicsSummary->expectPlan = $expectPlan;
        $agentEvalPlanDynamicsSummary->readDecisions($conversation);
        $agentEvalPlanDynamicsSummary->countPlanCalls($conversation);
        $activeTaskPlan = $conversation->getTaskPlan();
        $agentEvalPlanDynamicsSummary->endedWithOpenPlan = $activeTaskPlan !== null && $activeTaskPlan->hasOpenItems();
        $agentEvalPlanDynamicsSummary->finishedPlans = $conversation->getAgentWorkingState()?->countFinishedTaskPlans() ?? 0;
        $agentEvalPlanDynamicsSummary->verdict = $agentEvalPlanDynamicsSummary->buildVerdict();
        return $agentEvalPlanDynamicsSummary;
    }

    /**
     * The summary with the keys a case row stores under `planDynamics`.
     *
     * @return array{arm: string|null, expectPlan: string|null, band: mixed, needsWorkingPlanProbability: mixed, turnStartDecidedBy: string|null, retiredFinishedPlan: bool, wouldRetireFinishedPlan: bool, plansCreated: int, ticks: int, planCalls: int, endedWithOpenPlan: bool, finishedPlans: int, verdict: string}
     */
    public function toArray(): array
    {
        return [
            'arm' => $this->arm,
            'expectPlan' => $this->expectPlan,
            'band' => $this->band,
            'needsWorkingPlanProbability' => $this->needsWorkingPlanProbability,
            'turnStartDecidedBy' => $this->turnStartDecidedBy,
            'retiredFinishedPlan' => $this->retiredFinishedPlan,
            'wouldRetireFinishedPlan' => $this->wouldRetireFinishedPlan,
            'plansCreated' => $this->plansCreated,
            'ticks' => $this->ticks,
            'planCalls' => $this->getPlanCalls(),
            'endedWithOpenPlan' => $this->endedWithOpenPlan,
            'finishedPlans' => $this->finishedPlans,
            'verdict' => $this->verdict,
        ];
    }

    /** Plans created plus ticks. */
    public function getPlanCalls(): int
    {
        return $this->plansCreated + $this->ticks;
    }

    /**
     * The plan-dynamics records of the decision log: the FIRST turn-start record (the case judges the first owner
     * turn) and the newest retirement record. The retirement also stands on the working state's plan-dynamics
     * decision (it writes a turn event, not always a record), so either source sets the two retirement flags.
     */
    protected function readDecisions(AIConversation $conversation): void
    {
        $turnStartEntry = null;
        $retirementEntry = null;
        $agentWorkingState = $conversation->getAgentWorkingState();
        foreach ($agentWorkingState->decisions ?? [] as $decisionRecord) {
            if (($decisionRecord->site ?? null) !== AgentDecisionModelSettings::SITE_PLAN_DYNAMICS || !is_array($decisionRecord->outcome)) {
                continue;
            }
            $entry = ['decidedBy' => $decisionRecord->decidedBy, 'applied' => $decisionRecord->applied, 'elapsedMs' => $decisionRecord->elapsedMs] + $decisionRecord->outcome;
            if (($decisionRecord->outcome['subject'] ?? null) === AIConversationTurnEvent::SUBJECT_PLAN_RETIREMENT) {
                $retirementEntry = $entry;
            } else {
                $turnStartEntry ??= $entry;
            }
        }
        $this->band = $turnStartEntry['band'] ?? null;
        $this->needsWorkingPlanProbability = $turnStartEntry['needsWorkingPlanProbability'] ?? null;
        $this->turnStartDecidedBy = $turnStartEntry['decidedBy'] ?? null;
        $planDynamicsDecision = $agentWorkingState?->planDynamicsDecision;
        $this->retiredFinishedPlan = (bool)($retirementEntry['retiredFinishedPlan'] ?? false)
            || ($planDynamicsDecision !== null && $planDynamicsDecision->retiredFinishedPlan);
        $this->wouldRetireFinishedPlan = (bool)($retirementEntry['wouldRetireFinishedPlan'] ?? false)
            || ($planDynamicsDecision !== null && $planDynamicsDecision->wouldRetireFinishedPlan);
    }

    /** The completed plan-writing and status-setting calls of the tree; a call refused as a duplicate or by a gate does not count. */
    protected function countPlanCalls(AIConversation $conversation): void
    {
        foreach ($conversation->getAllMessagesIncludingDelegations()->getElements() as $message) {
            $toolCall = isset($message->toolCall) ? $message->toolCall : null;
            if ($toolCall === null
                || $toolCall->status !== AIConversationMessage::STATUS_COMPLETED
                || $toolCall->wasRefusedAsDuplicate
                || $toolCall->wasRefusedByGate
            ) {
                continue;
            }
            if (($toolCall->name ?? null) === UpdateTaskPlanTool::NAME) {
                $this->plansCreated++;
            } elseif (($toolCall->name ?? null) === SetTaskStatusTool::NAME) {
                $this->ticks++;
            }
        }
    }

    /** The verdict against {@see $expectPlan}. */
    protected function buildVerdict(): string
    {
        if ($this->expectPlan === static::EXPECT_PLAN_DIRECT) {
            return $this->plansCreated > 0 ? static::VERDICT_PLAN_ON_DIRECT : static::VERDICT_OK;
        }
        if ($this->expectPlan === static::EXPECT_PLAN_REQUIRED) {
            return $this->plansCreated > 0 ? static::VERDICT_OK : static::VERDICT_MISSING_ON_REQUIRED;
        }
        return static::VERDICT_NOT_JUDGED;
    }
}
