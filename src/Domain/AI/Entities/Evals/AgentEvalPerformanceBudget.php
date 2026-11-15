<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A case's PERFORMANCE BUDGET (the `expect.performance` block, every key optional): the criterion beside correctness —
 * a case that reaches the right tool in three times the calls is a regression too. Checks the figures of a run
 * ({@see AgentEvalRunFigures}) and returns one failure text per bound the run exceeded. No I/O.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalPerformanceBudget extends ValueObject
{
    /** @var array<string, string> The budget keys of `expect.performance` → the key of the figure they bound ({@see AgentEvalRunFigures::toArray()}) */
    public const array PERFORMANCE_BUDGET_KEYS = [
        'maxOwnerTurns' => 'ownerTurns',
        'maxModelCalls' => 'modelCalls',
        'maxModelCallsPerOwnerTurn' => 'modelCallsPerOwnerTurn',
        'maxToolCalls' => 'toolCalls',
        'maxAgentSeconds' => 'agentSeconds',
        'maxSecondsPerOwnerTurn' => 'maxSecondsPerOwnerTurn',
        'maxTotalTokens' => 'totalTokens',
        'maxCostUsd' => 'costUsd',
    ];

    /** @var array<string, mixed> The case's `expect.performance` block as written; a key that is no budget key or carries no number bounds nothing */
    public array $budgetValuesByKey = [];

    /**
     * The budget of a case.
     *
     * @param array<string, mixed> $performanceBudgetBlock the case's `expect.performance` block ([] = no budget)
     */
    public static function fromBudgetBlock(array $performanceBudgetBlock): static
    {
        $agentEvalPerformanceBudget = static::newInstance();
        $agentEvalPerformanceBudget->budgetValuesByKey = $performanceBudgetBlock;
        return $agentEvalPerformanceBudget;
    }

    /**
     * The bounds the run exceeded, in the order of {@see PERFORMANCE_BUDGET_KEYS}.
     *
     * @return string[] one failure text per exceeded bound (empty = inside the budget)
     */
    public function evaluateFigures(AgentEvalRunFigures $agentEvalRunFigures): array
    {
        $figuresByKey = $agentEvalRunFigures->toArray();
        $failures = [];
        foreach (static::PERFORMANCE_BUDGET_KEYS as $budgetKey => $figureKey) {
            if (!isset($this->budgetValuesByKey[$budgetKey]) || !is_numeric($this->budgetValuesByKey[$budgetKey])) {
                continue;
            }
            $measured = (float)($figuresByKey[$figureKey] ?? 0);
            if ($measured > (float)$this->budgetValuesByKey[$budgetKey]) {
                $failures[] = sprintf(
                    'performance: %s is %s, the budget allows %s',
                    $figureKey,
                    rtrim(rtrim(number_format($measured, 4, '.', ''), '0'), '.'),
                    (string)$this->budgetValuesByKey[$budgetKey]
                );
            }
        }
        return $failures;
    }
}
