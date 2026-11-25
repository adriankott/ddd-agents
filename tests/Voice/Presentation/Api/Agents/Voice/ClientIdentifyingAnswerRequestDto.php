<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Presentation\Api\Agents\Voice;

use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionAnswerRequestDto;

/** The `answer` request of an application whose clients state their own id (`clientId`). */
class ClientIdentifyingAnswerRequestDto extends AgentVoiceSessionAnswerRequestDto
{
    public ?string $clientId = null;
}
