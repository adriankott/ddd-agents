<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Services;

use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\AuthService;
use DDD\Presentation\Mcp\Dtos\MCPToolResponse;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;
use DDD\Tests\Agents\Presentation\Services\Probes\ProbeReadAndWriteMCPPackage;
use DDD\Tests\Agents\Presentation\Services\Probes\WriteLockedToolExecutor;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionProperty;

/**
 * The environment-wide write lock of the tool executor ({@see MCPToolExecutorService::isEnvironmentWideWriteLockActive()}):
 * under the lock a declared read runs, a declared write and a tool without a declared effect are refused before any
 * other gate with a final refusal; without the lock (the default) every tool runs.
 */
class MCPToolExecutorEnvironmentWideWriteLockTest extends UnitTestCase
{
    protected ?Account $accountBeforeTest = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accountBeforeTest = AuthService::instance()->getAccount();
        (new ReflectionProperty(AuthService::class, 'account'))->setValue(null, null);
        ProbeReadAndWriteMCPPackage::forgetRuns();
    }

    protected function tearDown(): void
    {
        ProbeReadAndWriteMCPPackage::forgetRuns();
        (new ReflectionProperty(AuthService::class, 'account'))->setValue(null, $this->accountBeforeTest);
        parent::tearDown();
    }

    public function testTheDefaultExecutorHasNoWriteLock(): void
    {
        self::assertFalse(self::invokeMethod(new MCPToolExecutorService(), 'isEnvironmentWideWriteLockActive', []));
    }

    public function testUnderTheLockADeclaredReadRuns(): void
    {
        $response = $this->callTool('probeReadNoteUnderWriteLock', true);

        self::assertFalse($response->isError);
        self::assertSame(['probeReadNoteUnderWriteLock'], ProbeReadAndWriteMCPPackage::$toolNamesOfRuns);
    }

    public function testUnderTheLockAWriteAndAnUndeclaredToolAreRefusedBeforeEveryOtherGate(): void
    {
        foreach (['probeWriteNoteUnderWriteLock', 'probeUndeclaredToolUnderWriteLock'] as $toolName) {
            $executor = $this->createExecutor(true);
            $response = $executor->callDispatchTool($toolName, [], [ProbeReadAndWriteMCPPackage::class], null);

            self::assertTrue($response->isError);
            self::assertTrue($response->isFinalRefusal());
            self::assertSame(MCPToolResponse::REFUSAL_ENVIRONMENT_WRITE_LOCKED, $response->refusalKind);
            self::assertSame(
                [['type' => 'text', 'text' => self::invokeMethod($executor, 'buildEnvironmentWideWriteLockRefusalText', [$toolName])]],
                $response->content
            );
            self::assertSame([], $executor->argumentsOfTargetAgentSpaceLookups, 'no gate after the lock was asked');
            self::assertSame([], $executor->targetAgentSpaceHandedToGate);
        }
        self::assertSame([], ProbeReadAndWriteMCPPackage::$toolNamesOfRuns);
    }

    public function testWithoutTheLockAWriteRuns(): void
    {
        $response = $this->callTool('probeWriteNoteUnderWriteLock', false);

        self::assertFalse($response->isError);
        self::assertSame(['probeWriteNoteUnderWriteLock'], ProbeReadAndWriteMCPPackage::$toolNamesOfRuns);
    }

    protected function createExecutor(bool $writeLockActive): WriteLockedToolExecutor
    {
        $executor = new WriteLockedToolExecutor();
        $executor->writeLockActive = $writeLockActive;
        $executor->answersWithStubbedTargetAgentSpace = true;
        return $executor;
    }

    protected function callTool(string $toolName, bool $writeLockActive): MCPToolResponse
    {
        return $this->createExecutor($writeLockActive)->callDispatchTool($toolName, [], [ProbeReadAndWriteMCPPackage::class], null);
    }
}
