<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\Eval;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * A child process the test scripts without starting one: whether it still runs, whether its ceiling has passed and
 * what it printed. A ceiling check of a process past its ceiling throws, as Symfony's does, whether or not it still
 * runs — so the order of the two checks decides what the caller sees.
 */
class ScriptedChildProcessDouble extends Process
{
    /** @var bool Whether the child still runs */
    public bool $running = false;

    /** @var bool Whether the child's ceiling has passed */
    public bool $ceilingPassed = false;

    /** @var string What the child printed */
    public string $printedOutput = '';

    /** @var int How often the ceiling was checked */
    public int $ceilingChecks = 0;

    /** @var int How often the parent asked the child to stop */
    public int $stopCalls = 0;

    public function __construct()
    {
        parent::__construct(['php', '-v']);
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function checkTimeout(): void
    {
        $this->ceilingChecks++;
        if ($this->ceilingPassed) {
            throw new ProcessTimedOutException($this, ProcessTimedOutException::TYPE_GENERAL);
        }
    }

    /** Nothing was started: nothing to stop (also when the object is destroyed). */
    public function stop(float $timeout = 10, ?int $signal = null): ?int
    {
        $this->stopCalls++;
        return null;
    }

    public function getPid(): ?int
    {
        return null;
    }

    public function getOutput(): string
    {
        return $this->printedOutput;
    }
}
