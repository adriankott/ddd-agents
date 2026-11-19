<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Repo\Argus\Conversations;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Models\Settings\AIModelAgenticUseCaseConfig;
use DDD\Domain\AI\Services\AIModelsService;

/**
 * A model catalog of the egress tests: the scope pins one model, a name lookup answers the named models; each model
 * carries the agentic settings the test gives it.
 */
class AgentEgressModelsServiceWithScopeModelDouble extends AIModelsService
{
    /** @var string The model a scope pins */
    public string $scopeModelName = 'TEST.SCOPE_MODEL';

    /** @var array<string, AIModelAgenticUseCaseConfig|null> The agentic settings by model name; a name not listed is unknown */
    public array $agenticUseCasesByModelName = [];

    /** @var string[] The scopes asked, in order */
    public array $askedScopes = [];

    public function getModelForScope(string $scope, ?int $requiredInputTokens = null): ?AIModel
    {
        $this->askedScopes[] = $scope;
        return $this->getAIModelByName($this->scopeModelName);
    }

    public function getAIModelByName(string $name): ?AIModel
    {
        if (!array_key_exists($name, $this->agenticUseCasesByModelName)) {
            return null;
        }
        $aIModel = new AIModel();
        $aIModel->name = $name;
        $aIModel->agenticUseCase = $this->agenticUseCasesByModelName[$name];
        return $aIModel;
    }
}
