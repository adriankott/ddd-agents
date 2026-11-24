<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use Symfony\Component\Validator\Constraints\NotNull;

/** Input of `startBackgroundTask`: the one-off background task's mission, its label and its report channel. */
class BackgroundTaskStartRequestDto extends RequestDto
{
    /** @var string|null The task's COMPLETE mission — write it like a full user request (the subject, the period and the result the owner wants), never a fragment; it becomes the background run's first message */
    #[Parameter(in: Parameter::BODY, required: true)]
    #[NotNull]
    public ?string $task = null;

    /** @var string|null 3-6 word label in the user's language shown in the owner's task list — ALWAYS provide it */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $title = null;

    /** @var string|null How the run's final report reaches the owner: EMAIL (default — mail to the owner's own address) or CHAT (the report appears only in the task list) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $reportChannel = null;

    /** @var int|null The space the task works for; omit it for the space this conversation works for */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $agentSpaceId = null;
}
