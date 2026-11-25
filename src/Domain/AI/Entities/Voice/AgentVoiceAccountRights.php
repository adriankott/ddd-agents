<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Voice;

/**
 * The account right of the voice channel. The tool package of the calls carries it as
 * `#[MCPRequiresAccountRights(...)]`, the skill `Agent.Voice` names the same right in `requiredAccountRights`, and the
 * admission of a call start asks for it. An application grants it: by roles in the config `AI.accountRights`, per tool
 * name in `AI.toolOverlays`, or by its own account rights implementation. The package's default mapping does not name
 * it, and a right no mapping names is not held: nobody talks to the agent by voice until the application says who
 * may. `app:agent:check-installation` reports the unmapped right once the voice channel is switched on.
 */
class AgentVoiceAccountRights
{
    /** @var string Talk to the agent by voice, and let the agent start, end and list the account's calls (`Agent.Voice`) */
    public const string USE_VOICE = 'useVoice';
}
