<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Entities\Voice\TraceEvents;

use DDD\Domain\AI\Entities\Voice\TraceEvents\AgentVoiceCallTraceBacklogPush;
use DDD\Domain\AI\Entities\Voice\TraceEvents\AgentVoiceCallTraceSpokenSentence;
use DDD\Tests\Agents\Voice\Support\AgentVoiceUnitTestCase;
use DDD\Tests\Agents\Voice\Support\AgentVoiceValueObjectAssertionsTrait;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The typed rows of the trace summary: a spoken sentence keeps its four values; a backlog push keeps its times as
 * given (null = not in the stored trace payload) and its spoken length is the span between them, null while either is
 * unknown and never negative (a stored payload may carry an end before its start).
 */
class AgentVoiceCallTraceRowsTest extends AgentVoiceUnitTestCase
{
    use AgentVoiceValueObjectAssertionsTrait;

    public function testASpokenSentenceKeepsItsFourValues(): void
    {
        static::assertSame(
            ['class' => AgentVoiceCallTraceSpokenSentence::class, 'role' => 'ASSISTANT', 'fromMs' => 1200, 'untilMs' => 2400, 'occurredAtUnixMs' => 1_800_000_000_000],
            static::describeValueObjects(AgentVoiceCallTraceSpokenSentence::create('ASSISTANT', 1200, 2400, 1_800_000_000_000))
        );
    }

    public function testABacklogPushKeepsItsTimesAsGiven(): void
    {
        static::assertSame(
            ['class' => AgentVoiceCallTraceBacklogPush::class, 'pushedAtUnixMs' => 1_800_000_000_000, 'startMs' => null, 'endMs' => 0],
            static::describeValueObjects(AgentVoiceCallTraceBacklogPush::create(1_800_000_000_000, null, 0))
        );
    }

    /** @return array<string, array{int|null, int|null, int|null}> */
    public static function provideSpokenLengths(): array
    {
        return [
            'both times' => [1000, 1600, 600],
            'the same instant' => [1000, 1000, 0],
            'from zero' => [0, 450, 450],
            'no start' => [null, 1600, null],
            'no end' => [1000, null, null],
            'no time at all' => [null, null, null],
            'an end before its start' => [1600, 1000, 0],
        ];
    }

    #[DataProvider('provideSpokenLengths')]
    public function testTheSpokenLengthIsTheSpanNullWhileATimeIsUnknownAndNeverNegative(?int $startMs, ?int $endMs, ?int $expectedSpokenLengthMs): void
    {
        static::assertSame($expectedSpokenLengthMs, AgentVoiceCallTraceBacklogPush::create(1_800_000_000_000, $startMs, $endMs)->getSpokenLengthMs());
    }
}
