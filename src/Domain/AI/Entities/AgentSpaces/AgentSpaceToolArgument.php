<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\AgentSpaces;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Libs\Config;

/**
 * The name and description of the argument by which the package's tools name the space a call targets
 * (`config/app/AI/agentSpaceToolArgument.php`, `AI.agentSpaceToolArgument`). The request DTOs of the package's tools
 * declare the property `agentSpaceId`; an application whose model knows the target by another word ("workspaceId",
 * "projectId") names it here:
 * - the tool schema shows the argument under this name with this description (every input schema property named
 *   `agentSpaceId`, {@see \DDD\Domain\AI\Entities\MCPPackages\MCPSchema});
 * - the executor fills it from the conversation's space like `agentSpaceId` and hands the call's arguments, this one
 *   included, to {@see \DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService::findAgentSpaceOfToolCall()}: the default
 *   reads its value as a space id, an application whose value is an id of its own entity resolves it there.
 *
 * Read once per process ({@see current()}). The defaults keep the package's schema byte-identical.
 *
 * @phpstan-consistent-constructor
 */
class AgentSpaceToolArgument extends ValueObject
{
    /** @var string The config path */
    public const string CONFIG_PATH = 'AI.agentSpaceToolArgument';

    /** @var string The property of the package's request DTOs that names the space */
    public const string DTO_PROPERTY_NAME = 'agentSpaceId';

    /** @var string The argument's name on the wire */
    public string $name = self::DTO_PROPERTY_NAME;

    /** @var string|null The description the model reads; null = the DTO's own */
    public ?string $description = null;

    /** @var self|null The argument of this process (null = not read yet) */
    protected static ?self $currentAgentSpaceToolArgument = null;

    /** The argument of this process, read once */
    public static function current(): static
    {
        if (!static::$currentAgentSpaceToolArgument instanceof static) {
            static::$currentAgentSpaceToolArgument = static::fromConfigValue(Config::get(static::CONFIG_PATH));
        }
        return static::$currentAgentSpaceToolArgument;
    }

    /** Forgets the argument read (a test) */
    public static function resetCurrent(): void
    {
        static::$currentAgentSpaceToolArgument = null;
    }

    /** Replaces the argument of this process (a test) */
    public static function replaceCurrent(?self $agentSpaceToolArgument): void
    {
        static::$currentAgentSpaceToolArgument = $agentSpaceToolArgument;
    }

    /** The argument of a config value (`['name' => …, 'description' => …]`); anything else keeps the defaults */
    public static function fromConfigValue(mixed $configValue): static
    {
        $agentSpaceToolArgument = new static();
        if (!is_array($configValue)) {
            return $agentSpaceToolArgument;
        }
        $configuredName = is_string($configValue['name'] ?? null) ? trim($configValue['name']) : '';
        if ($configuredName !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $configuredName) === 1) {
            $agentSpaceToolArgument->name = $configuredName;
        }
        $configuredDescription = is_string($configValue['description'] ?? null) ? trim($configValue['description']) : '';
        $agentSpaceToolArgument->description = $configuredDescription !== '' ? $configuredDescription : null;
        return $agentSpaceToolArgument;
    }

    /** Whether the argument carries another name on the wire than the DTO property */
    public function isRenamed(): bool
    {
        return $this->name !== static::DTO_PROPERTY_NAME;
    }

    /** Whether the schema differs from the package's (a name or a description of the application) */
    public function changesSchema(): bool
    {
        return $this->isRenamed() || $this->description !== null;
    }

    /** A short hash for the documentation cache keys ('' while the schema stays the package's) */
    public function getFingerprint(): string
    {
        return $this->changesSchema() ? substr(sha1($this->name . "\n" . (string)$this->description), 0, 12) : '';
    }
}
