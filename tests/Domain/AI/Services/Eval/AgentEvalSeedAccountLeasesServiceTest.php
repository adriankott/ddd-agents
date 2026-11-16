<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Evals\SeedAccounts\AgentEvalSeedPool;
use DDD\Tests\Agents\Support\UnitTestCase;
use RuntimeException;

/**
 * The seed-account lease on real lock files in a temporary directory: every double is one "process" with its own file
 * handles (an `flock()` is held per open file, so two doubles in one PHP process exclude each other like two
 * processes), the clock is a property, the wait is recorded instead of slept. One test starts two real PHP processes
 * that contend for one account.
 */
class AgentEvalSeedAccountLeasesServiceTest extends UnitTestCase
{
    /** @var string Environment variable that hands the lock directory to a child process. */
    public const string CHILD_LEASE_DIRECTORY_VARIABLE = 'AGENT_EVAL_LEASE_TEST_DIRECTORY';

    /** @var string Environment variable that names the file whose creation starts the children's attempts. */
    public const string CHILD_START_FILE_VARIABLE = 'AGENT_EVAL_LEASE_TEST_START_FILE';

    /** @var string Environment variable that names the test bootstrap a child process loads. */
    public const string CHILD_BOOTSTRAP_VARIABLE = 'AGENT_EVAL_LEASE_TEST_BOOTSTRAP';

    /** @var int Upper bound of the wait for a child's line (the child boots the test kernel first). */
    public const int CHILD_LINE_TIMEOUT_SECONDS = 120;

    protected string $leaseFileDirectory = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->leaseFileDirectory = sys_get_temp_dir() . '/agent-eval-lease-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->leaseFileDirectory . '/*') ?: [] as $leaseFilePath) {
            @unlink($leaseFilePath);
        }
        @rmdir($this->leaseFileDirectory);
        parent::tearDown();
    }

    public function testTwoProcessesNeverLeaseTheSameAccount(): void
    {
        $firstProcess = $this->buildProcessDouble();
        $secondProcess = $this->buildProcessDouble();
        $agentEvalSeedPool = AgentEvalSeedPool::fromSeedIds([101, 102]);

        $firstLease = $firstProcess->acquireLeaseFromPool($agentEvalSeedPool, 0);
        $secondLease = $secondProcess->acquireLeaseFromPool($agentEvalSeedPool, 0);

        self::assertSame(101, $firstLease->seedId);
        self::assertSame(1001, $firstLease->accountId);
        self::assertSame('agent-eval-seed-account-1001.lock', $firstLease->leaseFileName);
        self::assertFileExists($this->leaseFileDirectory . '/agent-eval-seed-account-1001.lock');
        self::assertSame(102, $secondLease->seedId, 'the first seed is taken: the second process gets the next account');
    }

    public function testAnApplicationsPrefixNamesTheLockFileItsProcessesHaveAlwaysLocked(): void
    {
        $processOfTheApplication = $this->buildProcessDouble();
        $processOfTheApplication->leaseFileNamePrefixOfAnApplication = 'app-eval-seed-account-';
        $otherProcessOfTheApplication = $this->buildProcessDouble();
        $otherProcessOfTheApplication->leaseFileNamePrefixOfAnApplication = 'app-eval-seed-account-';

        $agentEvalSeedAccountLease = $processOfTheApplication->acquireLeaseFromPool(AgentEvalSeedPool::fromSeedIds([101]), 0);

        self::assertSame('app-eval-seed-account-1001.lock', $agentEvalSeedAccountLease->leaseFileName);
        self::assertSame('app-eval-seed-account-1001.lock', $processOfTheApplication->buildLeaseFileName(1001));
        self::assertFileExists($this->leaseFileDirectory . '/app-eval-seed-account-1001.lock');
        $this->expectException(RuntimeException::class);
        $otherProcessOfTheApplication->acquireLeaseFromPool(AgentEvalSeedPool::fromSeedIds([101]), 0);
    }

    public function testAFullPoolIsWaitedForUntilALeaseIsGivenBack(): void
    {
        $holdingProcess = $this->buildProcessDouble();
        $waitingProcess = $this->buildProcessDouble();
        $agentEvalSeedPool = AgentEvalSeedPool::fromSeedIds([101]);
        $heldLease = $holdingProcess->acquireLeaseFromPool($agentEvalSeedPool, 0);
        // the holder gives the lease back while the waiting process sleeps its second round
        $waitingProcess->holdingProcessToReleaseOnPause = $holdingProcess;
        $waitingProcess->leaseToReleaseOnPause = $heldLease;
        $waitingProcess->releaseOnPauseNumber = 2;

        $waitedLease = $waitingProcess->acquireLeaseFromPool($agentEvalSeedPool, 60);

        self::assertSame(101, $waitedLease->seedId);
        self::assertSame([5, 5], $waitingProcess->pauses);
        self::assertSame(10, $waitedLease->waitedSeconds);
        self::assertFalse($heldLease->isHeld);
    }

    public function testTheWaitEndsWithAnErrorWhenNoSeedGetsFree(): void
    {
        // the holder must stay referenced: a destroyed service closes its lock files and so gives the lease back
        $holdingProcess = $this->buildProcessDouble();
        $holdingProcess->acquireLeaseFromPool(AgentEvalSeedPool::fromSeedIds([101]), 0);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No free seed account within 9 s — all 1 seeds of the pool [101] are leased by other eval processes.');
        $this->buildProcessDouble()->acquireLeaseFromPool(AgentEvalSeedPool::fromSeedIds([101]), 9);
    }

    public function testKeepAliveHoldsUntilTheLockFileIsReplacedThenTheLeaseIsLost(): void
    {
        $holdingProcess = $this->buildProcessDouble();
        $agentEvalSeedAccountLease = $holdingProcess->acquireLeaseFromPool(AgentEvalSeedPool::fromSeedIds([101]), 0);
        self::assertTrue($holdingProcess->keepLeaseAlive($agentEvalSeedAccountLease));

        // the lock file was deleted and another process locked a new file at the same path: the lease is lost
        unlink($this->leaseFileDirectory . '/' . $agentEvalSeedAccountLease->leaseFileName);
        $otherProcess = $this->buildProcessDouble();
        $otherProcess->acquireLeaseFromPool(AgentEvalSeedPool::fromSeedIds([101]), 0);

        self::assertFalse($holdingProcess->keepLeaseAlive($agentEvalSeedAccountLease));
        self::assertFalse($agentEvalSeedAccountLease->isHeld);
    }

    public function testAReleasedLeaseIsFreeForTheNextProcessAndAReleaseTwiceIsSafe(): void
    {
        $firstProcess = $this->buildProcessDouble();
        $agentEvalSeedAccountLease = $firstProcess->acquireLeaseFromPool(AgentEvalSeedPool::fromSeedIds([101]), 0);
        $firstProcess->releaseLease($agentEvalSeedAccountLease);
        $firstProcess->releaseLease($agentEvalSeedAccountLease);

        self::assertFalse($agentEvalSeedAccountLease->isHeld);
        self::assertFalse($firstProcess->keepLeaseAlive($agentEvalSeedAccountLease));
        self::assertSame(101, $this->buildProcessDouble()->acquireLeaseFromPool(AgentEvalSeedPool::fromSeedIds([101]), 0)->seedId);
    }

    public function testAnEmptyPoolIsRefusedWithThePoolsOwnText(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The seed pool is empty — the fixture names no seed.');
        $this->buildProcessDouble()->acquireLeaseFromPool(AgentEvalSeedPool::fromSeedIds([]), 0);
    }

    /**
     * Two real PHP processes try to lease the one account of a pool at the same moment: exactly one gets it. When the
     * holder is killed, the operating system releases its lock and the account is free again.
     */
    public function testTwoRealProcessesContendingForOneAccountExactlyOneAcquires(): void
    {
        $startFilePath = $this->leaseFileDirectory . '/start';
        $firstChild = $this->startContendingChildProcess($startFilePath);
        $secondChild = $this->startContendingChildProcess($startFilePath);
        // both children booted and wait: create the start file, then both attempt at once
        $readyLines = [$this->readChildLine($firstChild), $this->readChildLine($secondChild)];
        touch($startFilePath);
        $firstOutcome = $this->readChildLine($firstChild);
        $secondOutcome = $this->readChildLine($secondChild);
        $outcomes = [$firstOutcome, $secondOutcome];
        sort($outcomes);
        $holdingChild = $firstOutcome === 'ACQUIRED' ? $firstChild : $secondChild;
        $leaseRefusedWhileTheChildHolds = false;
        try {
            $this->buildProcessDouble()->acquireLeaseFromPool(AgentEvalSeedPool::fromSeedIds([101]), 0);
        } catch (RuntimeException) {
            $leaseRefusedWhileTheChildHolds = true;
        }
        proc_terminate($holdingChild['process'], 9);
        $childErrorOutput = stream_get_contents($firstChild['pipes'][2]) . stream_get_contents($secondChild['pipes'][2]);
        $this->closeChildProcess($firstChild);
        $this->closeChildProcess($secondChild);

        self::assertSame(['READY', 'READY'], $readyLines, "child stderr: $childErrorOutput");
        self::assertSame(['ACQUIRED', 'REFUSED'], $outcomes, "exactly one process leases the account; child stderr: $childErrorOutput");
        self::assertTrue($leaseRefusedWhileTheChildHolds, 'a third process is refused while the child holds the account');
        $leaseAfterTheHolderDied = $this->buildProcessDouble()->acquireLeaseFromPool(AgentEvalSeedPool::fromSeedIds([101]), 0);
        self::assertSame(1001, $leaseAfterTheHolderDied->accountId, 'the operating system released the killed holder\'s lock');
    }

    protected function buildProcessDouble(): AgentEvalSeedAccountLeasesServiceDouble
    {
        return new AgentEvalSeedAccountLeasesServiceDouble($this->leaseFileDirectory);
    }

    /** @return array{process: resource, pipes: array<int, resource>} */
    protected function startContendingChildProcess(string $startFilePath): array
    {
        if (!is_dir($this->leaseFileDirectory)) {
            mkdir($this->leaseFileDirectory, 0777, true);
        }
        $environment = array_merge(getenv(), [
            'APP_ENV' => 'test',
            'APP_DEBUG' => '0',
            self::CHILD_LEASE_DIRECTORY_VARIABLE => $this->leaseFileDirectory,
            self::CHILD_START_FILE_VARIABLE => $startFilePath,
            self::CHILD_BOOTSTRAP_VARIABLE => dirname(__DIR__, 4) . '/bootstrap.php',
        ]);
        $process = proc_open(
            [PHP_BINARY, '-r', self::buildContendingChildProgram()],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 5),
            $environment,
        );
        self::assertIsResource($process);
        return ['process' => $process, 'pipes' => $pipes];
    }

    /**
     * The next stdout line of a child, waiting at most {@see CHILD_LINE_TIMEOUT_SECONDS}; '' on a timeout or exit.
     *
     * @param array{process: resource, pipes: array<int, resource>} $childProcess
     */
    protected function readChildLine(array $childProcess): string
    {
        $readStreams = [$childProcess['pipes'][1]];
        $writeStreams = null;
        $exceptStreams = null;
        if (stream_select($readStreams, $writeStreams, $exceptStreams, self::CHILD_LINE_TIMEOUT_SECONDS) !== 1) {
            return '';
        }
        return trim((string)fgets($childProcess['pipes'][1]));
    }

    /** @param array{process: resource, pipes: array<int, resource>} $childProcess */
    protected function closeChildProcess(array $childProcess): void
    {
        foreach ($childProcess['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($childProcess['process']);
    }

    /**
     * The child: the module's lease service with only the account lookup and the lock directory replaced
     * ({@see AgentEvalSeedAccountLeasesServiceOfAChildProcess}). It boots, prints `READY`, waits for the start file,
     * makes one attempt and prints `ACQUIRED` or `REFUSED`; the holder then keeps the lease until it is killed.
     */
    protected static function buildContendingChildProgram(): string
    {
        return <<<'PHP'
require getenv('AGENT_EVAL_LEASE_TEST_BOOTSTRAP');

$agentEvalSeedAccountLeasesService = new \DDD\Tests\Agents\Domain\AI\Services\Eval\AgentEvalSeedAccountLeasesServiceOfAChildProcess();
$agentEvalSeedPool = \DDD\Domain\AI\Entities\Evals\SeedAccounts\AgentEvalSeedPool::fromSeedIds([101]);
echo "READY\n";
$startFilePath = (string)getenv('AGENT_EVAL_LEASE_TEST_START_FILE');
while (!file_exists($startFilePath)) {
    usleep(1000);
    clearstatcache(true, $startFilePath);
}
try {
    $agentEvalSeedAccountLeasesService->acquireLeaseFromPool($agentEvalSeedPool, 0);
} catch (\RuntimeException) {
    echo "REFUSED\n";
    exit(0);
}
echo "ACQUIRED\n";
sleep(60);
PHP;
    }
}
