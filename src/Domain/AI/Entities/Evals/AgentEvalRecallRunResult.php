<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The result of one run of the cross-conversation recall evaluation over its case rows
 * ({@see AgentEvalCaseRow::buildForRecallRun()}, the not-applicable rows among them): the passed cases, the cases that
 * count (every row but the not-applicable ones), the not-applicable cases and the model. Two documents with their own
 * keys: the result file a parent process reads ({@see toArray()}, with the model, the flavor and the rows' message
 * traces) and the printed JSON ({@see toPrintedArray()}).
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalRecallRunResult extends ValueObject
{
    /** @var int The rows whose case passed */
    public int $passed = 0;

    /** @var int The rows that count: every row but the not-applicable ones */
    public int $total = 0;

    /** @var int The rows whose case does not apply to its agent space */
    public int $notApplicable = 0;

    /** @var string|null The model of the run; null = the conversation default */
    public ?string $modelName = null;

    /** @var string The flavor of every recall run */
    public string $flavor = AgentEvalSuiteTask::FLAVOR_CALL;

    /** @var AgentEvalCaseRows The rows of the run, in the order the cases ran */
    public AgentEvalCaseRows $caseRows;

    /** The result of the rows `$agentEvalCaseRows` of a run on the model `$modelName`. */
    public static function forCaseRows(AgentEvalCaseRows $agentEvalCaseRows, ?string $modelName): static
    {
        $agentEvalRecallRunResult = static::newInstance();
        $agentEvalRecallRunResult->caseRows = $agentEvalCaseRows;
        $agentEvalRecallRunResult->passed = $agentEvalCaseRows->countPassed();
        $agentEvalRecallRunResult->notApplicable = $agentEvalCaseRows->countNotApplicable();
        $agentEvalRecallRunResult->total = $agentEvalCaseRows->count() - $agentEvalRecallRunResult->notApplicable;
        $agentEvalRecallRunResult->modelName = $modelName;
        return $agentEvalRecallRunResult;
    }

    /** Whether every case that counts passed (a run without such a case passes). */
    public function hasEveryCasePassed(): bool
    {
        return $this->passed === $this->total;
    }

    /**
     * The stored document of the result file a parent process reads (`--result-file`): `passed`, `total`,
     * `notApplicable`, `model`, `flavor`, `cases` — every row as it was built, its message trace included (the
     * recall conversation is never stored, the row is its only record).
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'passed' => $this->passed,
            'total' => $this->total,
            'notApplicable' => $this->notApplicable,
            'model' => $this->modelName,
            'flavor' => $this->flavor,
            'cases' => $this->caseRows->toArrays(),
        ];
    }

    /**
     * The printed document of `--json`: `passed`, `total`, `notApplicable`, `cases` (every row with its message trace).
     *
     * @return array
     */
    public function toPrintedArray(): array
    {
        return [
            'passed' => $this->passed,
            'total' => $this->total,
            'notApplicable' => $this->notApplicable,
            'cases' => $this->caseRows->toArrays(),
        ];
    }
}
