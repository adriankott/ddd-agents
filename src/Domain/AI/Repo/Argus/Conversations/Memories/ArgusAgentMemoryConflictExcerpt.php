<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\Conversations\Memories;

use DDD\Domain\AI\Entities\Conversations\Agent\Prompts\AgentAIPromptNames;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Entities\Models\Selection\ModelScope;
use DDD\Domain\AI\Entities\Prompts\AIPrompt;
use DDD\Domain\AI\Repo\Argus\Attributes\ArgusLanguageModel;
use DDD\Domain\AI\Repo\Argus\Attributes\ArgusOpenAIReasoningEffort;
use DDD\Domain\AI\Repo\Argus\Traits\ArgusAILanguageModelTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;

/**
 * The one or two sentences of an earlier owner message that state the value a new statement contradicts: the excerpt
 * the conflict signal shows when the value was said in another conversation.
 */
#[ArgusLoad(
    loadEndpoint: 'POST:https://openrouter.ai/api/v1/chat/completions',
    cacheLevel: ArgusCache::CACHELEVEL_NONE,
    cacheTtl: ArgusCache::CACHELEVEL_NONE
)]
#[ArgusLanguageModel(
    defaultAIPromptName: AgentAIPromptNames::APP_AI_MEMORY_CONFLICT_EXCERPT,
    temperature: 0.2,
    aiUsageScope: AgentAIUsageScope::AI_MEMORY_RECONCILE,
    modelScope: ModelScope::COMPACTION,
    providerPrioritizationStrategy: ArgusLanguageModel::PROVIDER_PRIORITIZATION_STRATEGY_SPEED,
    reasoningEffort: ArgusOpenAIReasoningEffort::LOW,
)]
class ArgusAgentMemoryConflictExcerpt extends ValueObject
{
    use ArgusTrait, ArgusAILanguageModelTrait;

    /** @var string|null The earlier owner message */
    public ?string $originalMessageText = null;

    /** @var string|null The stored value the new statement contradicts */
    public ?string $contradictedMemoryContent = null;

    /** @var string|null The new statement */
    public ?string $newContradictingStatement = null;

    /** @var Entity|null The entity within the account the AI costs are billed to */
    public ?Entity $billingEntity = null;

    /** @var string|null The excerpt; null when the model returned nothing */
    public ?string $curatedOriginalExcerpt = null;

    public function getAIPromptWithParametersApplied(): AIPrompt
    {
        if ($this->billingEntity) {
            $this->billAICostsTo(entityWithinAccount: $this->billingEntity);
        }
        $prompt = $this->getAIPrompt();
        $prompt->setParameter('CONTRADICTED_MEMORY', (string)($this->contradictedMemoryContent ?? ''));
        $prompt->setParameter('NEW_CONTRADICTING_STATEMENT', (string)($this->newContradictingStatement ?? ''));
        return $prompt;
    }

    public function getUserContent(): string|array
    {
        return (string)($this->originalMessageText ?? '');
    }

    protected function applyLoadResult(string $resultText): void
    {
        $excerpt = trim($this->removeProgrammingLanguageMarkup($resultText));
        $this->curatedOriginalExcerpt = $excerpt !== '' ? $excerpt : null;
    }
}
