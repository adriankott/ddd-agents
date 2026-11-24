<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Admin\System\Lines;

use DDD\Domain\Common\Entities\Workers\WorkerMessageClassStat;

/**
 * The runs of one message class on a transport inside a window as a line: handled and failed, how long a run takes
 * and waits, and its last error with the message as logged (first line, masked, cut). The message can quote what a
 * client sent: the tool marks these lines untrusted.
 * Example: `SendPushNotificationMessage · 120 handled, 2 thrown, 1 caught, 3 redelivered · avg 420 ms, max 9.1 s · wait avg 30 ms · last error TransportException at 2026-11-19 10:02 UTC: "Connection reset by peer"`
 */
class WorkerMessageClassLine
{
    /** @var int The characters of the error message a line shows */
    public const int MESSAGE_LENGTH = 200;

    /**
     * @param WorkerMessageClassStat $workerMessageClassStat
     */
    public function __construct(protected WorkerMessageClassStat $workerMessageClassStat)
    {
    }

    /**
     * @return string
     */
    public function toLlmLine(): string
    {
        $messageClassStat = $this->workerMessageClassStat;
        $lineParts = [
            SystemLineFormats::shortClassName($messageClassStat->messageClass),
            $messageClassStat->handledCount . ' handled, ' . $messageClassStat->thrownCount . ' thrown, ' . $messageClassStat->caughtCount . ' caught, '
            . $messageClassStat->redeliveredCount . ' redelivered',
        ];
        if ($messageClassStat->durationAverageMs !== null) {
            $lineParts[] = 'avg ' . SystemLineFormats::formatDuration($messageClassStat->durationAverageMs) . ', max ' . SystemLineFormats::formatDuration($messageClassStat->durationMaxMs);
        }
        if ($messageClassStat->waitAverageMs !== null) {
            $lineParts[] = 'wait avg ' . SystemLineFormats::formatDuration($messageClassStat->waitAverageMs);
        }
        if ($messageClassStat->lastErrorClass !== null && $messageClassStat->lastErrorClass !== '') {
            $errorMessage = SystemLineFormats::quoteLoggedText($messageClassStat->lastErrorMessage, static::MESSAGE_LENGTH);
            $lineParts[] = 'last error ' . SystemLineFormats::shortClassName($messageClassStat->lastErrorClass) . ' at '
                . SystemLineFormats::formatTime($messageClassStat->lastErrorAt) . ($errorMessage !== '' ? ': ' . $errorMessage : '');
        }
        return implode(' · ', $lineParts);
    }
}
