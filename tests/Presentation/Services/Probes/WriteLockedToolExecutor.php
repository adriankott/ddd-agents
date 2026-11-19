<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Services\Probes;

/** The observing executor of a process under the environment-wide write lock (when {@see $writeLockActive} is set). */
class WriteLockedToolExecutor extends TargetAgentSpaceObservingToolExecutor
{
    /** The answer of the write-lock hook */
    public bool $writeLockActive = true;

    protected function isEnvironmentWideWriteLockActive(): bool
    {
        return $this->writeLockActive;
    }
}
