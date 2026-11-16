<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The figures of one suite within one run of a suite record ({@see AgentEvalSuiteRecordRun}): the cases that count,
 * the not-applicable cases, the passed cases and the performance over the suite's rows.
 *
 * The unique key of the figures is their suite.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalFiguresOfSuite extends ValueObject
{
    /** @var string The suite */
    public string $suite = '';

    /** @var int The cases that count (every case but the not-applicable ones) */
    public int $total = 0;

    /** @var int The not-applicable cases */
    public int $notApplicable = 0;

    /** @var int The passed cases */
    public int $passed = 0;

    /** @var AgentEvalPerformanceRollUp|null The performance over the suite's rows; null until every row was counted */
    public ?AgentEvalPerformanceRollUp $performanceRollUp = null;

    /** The figures of the suite `$suite`, before any row was counted. */
    public static function forSuite(string $suite): static
    {
        $agentEvalFiguresOfSuite = static::newInstance();
        $agentEvalFiguresOfSuite->suite = $suite;
        return $agentEvalFiguresOfSuite;
    }

    /** Counts one row of the suite. */
    public function countRow(AgentEvalCaseRow $agentEvalCaseRow): void
    {
        $rowIsNotApplicable = $agentEvalCaseRow->isNotApplicable();
        $this->total += $rowIsNotApplicable ? 0 : 1;
        $this->notApplicable += $rowIsNotApplicable ? 1 : 0;
        $this->passed += $agentEvalCaseRow->isPassed() ? 1 : 0;
    }

    /** The key of the figures of the suite `$suite` in their set. */
    public static function uniqueKeyForSuite(string $suite): string
    {
        return self::class . '_' . $suite;
    }

    /** The suite. */
    public function uniqueKey(): string
    {
        return static::uniqueKeyForSuite($this->suite);
    }

    /**
     * The figures as a suite record stores them under `runs.<run>.bySuite.<suite>`: part of the stored document of
     * `<suite run>/_suite.json`.
     *
     * @return array{total: int, notApplicable: int, passed: int, performance: array}
     */
    public function toArray(): array
    {
        return [
            'total' => $this->total,
            'notApplicable' => $this->notApplicable,
            'passed' => $this->passed,
            'performance' => $this->performanceRollUp?->toArray(),
        ];
    }
}
