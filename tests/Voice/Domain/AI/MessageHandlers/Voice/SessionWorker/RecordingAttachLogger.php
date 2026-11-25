<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\MessageHandlers\Voice\SessionWorker;

use Psr\Log\AbstractLogger;
use Stringable;

/** The handler's logger: every line with its level, nothing written anywhere. */
class RecordingAttachLogger extends AbstractLogger
{
    /** @var array<int, array{level: string, message: string}> */
    public array $loggedLines = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->loggedLines[] = ['level' => (string)$level, 'message' => (string)$message];
    }
}
