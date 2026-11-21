<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Memories;

use DDD\Domain\AI\Entities\Conversations\Memories\AgentMemory;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoryStoreService;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Infrastructure\Libs\Config;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * Without an embedding provider (the package's default embedding service throws) a memory is still stored: a new row
 * leaves the column to its default (the zero vector), an edited row gets the zero vector instead of the stale vector of
 * its old content. Either way the row stays out of the similarity search until the embedding repair covers it.
 */
class AgentMemoryStoreEmbeddingFailureTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (Config::getEnv('LAZYLOAD_DEFAULT_REPO_TYPE') === null) {
            LazyLoadRepo::setDafaultRepoType(LazyLoadRepo::DB);
        }
    }

    public function testANewMemoryWithoutAnEmbeddingLeavesTheColumnToItsDefault(): void
    {
        $agentMemory = AgentMemory::buildStated('likes short answers', null, null);

        (new AgentMemoryStoreService())->embedMemory($agentMemory);

        self::assertFalse(isset($agentMemory->embedding));
    }

    public function testAnEditedMemoryWithoutAnEmbeddingGetsTheZeroVector(): void
    {
        $agentMemory = AgentMemory::buildStated('likes short answers', null, null);
        $agentMemory->id = 5;

        (new AgentMemoryStoreService())->embedMemory($agentMemory);

        self::assertTrue(isset($agentMemory->embedding));
        self::assertTrue($agentMemory->embedding->isEmpty() || array_sum($agentMemory->embedding->vectorValues) == 0);
    }
}
