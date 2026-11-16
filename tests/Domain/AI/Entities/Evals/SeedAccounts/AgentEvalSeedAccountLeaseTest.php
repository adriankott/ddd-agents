<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals\SeedAccounts;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentApplicationWording;
use DDD\Domain\AI\Entities\Evals\SeedAccounts\AgentEvalSeedAccountLease;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The progress line of a lease names the seed with the application's word for a space: an application whose word is
 * the one it has always printed keeps its line (`seed account 7 leased (seed <word> 3, waited 10 s)`).
 */
class AgentEvalSeedAccountLeaseTest extends UnitTestCase
{
    public function testTheProgressLineNamesTheAccountTheSeedAndTheWait(): void
    {
        $agentEvalSeedAccountLease = AgentEvalSeedAccountLease::forSeed(3, 7, 'agent-eval-seed-account-7.lock', 10);
        $seedName = AgentApplicationWording::current()->agentSpaceName;

        self::assertSame($seedName, $agentEvalSeedAccountLease->seedName);
        self::assertSame("seed account 7 leased (seed $seedName 3, waited 10 s)", $agentEvalSeedAccountLease->describe());
        self::assertTrue($agentEvalSeedAccountLease->isHeld);
        self::assertSame('agent-eval-seed-account-7.lock', $agentEvalSeedAccountLease->leaseFileName);

        $agentEvalSeedAccountLease->waitedSeconds = 0;
        $agentEvalSeedAccountLease->seedName = 'studio';
        self::assertSame('seed account 7 leased (seed studio 3)', $agentEvalSeedAccountLease->describe(), 'no wait, no wait part');
    }
}
