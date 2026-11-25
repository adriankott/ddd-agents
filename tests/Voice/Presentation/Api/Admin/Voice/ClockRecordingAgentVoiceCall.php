<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Presentation\Api\Admin\Voice;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;

/**
 * A call that records the moment it is judged at ({@see getCallStateAt()}). With {@see $waitsForTheNextSecond} set it
 * waits, after recording, until the clock has passed that second (at most one second), so that a caller who reads the
 * clock again for the next row reads a later second and the test can tell one reading from one per row.
 */
class ClockRecordingAgentVoiceCall extends AgentVoiceCall
{
    /** @var int[] the moments of every getCallStateAt() of every instance, in call order */
    public static array $judgedAtUnixTimes = [];

    public bool $waitsForTheNextSecond = false;

    public function getCallStateAt(int $nowUnixTime): string
    {
        static::$judgedAtUnixTimes[] = $nowUnixTime;
        if ($this->waitsForTheNextSecond) {
            while (time() <= $nowUnixTime) {
                usleep(20_000);
            }
        }

        return parent::getCallStateAt($nowUnixTime);
    }
}
