<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent;

use DDD\Infrastructure\Services\IssuesLogService;
use Psr\Log\LogLevel;
use Throwable;

/** The issues log that only records the level of each logged throwable. */
class RecordingIssuesLogServiceDouble extends IssuesLogService
{
    /** @var string[] The levels of the logged throwables, in order */
    public array $loggedLevels = [];

    public function __construct()
    {
    }

    public function logThrowable(
        Throwable $throwable,
        string $level = LogLevel::ERROR,
        array $additionalContext = [],
        bool $includeFullStackTrace = false,
        string $section = self::LOG_MESSAGE_SECTION_HTTP,
    ): void {
        $this->loggedLevels[] = $level;
    }
}
