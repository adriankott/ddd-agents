<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Admin\System\Lines;

use DDD\Domain\AI\Entities\MCPPackages\LlmRendering\LlmTextRendering;
use DDD\Domain\Common\Entities\Workers\WorkerProcess;

/**
 * One live worker process as a line: its state, since when it runs, when it last reported, what it handled, and the
 * class of the message it works on. Never its host, its process id or its key: they name machines and tell an
 * operator nothing the state does not.
 * Example: `BUSY · send_push_notification · started 2026-11-19 23:00 UTC · last seen 2026-11-19 23:39 UTC · 12 handled, 0 failed · working on SendPushNotificationMessage since 2026-11-19 23:38 UTC · memory 84.0 MB`
 */
class WorkerProcessLine
{
    /**
     * @param WorkerProcess $workerProcess
     */
    public function __construct(protected WorkerProcess $workerProcess)
    {
    }

    /**
     * @return string
     */
    public function toLlmLine(): string
    {
        $workerProcess = $this->workerProcess;
        $lineParts = [
            (string)($workerProcess->displayState ?? $workerProcess->state ?? 'UNKNOWN'),
            (string)($workerProcess->transport ?? '?'),
            'started ' . SystemLineFormats::formatTime($workerProcess->startedAt ?? null),
            'last seen ' . SystemLineFormats::formatTime($workerProcess->lastSeenAt ?? null),
            $workerProcess->handledCount . ' handled, ' . $workerProcess->failedCount . ' failed',
        ];
        $currentMessageClass = SystemLineFormats::shortClassName($workerProcess->currentMessageClass ?? null);
        if ($currentMessageClass !== '') {
            $lineParts[] = 'working on ' . $currentMessageClass . ' since ' . SystemLineFormats::formatTime($workerProcess->currentMessageSince ?? null);
        }
        if (($workerProcess->memoryBytes ?? null) !== null) {
            $lineParts[] = 'memory ' . LlmTextRendering::formatBytes($workerProcess->memoryBytes);
        }
        if (($workerProcess->restartRequestedAt ?? null) !== null) {
            $lineParts[] = 'restart requested ' . SystemLineFormats::formatTime($workerProcess->restartRequestedAt);
        }
        return implode(' · ', $lineParts);
    }
}
