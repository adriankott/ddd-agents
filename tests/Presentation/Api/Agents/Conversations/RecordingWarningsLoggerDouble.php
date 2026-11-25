<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Api\Agents\Conversations;

use Psr\Log\AbstractLogger;
use Stringable;

/** The application logger, keeping the level and the text of every record. */
class RecordingWarningsLoggerDouble extends AbstractLogger
{
    /** @var array<int, array{level: string, message: string}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string)$level, 'message' => (string)$message . ($context !== [] ? ' ' . json_encode($context) : '')];
    }

    /** @return string[] The texts of the records of `$level` that contain `$needle` */
    public function findMessages(string $level, string $needle): array
    {
        $matchingMessages = [];
        foreach ($this->records as $record) {
            if ($record['level'] === $level && str_contains($record['message'], $needle)) {
                $matchingMessages[] = $record['message'];
            }
        }
        return $matchingMessages;
    }

    /** @return string[] The texts that tell of a database connection or query */
    public function findDatabaseMessages(): array
    {
        $databaseMessages = [];
        foreach ($this->records as $record) {
            if (preg_match('/SQLSTATE|PDO|Doctrine|Access denied|Connection refused/i', $record['message']) === 1) {
                $databaseMessages[] = $record['message'];
            }
        }
        return $databaseMessages;
    }
}
