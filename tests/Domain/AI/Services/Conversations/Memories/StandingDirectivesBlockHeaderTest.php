<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Memories;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Infrastructure\Libs\Config;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The header of the standing-directives block the model reads is an override point of the memory port
 * (`AgentMemoriesService::getDirectivesBlockHeader()`): without an override the block is byte for byte the one the
 * package has always rendered; an application's header replaces exactly the header line, the leading separator and the
 * directive lines stay.
 */
class StandingDirectivesBlockHeaderTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (Config::getEnv('LAZYLOAD_DEFAULT_REPO_TYPE') === null) {
            LazyLoadRepo::setDafaultRepoType(LazyLoadRepo::DB);
        }
    }

    public function testWithoutAnOverrideTheBlockIsTheOneThePackageAlwaysRendered(): void
    {
        $agentStandingDirectives = (new AgentMemoriesServiceSwitchedOnDouble())->findStandingDirectivesForAgentSpace(1, $this->buildAgentSpace());

        self::assertNotNull($agentStandingDirectives);
        self::assertSame(
            "\n\n# Standing instructions of the owner — always follow them\n- answer briefly",
            $agentStandingDirectives->directivesBlock
        );
    }

    public function testAnApplicationsHeaderReplacesOnlyTheHeaderLine(): void
    {
        $agentStandingDirectives = (new AgentMemoriesServiceWithOwnDirectivesHeaderDouble())->findStandingDirectivesForAgentSpace(1, $this->buildAgentSpace());

        self::assertNotNull($agentStandingDirectives);
        self::assertSame("\n\n# Standing rules the shop owner set\n- answer briefly", $agentStandingDirectives->directivesBlock);
        self::assertSame('2026-01-01', $agentStandingDirectives->newestValidFrom->format('Y-m-d'));
    }

    protected function buildAgentSpace(): AgentSpace
    {
        $agentSpace = new AgentSpace();
        $agentSpace->id = 2;
        return $agentSpace;
    }
}
