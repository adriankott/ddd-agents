<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * How one case fared over the repeated runs of a tools evaluation: how many runs it had, how many passed, how many
 * ended in an error row (a child that crashed or timed out) and the pass rate (three decimals).
 *
 * The unique key of a case's repetitions is its case id.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalCaseRepetition extends ValueObject
{
    /** @var string The case id */
    public string $caseId = '';

    /** @var int The runs of the case */
    public int $runs = 0;

    /** @var int The runs that passed */
    public int $passed = 0;

    /** @var int The runs that ended in an error row */
    public int $errors = 0;

    /** @var float The passed runs per run, three decimals */
    public float $passRate = 0.0;

    /** The repetitions of the case `$caseId`, before any run was counted. */
    public static function forCase(string $caseId): static
    {
        $agentEvalCaseRepetition = static::newInstance();
        $agentEvalCaseRepetition->caseId = $caseId;
        return $agentEvalCaseRepetition;
    }

    /** Counts one run of the case and computes the pass rate again. */
    public function countRun(AgentEvalCaseRow $agentEvalCaseRow): void
    {
        $this->runs++;
        $this->passed += $agentEvalCaseRow->isPassed() ? 1 : 0;
        $this->errors += $agentEvalCaseRow->hasError() ? 1 : 0;
        $this->passRate = round($this->passed / $this->runs, 3);
    }

    /**
     * The entry as a tools summary stores it under `metrics.repetitions`: part of the stored document of
     * `<run>.json` / `<run>/_summary.json`.
     *
     * @return array{case: string, runs: int, passed: int, errors: int, passRate: float}
     */
    public function toArray(): array
    {
        return ['case' => $this->caseId, 'runs' => $this->runs, 'passed' => $this->passed, 'errors' => $this->errors, 'passRate' => $this->passRate];
    }

    /** The key of the repetitions of the case `$caseId` in their set. */
    public static function uniqueKeyForCaseId(string $caseId): string
    {
        return self::class . '_' . $caseId;
    }

    /** The case id. */
    public function uniqueKey(): string
    {
        return static::uniqueKeyForCaseId($this->caseId);
    }
}
