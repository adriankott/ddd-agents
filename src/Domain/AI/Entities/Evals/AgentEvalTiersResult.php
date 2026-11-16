<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The result of the tool evaluation under one model tier: the tier, the model it selected, the passed and the run
 * cases, and the efficiency of the runs that finished (tool calls, failed tool calls, tokens, wall milliseconds — lower
 * is better). A case whose run threw counts as run and failed, never as measured. Built case by case
 * ({@see countPassedCase()}, {@see countFailedCase()}, {@see addMeasuredRun()}), read as the result line of the tier
 * ({@see buildResultLine()}) and its row of the summary table ({@see buildSummaryLine()}).
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalTiersResult extends ValueObject
{
    /** @var string The label of the model of a tier that selected none (the conversation default) */
    public const string DEFAULT_MODEL_LABEL = '(default)';

    /** @var string The tier */
    public string $tier = '';

    /** @var string|null The model the tier selected; null = the conversation default */
    public ?string $modelName = null;

    /** @var int The cases that passed */
    public int $passedCaseCount = 0;

    /** @var int The cases that ran */
    public int $caseCount = 0;

    /** @var int The runs that finished and were measured */
    public int $measuredRunCount = 0;

    /** @var int The tool calls of the measured runs */
    public int $toolCallSum = 0;

    /** @var int The failed tool calls of the measured runs */
    public int $failedToolCallSum = 0;

    /** @var int The tokens of the measured runs */
    public int $tokenSum = 0;

    /** @var int The wall milliseconds of the measured runs */
    public int $durationMsSum = 0;

    /** @var string[] What each failed case is listed as (its id, with the error of a run that threw), in their order */
    public array $failureNotes = [];

    /** An empty result of the tier `$tier` on the model `$modelName` (null = the conversation default). */
    public static function forTier(string $tier, ?string $modelName): static
    {
        $agentEvalTiersResult = static::newInstance();
        $agentEvalTiersResult->tier = $tier;
        $agentEvalTiersResult->modelName = $modelName;
        return $agentEvalTiersResult;
    }

    /** Adds the efficiency of one finished run. */
    public function addMeasuredRun(int $toolCallCount, int $failedToolCallCount, int $tokenCount, int $durationMs): void
    {
        $this->measuredRunCount++;
        $this->toolCallSum += $toolCallCount;
        $this->failedToolCallSum += $failedToolCallCount;
        $this->tokenSum += $tokenCount;
        $this->durationMsSum += $durationMs;
    }

    /** Counts a case that ran and passed. */
    public function countPassedCase(): void
    {
        $this->caseCount++;
        $this->passedCaseCount++;
    }

    /** Counts a case that ran and failed, listed as `$failureNote`. */
    public function countFailedCase(string $failureNote): void
    {
        $this->caseCount++;
        $this->failureNotes[] = $failureNote;
    }

    /** The model as the lines name it: its name, {@see DEFAULT_MODEL_LABEL} for the conversation default. */
    public function getModelLabel(): string
    {
        return $this->modelName ?? static::DEFAULT_MODEL_LABEL;
    }

    /** The passed cases in percent of the run cases; 0 without a case. */
    public function getPassRate(): float
    {
        return $this->caseCount > 0 ? $this->passedCaseCount / $this->caseCount * 100.0 : 0.0;
    }

    /** Tool calls per measured run; 0 without one. */
    public function getAverageToolCalls(): float
    {
        return $this->measuredRunCount > 0 ? $this->toolCallSum / $this->measuredRunCount : 0.0;
    }

    /** Failed tool calls per measured run; 0 without one. */
    public function getAverageFailedToolCalls(): float
    {
        return $this->measuredRunCount > 0 ? $this->failedToolCallSum / $this->measuredRunCount : 0.0;
    }

    /** Tokens per measured run, rounded; 0 without one. */
    public function getAverageTokens(): int
    {
        return $this->measuredRunCount > 0 ? (int)round($this->tokenSum / $this->measuredRunCount) : 0;
    }

    /** Wall milliseconds per measured run, rounded; 0 without one. */
    public function getAverageDurationMs(): int
    {
        return $this->measuredRunCount > 0 ? (int)round($this->durationMsSum / $this->measuredRunCount) : 0;
    }

    /**
     * The failed cases, each listed once, in their order.
     *
     * @return string[]
     */
    public function getDistinctFailureNotes(): array
    {
        return array_values(array_unique($this->failureNotes));
    }

    /** The result line of the tier: `p/t passed (x%) | avg … tool-calls (… failed) | avg … tokens | avg …s`, indented. */
    public function buildResultLine(): string
    {
        return sprintf(
            '  %d/%d passed (%.0f%%) | avg %.1f tool-calls (%.1f failed) | avg %s tokens | avg %.1fs',
            $this->passedCaseCount,
            $this->caseCount,
            $this->getPassRate(),
            $this->getAverageToolCalls(),
            $this->getAverageFailedToolCalls(),
            number_format($this->getAverageTokens()),
            $this->getAverageDurationMs() / 1000.0
        );
    }

    /** The row of the tier in the summary table (columns of {@see AgentEvalTiersResults::buildSummaryHeaderLine()}). */
    public function buildSummaryLine(): string
    {
        return sprintf(
            '%-10s %-30s %7.0f%% %7.1f %7.1f %9s %7.1f',
            $this->tier,
            $this->getModelLabel(),
            $this->getPassRate(),
            $this->getAverageToolCalls(),
            $this->getAverageFailedToolCalls(),
            number_format($this->getAverageTokens()),
            $this->getAverageDurationMs() / 1000.0
        );
    }
}
