<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Memories;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Memories\AgentEmbeddingRepairTarget;
use DDD\Domain\AI\Entities\Conversations\Memories\AgentEmbeddingRepairTargets;
use DDD\Domain\AI\Entities\Conversations\Memories\AgentMemory;
use DDD\Domain\AI\Entities\Conversations\Memories\AgentMemoryHit;
use DDD\Domain\AI\Entities\Conversations\Memories\AgentMemoryHits;
use DDD\Domain\AI\Entities\Conversations\Memories\AgentStandingDirectives;
use DDD\Domain\AI\Repo\DB\Conversations\Memories\DBAgentMemoryModel;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * What the agent core asks of the durable memory (the owner's standing directives, curated facts and solutions): the
 * directives of a scope, the curation after a turn, a similarity search, and the memory table the embedding repair
 * covers. The core resolves this service only through `DDDService::instance()->getService(AgentMemoriesService::class)`.
 *
 * Two ways to answer:
 * - the package's memory store ({@see AgentMemoryStoreService}, table `AgentMemories`): switched on by
 *   `AI.agentMemory.enabled` (`config/app/AI/agentMemory.php`, default off). While it is off every method answers "no
 *   memory" and nothing reads or writes the table, so an application without the table is unaffected;
 * - an application's own memory component: a same-path subclass under the application's root namespace that overrides
 *   the methods (and leaves the switch off).
 */
class AgentMemoriesService extends Service
{
    /** @var string The config key of the switch of the package's memory store */
    public const string CONFIG_MEMORY_STORE_ENABLED = 'AI.agentMemory.enabled';

    /**
     * Whether the package's memory store is switched on (`AI.agentMemory.enabled`).
     *
     * @return bool
     */
    public static function isMemoryStoreEnabled(): bool
    {
        return Config::get(self::CONFIG_MEMORY_STORE_ENABLED) === true;
    }

    /**
     * The currently valid standing directives of account `$accountId` that apply to `$agentSpace` (null = the
     * account-wide ones only). Without the memory store: null (no directives).
     *
     * @param int $accountId
     * @param AgentSpace|null $agentSpace
     * @return AgentStandingDirectives|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findStandingDirectivesForAgentSpace(int $accountId, ?AgentSpace $agentSpace): ?AgentStandingDirectives
    {
        if (!static::isMemoryStoreEnabled()) {
            return null;
        }
        $currentDirectives = $this->getAgentMemoryStoreService()->findCurrentDirectivesForScope($accountId, $this->findAgentSpaceId($agentSpace));
        $directivesBlock = $currentDirectives->toDirectivesBlock();
        if ($directivesBlock === '') {
            return null;
        }
        $agentStandingDirectives = new AgentStandingDirectives();
        $agentStandingDirectives->directivesBlock = $directivesBlock;
        $agentStandingDirectives->newestValidFrom = $currentDirectives->findNewestValidFromOfCurrentDirectives();
        return $agentStandingDirectives;
    }

    /**
     * Curates the memory from the turn `$conversation` just answered (`$async` = in the background). Best effort: a
     * curation failure must never fail the answered turn. Without the memory store: nothing.
     *
     * @param AIConversation $conversation
     * @param bool $async
     * @return void
     */
    public function curateConversationAfterTurn(AIConversation $conversation, bool $async): void
    {
        if (!static::isMemoryStoreEnabled()) {
            return;
        }
        $this->getAgentMemoryStoreService()->curateConversationAfterTurn($conversation, $async);
    }

    /**
     * The memories of account `$accountId` that apply to `$agentSpace` (null = the account-wide ones only) most
     * similar to `$query`, at most `$limit`. Without the memory store: none.
     *
     * @param string $query
     * @param int $accountId
     * @param AgentSpace|null $agentSpace
     * @param int $limit
     * @return AgentMemoryHits
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findSimilarMemories(string $query, int $accountId, ?AgentSpace $agentSpace, int $limit): AgentMemoryHits
    {
        $agentMemoryHits = new AgentMemoryHits();
        if (!static::isMemoryStoreEnabled()) {
            return $agentMemoryHits;
        }
        $similarMemories = $this->getAgentMemoryStoreService()->findSimilarMemories($query, $accountId, $this->findAgentSpaceId($agentSpace), $limit);
        foreach ($similarMemories->getElements() as $agentMemory) {
            $agentMemoryHit = $this->buildMemoryHit($agentMemory);
            $agentMemoryHits->add($agentMemoryHit);
        }
        return $agentMemoryHits;
    }

    /**
     * The memory tables whose zero vectors the embedding repair re-embeds. With the memory store: its table.
     *
     * @return AgentEmbeddingRepairTargets
     */
    public function getEmbeddingRepairTargets(): AgentEmbeddingRepairTargets
    {
        $agentEmbeddingRepairTargets = new AgentEmbeddingRepairTargets();
        if (static::isMemoryStoreEnabled()) {
            $memoryRepairTarget = AgentEmbeddingRepairTarget::forModel(DBAgentMemoryModel::class, 'content', 'embedding');
            $agentEmbeddingRepairTargets->add($memoryRepairTarget);
        }
        return $agentEmbeddingRepairTargets;
    }

    /**
     * The hit of a stored memory.
     *
     * @param AgentMemory $agentMemory
     * @return AgentMemoryHit
     */
    protected function buildMemoryHit(AgentMemory $agentMemory): AgentMemoryHit
    {
        $agentMemoryHit = new AgentMemoryHit();
        $agentMemoryHit->memoryId = isset($agentMemory->id) ? (int)$agentMemory->id : null;
        $agentMemoryHit->memoryType = $agentMemory->memoryType;
        $agentMemoryHit->content = $agentMemory->content;
        $agentMemoryHit->createdTime = $agentMemory->getCreatedTime();
        $agentMemoryHit->modifiedTime = $agentMemory->getModifiedTime();
        return $agentMemoryHit;
    }

    protected function findAgentSpaceId(?AgentSpace $agentSpace): ?int
    {
        return $agentSpace !== null && isset($agentSpace->id) ? (int)$agentSpace->id : null;
    }

    protected function getAgentMemoryStoreService(): AgentMemoryStoreService
    {
        /** @var AgentMemoryStoreService $agentMemoryStoreService */
        $agentMemoryStoreService = DDDService::instance()->getService(AgentMemoryStoreService::class);
        return $agentMemoryStoreService;
    }
}
