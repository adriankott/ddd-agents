<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * How one case fared over the repeated runs of a tools evaluation: how many runs it had, how many passed, how many
 * ended in an error row (a child that crashed or timed out), the pass rate (three decimals) and why the runs that did
 * not pass failed ({@see $failureDetails}, printed by the console, not stored).
 *
 * The unique key of a case's repetitions is its case id.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalCaseRepetition extends ValueObject
{
    /** @var int The failure details kept per case */
    public const int MAX_FAILURE_DETAILS = 5;

    /** @var int The characters a failure detail keeps */
    public const int MAX_FAILURE_DETAIL_LENGTH = 200;

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

    /**
     * @var string[] Per run that did not pass (up to {@see MAX_FAILURE_DETAILS}, in the order counted): its error, else
     * its first failure, cut to {@see MAX_FAILURE_DETAIL_LENGTH} characters. Not part of {@see toArray()}.
     */
    public array $failureDetails = [];

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
        if (!$agentEvalCaseRow->isPassed() && count($this->failureDetails) < static::MAX_FAILURE_DETAILS) {
            $this->failureDetails[] = static::buildFailureDetailOfRow($agentEvalCaseRow);
        }
    }

    /** Why a run did not pass: its error, else its first failure, else `failed`; one line, cut to the kept length. */
    public static function buildFailureDetailOfRow(AgentEvalCaseRow $agentEvalCaseRow): string
    {
        $failures = $agentEvalCaseRow->getValue('failures');
        $firstFailure = is_array($failures) && $failures !== [] ? (string)reset($failures) : null;
        $failureDetail = trim((string)preg_replace('/\s+/', ' ', $agentEvalCaseRow->getError() ?? $firstFailure ?? 'failed'));
        return mb_strlen($failureDetail) > static::MAX_FAILURE_DETAIL_LENGTH ? mb_substr($failureDetail, 0, static::MAX_FAILURE_DETAIL_LENGTH - 1) . '…' : $failureDetail;
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
