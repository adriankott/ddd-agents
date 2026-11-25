<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Realtime;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A type of realtime message a package (or the host) registers through
 * {@see \DDD\Domain\AI\Services\Conversations\Realtime\AgentRealtimeMessageTypeProvider}: only registered types are
 * sent. The name is `<package id>.<message>` (`agent.ui.clientToolRequest`); the payload cap bounds what one message
 * carries through the transport and the mailbox.
 */
class AgentRealtimeMessageType extends ValueObject
{
    /** @var int The longest payload a type may declare */
    public const int MAX_PAYLOAD_JSON_CHARS_CEILING = 16384;

    /** @var string A type name: lowercase segments separated by `.`, camelCase allowed */
    public const string NAME_PATTERN = '/^[a-z][a-z0-9]*(\.[a-z][A-Za-z0-9]*){1,4}$/';

    /** @var string The type's name, e.g. `agent.ui.clientToolRequest` */
    public string $name;

    /** @var string The package that registered it (its package id or Composer name) */
    public string $registeredBy;

    /** @var string What a client does on it, for the host's transport and its developers */
    public string $description;

    /** @var int The longest payload JSON of one message */
    public int $maxPayloadJsonChars = 4096;

    public static function create(string $name, string $registeredBy, string $description, int $maxPayloadJsonChars = 4096): static
    {
        $agentRealtimeMessageType = new static();
        $agentRealtimeMessageType->name = $name;
        $agentRealtimeMessageType->registeredBy = $registeredBy;
        $agentRealtimeMessageType->description = $description;
        $agentRealtimeMessageType->maxPayloadJsonChars = max(2, min(static::MAX_PAYLOAD_JSON_CHARS_CEILING, $maxPayloadJsonChars));
        return $agentRealtimeMessageType;
    }

    public static function isValidName(string $name): bool
    {
        return preg_match(static::NAME_PATTERN, $name) === 1;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->name);
    }
}
