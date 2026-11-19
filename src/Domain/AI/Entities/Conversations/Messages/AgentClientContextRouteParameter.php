<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One parameter of the route the owner's client shows ({@see AgentClientContext::$routeParameters}): its name and its
 * value, both as the client's router names them.
 *
 * @phpstan-consistent-constructor
 */
class AgentClientContextRouteParameter extends ValueObject
{
    /** @var string The parameter's name, e.g. `ticketId` */
    public string $name = '';

    /** @var string The parameter's value as text, e.g. `42` */
    public string $value = '';

    public static function fromNameAndValue(string $name, string $value): static
    {
        $agentClientContextRouteParameter = new static();
        $agentClientContextRouteParameter->name = $name;
        $agentClientContextRouteParameter->value = $value;
        return $agentClientContextRouteParameter;
    }

    /** A route names each parameter once: the name is the key. */
    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->name);
    }

    /** `name=value`, as the rendered line and the size cap read it. */
    public function toAssignmentText(): string
    {
        return $this->name . '=' . $this->value;
    }
}
