<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Presentation\Api\Agents\Voice;

use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionOpenRequestDto;

/** The `open` request of an application whose clients state their own id (`clientId`, the field the chat's requests use). */
class ClientIdentifyingOpenRequestDto extends AgentVoiceSessionOpenRequestDto
{
    public ?string $clientId = null;
}
