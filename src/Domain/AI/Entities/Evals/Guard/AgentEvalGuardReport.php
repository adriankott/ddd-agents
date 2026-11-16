<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals\Guard;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The report of a guard probe run on one model: the figures per guard-prompt variant and the number of probes.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalGuardReport extends ValueObject
{
    /** @var string The guard model's name, `default` for the configured default */
    public string $modelName = '';

    /** @var AgentEvalGuardVariantResults The figures per guard-prompt variant */
    public AgentEvalGuardVariantResults $variantResults;

    /** @var int The probes the report counts */
    public int $cellCount = 0;

    /**
     * The report of the probes `$agentEvalGuardCellResults` on the model `$modelName`.
     *
     * @param AgentEvalGuardCellResults $agentEvalGuardCellResults
     * @param string $modelName
     * @return static
     */
    public static function forCellResults(AgentEvalGuardCellResults $agentEvalGuardCellResults, string $modelName): static
    {
        $agentEvalGuardReport = static::newInstance();
        $agentEvalGuardReport->modelName = $modelName;
        $agentEvalGuardReport->variantResults = AgentEvalGuardVariantResults::newInstance();
        foreach ($agentEvalGuardCellResults->getElements() as $agentEvalGuardCellResult) {
            $agentEvalGuardReport->variantResults->findOrAddVariant($agentEvalGuardCellResult->variantId)->countCellResult($agentEvalGuardCellResult);
        }
        $agentEvalGuardReport->cellCount = $agentEvalGuardCellResults->count();
        return $agentEvalGuardReport;
    }

    /**
     * The report as the stored document of the run file carries it (and the JSON a child process prints): `model`,
     * `variants`, `cellCount`, in that order.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'model' => $this->modelName,
            'variants' => $this->variantResults->toArray(),
            'cellCount' => $this->cellCount,
        ];
    }
}
