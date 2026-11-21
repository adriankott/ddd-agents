<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Memories;

use DDD\Domain\AI\Entities\Conversations\Memories\AgentMemories;
use DDD\Domain\AI\Entities\Conversations\Memories\Curation\AgentMemoryCurationResult;
use DDD\Domain\AI\Entities\Conversations\Memories\Curation\AgentMemoryOperations;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Exceptions\Conversations\Memories\AgentMemoryCurationUnavailableException;
use DDD\Domain\AI\Repo\Argus\Conversations\Memories\ArgusAgentMemoryCurationCall;
use DDD\Domain\AI\Repo\Argus\Conversations\Memories\ArgusAgentMemoryExtraction;
use DDD\Domain\AI\Repo\Argus\Conversations\Memories\ArgusAgentMemoryReconcile;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use DateTimeZone;

/**
 * The model calls of the memory curation: the extraction of candidates and their reconciliation against the stored
 * neighbours. An answer of the wrong shape is asked once more; no answer at all after that throws
 * {@see AgentMemoryCurationUnavailableException} (the conversation stays unharvested and is curated again later), a
 * wrong shape after that is logged and treated as "nothing to store".
 */
class AgentMemoryCurationModelCallsService extends Service
{
    /** @var int How often a call whose answer had the wrong shape is repeated */
    public const int MAX_PARSE_RETRIES = 1;

    /**
     * The candidate memories in `$conversationDelta` (every one an ADD proposal).
     *
     * @param AIConversationMessages $conversationDelta The new messages
     * @param AIConversationMessages|null $recentContextWindow The messages before them (read-only context)
     * @param string $memoryLanguageCode The language every content is written in
     * @param string $structuredHomes The application's data with a home of its own
     * @param Account|null $budgetAccount
     * @param Entity|null $billingEntity
     * @param DateTimeZone|null $sourceTimezone
     * @return AgentMemoryCurationResult
     */
    public function extractCandidates(
        AIConversationMessages $conversationDelta,
        ?AIConversationMessages $recentContextWindow,
        string $memoryLanguageCode,
        string $structuredHomes,
        ?Account $budgetAccount = null,
        ?Entity $billingEntity = null,
        ?DateTimeZone $sourceTimezone = null
    ): AgentMemoryCurationResult {
        $argusAgentMemoryExtraction = ArgusAgentMemoryExtraction::newInstance();
        $argusAgentMemoryExtraction->conversationDelta = $conversationDelta;
        $argusAgentMemoryExtraction->recentContextWindow = $recentContextWindow;
        $argusAgentMemoryExtraction->memoryLanguageCode = $memoryLanguageCode;
        $argusAgentMemoryExtraction->structuredHomes = $structuredHomes;
        $argusAgentMemoryExtraction->budgetAccount = $budgetAccount;
        $argusAgentMemoryExtraction->billingEntity = $billingEntity;
        $argusAgentMemoryExtraction->sourceTimezone = $sourceTimezone;
        return $this->loadWithParseRetry($argusAgentMemoryExtraction);
    }

    /**
     * The operations for `$candidates` against `$neighbours`: the candidates that settle a raised contradiction go to
     * the resolution prompt, the others to the normal one; the two answers are merged.
     *
     * @param AgentMemoryOperations $candidates
     * @param AgentMemories $neighbours In the order of their `REF_<n>` labels
     * @param string $scopeLabel
     * @param string $memoryLanguageCode
     * @param Account|null $budgetAccount
     * @param Entity|null $billingEntity
     * @param DateTimeZone|null $sourceTimezone
     * @return AgentMemoryCurationResult
     */
    public function reconcileCandidatesRoutingByResolution(
        AgentMemoryOperations $candidates,
        AgentMemories $neighbours,
        string $scopeLabel,
        string $memoryLanguageCode,
        ?Account $budgetAccount = null,
        ?Entity $billingEntity = null,
        ?DateTimeZone $sourceTimezone = null
    ): AgentMemoryCurationResult {
        $mergedResult = new AgentMemoryCurationResult();
        foreach ([false, true] as $resolutionMode) {
            $branchCandidates = $candidates->getOperationsResolvingConflict($resolutionMode);
            if ($branchCandidates->count() === 0) {
                continue;
            }
            $argusAgentMemoryReconcile = ArgusAgentMemoryReconcile::newInstance();
            $argusAgentMemoryReconcile->candidates = $branchCandidates;
            $argusAgentMemoryReconcile->neighbours = $neighbours;
            $argusAgentMemoryReconcile->scopeLabel = $scopeLabel;
            $argusAgentMemoryReconcile->memoryLanguageCode = $memoryLanguageCode;
            $argusAgentMemoryReconcile->budgetAccount = $budgetAccount;
            $argusAgentMemoryReconcile->billingEntity = $billingEntity;
            $argusAgentMemoryReconcile->sourceTimezone = $sourceTimezone;
            $argusAgentMemoryReconcile->resolutionMode = $resolutionMode;
            $mergedResult->mergeResult($this->loadWithParseRetry($argusAgentMemoryReconcile));
        }
        return $mergedResult;
    }

    /**
     * Loads `$preparedCall`, and a fresh copy of it once more per retry while the answer has the wrong shape.
     *
     * @param ArgusAgentMemoryCurationCall $preparedCall A prepared call that has not been loaded
     * @return AgentMemoryCurationResult
     * @throws AgentMemoryCurationUnavailableException When no attempt returned any answer
     */
    protected function loadWithParseRetry(ArgusAgentMemoryCurationCall $preparedCall): AgentMemoryCurationResult
    {
        $loadedCall = clone $preparedCall;
        $this->loadCall($loadedCall);
        for ($retryIndex = 0; $retryIndex < static::MAX_PARSE_RETRIES; $retryIndex++) {
            if ($loadedCall->getResultOrInvalidEmptyResult()->schemaValid) {
                break;
            }
            $loadedCall = clone $preparedCall;
            $this->loadCall($loadedCall);
        }
        $result = $loadedCall->getResultOrInvalidEmptyResult();
        if ($result->schemaValid) {
            return $result;
        }
        $rawResponseExcerpt = mb_substr((string)json_encode($loadedCall->lastRawResponse ?? null, JSON_UNESCAPED_UNICODE), 0, 600);
        $modelName = $loadedCall->getAIModel()->name ?? 'unknown';
        if ($loadedCall->result === null) {
            throw new AgentMemoryCurationUnavailableException(sprintf(
                '%s got no answer from model %s after %d attempts: %s',
                $loadedCall::class,
                $modelName,
                static::MAX_PARSE_RETRIES + 1,
                $rawResponseExcerpt
            ));
        }
        DDDService::instance()->getLogger()->warning(sprintf(
            'Agent memory curation: %s gave no usable answer after %d attempts (model %s): %s',
            $loadedCall::class,
            static::MAX_PARSE_RETRIES + 1,
            $modelName,
            $rawResponseExcerpt
        ));
        return $result;
    }

    /**
     * Sends one call (no cache: every call is a fresh decision).
     *
     * @param ArgusAgentMemoryCurationCall $argusAgentMemoryCurationCall
     * @return void
     */
    protected function loadCall(ArgusAgentMemoryCurationCall $argusAgentMemoryCurationCall): void
    {
        if ($argusAgentMemoryCurationCall instanceof ArgusAgentMemoryExtraction || $argusAgentMemoryCurationCall instanceof ArgusAgentMemoryReconcile) {
            $argusAgentMemoryCurationCall->argusLoad(useArgusEntityCache: false, useApiACallCache: false);
        }
    }
}
