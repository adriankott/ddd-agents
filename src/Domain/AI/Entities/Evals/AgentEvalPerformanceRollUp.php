<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The PERFORMANCE and the SPEED of a run over the cases it measured: the case rows that carry a `performance` block
 * ({@see AgentEvalRunFigures::toArray()}; an error row carries none). Model calls, owner turns, seconds per owner turn,
 * tokens, cost and the decision layer's time, then the speed figures: the seconds from the owner row to the turn's
 * first model output and to its first visible answer (median and p90 over every owner turn), the uncached input
 * tokens per owner turn and per conversation (average, median) and the conversation wall time per case (median, p90).
 *
 * Each figure is computed from the stored per-case figures only — it adds nothing the cases did not measure. A speed
 * figure no measured row carries is null; a row written before the speed figures existed counts with its
 * conversation's uncached tokens only (input − cached). Percentiles are nearest-rank ({@see percentile()}).
 *
 * {@see toArray()} gives the figures with the keys and in the order a run summary stores them.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalPerformanceRollUp extends ValueObject
{
    /** @var int The rows that carry a performance block */
    public int $measuredCases = 0;

    /** @var int The owner turns of every measured case */
    public int $ownerTurns = 0;

    /** @var int The model calls of every measured case */
    public int $modelCalls = 0;

    /** @var float Model calls per measured case, two decimals */
    public float $avgModelCallsPerCase = 0.0;

    /** @var float Model calls per owner turn, two decimals; 0 without an owner turn */
    public float $avgModelCallsPerOwnerTurn = 0.0;

    /** @var float Owner turns per measured case, two decimals */
    public float $avgOwnerTurnsPerCase = 0.0;

    /** @var float Tool calls per measured case, two decimals */
    public float $avgToolCallsPerCase = 0.0;

    /** @var float The agent seconds of every measured case, one decimal */
    public float $agentSeconds = 0.0;

    /** @var float Agent seconds per measured case, one decimal */
    public float $avgAgentSecondsPerCase = 0.0;

    /** @var float The median of the cases' agent seconds */
    public float $medianAgentSecondsPerCase = 0.0;

    /** @var float The 90th percentile of the cases' agent seconds */
    public float $p90AgentSecondsPerCase = 0.0;

    /** @var float Agent seconds per owner turn, one decimal; 0 without an owner turn */
    public float $avgSecondsPerOwnerTurn = 0.0;

    /** @var float The median of the seconds of every owner turn */
    public float $medianSecondsPerOwnerTurn = 0.0;

    /** @var float The 90th percentile of the seconds of every owner turn */
    public float $p90SecondsPerOwnerTurn = 0.0;

    /** @var float The longest owner turn; 0 without one */
    public float $maxSecondsPerOwnerTurn = 0.0;

    /** @var int The tokens of every measured case */
    public int $totalTokens = 0;

    /** @var float Tokens per measured case, no decimals */
    public float $avgTotalTokensPerCase = 0.0;

    /** @var float|null Cached input tokens divided by input tokens, three decimals; null without input tokens */
    public ?float $cachedInputShare = null;

    /** @var float The cost of every measured case in USD, six decimals */
    public float $costUsd = 0.0;

    /** @var float Cost per measured case in USD, six decimals */
    public float $avgCostPerCaseUsd = 0.0;

    /** @var float Cost per owner turn in USD, six decimals; 0 without an owner turn */
    public float $avgCostPerOwnerTurnUsd = 0.0;

    /** @var int The milliseconds the decision model worked in every measured case */
    public int $decisionModelMs = 0;

    /** @var int Decision-model milliseconds per owner turn, rounded; 0 without an owner turn */
    public int $avgDecisionModelMsPerOwnerTurn = 0;

    /** @var float|null The median seconds from an owner row to the turn's first model output; null when nothing measured it */
    public ?float $medianFirstModelOutputSeconds = null;

    /** @var float|null The 90th percentile of the seconds to the first model output */
    public ?float $p90FirstModelOutputSeconds = null;

    /** @var float|null The median seconds from an owner row to the first answer the owner sees */
    public ?float $medianFirstAnswerSeconds = null;

    /** @var float|null The 90th percentile of the seconds to the first answer */
    public ?float $p90FirstAnswerSeconds = null;

    /** @var int The uncached input tokens of every measured case */
    public int $uncachedInputTokens = 0;

    /** @var int|null Uncached input tokens per owner turn, rounded; null without an owner turn */
    public ?int $avgUncachedInputTokensPerOwnerTurn = null;

    /** @var float|null The median of the uncached input tokens of every owner turn that measured them */
    public ?float $medianUncachedInputTokensPerOwnerTurn = null;

    /** @var int|null Uncached input tokens per measured case, rounded; null without a measured case */
    public ?int $avgUncachedInputTokensPerCase = null;

    /** @var float|null The median of the cases' uncached input tokens */
    public ?float $medianUncachedInputTokensPerCase = null;

    /** @var float|null The median of the cases' conversation wall seconds */
    public ?float $medianConversationWallSeconds = null;

    /** @var float|null The 90th percentile of the cases' conversation wall seconds */
    public ?float $p90ConversationWallSeconds = null;

    /** The figures over the measured rows of `$agentEvalCaseRows`. */
    public static function fromCaseRows(AgentEvalCaseRows $agentEvalCaseRows): static
    {
        $agentEvalPerformanceRollUp = static::newInstance();
        $agentEvalPerformanceRollUp->rollUpMeasuredRows($agentEvalCaseRows->buildMeasuredRows());
        return $agentEvalPerformanceRollUp;
    }

    /** Computes the performance figures, then the speed figures, over `$measuredRows`. */
    protected function rollUpMeasuredRows(AgentEvalCaseRows $measuredRows): void
    {
        $this->measuredCases = $measuredRows->count();
        $this->ownerTurns = (int)array_sum($this->collectPerformanceNumbers($measuredRows, 'ownerTurns'));
        $modelCalls = array_sum($this->collectPerformanceNumbers($measuredRows, 'modelCalls'));
        $agentSecondsPerCase = $this->collectPerformanceNumbers($measuredRows, 'agentSeconds');
        $agentSeconds = (float)array_sum($agentSecondsPerCase);
        $secondsOfEveryTurn = $this->collectSecondsOfEveryOwnerTurn($measuredRows);
        $inputTokens = (int)array_sum($this->collectPerformanceNumbers($measuredRows, 'inputTokens'));
        $cachedInputTokens = (int)array_sum($this->collectPerformanceNumbers($measuredRows, 'cachedInputTokens'));
        $costsUsd = $this->collectPerformanceNumbers($measuredRows, 'costUsd');
        $decisionModelMs = array_sum($this->collectPerformanceNumbers($measuredRows, 'decisionModelMs'));
        $this->modelCalls = (int)$modelCalls;
        $this->avgModelCallsPerCase = $this->average($this->collectPerformanceNumbers($measuredRows, 'modelCalls'));
        $this->avgModelCallsPerOwnerTurn = $this->ownerTurns > 0 ? round($modelCalls / $this->ownerTurns, 2) : 0.0;
        $this->avgOwnerTurnsPerCase = $this->average($this->collectPerformanceNumbers($measuredRows, 'ownerTurns'));
        $this->avgToolCallsPerCase = $this->average($this->collectPerformanceNumbers($measuredRows, 'toolCalls'));
        $this->agentSeconds = round($agentSeconds, 1);
        $this->avgAgentSecondsPerCase = $this->average($agentSecondsPerCase, 1);
        $this->medianAgentSecondsPerCase = $this->percentile($agentSecondsPerCase, 50);
        $this->p90AgentSecondsPerCase = $this->percentile($agentSecondsPerCase, 90);
        $this->avgSecondsPerOwnerTurn = $this->ownerTurns > 0 ? round($agentSeconds / $this->ownerTurns, 1) : 0.0;
        $this->medianSecondsPerOwnerTurn = $this->percentile($secondsOfEveryTurn, 50);
        $this->p90SecondsPerOwnerTurn = $this->percentile($secondsOfEveryTurn, 90);
        $this->maxSecondsPerOwnerTurn = $secondsOfEveryTurn === [] ? 0.0 : (float)max($secondsOfEveryTurn);
        $this->totalTokens = (int)array_sum($this->collectPerformanceNumbers($measuredRows, 'totalTokens'));
        $this->avgTotalTokensPerCase = $this->average($this->collectPerformanceNumbers($measuredRows, 'totalTokens'), 0);
        $this->cachedInputShare = $inputTokens > 0 ? round($cachedInputTokens / $inputTokens, 3) : null;
        $this->costUsd = round((float)array_sum($costsUsd), 6);
        $this->avgCostPerCaseUsd = $this->average($costsUsd, 6);
        $this->avgCostPerOwnerTurnUsd = $this->ownerTurns > 0 ? round(array_sum($costsUsd) / $this->ownerTurns, 6) : 0.0;
        $this->decisionModelMs = (int)$decisionModelMs;
        $this->avgDecisionModelMsPerOwnerTurn = $this->ownerTurns > 0 ? (int)round($decisionModelMs / $this->ownerTurns) : 0;
        $this->rollUpSpeed($measuredRows);
    }

    /** Computes the speed figures over `$measuredRows`. */
    protected function rollUpSpeed(AgentEvalCaseRows $measuredRows): void
    {
        $firstModelOutputSeconds = $this->collectOwnerTurnValues($measuredRows, 'firstModelOutputSecondsPerOwnerTurn');
        $firstAnswerSeconds = $this->collectOwnerTurnValues($measuredRows, 'firstAnswerSecondsPerOwnerTurn');
        $uncachedInputTokensPerOwnerTurn = $this->collectOwnerTurnValues($measuredRows, 'uncachedInputTokensPerOwnerTurn');
        $uncachedInputTokensPerCase = array_map($this->getUncachedInputTokensOfCase(...), $measuredRows->getElements());
        $conversationWallSeconds = $this->collectCaseValues($measuredRows, 'conversationWallSeconds');
        $uncachedInputTokens = (int)array_sum($uncachedInputTokensPerCase);
        $this->medianFirstModelOutputSeconds = $this->percentileOrNull($firstModelOutputSeconds, 50);
        $this->p90FirstModelOutputSeconds = $this->percentileOrNull($firstModelOutputSeconds, 90);
        $this->medianFirstAnswerSeconds = $this->percentileOrNull($firstAnswerSeconds, 50);
        $this->p90FirstAnswerSeconds = $this->percentileOrNull($firstAnswerSeconds, 90);
        $this->uncachedInputTokens = $uncachedInputTokens;
        $this->avgUncachedInputTokensPerOwnerTurn = $this->ownerTurns > 0 ? (int)round($uncachedInputTokens / $this->ownerTurns) : null;
        $this->medianUncachedInputTokensPerOwnerTurn = $this->percentileOrNull($uncachedInputTokensPerOwnerTurn, 50);
        $this->avgUncachedInputTokensPerCase = $uncachedInputTokensPerCase === [] ? null : (int)round($uncachedInputTokens / count($uncachedInputTokensPerCase));
        $this->medianUncachedInputTokensPerCase = $this->percentileOrNull(array_map('floatval', $uncachedInputTokensPerCase), 50);
        $this->medianConversationWallSeconds = $this->percentileOrNull($conversationWallSeconds, 50);
        $this->p90ConversationWallSeconds = $this->percentileOrNull($conversationWallSeconds, 90);
    }

    /**
     * One number of every row's performance block (0 for a row without it).
     *
     * @return float[]
     */
    protected function collectPerformanceNumbers(AgentEvalCaseRows $measuredRows, string $key): array
    {
        return array_map(static fn (AgentEvalCaseRow $agentEvalCaseRow): float => $agentEvalCaseRow->getPerformanceNumber($key), $measuredRows->getElements());
    }

    /**
     * The seconds of every owner turn over the cases (`secondsPerOwnerTurn`).
     *
     * @return float[]
     */
    protected function collectSecondsOfEveryOwnerTurn(AgentEvalCaseRows $measuredRows): array
    {
        $secondsOfEveryTurn = [];
        foreach ($measuredRows->getElements() as $agentEvalCaseRow) {
            foreach ($agentEvalCaseRow->getPerformance()->getListAsNumbers('secondsPerOwnerTurn') as $turnSeconds) {
                $secondsOfEveryTurn[] = $turnSeconds;
            }
        }
        return $secondsOfEveryTurn;
    }

    /**
     * Every owner turn's value of a per-turn list (`$key`) over the cases — a turn that measured nothing (null) is left out.
     *
     * @return float[]
     */
    protected function collectOwnerTurnValues(AgentEvalCaseRows $measuredRows, string $key): array
    {
        $values = [];
        foreach ($measuredRows->getElements() as $agentEvalCaseRow) {
            foreach ($agentEvalCaseRow->getPerformance()->getNumericListEntries($key) as $ownerTurnValue) {
                $values[] = $ownerTurnValue;
            }
        }
        return $values;
    }

    /**
     * Every case's value of a per-case number (`$key`) — a case that measured nothing (null, absent) is left out.
     *
     * @return float[]
     */
    protected function collectCaseValues(AgentEvalCaseRows $measuredRows, string $key): array
    {
        $values = [];
        foreach ($measuredRows->getElements() as $agentEvalCaseRow) {
            $caseValue = $agentEvalCaseRow->getPerformance()->findNumber($key);
            if ($caseValue !== null) {
                $values[] = $caseValue;
            }
        }
        return $values;
    }

    /**
     * The uncached input tokens of one case's conversation: the stored number, else input − cached (a row written
     * before the speed figures existed).
     */
    public function getUncachedInputTokensOfCase(AgentEvalCaseRow $agentEvalCaseRow): int
    {
        return $agentEvalCaseRow->getPerformance()->getUncachedInputTokens();
    }

    /**
     * The average of `$values` rounded to `$precision` decimals; 0 for an empty list.
     *
     * @param float[] $values
     */
    protected function average(array $values, int $precision = 2): float
    {
        return $values === [] ? 0.0 : round(array_sum($values) / count($values), $precision);
    }

    /**
     * The nearest-rank percentile of a list of numbers, one decimal; 0 for an empty list.
     *
     * @param float[] $values
     */
    public function percentile(array $values, int $percentile): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $rank = (int)ceil($percentile / 100 * count($values));
        return round((float)$values[max(0, min(count($values) - 1, $rank - 1))], 1);
    }

    /**
     * {@see percentile()}, null for an empty list (a figure nothing measured).
     *
     * @param float[] $values
     */
    protected function percentileOrNull(array $values, int $percentile): ?float
    {
        return $values === [] ? null : $this->percentile($values, $percentile);
    }

    /** One figure by its stored key ({@see toArray()}) as a number; 0 for a null or unknown figure. */
    public function getFigure(string $figureKey): float
    {
        return (float)($this->toArray()[$figureKey] ?? 0);
    }

    /**
     * The compact speed block of the end-of-run report: two lines over the speed figures (`n/a` = not measured).
     *
     * @return string[]
     */
    public function buildSpeedKpiLines(): array
    {
        return [
            sprintf(
                'speed: first model output median %s · p90 %s · first answer median %s · p90 %s · conversation wall median %s · p90 %s',
                $this->formatSeconds($this->medianFirstModelOutputSeconds),
                $this->formatSeconds($this->p90FirstModelOutputSeconds),
                $this->formatSeconds($this->medianFirstAnswerSeconds),
                $this->formatSeconds($this->p90FirstAnswerSeconds),
                $this->formatSeconds($this->medianConversationWallSeconds),
                $this->formatSeconds($this->p90ConversationWallSeconds)
            ),
            sprintf(
                'uncached input tokens: per owner turn avg %s · median %s · per conversation avg %s · median %s',
                $this->formatTokens($this->avgUncachedInputTokensPerOwnerTurn),
                $this->formatTokens($this->medianUncachedInputTokensPerOwnerTurn),
                $this->formatTokens($this->avgUncachedInputTokensPerCase),
                $this->formatTokens($this->medianUncachedInputTokensPerCase)
            ),
        ];
    }

    /** `12.3 s`, or `n/a` for a figure nothing measured. */
    protected function formatSeconds(int|float|null $seconds): string
    {
        return $seconds !== null ? sprintf('%.1f s', (float)$seconds) : 'n/a';
    }

    /** `10.5k`, or `n/a` for a figure nothing measured. */
    protected function formatTokens(int|float|null $tokens): string
    {
        return $tokens !== null ? sprintf('%.1fk', (float)$tokens / 1000) : 'n/a';
    }

    /**
     * The figures with the keys and in the order a run summary stores them under `metrics.performance`: part of the
     * stored document of every summary file.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'measuredCases' => $this->measuredCases,
            'ownerTurns' => $this->ownerTurns,
            'modelCalls' => $this->modelCalls,
            'avgModelCallsPerCase' => $this->avgModelCallsPerCase,
            'avgModelCallsPerOwnerTurn' => $this->avgModelCallsPerOwnerTurn,
            'avgOwnerTurnsPerCase' => $this->avgOwnerTurnsPerCase,
            'avgToolCallsPerCase' => $this->avgToolCallsPerCase,
            'agentSeconds' => $this->agentSeconds,
            'avgAgentSecondsPerCase' => $this->avgAgentSecondsPerCase,
            'medianAgentSecondsPerCase' => $this->medianAgentSecondsPerCase,
            'p90AgentSecondsPerCase' => $this->p90AgentSecondsPerCase,
            'avgSecondsPerOwnerTurn' => $this->avgSecondsPerOwnerTurn,
            'medianSecondsPerOwnerTurn' => $this->medianSecondsPerOwnerTurn,
            'p90SecondsPerOwnerTurn' => $this->p90SecondsPerOwnerTurn,
            'maxSecondsPerOwnerTurn' => $this->maxSecondsPerOwnerTurn,
            'totalTokens' => $this->totalTokens,
            'avgTotalTokensPerCase' => $this->avgTotalTokensPerCase,
            'cachedInputShare' => $this->cachedInputShare,
            'costUsd' => $this->costUsd,
            'avgCostPerCaseUsd' => $this->avgCostPerCaseUsd,
            'avgCostPerOwnerTurnUsd' => $this->avgCostPerOwnerTurnUsd,
            'decisionModelMs' => $this->decisionModelMs,
            'avgDecisionModelMsPerOwnerTurn' => $this->avgDecisionModelMsPerOwnerTurn,
            'medianFirstModelOutputSeconds' => $this->medianFirstModelOutputSeconds,
            'p90FirstModelOutputSeconds' => $this->p90FirstModelOutputSeconds,
            'medianFirstAnswerSeconds' => $this->medianFirstAnswerSeconds,
            'p90FirstAnswerSeconds' => $this->p90FirstAnswerSeconds,
            'uncachedInputTokens' => $this->uncachedInputTokens,
            'avgUncachedInputTokensPerOwnerTurn' => $this->avgUncachedInputTokensPerOwnerTurn,
            'medianUncachedInputTokensPerOwnerTurn' => $this->medianUncachedInputTokensPerOwnerTurn,
            'avgUncachedInputTokensPerCase' => $this->avgUncachedInputTokensPerCase,
            'medianUncachedInputTokensPerCase' => $this->medianUncachedInputTokensPerCase,
            'medianConversationWallSeconds' => $this->medianConversationWallSeconds,
            'p90ConversationWallSeconds' => $this->p90ConversationWallSeconds,
        ];
    }
}
