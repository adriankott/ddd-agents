<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\ClientTools;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * An open client tool request as the client receives it with the run-state read: what to do, with which arguments,
 * and how many seconds the waiting tool still waits. The client executes it at most once and posts the answer.
 */
class AgentClientToolRequestDelivery extends ValueObject
{
    /** @var int The id of the request (the answer route takes it) */
    public int $id;

    /** @var string One of AgentClientToolRequest::TYPE_* */
    public string $requestType;

    /** @var string The arguments as one JSON object */
    public string $argumentsJson;

    /** @var int How many seconds the waiting tool still waits; a client does not start a request with less than 3 */
    public int $secondsLeft;

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->id ?? spl_object_id($this));
    }
}
