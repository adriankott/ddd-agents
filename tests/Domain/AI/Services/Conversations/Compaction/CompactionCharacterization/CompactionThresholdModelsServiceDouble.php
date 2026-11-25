<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Compaction\CompactionCharacterization;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Services\AIModelsService;

/**
 * The model catalog of the compaction threshold tests: {@see getAIModelByName()} answers from the models the test
 * gives instead of the catalog config, and records every name it was asked for (the conversation reads the catalog
 * once per threshold breakdown, so the reads are part of the behaviour).
 */
class CompactionThresholdModelsServiceDouble extends AIModelsService
{
    /** @var array<string, AIModel> model name → the model the catalog answers; a name not listed is unknown */
    public array $modelsByName = [];

    /** @var string[] every name asked for, in order */
    public array $requestedModelNames = [];

    public function getAIModelByName(string $aiModelName): ?AIModel
    {
        $this->requestedModelNames[] = $aiModelName;
        return $this->modelsByName[$aiModelName] ?? null;
    }

    /** A catalog model with the two limits the compaction threshold reads. */
    public static function buildModel(string $modelName, ?int $effectiveContextTokens, ?int $contextWindowTokens): AIModel
    {
        $aiModel = new AIModel();
        $aiModel->name = $modelName;
        $aiModel->effectiveContextTokens = $effectiveContextTokens;
        $aiModel->contextWindowTokens = $contextWindowTokens;
        return $aiModel;
    }
}
