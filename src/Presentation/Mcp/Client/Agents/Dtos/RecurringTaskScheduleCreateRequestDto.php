<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use Symfony\Component\Validator\Constraints\NotNull;

/** Input of `createRecurringTaskSchedule`: a recurring background task on a cron. */
class RecurringTaskScheduleCreateRequestDto extends RequestDto
{
    /** @var string|null The task's COMPLETE mission for EVERY run — write it like a full user request; each firing spawns a fresh background run with this as its first message */
    #[Parameter(in: Parameter::BODY, required: true)]
    #[NotNull]
    public ?string $task = null;

    /** @var string|null 3-6 word label in the user's language shown in the owner's task list — ALWAYS provide it */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $title = null;

    /** @var string|null Standard cron expression for the firing times (e.g. "0 7 * * 1" = every Monday 07:00), digits in the local time */
    #[Parameter(in: Parameter::BODY, required: true)]
    #[NotNull]
    public ?string $cronSchedule = null;

    /** @var string|null When the schedule counts as DONE and stops (plain language, judged after each run) — omit for an open-ended recurring task */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $doneCriteria = null;

    /** @var int|null Maximum number of runs before the schedule closes. A task that keeps running has no cap: omit it (0 counts as omitted) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $maxRuns = null;

    /** @var string|null How each run's final report reaches the owner: EMAIL (default — mail to the owner's own address) or CHAT (the report appears only in the task list) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $reportChannel = null;

    /** @var string|null Optional IANA time zone (e.g. "Europe/Berlin") the times are meant in — ONLY when the user explicitly names a different zone; omit to use the local zone */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $timezoneIdentifier = null;

    /** @var string|null The purpose every run starts with (its model, skills, seeded plan) — ONLY when the owner or a loaded skill names one; omit for the default */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $purposeName = null;

    /** @var int|null The space the task works for; omit it for the space this conversation works for */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $agentSpaceId = null;
}
