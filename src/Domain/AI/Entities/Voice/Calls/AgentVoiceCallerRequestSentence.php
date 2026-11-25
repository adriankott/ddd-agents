<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Voice\Calls;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One completed sentence of the caller that the session worker keeps as material for the next request: a delegation
 * of the voice model carries no text, so the request of its task is composed from the caller's most recent sentences.
 */
class AgentVoiceCallerRequestSentence extends ValueObject
{
    /** @var string The sentence */
    public string $text = '';

    /** @var int When the session worker got the completed sentence, in Unix milliseconds */
    public int $completedAtUnixMs = 0;

    public static function create(string $text, int $completedAtUnixMs): static
    {
        $agentVoiceCallerRequestSentence = new static();
        $agentVoiceCallerRequestSentence->text = $text;
        $agentVoiceCallerRequestSentence->completedAtUnixMs = $completedAtUnixMs;

        return $agentVoiceCallerRequestSentence;
    }
}
