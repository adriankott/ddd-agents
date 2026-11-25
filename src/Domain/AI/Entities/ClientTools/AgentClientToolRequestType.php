<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\ClientTools;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A type of client tool request (`Agent.UI`): what a client is asked to do. The package registers its own
 * ({@see AgentClientToolRequest::TYPE_READ_SCREEN} …); a host or another package registers more through
 * {@see \DDD\Domain\AI\Services\ClientTools\AgentClientToolRequestTypeProvider}, as it adds screen catalog entries. A
 * request of a type that is not registered, or registered but switched off, is never sent.
 */
class AgentClientToolRequestType extends ValueObject
{
    /** @var string A type name: capital letters, digits and `_` */
    public const string NAME_PATTERN = '/^[A-Z][A-Z0-9_]{1,47}$/';

    /** @var string The type, e.g. `READ_SCREEN` */
    public string $name;

    /** @var string What the client does on it, for the client's developers */
    public string $description;

    /** @var string The package that registered it */
    public string $registeredBy;

    /** @var bool Whether requests of the type are sent; a type that is off is answered "not switched on" */
    public bool $isEnabled = true;

    public static function create(string $name, string $description, string $registeredBy, bool $isEnabled = true): static
    {
        $agentClientToolRequestType = new static();
        $agentClientToolRequestType->name = $name;
        $agentClientToolRequestType->description = $description;
        $agentClientToolRequestType->registeredBy = $registeredBy;
        $agentClientToolRequestType->isEnabled = $isEnabled;
        return $agentClientToolRequestType;
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
