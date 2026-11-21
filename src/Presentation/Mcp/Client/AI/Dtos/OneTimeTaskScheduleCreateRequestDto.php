<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\AI\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * Input of `scheduleOneTimeTask`: a ONE-TIME task at a specific moment — relative via `runInMinutes`, absolute via
 * `runAtLocalTime` in the local time of the space. Exactly one of the two.
 */
class OneTimeTaskScheduleCreateRequestDto extends RequestDto
{
    /** @var string|null The task's COMPLETE mission — write it like a full user request (the action and the result the owner wants from it); it becomes the background run's first message and the run cannot see this chat */
    #[Parameter(in: Parameter::BODY, required: true)]
    #[NotNull]
    public ?string $task = null;

    /** @var string|null 3-6 word label in the user's language shown in the owner's task list — ALWAYS provide it */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $title = null;

    /** @var int|null Run the task THIS many minutes from now (a wish for ten minutes from now → 10) — for RELATIVE times only; no timezone math needed. Exactly one of runInMinutes / runAtLocalTime */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $runInMinutes = null;

    /** @var string|null Run the task at this LOCAL date and time, format "YYYY-MM-DD HH:MM" (a stated time of today → today's date + that time) — the system converts from the local time zone. Exactly one of runInMinutes / runAtLocalTime */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $runAtLocalTime = null;

    /** @var string|null How the run's final report reaches the owner: EMAIL (default) or CHAT (only in the task list — e.g. when the task itself calls the owner) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $reportChannel = null;

    /** @var string|null Optional IANA time zone (e.g. "Europe/Berlin") the times are meant in — ONLY when the user explicitly names a different zone; omit to use the local zone */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $timezoneIdentifier = null;

    /** @var int|null The space the task works for; omit it for the space this conversation works for */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $agentSpaceId = null;
}
