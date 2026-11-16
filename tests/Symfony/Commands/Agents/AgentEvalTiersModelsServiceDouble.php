<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Models\Selection\ModelSelectionContext;
use DDD\Domain\AI\Services\AIModelsService;
use DDD\Infrastructure\Exceptions\BadRequestException;

/**
 * A model catalog of the tiers command's tests: each tier selects the model the test names; a tier the test leaves out
 * has no eligible model.
 */
class AgentEvalTiersModelsServiceDouble extends AIModelsService
{
    /** @var string[] The model name by tier */
    public array $modelNamesByTier = [];

    public function selectModel(ModelSelectionContext $modelSelectionContext): ?AIModel
    {
        $tier = (string)$modelSelectionContext->policy->preferredTier;
        if (!isset($this->modelNamesByTier[$tier])) {
            throw new BadRequestException("No eligible model in tier {$tier}");
        }
        $aIModel = new AIModel();
        $aIModel->name = $this->modelNamesByTier[$tier];
        return $aIModel;
    }
}
