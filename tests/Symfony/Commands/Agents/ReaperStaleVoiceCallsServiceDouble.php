<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallsService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Throwable;

/**
 * A voice calls service whose sweep answers the calls the test hands it, or throws the failure it hands it, and
 * records each sweep in the pass log. With a command set, the sweep first runs that command (a second run of the
 * reaper while this one holds the sweep's lock) and keeps what it printed.
 */
class ReaperStaleVoiceCallsServiceDouble extends AgentVoiceCallsService
{
    /** @var AgentVoiceCall[] The calls the sweep answers as closed */
    public array $closedCalls = [];

    public ?Throwable $sweepFailure = null;

    public int $sweepCount = 0;

    public int $staleAfterMinutes = 120;

    /** @var Command|null A command run while the sweep is active */
    public ?Command $commandRunDuringTheSweep = null;

    /** @var string|null What the command run during the sweep printed */
    public ?string $displayOfTheCommandRunDuringTheSweep = null;

    /**
     * @param RecoveryPassLog $recoveryPassLog
     */
    public function __construct(public RecoveryPassLog $recoveryPassLog)
    {
    }

    public function closeStaleRunningCalls(): array
    {
        $this->sweepCount++;
        $this->recoveryPassLog->record('voice-sweep');
        if ($this->commandRunDuringTheSweep !== null) {
            $commandTester = new CommandTester($this->commandRunDuringTheSweep);
            $commandTester->execute([]);
            $this->displayOfTheCommandRunDuringTheSweep = $commandTester->getDisplay();
        }
        if ($this->sweepFailure !== null) {
            throw $this->sweepFailure;
        }
        return $this->closedCalls;
    }

    public function getRunningCallStaleAfterMinutes(): int
    {
        return $this->staleAfterMinutes;
    }
}
