<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Support;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * Calls at fixed moments: every time of a call is given as seconds relative to a fixed "now" the test passes in
 * (null = the column is empty), so no fixture depends on the clock of the run.
 */
class AgentVoiceCallFixtures
{
    /** @var int The fixed "now" of the tests: 2027-01-15 08:00:00 UTC */
    public const int NOW_UNIX_TIME = 1_800_000_000;

    /**
     * @template T of AgentVoiceCall
     * @param class-string<T> $callClassName
     * @return T
     */
    public static function buildCallAt(
        int $nowUnixTime,
        ?int $createdOffsetSeconds = null,
        ?int $ringDeadlineOffsetSeconds = null,
        ?int $startedOffsetSeconds = null,
        ?int $endedOffsetSeconds = null,
        string $callClassName = AgentVoiceCall::class
    ): AgentVoiceCall {
        $agentVoiceCall = new $callClassName();
        $agentVoiceCall->id = 7;
        if ($createdOffsetSeconds !== null) {
            $agentVoiceCall->getChangeHistory()->createdTime = DateTime::fromTimestamp($nowUnixTime + $createdOffsetSeconds);
        }
        $agentVoiceCall->ringDeadlineAt = static::buildMomentAt($nowUnixTime, $ringDeadlineOffsetSeconds);
        $agentVoiceCall->startedAt = static::buildMomentAt($nowUnixTime, $startedOffsetSeconds);
        $agentVoiceCall->endedAt = static::buildMomentAt($nowUnixTime, $endedOffsetSeconds);
        return $agentVoiceCall;
    }

    public static function buildMomentAt(int $nowUnixTime, ?int $offsetSeconds): ?DateTime
    {
        return $offsetSeconds === null ? null : DateTime::fromTimestamp($nowUnixTime + $offsetSeconds);
    }

    /** The text a moment is written as in the texts of a call (`Y-m-d H:i:s`, the process time zone). */
    public static function formatMomentAt(int $nowUnixTime, int $offsetSeconds): string
    {
        return date('Y-m-d H:i:s', $nowUnixTime + $offsetSeconds);
    }
}
