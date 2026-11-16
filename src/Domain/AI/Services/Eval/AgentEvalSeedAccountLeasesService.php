<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Eval;

use DDD\DDDBundle;
use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Evals\SeedAccounts\AgentEvalSeedAccountLease;
use DDD\Domain\AI\Entities\Evals\SeedAccounts\AgentEvalSeedPool;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use RuntimeException;

/**
 * Hands one seed ACCOUNT of a pool to one evaluation process at a time ({@see AgentEvalSeedAccountLease}), so cases
 * that run in parallel — the children of a suite run, separate processes of a shell pool — never share an account:
 * every case resets its account at its start.
 *
 * The lease is an exclusive, non-blocking `flock()` on one lock file per account in {@see getLeaseFileDirectory()}
 * (`<project>/var/eval-seed-account-leases/<prefix><accountId>.lock`, the prefix from {@see getLeaseFileNamePrefix()}),
 * held through an open file handle for the length of the case. The operating system releases the lock the moment the
 * holding process ends — a killed or crashed evaluation process never leaves an account blocked.
 *
 * The lease is PER HOST: it excludes the evaluation processes of one workspace on one machine, which is where they all
 * run. Two hosts running cases against the same accounts at once are not excluded from each other. A named database
 * lock is no replacement: behind a connection router it lands on whichever server the connection is routed to, and two
 * processes then hold "the same" lock on two servers.
 *
 * Override points of an application: {@see findAccountIdOfSeed()} (a seed of its own kind), {@see getLeaseFileNamePrefix()}
 * (the lock files its processes have always locked — processes of an older build and of this one on one host must lock
 * the same file).
 */
class AgentEvalSeedAccountLeasesService extends Service
{
    /** @var int Seconds between two rounds over the pool while every seed is leased. */
    public const int WAIT_ROUND_SECONDS = 5;

    /** @var int Default upper bound of the wait for a free seed. */
    public const int DEFAULT_MAX_WAIT_SECONDS = 3600;

    /** @var string The folder below the project's var/ directory that holds the lock files. */
    public const string LEASE_FILE_FOLDER_NAME = 'eval-seed-account-leases';

    /** @var string The module's prefix of a lock file name; the account id and {@see LEASE_FILE_SUFFIX} follow. */
    public const string DEFAULT_LEASE_FILE_NAME_PREFIX = 'agent-eval-seed-account-';

    /** @var string Extension of the lock file name. */
    public const string LEASE_FILE_SUFFIX = '.lock';

    /** @var array<string, resource> The open, locked lease files of this process, keyed by lease file name; never handed out. */
    protected array $leaseFileHandlesByFileName = [];

    /**
     * Lease the first free seed of the pool, waiting in rounds of {@see WAIT_ROUND_SECONDS} while all are leased.
     *
     * @throws RuntimeException when the pool is empty, a seed has no account, the lock directory cannot be used, or no
     *     seed got free within the wait
     */
    public function acquireLeaseFromPool(AgentEvalSeedPool $agentEvalSeedPool, int $maxWaitSeconds = self::DEFAULT_MAX_WAIT_SECONDS): AgentEvalSeedAccountLease
    {
        if ($agentEvalSeedPool->isEmpty()) {
            throw new RuntimeException($agentEvalSeedPool->describeEmptyPool());
        }
        $accountIdsBySeedId = [];
        foreach ($agentEvalSeedPool->seedIds as $seedId) {
            $accountIdsBySeedId[$seedId] = $this->findAccountIdOfSeed($seedId);
        }
        $startedAtSeconds = $this->currentSeconds();
        $seedIdsInTryOrder = $agentEvalSeedPool->buildSeedIdsInTryOrder($this->currentProcessId());
        while (true) {
            foreach ($seedIdsInTryOrder as $seedId) {
                $leaseFileName = $this->buildLeaseFileName($accountIdsBySeedId[$seedId]);
                if ($this->tryLockLeaseFile($leaseFileName)) {
                    return AgentEvalSeedAccountLease::forSeed(
                        $seedId,
                        $accountIdsBySeedId[$seedId],
                        $leaseFileName,
                        (int)round($this->currentSeconds() - $startedAtSeconds)
                    );
                }
            }
            if ($this->currentSeconds() - $startedAtSeconds >= max(0, $maxWaitSeconds)) {
                throw new RuntimeException(sprintf(
                    'No free seed account within %d s — all %d seeds of the pool [%s] are leased by other eval processes.',
                    $maxWaitSeconds,
                    $agentEvalSeedPool->getSize(),
                    implode(',', $agentEvalSeedPool->seedIds)
                ));
            }
            $this->pauseSeconds(static::WAIT_ROUND_SECONDS);
        }
    }

    /**
     * Check, once per owner turn, that this process still holds the lease: its lock file handle is open and the file at
     * the lease path is still the one it locked (a deleted or replaced lock file could be locked by another process).
     * On a lost lease the lease is marked lost and false is returned — the caller aborts the case.
     */
    public function keepLeaseAlive(AgentEvalSeedAccountLease $agentEvalSeedAccountLease): bool
    {
        if (!$agentEvalSeedAccountLease->isHeld) {
            return false;
        }
        if ($this->isLeaseFileLockedByThisProcess($agentEvalSeedAccountLease->leaseFileName)) {
            return true;
        }
        $this->closeLeaseFile($agentEvalSeedAccountLease->leaseFileName);
        $agentEvalSeedAccountLease->isHeld = false;
        return false;
    }

    /** Give the lease back: unlock and close its lock file. Safe to call twice. */
    public function releaseLease(AgentEvalSeedAccountLease $agentEvalSeedAccountLease): void
    {
        $agentEvalSeedAccountLease->isHeld = false;
        $this->closeLeaseFile($agentEvalSeedAccountLease->leaseFileName);
    }

    /** The name of the lock file of an account: {@see getLeaseFileNamePrefix()}, the account id, {@see LEASE_FILE_SUFFIX}. */
    public function buildLeaseFileName(int $accountId): string
    {
        return $this->getLeaseFileNamePrefix() . $accountId . static::LEASE_FILE_SUFFIX;
    }

    /** The prefix of every lock file name; an application keeps the prefix its processes have always locked. */
    protected function getLeaseFileNamePrefix(): string
    {
        return static::DEFAULT_LEASE_FILE_NAME_PREFIX;
    }

    /**
     * The account a seed belongs to, read without rights (a console run has no auth context yet). By default the seed
     * is an agent space and its account is the space's owner.
     *
     * @throws RuntimeException when the seed or its account cannot be read
     */
    protected function findAccountIdOfSeed(int $seedId): int
    {
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $seedAgentSpace = $agentSpacesService->find($seedId);
            $ownerAccount = $seedAgentSpace instanceof AgentSpace ? $agentSpacesService->findOwnerAccountOfAgentSpace($seedAgentSpace) : null;
            $accountId = (int)($ownerAccount->id ?? 0);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        if ($accountId <= 0) {
            throw new RuntimeException("Seed $seedId has no readable account.");
        }
        return $accountId;
    }

    /**
     * One exclusive, non-blocking `flock()` on the lease file — true when this process now holds it. The handle stays
     * open until {@see closeLeaseFile()} (or the end of the process) releases the lock.
     *
     * @throws RuntimeException when the lock directory or the lock file cannot be created or opened
     */
    protected function tryLockLeaseFile(string $leaseFileName): bool
    {
        if (isset($this->leaseFileHandlesByFileName[$leaseFileName])) {
            // this process holds the account already (one lease per account and process)
            return false;
        }
        $leaseFilePath = $this->buildLeaseFilePath($leaseFileName);
        $leaseFileHandle = @fopen($leaseFilePath, 'c');
        if ($leaseFileHandle === false) {
            throw new RuntimeException("The seed-account lease file $leaseFilePath cannot be opened.");
        }
        // several system users run evaluation processes: a lock file one of them created stays openable by the others
        @chmod($leaseFilePath, 0666);
        if (!flock($leaseFileHandle, LOCK_EX | LOCK_NB)) {
            fclose($leaseFileHandle);
            return false;
        }
        $this->leaseFileHandlesByFileName[$leaseFileName] = $leaseFileHandle;
        return true;
    }

    /**
     * Whether this process still holds the lock: the handle is open and the lease path still names the locked file
     * (same inode). A cheap local check — no I/O beyond two stat calls.
     */
    protected function isLeaseFileLockedByThisProcess(string $leaseFileName): bool
    {
        $leaseFileHandle = $this->leaseFileHandlesByFileName[$leaseFileName] ?? null;
        if (!is_resource($leaseFileHandle)) {
            return false;
        }
        $leaseFilePath = $this->buildLeaseFilePath($leaseFileName);
        clearstatcache(true, $leaseFilePath);
        $lockedFileStat = fstat($leaseFileHandle);
        $currentFileStat = @stat($leaseFilePath);
        if ($lockedFileStat === false || $currentFileStat === false) {
            return false;
        }
        return $lockedFileStat['ino'] === $currentFileStat['ino'] && $lockedFileStat['dev'] === $currentFileStat['dev'];
    }

    /** Unlock and close the lease file of this process, when it holds one. */
    protected function closeLeaseFile(string $leaseFileName): void
    {
        $leaseFileHandle = $this->leaseFileHandlesByFileName[$leaseFileName] ?? null;
        unset($this->leaseFileHandlesByFileName[$leaseFileName]);
        if (is_resource($leaseFileHandle)) {
            flock($leaseFileHandle, LOCK_UN);
            fclose($leaseFileHandle);
        }
    }

    /**
     * The lease file's path; the directory is created on first use (writable for every user of the host).
     *
     * @throws RuntimeException when the directory cannot be created
     */
    protected function buildLeaseFilePath(string $leaseFileName): string
    {
        $leaseFileDirectory = $this->getLeaseFileDirectory();
        if (!is_dir($leaseFileDirectory)) {
            if (!@mkdir($leaseFileDirectory, 0777, true) && !is_dir($leaseFileDirectory)) {
                throw new RuntimeException("The seed-account lease directory $leaseFileDirectory cannot be created.");
            }
            @chmod($leaseFileDirectory, 0777);
        }
        return $leaseFileDirectory . '/' . $leaseFileName;
    }

    /**
     * The directory every evaluation process of this workspace shares: `<project>/var/eval-seed-account-leases` (keep
     * var/ outside any file sync, so no sync deletes a held lock file).
     */
    protected function getLeaseFileDirectory(): string
    {
        $projectDirectory = (string)DDDBundle::getContainer()->getParameter('kernel.project_dir');
        return $projectDirectory . '/var/' . static::LEASE_FILE_FOLDER_NAME;
    }

    /** @phpstan-impure the wall clock */
    protected function currentSeconds(): float
    {
        return microtime(true);
    }

    protected function currentProcessId(): int
    {
        return (int)getmypid();
    }

    protected function pauseSeconds(int $seconds): void
    {
        if ($seconds > 0) {
            sleep($seconds);
        }
    }
}
