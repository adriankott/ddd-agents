<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Ring;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * The application logger, keeping every record. The staging fails a test whose code path logged a database error
 * (a best-effort catch would otherwise hide that a read reached the database): {@see findDatabaseErrorRecords()}.
 */
class RecordingLoggerDouble extends AbstractLogger
{
    /** @var array<int, array{level: string, message: string}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string)$level, 'message' => (string)$message . ($context !== [] ? ' ' . json_encode($context) : '')];
    }

    /** @return string[] The messages of the records whose text contains `$needle` */
    public function findMessagesContaining(string $needle): array
    {
        $matchingMessages = [];
        foreach ($this->records as $record) {
            if (str_contains($record['message'], $needle)) {
                $matchingMessages[] = $record['message'];
            }
        }
        return $matchingMessages;
    }

    /** @return string[] The messages that tell of a database connection or query */
    public function findDatabaseErrorRecords(): array
    {
        $databaseErrorMessages = [];
        foreach ($this->records as $record) {
            if (preg_match('/SQLSTATE|PDO|Doctrine|Access denied|Connection refused/i', $record['message']) === 1) {
                $databaseErrorMessages[] = $record['message'];
            }
        }
        return $databaseErrorMessages;
    }
}
