<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Voice\Calls;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What goes to the voice model at one check of a running task, as {@see AgentVoiceTaskProgressSchedule::decideUpdateAt()}
 * decided it: a line to speak, unspoken context, the generic bridge, or nothing.
 */
class AgentVoiceTaskProgressUpdate extends ValueObject
{
    /** @var string|null The line to speak; null = no spoken progress now */
    public ?string $spokenProgressLine = null;

    /** @var string[] Unspoken context: older new lines and the words of steps without a line */
    public array $silentContextLines = [];

    /** @var bool Whether the generic "still working" bridge is due */
    public bool $bridgeDue = false;

    /** @var int|null Set on the ONE decision that saw the task's first narrated line: the milliseconds since the delegation */
    public ?int $firstLineSeenAfterMilliseconds = null;
}
