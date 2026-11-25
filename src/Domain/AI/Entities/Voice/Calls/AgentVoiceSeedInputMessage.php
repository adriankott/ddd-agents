<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Voice\Calls;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One seed row of a voice session: a text the voice model is given when its session is created, before anyone
 * speaks (the opening order, the agent's character, what was discussed before). {@see AgentVoiceSessionContext}
 * builds the rows, the most important first; the provider adapter caps their count and size and turns them into the
 * provider's own shape ({@see \DDD\Domain\AI\Services\Voice\Providers\RealtimeVoiceProviderAdapter::buildSessionCreationPayload()}).
 */
class AgentVoiceSeedInputMessage extends ValueObject
{
    /** @var string A row the voice model reads as an order or as context of the application */
    public const string ROLE_DEVELOPER = 'developer';

    /** @var string A row the voice model reads as said by the caller */
    public const string ROLE_USER = 'user';

    /** @var string A row the voice model reads as said by itself */
    public const string ROLE_ASSISTANT = 'assistant';

    /** @var string {@see self::ROLE_DEVELOPER}, {@see self::ROLE_USER} or {@see self::ROLE_ASSISTANT} */
    public string $role = self::ROLE_DEVELOPER;

    /** @var string The text of the row */
    public string $text = '';

    public static function create(string $role, string $text): static
    {
        $agentVoiceSeedInputMessage = new static();
        $agentVoiceSeedInputMessage->role = $role;
        $agentVoiceSeedInputMessage->text = $text;

        return $agentVoiceSeedInputMessage;
    }

    /**
     * The key of a row in {@see AgentVoiceSeedInputMessages} is the object itself, never its content: two rows with
     * the same role and text are two rows, and the set keeps them in the order they were added.
     */
    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic(spl_object_id($this));
    }
}
