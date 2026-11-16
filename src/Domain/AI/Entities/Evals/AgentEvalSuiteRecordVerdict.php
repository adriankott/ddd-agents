<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The two verdicts of a suite run ({@see AgentEvalSuiteRecord}): correctness and performance.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalSuiteRecordVerdict extends ValueObject
{
    /** @var string {@see AgentEvalSuiteRecord::VERDICT_PASSED} or {@see AgentEvalSuiteRecord::VERDICT_FAILED} */
    public string $correctness = '';

    /** @var string {@see AgentEvalSuiteRecord::VERDICT_NOT_COMPARED}, {@see AgentEvalSuiteRecord::VERDICT_REGRESSED} or {@see AgentEvalSuiteRecord::VERDICT_OK} */
    public string $performance = '';

    /** The verdicts `$correctness` and `$performance`. */
    public static function forVerdicts(string $correctness, string $performance): static
    {
        $agentEvalSuiteRecordVerdict = static::newInstance();
        $agentEvalSuiteRecordVerdict->correctness = $correctness;
        $agentEvalSuiteRecordVerdict->performance = $performance;
        return $agentEvalSuiteRecordVerdict;
    }

    /**
     * The verdicts as a record stores them under `verdict`: part of the stored document of `<suite run>/_suite.json`.
     *
     * @return array{correctness: string, performance: string}
     */
    public function toArray(): array
    {
        return ['correctness' => $this->correctness, 'performance' => $this->performance];
    }
}
