<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Admin\System\Lines;

use DDD\Domain\Common\Entities\Workers\WorkerQueueCount;
use DDD\Domain\Common\Entities\Workers\WorkerTransportOverview;

/**
 * One worker transport as a line: its state, what waits and what is in work, its processes against the allowed
 * range, what it finished and failed, and the class and time of its last error. Never the consumer's settings and
 * never an error message (a message is shown masked by the tool, as logged text).
 * Example: `send_push_notification · IDLE · 0 waiting · 0 in work · 1 process (allowed 1–20) · 15 min: 0 handled, 0 failed · 1 h: 12 handled, 0 failed · 24 h: 310 handled, 2 failed · avg 420 ms · last error TransportException at 2026-11-19 10:02 UTC`
 */
class WorkerTransportLine
{
    /**
     * @param WorkerTransportOverview $workerTransportOverview
     */
    public function __construct(protected WorkerTransportOverview $workerTransportOverview)
    {
    }

    /**
     * @param int $windowSeconds 3600 or 86400
     * @return int[] Handled and failed messages of the window: [handled, failed]
     */
    public function getCountsOfWindow(int $windowSeconds): array
    {
        foreach ($this->workerTransportOverview->throughputWindows->getElements() as $workerThroughputWindow) {
            if ($workerThroughputWindow->windowSeconds === $windowSeconds) {
                return [$workerThroughputWindow->handledCount, $workerThroughputWindow->failedCount];
            }
        }
        return [0, 0];
    }

    /**
     * @return string What waits: a count, or why there is none
     */
    public function describeWaiting(): string
    {
        return match ($this->workerTransportOverview->waitingState) {
            WorkerQueueCount::STATE_COUNTED => ($this->workerTransportOverview->waiting ?? 0) . ' waiting',
            WorkerQueueCount::STATE_QUEUE_NOT_SET_UP => 'queue not set up yet (nothing was ever sent)',
            default => 'waiting count unknown',
        };
    }

    /**
     * @return string
     */
    public function toLlmLine(): string
    {
        $transport = $this->workerTransportOverview;
        $lineParts = [$transport->transport, $transport->state, $this->describeWaiting(), $transport->inFlight . ' in work'];
        $allowedRange = $transport->effectiveMin !== null || $transport->effectiveMax !== null
            ? ' (allowed ' . ($transport->effectiveMin ?? '?') . '–' . ($transport->effectiveMax ?? '?') . ')'
            : '';
        $lineParts[] = $transport->liveProcessCount . ($transport->liveProcessCount === 1 ? ' process' : ' processes') . $allowedRange;
        $lineParts[] = '15 min: ' . $transport->handledCount . ' handled, ' . ($transport->thrownCount + $transport->caughtCount) . ' failed';
        foreach ([3600 => '1 h', 86400 => '24 h'] as $windowSeconds => $windowLabel) {
            [$handledCount, $failedCount] = $this->getCountsOfWindow($windowSeconds);
            $lineParts[] = $windowLabel . ': ' . $handledCount . ' handled, ' . $failedCount . ' failed';
        }
        if ($transport->durationAverageMs !== null) {
            $lineParts[] = 'avg ' . SystemLineFormats::formatDuration($transport->durationAverageMs);
        }
        if ($transport->stuckSince !== null) {
            $lineParts[] = 'nothing finished since ' . SystemLineFormats::formatTime($transport->stuckSince);
        }
        if ($transport->restartPending) {
            $lineParts[] = 'restart requested';
        }
        if (!$transport->isDefined) {
            $lineParts[] = 'not in the worker list';
        }
        if ($transport->lastErrorClass !== null && $transport->lastErrorClass !== '') {
            $lineParts[] = 'last error ' . SystemLineFormats::shortClassName($transport->lastErrorClass) . ' at ' . SystemLineFormats::formatTime($transport->lastErrorAt);
        }
        return implode(' · ', $lineParts);
    }
}
