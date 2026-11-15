<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Mandates;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * What a mandate grant asks for ({@see \DDD\Domain\AI\Services\Mandates\AgentMandatesService::grantMandateFromDialogue()}):
 * the action class, the scope, the end of a time window, the customer's constraint and, for the platform's own check of
 * one call, the tool of that call. The dialogue the grant is
 * verified against is never part of it — the service reads it from the conversation itself.
 *
 * @phpstan-consistent-constructor
 */
class AgentMandateGrantArguments extends ValueObject
{
    /** One of {@see AgentMandate::getActionClasses()}. */
    public string $actionClass = '';

    /** One of {@see AgentMandate::SCOPE_TYPES} (the legacy ONE_SHOT is read as ONE_INSTRUCTION). */
    public string $scopeType = '';

    /** The end of a TIME_WINDOW mandate. */
    public ?DateTime $validUntil = null;

    /** The customer's constraint as the model passed it; the stored one is the approval check's. */
    public ?string $constraintsNote = null;

    /** The tool whose call the grant is checked for (a ONE_INSTRUCTION the platform checks itself); null = the class. */
    public ?string $requestedToolName = null;

    /** The summary line of {@see $requestedToolName} (its #[MCPToolSummary]); null when it has none. */
    public ?string $requestedToolSummary = null;

    public static function build(
        string $actionClass,
        string $scopeType,
        ?DateTime $validUntil,
        ?string $constraintsNote,
        ?string $requestedToolName = null,
        ?string $requestedToolSummary = null
    ): static {
        $agentMandateGrantArguments = new static();
        $agentMandateGrantArguments->actionClass = $actionClass;
        $agentMandateGrantArguments->scopeType = $scopeType;
        $agentMandateGrantArguments->validUntil = $validUntil;
        $agentMandateGrantArguments->constraintsNote = $constraintsNote;
        $agentMandateGrantArguments->requestedToolName = $requestedToolName;
        $agentMandateGrantArguments->requestedToolSummary = $requestedToolSummary;
        return $agentMandateGrantArguments;
    }
}
