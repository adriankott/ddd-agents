<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Result of deleting a task schedule under the archive policy: which action happened and what to tell the user. */
class TaskScheduleDeleteMcpResponseDto extends RestResponseDto
{
    public const string NOTE_HARD_DELETE = 'The task never ran and is deleted for good — confirm in ONE short sentence.';

    public const string NOTE_ARCHIVE = 'The recurring task is stopped and archived (its past runs stay readable) — confirm in ONE short sentence.';

    public const string NOTE_KEEP_HISTORY = 'This one-time task already ran — it stays in the history unchanged; nothing was deleted. Tell the user so.';

    /** @var string|null What happened: HARD_DELETE (never ran — removed for good), ARCHIVE (recurring with runs — stopped, hidden from default lists), KEEP_HISTORY (one-time already ran — stays as history) */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $actionTaken = null;

    /** @var string|null What to tell the user now */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $note = null;

    /**
     * The result of the archive-policy delete that took `$actionTaken`.
     *
     * @param string $actionTaken {@see AIConversationSchedule::DELETE_ACTION_HARD_DELETE}, `DELETE_ACTION_ARCHIVE` or the keep-history action
     * @return static
     */
    public static function forActionTaken(string $actionTaken): static
    {
        $responseDto = new static();
        $responseDto->actionTaken = $actionTaken;
        $responseDto->note = match ($actionTaken) {
            AIConversationSchedule::DELETE_ACTION_HARD_DELETE => static::NOTE_HARD_DELETE,
            AIConversationSchedule::DELETE_ACTION_ARCHIVE => static::NOTE_ARCHIVE,
            default => static::NOTE_KEEP_HISTORY,
        };
        return $responseDto;
    }
}
