<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations;

use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Tests\Agents\Support\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The edges of {@see AIConversationSchedule::isExpired()}: the ttl at the very second, more runs than the cap, and
 * that either the ttl or the cap is enough. The plain cases (no ttl and no cap, a passed ttl, the cap reached and
 * one below it) are in {@see AIConversationScheduleTest::testExpiryByTtlAndByMaxIterations()}.
 */
class AIConversationScheduleExpiryBoundariesTest extends UnitTestCase
{
    protected const string NOW = '2030-01-01 12:00:00';

    #[DataProvider('provideSchedulesAtTheEdges')]
    public function testExpiryAtTheEdges(?string $ttlAt, ?int $maxIterations, int $iterationsUsed, bool $expectedExpired): void
    {
        $schedule = self::instantiateWithoutConstructor(AIConversationSchedule::class);
        $schedule->schedule = '0 7 * * 1';
        if ($ttlAt !== null) {
            $schedule->ttlAt = new DateTime($ttlAt);
        }
        if ($maxIterations !== null) {
            $schedule->maxIterations = $maxIterations;
        }
        $schedule->iterationsUsed = $iterationsUsed;

        self::assertSame($expectedExpired, $schedule->isExpired(new DateTime(static::NOW)));
    }

    /**
     * @return array<string, array{0: string|null, 1: int|null, 2: int, 3: bool}>
     */
    public static function provideSchedulesAtTheEdges(): array
    {
        return [
            'a ttl of this very second has passed' => ['2030-01-01 12:00:00', null, 7, true],
            'a ttl one second ahead has not' => ['2030-01-01 12:00:01', null, 7, false],
            'more runs than the cap' => [null, 3, 5, true],
            'a ttl ahead does not save a schedule at its cap' => ['2030-01-01 12:00:01', 3, 3, true],
            'a passed ttl expires a schedule below its cap' => ['2030-01-01 11:59:59', 3, 2, true],
            'a ttl ahead and runs left' => ['2030-01-01 12:00:01', 3, 2, false],
            'a one-time schedule that never ran' => [null, 1, 0, false],
            'a one-time schedule that never ran, its ttl passed' => ['2030-01-01 11:59:59', 1, 0, true],
        ];
    }
}
