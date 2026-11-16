<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals\SeedAccounts;

use DDD\Domain\AI\Entities\Evals\SeedAccounts\AgentEvalSeedPool;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The pool of seeds an evaluation fixture runs its cases on: distinct positive ids in their order, tried by a process
 * in an order rotated by its process id, shared between fixtures by the sorted key.
 */
class AgentEvalSeedPoolTest extends UnitTestCase
{
    public function testThePoolKeepsDistinctPositiveSeedsInTheirOrder(): void
    {
        $agentEvalSeedPool = AgentEvalSeedPool::fromSeedIds([30, '10', 0, -4, 30, 20]);

        self::assertSame([30, 10, 20], $agentEvalSeedPool->seedIds);
        self::assertSame(3, $agentEvalSeedPool->getSize());
        self::assertFalse($agentEvalSeedPool->isEmpty());
        self::assertSame(30, $agentEvalSeedPool->getFirstSeedId());
        self::assertSame('10,20,30', $agentEvalSeedPool->buildPoolKey());
    }

    public function testTheTryOrderIsRotatedByTheProcess(): void
    {
        $agentEvalSeedPool = AgentEvalSeedPool::fromSeedIds([1, 2, 3]);

        self::assertSame([1, 2, 3], $agentEvalSeedPool->buildSeedIdsInTryOrder(0));
        self::assertSame([2, 3, 1], $agentEvalSeedPool->buildSeedIdsInTryOrder(4));
        self::assertSame([3, 1, 2], $agentEvalSeedPool->buildSeedIdsInTryOrder(-1), 'a negative offset rotates backwards');
    }

    public function testAnEmptyPoolHasNoSeedAndSaysSo(): void
    {
        $agentEvalSeedPool = AgentEvalSeedPool::fromSeedIds([]);

        self::assertTrue($agentEvalSeedPool->isEmpty());
        self::assertNull($agentEvalSeedPool->getFirstSeedId());
        self::assertSame([], $agentEvalSeedPool->buildSeedIdsInTryOrder(7));
        self::assertSame('The seed pool is empty — the fixture names no seed.', $agentEvalSeedPool->describeEmptyPool());
    }
}
