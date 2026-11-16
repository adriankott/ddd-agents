<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Services\Eval\AgentEvalSeedAccountLeasesService;

/**
 * The module's lease service as a child process of {@see AgentEvalSeedAccountLeasesServiceTest} runs it: only the
 * account lookup (seed n belongs to account n + 900) and the lock directory (handed over by an environment variable)
 * replaced — the real clock, process id and wait stay.
 */
class AgentEvalSeedAccountLeasesServiceOfAChildProcess extends AgentEvalSeedAccountLeasesService
{
    protected function findAccountIdOfSeed(int $seedId): int
    {
        return $seedId + 900;
    }

    protected function getLeaseFileDirectory(): string
    {
        // the name of AgentEvalSeedAccountLeasesServiceTest::CHILD_LEASE_DIRECTORY_VARIABLE; the child loads no test case
        return (string)getenv('AGENT_EVAL_LEASE_TEST_DIRECTORY');
    }
}
