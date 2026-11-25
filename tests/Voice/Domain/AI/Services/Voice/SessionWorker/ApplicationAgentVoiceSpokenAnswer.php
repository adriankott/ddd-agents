<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\SessionWorker;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceSpokenAnswer;

/** An application's same-path subclass of the spoken answer: the worker must build this class when it is registered. */
class ApplicationAgentVoiceSpokenAnswer extends AgentVoiceSpokenAnswer
{
}
