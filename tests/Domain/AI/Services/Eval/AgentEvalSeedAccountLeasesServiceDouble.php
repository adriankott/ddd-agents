<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Evals\SeedAccounts\AgentEvalSeedAccountLease;
use DDD\Domain\AI\Services\Eval\AgentEvalSeedAccountLeasesService;

/**
 * One evaluation process: seed n belongs to account n + 900; the lock files live in the test's directory, the clock is
 * a property and a wait is recorded instead of slept.
 */
class AgentEvalSeedAccountLeasesServiceDouble extends AgentEvalSeedAccountLeasesService
{
    /** @var int[] */
    public array $pauses = [];

    public float $clockSeconds = 1000.0;

    /** The process that gives a lease back during pause number {@see $releaseOnPauseNumber}. */
    public ?AgentEvalSeedAccountLeasesService $holdingProcessToReleaseOnPause = null;

    public ?AgentEvalSeedAccountLease $leaseToReleaseOnPause = null;

    public int $releaseOnPauseNumber = 0;

    /** @var string|null A lock file name prefix of an application; null = the module's */
    public ?string $leaseFileNamePrefixOfAnApplication = null;

    public function __construct(protected string $leaseFileDirectory)
    {
    }

    protected function findAccountIdOfSeed(int $seedId): int
    {
        return $seedId + 900;
    }

    protected function getLeaseFileNamePrefix(): string
    {
        return $this->leaseFileNamePrefixOfAnApplication ?? parent::getLeaseFileNamePrefix();
    }

    protected function getLeaseFileDirectory(): string
    {
        return $this->leaseFileDirectory;
    }

    protected function currentSeconds(): float
    {
        return $this->clockSeconds;
    }

    protected function currentProcessId(): int
    {
        return 0;
    }

    protected function pauseSeconds(int $seconds): void
    {
        $this->pauses[] = $seconds;
        if (count($this->pauses) === $this->releaseOnPauseNumber && $this->leaseToReleaseOnPause !== null) {
            $this->holdingProcessToReleaseOnPause?->releaseLease($this->leaseToReleaseOnPause);
        }
        $this->clockSeconds += $seconds;
    }
}
