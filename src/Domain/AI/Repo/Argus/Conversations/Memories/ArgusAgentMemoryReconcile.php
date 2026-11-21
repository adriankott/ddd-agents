<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\Conversations\Memories;

use DDD\Domain\AI\Entities\Conversations\Agent\Prompts\AgentAIPromptNames;
use DDD\Domain\AI\Entities\Conversations\Memories\AgentMemories;
use DDD\Domain\AI\Entities\Conversations\Memories\Curation\AgentMemoryCurationResult;
use DDD\Domain\AI\Entities\Conversations\Memories\Curation\AgentMemoryOperations;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Entities\Models\Selection\ModelScope;
use DDD\Domain\AI\Entities\Prompts\AIPrompt;
use DDD\Domain\AI\Repo\Argus\Attributes\ArgusLanguageModel;
use DDD\Domain\AI\Repo\Argus\Attributes\ArgusOpenAIReasoningEffort;
use DDD\Domain\AI\Repo\Argus\Traits\ArgusAILanguageModelTrait;
use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use DateTimeZone;

/**
 * The memory curation's second step: for each candidate, ADD / UPDATE / INVALIDATE / NOOP / CONFLICT against the
 * nearest stored memories of the scope, each shown as `[REF_<n>]` with its type, subject, source and age. In
 * {@see $resolutionMode} the candidates settle a contradiction the agent raised, and the resolution prompt is used
 * (INVALIDATE / ADD / NOOP only).
 */
#[ArgusLoad(
    loadEndpoint: 'POST:https://openrouter.ai/api/v1/chat/completions',
    cacheLevel: ArgusCache::CACHELEVEL_NONE,
    cacheTtl: ArgusCache::CACHELEVEL_NONE
)]
#[ArgusLanguageModel(
    defaultAIPromptName: AgentAIPromptNames::APP_AI_MEMORY_RECONCILE,
    temperature: 0.1,
    responseFormat: ArgusLanguageModel::RESPONSE_FORMAT_JSON_OBJECT,
    aiUsageScope: AgentAIUsageScope::AI_MEMORY_RECONCILE,
    modelScope: ModelScope::COMPACTION,
    providerPrioritizationStrategy: ArgusLanguageModel::PROVIDER_PRIORITIZATION_STRATEGY_SPEED,
    reasoningEffort: ArgusOpenAIReasoningEffort::LOW,
)]
class ArgusAgentMemoryReconcile extends ArgusAgentMemoryCurationCall
{
    use ArgusTrait, ArgusAILanguageModelTrait;

    /** @var int The characters a candidate's or neighbour's content shows at most */
    public const int MAX_RENDERED_CONTENT_CHARS = 600;

    /** @var AgentMemoryOperations The candidates of the extraction */
    public AgentMemoryOperations $candidates;

    /** @var AgentMemories|null The neighbours, in the order of their `REF_<n>` labels */
    public ?AgentMemories $neighbours = null;

    /** @var string The scope, as the prompt reads it */
    public string $scopeLabel = '';

    /** @var DateTimeZone|null The zone the neighbours' ages are read in; null = UTC */
    public ?DateTimeZone $sourceTimezone = null;

    /** @var bool The candidates settle a raised contradiction: the resolution prompt */
    public bool $resolutionMode = false;

    public function __construct()
    {
        parent::__construct();
        $this->candidates = new AgentMemoryOperations();
    }

    public function getAIPrompt(): ?AIPrompt
    {
        if (isset($this->aiPrompt)) {
            return $this->aiPrompt;
        }
        $promptName = $this->resolutionMode
            ? AgentAIPromptNames::APP_AI_MEMORY_RECONCILE_RESOLUTION
            : $this->getArgusLanguageModelInstance()?->defaultAIPromptName;
        if ($promptName === null) {
            return null;
        }
        $loadedAIPrompt = $this->findAIPromptByNameOrThrow((string)$promptName);
        if ($loadedAIPrompt instanceof AIPrompt) {
            $this->aiPrompt = $loadedAIPrompt;
        }
        return $this->aiPrompt ?? null;
    }

    public function getAIPromptWithParametersApplied(): AIPrompt
    {
        if ($this->billingEntity) {
            $this->billAICostsTo(entityWithinAccount: $this->billingEntity);
        } elseif ($this->budgetAccount) {
            $this->billAICostsTo(account: $this->budgetAccount);
        }
        $prompt = $this->getAIPrompt();
        $prompt->setParameter('SCOPE', $this->scopeLabel !== '' ? $this->scopeLabel : 'account-wide');
        $prompt->setParameter('NEIGHBOUR_MEMORIES', $this->renderIndexedNeighbours());
        $prompt->setParameter('MEMORY_LANGUAGE', $this->memoryLanguageCode);
        return $prompt;
    }

    public function getUserContent(): string|array
    {
        $candidateEntries = [];
        foreach ($this->candidates->getElements() as $candidate) {
            $candidateEntries[] = [
                'type' => $candidate->memoryType,
                'subject' => $candidate->subject,
                'content' => $this->capContent($candidate->content),
                'effectiveFrom' => $candidate->effectiveFrom,
                'resolvesConflict' => $candidate->resolvesConflict,
                'sourceMessageId' => $candidate->sourceMessageId,
            ];
        }
        return "CANDIDATES:\n" . (string)json_encode(['candidates' => $candidateEntries], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The neighbours as `[REF_<n>] <type>/<subject> (<source>, since <day> (<n>d ago)): "<content>"`, or `(none)`.
     *
     * @return string
     */
    public function renderIndexedNeighbours(): string
    {
        if (!$this->neighbours) {
            return '(none)';
        }
        $renderedLines = '';
        foreach (array_values($this->neighbours->getElements()) as $neighbourIndex => $neighbour) {
            $ageDescription = $neighbour->getValidFromAgeDescription($this->sourceTimezone ?? new DateTimeZone('UTC'));
            $renderedLines .= sprintf(
                "[REF_%d] %s/%s (%s%s): \"%s\"\n",
                $neighbourIndex,
                $neighbour->memoryType,
                $neighbour->subject,
                $neighbour->source,
                $ageDescription !== null ? ', ' . $ageDescription : '',
                $this->capContent($neighbour->content)
            );
        }
        return $renderedLines === '' ? '(none)' : $renderedLines;
    }

    protected function capContent(?string $content): string
    {
        $content = (string)$content;
        return mb_strlen($content) > static::MAX_RENDERED_CONTENT_CHARS
            ? rtrim(mb_substr($content, 0, static::MAX_RENDERED_CONTENT_CHARS)) . '…'
            : $content;
    }

    protected function applyLoadResult(string $resultText): void
    {
        $this->result = AgentMemoryCurationResult::fromModelJson($resultText);
    }
}
