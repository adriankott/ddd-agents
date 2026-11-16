<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The plan-dynamics figures of a tools evaluation over the rows that carry a `planDynamics` block: plans on `direct`
 * cases (a defect), missing plans on `required` cases, retired plans, plan calls per case, and the tool calls and the
 * duration per case over the timed rows.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalPlanDynamicsRollUp extends ValueObject
{
    /** @var string The plan-dynamics arm of the run */
    public string $arm = '';

    /** @var int The rows that carry the block */
    public int $cases = 0;

    /** @var int The cases that expect no plan (`direct`) */
    public int $directCases = 0;

    /** @var int The `direct` cases that made a plan */
    public int $plansOnDirect = 0;

    /** @var float|null Plans on `direct` cases per `direct` case, three decimals; null without such a case */
    public ?float $plansOnDirectRate = null;

    /** @var int The cases that require a plan */
    public int $requiredCases = 0;

    /** @var int The `required` cases without a plan */
    public int $missingOnRequired = 0;

    /** @var int The cases that retired a finished plan */
    public int $retiredPlans = 0;

    /** @var float Plan calls per case, two decimals */
    public float $avgPlanCallsPerCase = 0.0;

    /** @var float Tool calls per timed row, two decimals */
    public float $avgToolCallCount = 0.0;

    /** @var float Milliseconds per timed row, two decimals */
    public float $avgDurationMs = 0.0;

    /**
     * The figures of the rows `$planRows` (each carries a `planDynamics` block) and the timed rows `$timedRows` of the
     * run, under the arm `$planDynamicsArm`.
     */
    public static function fromRows(string $planDynamicsArm, AgentEvalCaseRows $planRows, AgentEvalCaseRows $timedRows): static
    {
        $agentEvalPlanDynamicsRollUp = static::newInstance();
        $agentEvalPlanDynamicsRollUp->arm = $planDynamicsArm;
        $agentEvalPlanDynamicsRollUp->cases = $planRows->count();
        $agentEvalPlanDynamicsRollUp->directCases = $planRows->countRowsWithBlockValue('planDynamics', 'expectPlan', 'direct');
        $agentEvalPlanDynamicsRollUp->plansOnDirect = $planRows->countRowsWithBlockValue('planDynamics', 'verdict', 'planOnDirect');
        $agentEvalPlanDynamicsRollUp->plansOnDirectRate = $agentEvalPlanDynamicsRollUp->directCases > 0
            ? round($agentEvalPlanDynamicsRollUp->plansOnDirect / $agentEvalPlanDynamicsRollUp->directCases, 3)
            : null;
        $agentEvalPlanDynamicsRollUp->requiredCases = $planRows->countRowsWithBlockValue('planDynamics', 'expectPlan', 'required');
        $agentEvalPlanDynamicsRollUp->missingOnRequired = $planRows->countRowsWithBlockValue('planDynamics', 'verdict', 'missingOnRequired');
        $planCalls = 0;
        foreach ($planRows->getElements() as $planRow) {
            $planCalls += (int)($planRow->getValue('planDynamics')['planCalls'] ?? 0);
            $agentEvalPlanDynamicsRollUp->retiredPlans += (bool)($planRow->getValue('planDynamics')['retiredFinishedPlan'] ?? false) ? 1 : 0;
        }
        $agentEvalPlanDynamicsRollUp->avgPlanCallsPerCase = round($planCalls / $planRows->count(), 2);
        $agentEvalPlanDynamicsRollUp->avgToolCallCount = $timedRows->averageNumber('toolCallCount');
        $agentEvalPlanDynamicsRollUp->avgDurationMs = $timedRows->averageNumber('durationMs');
        return $agentEvalPlanDynamicsRollUp;
    }

    /**
     * The figures as a tools summary stores them under `metrics.planDynamics`: part of the stored document of
     * `<run>.json` / `<run>/_summary.json`.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'arm' => $this->arm,
            'cases' => $this->cases,
            'directCases' => $this->directCases,
            'plansOnDirect' => $this->plansOnDirect,
            'plansOnDirectRate' => $this->plansOnDirectRate,
            'requiredCases' => $this->requiredCases,
            'missingOnRequired' => $this->missingOnRequired,
            'retiredPlans' => $this->retiredPlans,
            'avgPlanCallsPerCase' => $this->avgPlanCallsPerCase,
            'avgToolCallCount' => $this->avgToolCallCount,
            'avgDurationMs' => $this->avgDurationMs,
        ];
    }
}
