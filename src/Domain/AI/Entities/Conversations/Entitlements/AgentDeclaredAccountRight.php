<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Entitlements;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One account right an installed package declares: in the `RIGHTS` list of its rights constant class
 * (`*AccountRights`, e.g. {@see AgentAccountRights::RIGHTS}) or on one of its MCP packages or tools
 * (`#[MCPRequiresAccountRights]`).
 *
 * @phpstan-consistent-constructor
 */
class AgentDeclaredAccountRight extends ValueObject
{
    /** @var string The right's name (`scheduleAgentTasks`) */
    public string $rightName = '';

    /** @var string[] The classes that declare it: rights constant classes and MCP package classes */
    public array $declaringClassNames = [];

    /** A declared right named `$rightName`, first seen on `$declaringClassName` */
    public static function forRightName(string $rightName, string $declaringClassName): static
    {
        $agentDeclaredAccountRight = new static();
        $agentDeclaredAccountRight->rightName = $rightName;
        $agentDeclaredAccountRight->declaringClassNames = [$declaringClassName];
        return $agentDeclaredAccountRight;
    }

    /** Adds one more declaring class (each once) */
    public function addDeclaringClassName(string $declaringClassName): void
    {
        if (!in_array($declaringClassName, $this->declaringClassNames, true)) {
            $this->declaringClassNames[] = $declaringClassName;
        }
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->rightName);
    }
}
