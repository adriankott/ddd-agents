<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Presentation\Api\Agents\Voice;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;

/** A call an application runs over a medium of its own, not the application's window: the window routes do not open it. */
class OtherMediumAgentVoiceCall extends AgentVoiceCall
{
    public const string CHANNEL_TYPE_OTHER_MEDIUM = 'OTHER_MEDIUM';

    public function getChannelType(): string
    {
        return static::CHANNEL_TYPE_OTHER_MEDIUM;
    }
}
