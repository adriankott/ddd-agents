<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Drives;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A source of a save that is refused before anything is written or asked: the argument the model passed and the
 * reason (the file is not readable for the caller, the application's flow rule keeps it out of the target).
 */
class AgentDriveSaveRefusal extends ValueObject
{
    /** @var string The source argument as the model passed it (`media/901`, `reports/Plan.md`) */
    public string $sourceArgument = '';

    /** @var string Why it is refused, a sentence for the model and the owner */
    public string $reason = '';

    public static function forSourceArgument(string $sourceArgument, string $reason): static
    {
        $agentDriveSaveRefusal = new static();
        $agentDriveSaveRefusal->sourceArgument = $sourceArgument;
        $agentDriveSaveRefusal->reason = $reason;
        return $agentDriveSaveRefusal;
    }

    /** The refused line of the tool's answer: `<argument>: <reason>` */
    public function buildRefusedLine(): string
    {
        return $this->sourceArgument . ': ' . $this->reason;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->sourceArgument);
    }
}
