<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Memories;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Memories\AgentMemories;
use DDD\Domain\AI\Entities\Conversations\Memories\AgentMemory;
use DDD\Domain\AI\Entities\Texts\Embeddings\TextEmbedding;
use DDD\Domain\AI\Exceptions\Texts\Embeddings\TextEmbeddingNotGeneratedException;
use DDD\Domain\AI\MessageHandlers\AgentMemoryCurateMessage;
use DDD\Domain\AI\Repo\DB\Conversations\Memories\DBAgentMemories;
use DDD\Domain\AI\Repo\DB\Conversations\Memories\DBAgentMemory;
use DDD\Domain\AI\Repo\DB\Conversations\Memories\DBAgentMemoryModel;
use DDD\Domain\AI\Services\Texts\Embeddings\AITextEmbeddingsService;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\Base\Services\EntitiesService;
use DDD\Domain\Common\Entities\MathEntities\Vector;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * The package's memory store: reads by scope (an account plus an optional space), the similarity search, the write
 * (cut, hash, embed, store) and the bitemporal closing of a row. What a memory is and how it is keyed lives on
 * {@see AgentMemory}; the curation after a turn is {@see AgentMemoryCurationService}. The core reaches this store
 * through the port {@see AgentMemoriesService} while the store is switched on.
 *
 * @method AgentMemory|null find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method AgentMemories findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method DBAgentMemory getEntityRepoClassInstance()
 * @method DBAgentMemories getEntitySetRepoClassInstance()
 */
class AgentMemoryStoreService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = AgentMemory::class;

    /**
     * The current memories of the scope, newest first.
     *
     * @param int $accountId
     * @param int|null $agentSpaceId Empty = the account-level memories only
     * @param string|null $memoryType
     * @param int|null $limit
     * @return AgentMemories
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findCurrentMemoriesForScope(
        int $accountId,
        ?int $agentSpaceId = null,
        ?string $memoryType = null,
        ?int $limit = null
    ): AgentMemories {
        return $this->getEntitySetRepoClassInstance()->findCurrentMemoriesForScope($accountId, $agentSpaceId, $memoryType, $limit)
            ?? AgentMemories::newInstance();
    }

    /**
     * The current directives of the scope, newest first, at most {@see AgentMemories::MAX_DIRECTIVES_IN_CONTEXT} (the
     * number the model input takes).
     *
     * @param int $accountId
     * @param int|null $agentSpaceId
     * @return AgentMemories
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findCurrentDirectivesForScope(int $accountId, ?int $agentSpaceId = null): AgentMemories
    {
        return $this->findCurrentMemoriesForScope(
            $accountId,
            $agentSpaceId,
            AgentMemory::MEMORY_TYPE_DIRECTIVE,
            AgentMemories::MAX_DIRECTIVES_IN_CONTEXT
        );
    }

    /**
     * The current memories of the scope most similar to `$searchText`. With an embedding provider: the nearest by
     * cosine distance. Without one (the provider throws): the memories whose content contains the text, newest first.
     *
     * @param string $searchText
     * @param int $accountId
     * @param int|null $agentSpaceId
     * @param int $limit
     * @param string|null $memoryType
     * @return AgentMemories
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findSimilarMemories(
        string $searchText,
        int $accountId,
        ?int $agentSpaceId = null,
        int $limit = 10,
        ?string $memoryType = null
    ): AgentMemories {
        $queryEmbedding = $this->generateQueryEmbedding($searchText, $accountId);
        if ($queryEmbedding === null) {
            return $this->getEntitySetRepoClassInstance()->findContainingText($searchText, $accountId, $agentSpaceId, $limit, $memoryType)
                ?? AgentMemories::newInstance();
        }
        return $this->getEntitySetRepoClassInstance()->findNearestByEmbedding($queryEmbedding, $accountId, $agentSpaceId, $limit, $memoryType)
            ?? AgentMemories::newInstance();
    }

    /**
     * The current memory of exactly this scope that holds the same content (after normalization), or null.
     *
     * @param int $accountId
     * @param int|null $agentSpaceId
     * @param string $content
     * @return AgentMemory|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findCurrentDuplicateOfContent(int $accountId, ?int $agentSpaceId, string $content): ?AgentMemory
    {
        return $this->getEntitySetRepoClassInstance()->findCurrentMemoryByContentHash(
            $accountId,
            $agentSpaceId,
            AgentMemory::hashForContent($content)
        );
    }

    /**
     * Stores a memory: cuts the content, stamps the scope key and the content hash, embeds the content and writes the
     * row (insert or update).
     *
     * @param AgentMemory $agentMemory
     * @return AgentMemory
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function persistMemory(AgentMemory $agentMemory): AgentMemory
    {
        $agentMemory->prepareForPersistence();
        if (!isset($agentMemory->validFrom)) {
            $agentMemory->validFrom = new DateTime('now');
        }
        $this->embedMemory($agentMemory);
        return $this->update($agentMemory, 0);
    }

    /**
     * Sets {@see AgentMemory::$embedding} from the content. Without an embedding the row is stored with the zero vector
     * (the column default for a new row) and stays out of the similarity search until the embedding repair covers it;
     * one warning is logged.
     *
     * @param AgentMemory $agentMemory
     * @return void
     */
    public function embedMemory(AgentMemory $agentMemory): void
    {
        /** @var AITextEmbeddingsService $aITextEmbeddingsService */
        $aITextEmbeddingsService = DDDService::instance()->getService(AITextEmbeddingsService::class);
        try {
            $agentMemory->embedding = $aITextEmbeddingsService->generateEmbeddingForText($agentMemory->content);
        } catch (TextEmbeddingNotGeneratedException $embeddingNotGenerated) {
            if (isset($agentMemory->id)) {
                // an edit of a stored row: the update skips an unset column, so the old content's vector is replaced here
                $agentMemory->embedding = TextEmbedding::zeroVectorOfDimension(Vector::DIMENSION_OPENAI_EMBEDDING_SMALL);
            } else {
                unset($agentMemory->embedding);
            }
            $aITextEmbeddingsService->logEmbeddingFailure(
                $embeddingNotGenerated,
                'memory stored without an embedding (left out of the similarity search until repaired)',
                ['accountId' => $agentMemory->accountId ?? null, 'memoryId' => $agentMemory->id ?? null]
            );
        }
    }

    /**
     * Closes the current row `$targetId` because `$successorId` replaces it: one guarded UPDATE, so a row another
     * writer closed first stays as it is.
     *
     * @param int $targetId
     * @param int $successorId
     * @param DateTime $validTo
     * @param DateTime|null $invalidatedAt
     * @return int The number of rows closed (0 or 1)
     */
    public function closeValidityWindow(int $targetId, int $successorId, DateTime $validTo, ?DateTime $invalidatedAt = null): int
    {
        $tableName = DBAgentMemoryModel::getTableName();
        return (int)EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            "UPDATE `{$tableName}` SET `validTo` = :validTo, `invalidatedAt` = :invalidatedAt, `supersededByMemoryId` = :successorId"
            . ' WHERE `id` = :targetId AND `validTo` IS NULL AND `invalidatedAt` IS NULL',
            [
                'validTo' => $validTo->format('Y-m-d H:i:s'),
                'invalidatedAt' => ($invalidatedAt ?? new DateTime('now'))->format('Y-m-d H:i:s'),
                'successorId' => $successorId,
                'targetId' => $targetId,
            ]
        );
    }

    /**
     * Forgets a memory: closes it now, it stays as history. The row is loaded with read rights first, so only a memory
     * the authenticated account may read can be forgotten.
     *
     * @param int $memoryId
     * @return AgentMemory|null The closed memory, or null when none was found or it was closed already
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function invalidateMemory(int $memoryId): ?AgentMemory
    {
        $agentMemory = $this->find($memoryId);
        if (!$agentMemory instanceof AgentMemory || !isset($agentMemory->id) || !$agentMemory->isCurrentlyValid()) {
            return null;
        }
        $now = (new DateTime('now'))->format('Y-m-d H:i:s');
        $tableName = DBAgentMemoryModel::getTableName();
        EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            "UPDATE `{$tableName}` SET `validTo` = :closedAt, `invalidatedAt` = :closedAt"
            . ' WHERE `id` = :id AND `validTo` IS NULL AND `invalidatedAt` IS NULL',
            ['closedAt' => $now, 'id' => (int)$agentMemory->id]
        );
        return $this->find($memoryId, useEntityRegistrCache: false);
    }

    /**
     * The curation after an owner turn: only a root conversation with an id and an account, where the owner was
     * present (a scheduled or background run holds no owner statement, a supervisor checkpoint no owner content), and
     * never an evaluation run (its owner is simulated). `$async` dispatches {@see AgentMemoryCurateMessage}; otherwise
     * the curation runs inline.
     *
     * @param AIConversation $conversation
     * @param bool $async
     * @return void
     */
    public function curateConversationAfterTurn(AIConversation $conversation, bool $async = true): void
    {
        if (!$this->isConversationCuratable($conversation)) {
            return;
        }
        if ($async) {
            (new AgentMemoryCurateMessage((int)$conversation->id))->dispatch();
            return;
        }
        /** @var AgentMemoryCurationService $agentMemoryCurationService */
        $agentMemoryCurationService = DDDService::instance()->getService(AgentMemoryCurationService::class);
        $agentMemoryCurationService->curateConversation($conversation);
    }

    /**
     * Whether the curation harvests `$conversation` (see {@see curateConversationAfterTurn()}).
     *
     * @param AIConversation $conversation
     * @return bool
     */
    public function isConversationCuratable(AIConversation $conversation): bool
    {
        if (!isset($conversation->id) || !isset($conversation->accountId) || !$conversation->accountId || $conversation->isDelegation()) {
            return false;
        }
        if ($conversation->isSupervisorTandem() || !$conversation->isUserPresentRun()) {
            return false;
        }
        return (isset($conversation->runSettings) ? ($conversation->runSettings->evalRun ?? null) : null) !== true;
    }

    /**
     * The embedding of a search text, or null when the provider gives none (one warning is logged).
     *
     * @param string $searchText
     * @param int $accountId
     * @return TextEmbedding|null
     */
    protected function generateQueryEmbedding(string $searchText, int $accountId): ?TextEmbedding
    {
        /** @var AITextEmbeddingsService $aITextEmbeddingsService */
        $aITextEmbeddingsService = DDDService::instance()->getService(AITextEmbeddingsService::class);
        try {
            return $aITextEmbeddingsService->generateEmbeddingForText($searchText);
        } catch (TextEmbeddingNotGeneratedException $embeddingNotGenerated) {
            $aITextEmbeddingsService->logEmbeddingFailure(
                $embeddingNotGenerated,
                'memory search fell back to a text match (no query embedding)',
                ['accountId' => $accountId]
            );
            return null;
        }
    }
}
