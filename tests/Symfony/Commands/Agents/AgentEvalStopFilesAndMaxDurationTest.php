<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Tests\Agents\Domain\AI\Services\Eval\AgentEvalResultFilesTestCase;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;

/**
 * The graceful stop of an evaluation run without a signal: a `STOP` file in the run folder, a `STOP` file in the results
 * folder (every run of the host, announced at the start) and a passed `--max-duration` count as the first stop signal;
 * the line of a stop by the deadline says how many cases were not run. Without any of them the run goes on.
 */
class AgentEvalStopFilesAndMaxDurationTest extends AgentEvalResultFilesTestCase
{
    public function testARunGoesOnWithoutAStopFileAndWithoutADeadline(): void
    {
        $probeCommand = $this->buildProbeCommandWithInput([]);

        $this->requestStopForStopFilesOrDeadline($probeCommand, $this->resultsDirectory . '/run');

        self::assertFalse($probeCommand->isStopRequested());
        self::assertNull($this->invokeProtected($probeCommand, 'buildHostWideStopFileWarning', []));
    }

    public function testAStopFileInTheRunFolderStopsThatRun(): void
    {
        $runDirectory = $this->resultsDirectory . '/run';
        mkdir($runDirectory);
        touch($runDirectory . '/STOP');
        $probeCommand = $this->buildProbeCommandWithInput([]);

        $this->requestStopForStopFilesOrDeadline($probeCommand, $runDirectory);

        self::assertTrue($probeCommand->isStopRequested());
        self::assertFalse($probeCommand->isKillRequested(), 'a file is a first signal only');
        self::assertFalse($probeCommand->isStoppedAtMaxDuration());
    }

    public function testAStopFileInTheResultsFolderStopsEveryRunAndIsAnnounced(): void
    {
        touch($this->resultsDirectory . '/STOP');
        $probeCommand = $this->buildProbeCommandWithInput([]);

        self::assertStringContainsString($this->resultsDirectory . '/STOP', (string)$this->invokeProtected($probeCommand, 'buildHostWideStopFileWarning', []));
        $this->requestStopForStopFilesOrDeadline($probeCommand, null);

        self::assertTrue($probeCommand->isStopRequested());
    }

    public function testAPassedMaxDurationStopsTheRunAndSaysHowManyCasesWereNotRun(): void
    {
        $probeCommand = $this->buildProbeCommandWithInput(['--max-duration' => '0.01']);
        usleep(30_000);

        $this->requestStopForStopFilesOrDeadline($probeCommand, null);

        self::assertTrue($probeCommand->isStopRequested());
        self::assertTrue($probeCommand->isStoppedAtMaxDuration());
        self::assertStringStartsWith('Stopped at max duration — 4 case(s) not run', (string)$this->invokeProtected($probeCommand, 'describeStopRequest', [4]));
    }

    public function testADeadlineStillAheadAndAMissingOptionSetNoStop(): void
    {
        $probeCommand = $this->buildProbeCommandWithInput(['--max-duration' => '3600']);
        $this->requestStopForStopFilesOrDeadline($probeCommand, null);
        self::assertFalse($probeCommand->isStopRequested());

        $probeCommandWithoutLimit = $this->buildProbeCommandWithInput(['--max-duration' => '0']);
        $this->requestStopForStopFilesOrDeadline($probeCommandWithoutLimit, null);
        self::assertFalse($probeCommandWithoutLimit->isStopRequested(), 'zero is no limit');
    }

    /** @param array<string, string> $options */
    protected function buildProbeCommandWithInput(array $options): AgentEvalConsoleProbeCommand
    {
        $probeCommand = new AgentEvalConsoleProbeCommand(new AgentSpacesServiceConsoleScopeDouble(), new RecordingUsageEnvelopesServiceDouble());
        $input = new ArrayInput($options, $probeCommand->getDefinition());
        $this->invokeProtected($probeCommand, 'startMaxDurationClock', [$input]);
        return $probeCommand;
    }

    protected function requestStopForStopFilesOrDeadline(AgentEvalConsoleProbeCommand $probeCommand, ?string $runDirectory): void
    {
        $this->invokeProtected($probeCommand, 'requestStopForStopFilesOrDeadline', [$runDirectory]);
    }

    /** @param array<int, mixed> $arguments */
    protected function invokeProtected(object $object, string $methodName, array $arguments): mixed
    {
        return (new ReflectionMethod($object, $methodName))->invokeArgs($object, $arguments);
    }
}
