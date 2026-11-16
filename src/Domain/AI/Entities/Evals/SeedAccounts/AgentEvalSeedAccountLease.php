<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals\SeedAccounts;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentApplicationWording;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The exclusive use of ONE seed account by one evaluation process for the length of one case: no other process (a
 * sibling child of a suite run, a separate run of a shell pool) runs a case on this account while the lease is held.
 * Taken and given back by {@see \DDD\Domain\AI\Services\Eval\AgentEvalSeedAccountLeasesService}; the lock behind it is
 * an exclusive `flock()` on a per-account lock file of the host, which the operating system releases by itself when
 * the holding process dies. The lease is per host: all evaluation processes of a workspace run on one host.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalSeedAccountLease extends ValueObject
{
    /** @var int The seed the case works from — by default an agent space of the account (the pool's seed id). */
    public int $seedId = 0;

    /** @var int The account of the seed — what the lease actually reserves. */
    public int $accountId = 0;

    /** @var string The name of the lock file locked for the account. */
    public string $leaseFileName = '';

    /** @var int Seconds the process waited until a seed of the pool was free. */
    public int $waitedSeconds = 0;

    /** @var bool False once the lease was given back, or lost and not taken again. */
    public bool $isHeld = true;

    /** @var string The word for what a seed is in the progress line ({@see AgentApplicationWording::$agentSpaceName}) */
    public string $seedName = '';

    public static function forSeed(int $seedId, int $accountId, string $leaseFileName, int $waitedSeconds): static
    {
        $agentEvalSeedAccountLease = static::newInstance();
        $agentEvalSeedAccountLease->seedId = $seedId;
        $agentEvalSeedAccountLease->accountId = $accountId;
        $agentEvalSeedAccountLease->leaseFileName = $leaseFileName;
        $agentEvalSeedAccountLease->waitedSeconds = $waitedSeconds;
        $agentEvalSeedAccountLease->seedName = AgentApplicationWording::current()->agentSpaceName;
        return $agentEvalSeedAccountLease;
    }

    /** One progress line for the command output: `seed account 7 leased (seed workspace 3, waited 10 s)`. */
    public function describe(): string
    {
        return "seed account {$this->accountId} leased (seed {$this->seedName} {$this->seedId}"
            . ($this->waitedSeconds > 0 ? ", waited {$this->waitedSeconds} s" : '') . ')';
    }
}
