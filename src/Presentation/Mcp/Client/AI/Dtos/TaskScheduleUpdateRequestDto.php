<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\AI\Dtos;

use DDD\Domain\AI\Entities\MCPPackages\MCPEmptyStringIsValue;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use Symfony\Component\Validator\Constraints\NotNull;

/** Input of `updateTaskSchedule`: an IN-PLACE edit of an existing task schedule. Only the sent fields change. */
class TaskScheduleUpdateRequestDto extends RequestDto
{
    /** @var int|null The schedule id to change (from getTaskSchedules) */
    #[Parameter(in: Parameter::BODY, required: true)]
    #[NotNull]
    public ?int $scheduleId = null;

    /** @var string|null New COMPLETE task mission (replaces the old one entirely — write it like a full user request); omit to keep the current task */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $task = null;

    /** @var string|null New 3-6 word label in the user's language shown in the owner's task list */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $title = null;

    /** @var string|null New cron expression (e.g. "0 8 * * 5") — RECURRING schedules only; at most 4 firings per day */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $cronSchedule = null;

    /** @var string|null Move a ONE-TIME task to this LOCAL date and time, format "YYYY-MM-DD HH:MM" — only while it has not run yet. Exactly one of runInMinutes / runAtLocalTime */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $runAtLocalTime = null;

    /** @var int|null Move a ONE-TIME task to THIS many minutes from now — only while it has not run yet. Exactly one of runInMinutes / runAtLocalTime */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $runInMinutes = null;

    /** @var string|null New done criteria (plain language, judged after each run); pass an empty string to remove them (the schedule then runs until expiry or cancel) */
    #[Parameter(in: Parameter::BODY, required: false)]
    #[MCPEmptyStringIsValue]
    public ?string $doneCriteria = null;

    /** @var string|null New report channel: EMAIL (mail to the owner) or CHAT (only in the task list) — applies to future runs only */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $reportChannel = null;

    /** @var string|null Optional IANA time zone (e.g. "Europe/Berlin") the times are meant in — ONLY when the user explicitly names a different zone */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $timezoneIdentifier = null;
}
