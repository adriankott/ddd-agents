<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\RunRelease;

use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * The ordered record of what the resume of a worker turn wrote and dispatched: every double of the release stage adds
 * one readable line per call, so one row of the release table is the exact sequence the resume produced (the drive,
 * the turn-end event, the token-guarded release, the schedule record, the probe, the realtime status, the re-fire, the
 * promotion and the follow-ups of an answered turn).
 */
class RunReleaseEventLog
{
    /** @var string[] the lines, in call order */
    public array $events = [];

    public function record(string $event): void
    {
        $this->events[] = $event;
    }

    /**
     * The handover marker of a release as a line fragment: `none` (null), `now` (within a minute of the test clock — the
     * release stamps `new DateTime()`), or the literal value.
     */
    public static function describeOwedAt(?DateTime $agentTurnOwedAt): string
    {
        if ($agentTurnOwedAt === null) {
            return 'none';
        }
        $secondsFromNow = abs($agentTurnOwedAt->getTimestamp() - (new DateTime())->getTimestamp());
        return $secondsFromNow <= 60 ? 'now' : $agentTurnOwedAt->format('Y-m-d H:i:s');
    }
}
