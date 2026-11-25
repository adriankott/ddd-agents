<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Memories;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Memories\AgentEmbeddingRepairTarget;
use DDD\Domain\AI\Entities\Conversations\Memories\AgentEmbeddingRepairTargets;
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
 * THE memory port of the agent: every read and write of the durable memory (the owner's standing directives, curated
 * facts and solutions) goes through this store — the core's questions ({@see AgentMemoriesService}, while
 * `AI.agentMemory.enabled` is on), the memory tools ({@see \DDD\Presentation\Mcp\Client\Agents\AgentMemoriesMCPPackage}),
 * the curation after a turn ({@see AgentMemoryCurationService}), the embedding repair and the evaluation purge. Nothing
 * outside the store, its repositories and its entities names the package's memory table or one of its columns.
 *
 * The default answers from the package's table (`AgentMemories`): reads by scope (an account plus an optional space),
 * the similarity search, the write (cut, hash, embed, store) and the bitemporal closing of a row.
 *
 * A host that keeps memories in rows of its own puts a same-path subclass of this service under its root namespace
 * and answers the port's methods from its rows; {@see AgentMemory} is the carrier it fills (its `id` is the host row's
 * id, `accountId` / `agentSpaceId` the scope, `memoryType`, `content`, `validFrom` / `validTo` the validity), and the
 * scope arguments stay `(int $accountId, ?int $agentSpaceId)`, an empty space meaning account level. The port:
 * {@see findCurrentMemoriesForScope()}, {@see findCurrentDirectivesForScope()}, {@see findSimilarMemories()},
 * {@see findCurrentDuplicateOfContent()}, {@see persistMemory()}, {@see embedMemory()}, {@see closeValidityWindow()},
 * {@see invalidateMemory()}, `find()`, {@see getEmbeddingRepairTargets()} and {@see getColumnsNotPurgedByEvalPurge()};
 * {@see curateConversationAfterTurn()} and {@see isConversationCuratable()} decide the curation and stay as they are
 * unless the host curates differently. The host switches memory on (`AI.agentMemory.enabled`) without creating the
 * package's table.
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
     * The memory tables whose zero vectors the embedding repair re-embeds (`app:agent:repair-zero-embeddings`). The
     * default: the package's table (content `content`, vector `embedding`). A host store names its own table, or none.
     *
     * @return AgentEmbeddingRepairTargets
     */
    public function getEmbeddingRepairTargets(): AgentEmbeddingRepairTargets
    {
        $agentEmbeddingRepairTargets = new AgentEmbeddingRepairTargets();
        $memoryEmbeddingRepairTarget = AgentEmbeddingRepairTarget::forModel(DBAgentMemoryModel::class, 'content', 'embedding');
        $agentEmbeddingRepairTargets->add($memoryEmbeddingRepairTarget);
        return $agentEmbeddingRepairTargets;
    }

    /**
     * The memory columns that point at a conversation or its rows and are deliberately NOT a step of the evaluation
     * purge, `table.column` => why ({@see \DDD\Domain\AI\Services\Eval\AIConversationEvalPurgeService::getColumnsNotPurged()}).
     * The default: the package's table, whatever the switch says (its model exists in every installation). A host
     * store backed by its own rows names its columns, or returns [].
     *
     * @return string[] `table.column` => reason
     */
    public function getColumnsNotPurgedByEvalPurge(): array
    {
        return [
            DBAgentMemoryModel::getTableName() . '.sourceMessageId' => 'a plain number recording where a memory was said; an evaluation run is never curated into memories, and a memory outlives its message',
        ];
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
