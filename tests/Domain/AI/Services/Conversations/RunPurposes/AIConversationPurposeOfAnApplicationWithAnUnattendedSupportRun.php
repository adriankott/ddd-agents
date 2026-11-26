<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\RunPurposes;

use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;

/**
 * The purpose class of an application with an unattended support run its schedules may start with (a schedule-run
 * purpose with a pinned model) and an intake purpose no schedule may use.
 */
class AIConversationPurposeOfAnApplicationWithAnUnattendedSupportRun extends AIConversationPurpose
{
    public const string SUPPORT_UNATTENDED = 'Support.Unattended';

    public const string INTAKE_CHAT = 'Intake.Chat';

    protected const array REGISTERED_PURPOSE_NAMES = [
        self::BACKGROUND_MECHANICAL_OPERATION,
        self::SUPERVISOR_CHECKPOINT,
        self::SCHEDULE_RUN,
        self::SUPPORT_UNATTENDED,
        self::INTAKE_CHAT,
    ];
}
