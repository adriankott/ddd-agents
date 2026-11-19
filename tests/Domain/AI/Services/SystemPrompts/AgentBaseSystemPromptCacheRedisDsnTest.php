<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\SystemPrompts;

use DDD\Domain\AI\Services\SystemPrompts\AgentBaseSystemPromptCacheService;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The DSN of the native Redis connection of the base system prompt cache: every key of the cache group's configuration
 * is optional (a Redis without a password has no `CACHE_REDIS_PASSWORD`), and an absent key is never read — reading it
 * logged "Undefined array key" on every turn of a worker.
 */
class AgentBaseSystemPromptCacheRedisDsnTest extends UnitTestCase
{
    public function testAConfigurationWithoutPasswordConnectsWithoutOne(): void
    {
        self::assertSame(
            'redis://redis.internal:6380/2',
            AgentBaseSystemPromptCacheService::buildRedisConnectionDsn(['host' => 'redis.internal', 'port' => '6380', 'database' => '2'])
        );
    }

    public function testAFullConfigurationNamesItsPassword(): void
    {
        self::assertSame(
            'redis://secret@redis.internal:6379/0',
            AgentBaseSystemPromptCacheService::buildRedisConnectionDsn(['password' => 'secret', 'host' => 'redis.internal', 'port' => 6379, 'database' => 0])
        );
    }

    public function testAnEmptyConfigurationTakesTheRedisDefaultsWithoutAWarning(): void
    {
        $warnings = [];
        set_error_handler(static function (int $errorNumber, string $errorMessage) use (&$warnings): bool {
            $warnings[] = $errorMessage;
            return true;
        });
        try {
            $dsn = AgentBaseSystemPromptCacheService::buildRedisConnectionDsn([]);
        } finally {
            restore_error_handler();
        }
        self::assertSame('redis://127.0.0.1:6379/0', $dsn);
        self::assertSame([], $warnings);
    }
}
