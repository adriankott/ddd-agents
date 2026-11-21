<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Memories;

use DDD\Domain\AI\Entities\Conversations\Memories\AgentMemories;
use DDD\Domain\AI\Entities\Conversations\Memories\AgentMemory;
use DDD\Domain\AI\Entities\Conversations\Memories\Curation\AgentMemoryOperation;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoryCurationService;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Infrastructure\Libs\Config;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The curation's own rules, without a model or a database: the scope of a memory (the owner → account, the business →
 * space), the target check of UPDATE / INVALIDATE (label, echo, scope) and the stated "true since".
 */
class AgentMemoryCurationServiceRulesTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (Config::getEnv('LAZYLOAD_DEFAULT_REPO_TYPE') === null) {
            LazyLoadRepo::setDafaultRepoType(LazyLoadRepo::DB);
        }
    }

    public function testAMemoryAboutTheOwnerBelongsToTheAccountAndOneAboutTheBusinessToTheSpace(): void
    {
        $agentMemoryCurationService = new AgentMemoryCurationService();

        self::assertNull($agentMemoryCurationService->resolveScopeAgentSpaceId($this->buildOperation(AgentMemory::SUBJECT_USER), 5));
        self::assertSame(5, $agentMemoryCurationService->resolveScopeAgentSpaceId($this->buildOperation(AgentMemory::SUBJECT_BUSINESS), 5));
        self::assertSame(5, $agentMemoryCurationService->resolveScopeAgentSpaceId($this->buildOperation(null), 5));
        self::assertNull($agentMemoryCurationService->resolveScopeAgentSpaceId($this->buildOperation(AgentMemory::SUBJECT_BUSINESS), null));
    }

    public function testTheTargetIsTheLabelledNeighbourOfTheSameScope(): void
    {
        $agentMemoryCurationService = new AgentMemoryCurationService();
        $accountNeighbour = $this->buildNeighbour(1, 'the owner is called Alex', null);
        $spaceNeighbour = $this->buildNeighbour(2, 'has 4 refrigerated counters', 5);
        $orderedNeighbours = AgentMemories::newInstance();
        $orderedNeighbours->add($accountNeighbour);
        $orderedNeighbours->add($spaceNeighbour);

        $businessOperation = $this->buildOperation(AgentMemory::SUBJECT_BUSINESS);
        $businessOperation->targetMemoryRef = 1;
        self::assertSame($spaceNeighbour, $agentMemoryCurationService->resolveTarget($businessOperation, $orderedNeighbours, 5));

        $businessOperation->targetMemoryRef = 0;
        self::assertNull($agentMemoryCurationService->resolveTarget($businessOperation, $orderedNeighbours, 5), 'an account-level neighbour is no target of a space operation');

        $businessOperation->targetMemoryRef = 7;
        self::assertNull($agentMemoryCurationService->resolveTarget($businessOperation, $orderedNeighbours, 5));

        $userOperation = $this->buildOperation(AgentMemory::SUBJECT_USER);
        $userOperation->targetMemoryRef = 0;
        self::assertSame($accountNeighbour, $agentMemoryCurationService->resolveTarget($userOperation, $orderedNeighbours, 5));
    }

    public function testTheEchoMustMatchTheBeginningOfTheTarget(): void
    {
        $agentMemoryCurationService = new AgentMemoryCurationService();
        $target = $this->buildNeighbour(1, 'Has 4 refrigerated counters in the shop.', 5);
        $agentMemoryOperation = $this->buildOperation(AgentMemory::SUBJECT_BUSINESS);

        $agentMemoryOperation->targetEcho = 'has 4  refrigerated';
        self::assertTrue($agentMemoryCurationService->operationTargetEchoMatches($agentMemoryOperation, $target));

        $agentMemoryOperation->targetEcho = 'the wife is named';
        self::assertFalse($agentMemoryCurationService->operationTargetEchoMatches($agentMemoryOperation, $target));

        $agentMemoryOperation->targetEcho = null;
        self::assertTrue($agentMemoryCurationService->operationTargetEchoMatches($agentMemoryOperation, $target), 'no echo trusts the label');
    }

    public function testTheStatedTrueSinceIsTheFirstDayOfThePeriodAndNeverInTheFuture(): void
    {
        $agentMemoryCurationService = new AgentMemoryCurationService();

        self::assertSame('2020-01-01', $agentMemoryCurationService->parseEffectiveFrom('2020')->format('Y-m-d'));
        self::assertSame('2024-03-01', $agentMemoryCurationService->parseEffectiveFrom('2024-03')->format('Y-m-d'));
        self::assertSame('2025-05-17', $agentMemoryCurationService->parseEffectiveFrom('2025-05-17')->format('Y-m-d'));
        self::assertNull($agentMemoryCurationService->parseEffectiveFrom('2024-13-40'));
        self::assertNull($agentMemoryCurationService->parseEffectiveFrom('last month'));
        self::assertNull($agentMemoryCurationService->parseEffectiveFrom(((int)date('Y') + 2) . '-01-01'));
        self::assertNull($agentMemoryCurationService->parseEffectiveFrom(null));
    }

    protected function buildOperation(?string $subject): AgentMemoryOperation
    {
        $agentMemoryOperation = new AgentMemoryOperation();
        $agentMemoryOperation->operation = AgentMemoryOperation::OPERATION_INVALIDATE;
        $agentMemoryOperation->subject = $subject;
        $agentMemoryOperation->content = 'a new value';
        return $agentMemoryOperation;
    }

    protected function buildNeighbour(int $id, string $content, ?int $agentSpaceId): AgentMemory
    {
        $agentMemory = AgentMemory::buildStated($content, AgentMemory::MEMORY_TYPE_FACT, null);
        $agentMemory->id = $id;
        $agentMemory->anchorToScope(3, $agentSpaceId);
        return $agentMemory;
    }
}
