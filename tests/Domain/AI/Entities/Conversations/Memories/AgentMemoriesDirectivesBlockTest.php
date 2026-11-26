<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Memories;

use DDD\Domain\AI\Entities\Conversations\Memories\AgentMemories;
use DDD\Domain\AI\Entities\Conversations\Memories\AgentMemory;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Libs\Config;
use DDD\Tests\Agents\Support\UnitTestCase;

/** The standing-directives block of the model input: only current directives, its own separator and header. */
class AgentMemoriesDirectivesBlockTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (Config::getEnv('LAZYLOAD_DEFAULT_REPO_TYPE') === null) {
            LazyLoadRepo::setDafaultRepoType(LazyLoadRepo::DB);
        }
    }

    public function testTheBlockHoldsOnlyCurrentDirectives(): void
    {
        $agentMemories = AgentMemories::newInstance();
        $builtMemory = $this->buildMemory(1, 'answer briefly', AgentMemory::MEMORY_TYPE_DIRECTIVE, '2026-01-01');
        $agentMemories->add($builtMemory);
        $builtMemory = $this->buildMemory(2, 'has two shops', AgentMemory::MEMORY_TYPE_FACT, '2026-02-01');
        $agentMemories->add($builtMemory);
        $closedDirective = $this->buildMemory(3, 'call the owner Boss', AgentMemory::MEMORY_TYPE_DIRECTIVE, '2026-03-01');
        $closedDirective->invalidatedAt = new DateTime('2026-03-05 00:00:00');
        $agentMemories->add($closedDirective);
        $builtMemory = $this->buildMemory(4, 'never propose discounts', AgentMemory::MEMORY_TYPE_DIRECTIVE, '2026-04-01');
        $agentMemories->add($builtMemory);

        self::assertSame(
            "\n\n" . AgentMemories::DIRECTIVES_BLOCK_HEADER . "\n- answer briefly\n- never propose discounts",
            $agentMemories->toDirectivesBlock()
        );
        self::assertSame('2026-04-01', $agentMemories->findNewestValidFromOfCurrentDirectives()->format('Y-m-d'));
    }

    public function testNoDirectiveIsAnEmptyBlock(): void
    {
        $agentMemories = AgentMemories::newInstance();
        $builtMemory = $this->buildMemory(1, 'has two shops', AgentMemory::MEMORY_TYPE_FACT, '2026-02-01');
        $agentMemories->add($builtMemory);

        self::assertSame('', $agentMemories->toDirectivesBlock());
        self::assertNull($agentMemories->findNewestValidFromOfCurrentDirectives());
    }

    public function testWithoutAHeaderTheBlockReadsThePackagesHeaderAndAGivenHeaderReplacesIt(): void
    {
        $agentMemories = AgentMemories::newInstance();
        $builtMemory = $this->buildMemory(1, 'answer briefly', AgentMemory::MEMORY_TYPE_DIRECTIVE, '2026-01-01');
        $agentMemories->add($builtMemory);

        self::assertSame("\n\n# Standing instructions of the owner — always follow them\n- answer briefly", $agentMemories->toDirectivesBlock());
        self::assertSame("\n\n# Rules of the shop owner\n- answer briefly", $agentMemories->toDirectivesBlockUnderHeader('# Rules of the shop owner'));
    }

    protected function buildMemory(int $id, string $content, string $memoryType, string $validFromDay): AgentMemory
    {
        $agentMemory = AgentMemory::buildStated($content, $memoryType, null);
        $agentMemory->id = $id;
        $agentMemory->validFrom = new DateTime($validFromDay . ' 00:00:00');
        return $agentMemory;
    }
}
