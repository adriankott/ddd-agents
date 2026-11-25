<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Symfony\Commands\Agents;

use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Tests\Agents\Symfony\Commands\Agents\AgentReapStuckConversationsProbeCommand;
use DDD\Tests\Agents\Symfony\Commands\Agents\ReaperConversationsServiceDouble;
use DDD\Tests\Agents\Symfony\Commands\Agents\ReaperDelegationBatchesServiceDouble;
use DDD\Tests\Agents\Symfony\Commands\Agents\ReaperDelegationNotificationsServiceDouble;
use DDD\Tests\Agents\Symfony\Commands\Agents\ReaperSettlingAgentServiceDouble;
use DDD\Tests\Agents\Symfony\Commands\Agents\ReaperStaleVoiceCallsServiceDouble;
use DDD\Tests\Agents\Symfony\Commands\Agents\RecoveryPassLog;
use DDD\Tests\Agents\Voice\Support\AgentVoiceTestConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The stale voice-call sweep of `app:agent:reap` lifts the entity rights for the sweep and they apply again after the
 * run, for a sweep that closes calls AND for one that throws (the case the former sweep command pinned for both; the
 * reaper's own sweep test `AgentReapStaleVoiceCallsSweepTest` pins the closing one).
 */
class AgentReapStaleVoiceCallsSweepRightsTest extends UnitTestCase
{
    /** @var array<string, mixed>|null The config values before the test declared the voice channel */
    protected ?array $configValuesBeforeTheTest = null;

    protected function setUp(): void
    {
        parent::setUp();
        DDDService::instance()->createEntityRightsRestrictionsStateSnapshot();
        DBEntity::setApplyRightsRestrictions(true);
        $this->configValuesBeforeTheTest = AgentVoiceTestConfiguration::declareAgentVoiceConfiguration(AgentVoiceTestConfiguration::buildSwitchedOnConfiguration());
    }

    protected function tearDown(): void
    {
        DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        if ($this->configValuesBeforeTheTest !== null) {
            AgentVoiceTestConfiguration::restoreConfigValues($this->configValuesBeforeTheTest);
        }
        parent::tearDown();
    }

    /** @return array<string, array{bool}> */
    public static function provideSweepFailures(): array
    {
        return ['a sweep that closes' => [false], 'a sweep that throws' => [true]];
    }

    #[DataProvider('provideSweepFailures')]
    public function testTheSweepRunsWithTheEntityRightsLiftedAndTheyApplyAgainAfterTheRun(bool $sweepThrows): void
    {
        $recoveryPassLog = new RecoveryPassLog();
        $staleVoiceCallsServiceDouble = new ReaperStaleVoiceCallsServiceDouble($recoveryPassLog);
        if ($sweepThrows) {
            $staleVoiceCallsServiceDouble->sweepFailure = new InternalErrorException('the call store is gone');
        }
        $probeCommand = new AgentReapStuckConversationsProbeCommand(
            $recoveryPassLog,
            new ReaperConversationsServiceDouble(),
            new ReaperDelegationBatchesServiceDouble($recoveryPassLog),
            new ReaperSettlingAgentServiceDouble($recoveryPassLog),
            new ReaperDelegationNotificationsServiceDouble($recoveryPassLog)
        );
        $probeCommand->agentVoiceCallsServiceDouble = $staleVoiceCallsServiceDouble;
        $rightsAppliedBeforeTheRun = DBEntity::getApplyRightsRestrictions();

        $exitCode = (new CommandTester($probeCommand))->execute([]);

        static::assertSame(0, $exitCode);
        static::assertTrue($rightsAppliedBeforeTheRun);
        static::assertSame(1, $staleVoiceCallsServiceDouble->sweepCount);
        static::assertSame([false], array_values(array_intersect_key(
            $recoveryPassLog->rightsAppliedAtEntries,
            array_filter($recoveryPassLog->entries, static fn (string $entry): bool => str_starts_with($entry, 'voice-sweep@'))
        )), 'the sweep ran with the rights lifted');
        static::assertTrue(DBEntity::getApplyRightsRestrictions(), 'the rights apply again after the run');
    }
}
