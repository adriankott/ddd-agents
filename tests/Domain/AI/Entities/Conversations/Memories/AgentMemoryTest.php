<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Memories;

use DDD\Domain\AI\Entities\Conversations\Memories\AgentMemory;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Libs\Config;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The rules a memory carries itself: the content hash, the scope key the dedup index reads, the cut before a write, the
 * bitemporal closing, an edit in place and the line the model reads.
 */
class AgentMemoryTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (Config::getEnv('LAZYLOAD_DEFAULT_REPO_TYPE') === null) {
            LazyLoadRepo::setDafaultRepoType(LazyLoadRepo::DB);
        }
    }

    public function testTheContentHashIgnoresCaseOuterSpaceAndRepeatedWhitespace(): void
    {
        self::assertSame(
            AgentMemory::hashForContent('Has 3  Children'),
            AgentMemory::hashForContent("  has 3\nchildren ")
        );
        self::assertNotSame(AgentMemory::hashForContent('has 3 children'), AgentMemory::hashForContent('has 2 children'));
    }

    public function testTheScopeKeyNamesTheSpaceOrElseTheAccount(): void
    {
        $agentMemory = AgentMemory::buildStated('a fact', null, null);
        $agentMemory->anchorToScope(7, null);
        self::assertSame('a:7', $agentMemory->deriveScopeKey());
        self::assertFalse($agentMemory->isAgentSpaceBound());

        $agentMemory->anchorToScope(7, 12);
        self::assertSame('s:12', $agentMemory->deriveScopeKey());
        self::assertTrue($agentMemory->isAgentSpaceBound());
    }

    public function testPreparingForPersistenceCutsTheContentAndStampsScopeAndHash(): void
    {
        $agentMemory = AgentMemory::buildStated(str_repeat('x', AgentMemory::MAX_CONTENT_CHARS + 50), AgentMemory::MEMORY_TYPE_DIRECTIVE, null);
        $agentMemory->anchorToScope(3, 4);

        $agentMemory->prepareForPersistence();

        self::assertSame(AgentMemory::MAX_CONTENT_CHARS + 1, mb_strlen($agentMemory->content));
        self::assertStringEndsWith('…', $agentMemory->content);
        self::assertSame('s:4', $agentMemory->scopeKey);
        self::assertSame(AgentMemory::hashForContent($agentMemory->content), $agentMemory->contentHash);
    }

    public function testAStatedMemoryDefaultsToAFactTheOwnerStated(): void
    {
        $agentMemory = AgentMemory::buildStated('likes short answers', null, null);

        self::assertSame(AgentMemory::MEMORY_TYPE_FACT, $agentMemory->memoryType);
        self::assertSame(AgentMemory::SOURCE_USER_STATED, $agentMemory->source);
        self::assertTrue($agentMemory->isCurrentlyValid());
    }

    public function testSupersedingClosesTheMemoryWhenTheSuccessorBecameTrue(): void
    {
        $agentMemory = AgentMemory::buildStated('has 1 dog', null, null);
        $successor = AgentMemory::buildStated('has 2 dogs', null, null);
        $successor->id = 99;
        $successor->validFrom = new DateTime('2026-03-01 00:00:00');

        $agentMemory->supersedeBy($successor, new DateTime('2026-03-02 10:00:00'));

        self::assertFalse($agentMemory->isCurrentlyValid());
        self::assertSame('2026-03-01', $agentMemory->validTo->format('Y-m-d'));
        self::assertSame('2026-03-02', $agentMemory->invalidatedAt->format('Y-m-d'));
        self::assertSame(99, $agentMemory->supersededByMemoryId);
    }

    public function testAnEditReplacesOnlyTheGivenFields(): void
    {
        $agentMemory = AgentMemory::buildStated('old text', AgentMemory::MEMORY_TYPE_FACT, null);

        $agentMemory->applyEdit(null, AgentMemory::MEMORY_TYPE_SOLUTION);
        self::assertSame('old text', $agentMemory->content);
        self::assertSame(AgentMemory::MEMORY_TYPE_SOLUTION, $agentMemory->memoryType);

        $agentMemory->applyEdit('new text', '');
        self::assertSame('new text', $agentMemory->content);
        self::assertSame(AgentMemory::MEMORY_TYPE_SOLUTION, $agentMemory->memoryType);
    }

    public function testTheModelLineLeadsWithTheIdAndNamesTypeScopeAndSource(): void
    {
        $agentMemory = AgentMemory::buildStated('always answer briefly', AgentMemory::MEMORY_TYPE_DIRECTIVE, null);
        $agentMemory->id = 42;
        $agentMemory->anchorToScope(3, 8);

        self::assertSame('- #42 [DIRECTIVE/space:8] "always answer briefly" (USER_STATED)', $agentMemory->toLlmMarkdown());
    }
}
