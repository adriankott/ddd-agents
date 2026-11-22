<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\WebSearch;

use DDD\Domain\AI\Entities\Conversations\Agent\Prompts\AgentAIPromptNames;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Prompts\AIPrompt;
use DDD\Domain\AI\Entities\WebSearch\WebPageExtract;
use DDD\Domain\AI\Entities\WebSearch\WebSearchConfiguration;
use DDD\Domain\AI\Entities\WebSearch\WebPageMarkdown;
use DDD\Domain\AI\Repo\Argus\Attributes\ArgusLanguageModel;
use DDD\Domain\AI\Repo\Argus\Attributes\ArgusOpenAIReasoningEffort;
use DDD\Domain\AI\Repo\Argus\Traits\ArgusAILanguageModelTrait;
use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperation;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use DDD\Infrastructure\Traits\Serializer\Attributes\DontPersistProperty;

/**
 * ONE page read by a fast model for an extraction task: no reasoning, the speed-first provider order, a JSON answer
 * parsed onto the {@see WebPageExtract} with every quote verified against the page and the excerpts. The model is
 * `AI.webSearch.reading.modelName` (default {@see AIModel::MODEL_OPENAI_GPT6_LUNA}); the prompt is
 * {@see AgentAIPromptNames::APP_AI_WEB_SEARCH_PAGE_EXTRACT}. The cost is recorded by the language-model trait under
 * {@see AgentAIUsageScope::AI_WEB_PAGE_EXTRACT}, billed to the payer the service sets ({@see billAICostsTo()}).
 *
 * Service-side load ({@see \DDD\Domain\AI\Services\WebSearch\AIWebSearchService::findWebPageExtracts()}), as a child of
 * {@see ArgusWebPageExtracts}. The cache key is the canonical URL, the task, the evidence and {@see EXTRACTION_VERSION},
 * so a changed page or task is read again; only a reading of a real page is kept.
 */
#[ArgusLoad(
    loadEndpoint: 'POST:https://openrouter.ai/api/v1/chat/completions',
    cacheLevel: ArgusCache::CACHELEVEL_MEMORY_AND_DB,
    cacheTtl: ArgusCache::CACHE_TTL_ONE_HOUR,
    timeout: self::EXTRACTION_TIMEOUT_SECONDS,
)]
#[ArgusLanguageModel(
    defaultAIModelName: AIModel::MODEL_OPENAI_GPT6_LUNA,
    defaultAIPromptName: AgentAIPromptNames::APP_AI_WEB_SEARCH_PAGE_EXTRACT,
    temperature: 0.0,
    responseFormat: ArgusLanguageModel::RESPONSE_FORMAT_JSON_OBJECT,
    aiUsageScope: AgentAIUsageScope::AI_WEB_PAGE_EXTRACT,
    reasoningEffort: ArgusOpenAIReasoningEffort::NONE,
    providerPrioritizationStrategy: ArgusLanguageModel::PROVIDER_PRIORITIZATION_STRATEGY_SPEED,
    fastMode: true,
)]
class ArgusWebPageExtract extends WebPageExtract
{
    use ArgusTrait, ArgusAILanguageModelTrait {
        ArgusAILanguageModelTrait::handleLoadResponseInternal as handleLanguageModelLoadResponse;
        ArgusAILanguageModelTrait::getAIModel as getAIModelOfTheAttribute;
    }

    /** One reading answers in a few seconds; past this bound the page counts as failed. */
    public const float EXTRACTION_TIMEOUT_SECONDS = 15;

    /** Raised when the prompt or the parsing changes, so no cached reading of the old rules is served. */
    public const string EXTRACTION_VERSION = '3';

    /** What the agent wants from the page, in its own words. */
    #[DontPersistProperty]
    public string $extractionTask = '';

    /** The page text the model reads (already cut to the configured cap); null when the page could not be read. */
    #[DontPersistProperty]
    public ?string $pageText = null;

    /** @var string[] The search engine's excerpts of this page, when the page came from a search. */
    #[DontPersistProperty]
    public array $snippets = [];

    public function uniqueKey(): string
    {
        $evidenceText = ($this->pageText ?? '') . "\n\u{0}\n" . implode("\n\u{0}\n", $this->snippets);
        return self::uniqueKeyStatic(implode('|', [
            WebPageMarkdown::canonicalUrlKey((string)($this->url ?? '')),
            sha1(trim($this->extractionTask)),
            sha1($evidenceText),
            self::EXTRACTION_VERSION,
        ]));
    }

    /**
     * The reading model: `AI.webSearch.reading.modelName` when the application names one, else the attribute's
     * default.
     */
    public function getAIModel(): ?AIModel
    {
        if (isset($this->aiModel)) {
            return $this->aiModel;
        }
        $configuredModelName = trim((string)WebSearchConfiguration::findValue('reading.modelName', ''));
        if ($configuredModelName !== '') {
            $this->aiModel = $this->findAIModelByNameOrThrow($configuredModelName);
            return $this->aiModel;
        }
        return $this->getAIModelOfTheAttribute();
    }

    /** The payer is set on the loader by the service before the load (the gate reads it). */
    public function getAIPromptWithParametersApplied(): AIPrompt
    {
        return $this->getAIPrompt();
    }

    public function getUserContent(): string|array
    {
        $userContent = 'EXTRACTION TASK:' . "\n" . trim($this->extractionTask) . "\n\n"
            . 'SOURCE: ' . $this->url . "\n"
            . 'TITLE: ' . trim((string)($this->title ?? '')) . "\n";
        if ($this->snippets !== []) {
            $userContent .= "\nSEARCH EXCERPTS OF THIS SOURCE:\n" . implode("\n---\n", $this->snippets) . "\n";
        }
        $userContent .= trim((string)$this->pageText) !== ''
            ? "\nPAGE TEXT:\n" . $this->pageText
            : "\nPAGE TEXT: (the page could not be read — use the search excerpts alone)";
        return $userContent;
    }

    protected function applyLoadResult(string $resultText): void
    {
        $this->applyExtractorAnswer($resultText, $this->pageText, $this->snippets);
    }

    /**
     * The language-model response handling of the trait, then: a request without an answer (timeout, transport
     * failure) marks the extract failed with the reason, and a reading that is not a real page's (excerpts only, a
     * failed parse, no answer) is removed from the cache again — the next call reads it afresh.
     */
    public function handleLoadResponseInternal(
        mixed &$callResponseData = null,
        ?ArgusApiOperation &$apiOperation = null
    ): void {
        $this->handleLanguageModelLoadResponse($callResponseData, $apiOperation);
        if (!$this->getArgusSettings()->isLoadedSuccessfully) {
            $rejectionReason = $apiOperation !== null ? trim((string)($apiOperation->rejectionReason ?? '')) : '';
            $this->status = self::STATUS_EXTRACTION_FAILED;
            $this->failureReason = 'the fast model did not answer' . ($rejectionReason !== '' ? ': ' . mb_substr($rejectionReason, 0, 120) : '');
        }
        if (!$this->isCacheable()) {
            $this->clearArgusCache();
        }
    }
}
