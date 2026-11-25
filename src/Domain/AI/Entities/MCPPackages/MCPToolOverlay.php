<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What an application changes on ONE tool by its name, without redeclaring the method (`config/app/AI/toolOverlays.php`,
 * {@see MCPToolOverlays}): its account rights and its texts. Every field left null keeps the tool's own declaration.
 *
 *   'getTaskRuns' => [
 *       'accountRights' => [],                          // no right for this tool (the attribute's right is dropped)
 *       'description' => 'The runs of the tasks …',    // replaces the method docblock the model reads
 *       'summary' => 'The runs that executed …',       // replaces #[MCPToolSummary]
 *       'parameters' => ['filters' => 'Filter on …'], // replaces a parameter description, by parameter name
 *       'parameters' => ['agentSpaceId' => false],     // false hides the parameter: schema, sent value, gates
 *       'examples' => [['scheduleId' => 42]],          // replaces the @example lines of the input schema
 *       'ownerFacingSubject' => 'Task runs',           // replaces #[MCPToolOwnerFacingSubject]
 *   ],
 *
 * @phpstan-consistent-constructor
 */
class MCPToolOverlay extends ValueObject
{
    /** @var string The config key of the account rights */
    public const string KEY_ACCOUNT_RIGHTS = 'accountRights';

    /** @var string The config key of the description */
    public const string KEY_DESCRIPTION = 'description';

    /** @var string The config key of the summary */
    public const string KEY_SUMMARY = 'summary';

    /** @var string The config key of the parameter descriptions */
    public const string KEY_PARAMETERS = 'parameters';

    /** @var string The config key of the examples */
    public const string KEY_EXAMPLES = 'examples';

    /** @var string The config key of the owner-facing subject */
    public const string KEY_OWNER_FACING_SUBJECT = 'ownerFacingSubject';

    /** @var string[] Every key an overlay entry may carry */
    public const array KEYS = [self::KEY_ACCOUNT_RIGHTS, self::KEY_DESCRIPTION, self::KEY_SUMMARY, self::KEY_PARAMETERS, self::KEY_OWNER_FACING_SUBJECT, self::KEY_EXAMPLES];

    /** @var string The tool's name (the method name on the wire) */
    public string $toolName = '';

    /**
     * @var string[]|null The rights the tool requires INSTEAD of `#[MCPRequiresAccountRights]` on the method and its
     *                    package; [] = no right; null = the attributes
     */
    public ?array $accountRightNames = null;

    /** @var string|null The description the model reads instead of the method docblock */
    public ?string $description = null;

    /** @var string|null The routing line instead of `#[MCPToolSummary]` */
    public ?string $summary = null;

    /** @var string[] Parameter name => the description that replaces the property docblock */
    public array $parameterDescriptions = [];

    /**
     * @var string[] The parameters the application hides (`'parameters' => ['<name>' => false]`): left out of the
     *               input schema, a sent value is ignored. The space argument (by its DTO name or the configured name)
     *               then comes from the conversation the call runs in, for the gates too.
     */
    public array $hiddenParameterNames = [];

    /**
     * @var array<int, mixed>|null The examples of the input schema INSTEAD of the method's `@example` lines (each a JSON
     *                             object as an array, or a text); [] = none; null = the method's
     */
    public ?array $examples = null;

    /** @var string|null The run report's name of what the tool saves, instead of `#[MCPToolOwnerFacingSubject]` */
    public ?string $ownerFacingSubject = null;

    /** @var string[] The keys of the config entry this overlay does not know (a warning of the installation check) */
    public array $unknownKeys = [];

    /**
     * The overlay of `$toolName` from its config entry; unknown keys are kept for the warning, values of the wrong
     * type are ignored.
     *
     * @param array<array-key, mixed> $overlayEntry
     */
    public static function fromConfigEntry(string $toolName, array $overlayEntry): static
    {
        $mcpToolOverlay = new static();
        $mcpToolOverlay->toolName = $toolName;
        if (array_key_exists(self::KEY_ACCOUNT_RIGHTS, $overlayEntry) && is_array($overlayEntry[self::KEY_ACCOUNT_RIGHTS])) {
            $mcpToolOverlay->accountRightNames = array_values(array_unique(array_filter(
                array_map('strval', array_filter($overlayEntry[self::KEY_ACCOUNT_RIGHTS], 'is_string')),
                'strlen'
            )));
        }
        $mcpToolOverlay->description = self::readText($overlayEntry, self::KEY_DESCRIPTION);
        $mcpToolOverlay->summary = self::readText($overlayEntry, self::KEY_SUMMARY);
        $mcpToolOverlay->ownerFacingSubject = self::readText($overlayEntry, self::KEY_OWNER_FACING_SUBJECT);
        if (is_array($overlayEntry[self::KEY_PARAMETERS] ?? null)) {
            foreach ($overlayEntry[self::KEY_PARAMETERS] as $parameterName => $parameterDescription) {
                if (is_string($parameterName) && $parameterDescription === false) {
                    $mcpToolOverlay->hiddenParameterNames[] = $parameterName;
                } elseif (is_string($parameterName) && is_string($parameterDescription) && trim($parameterDescription) !== '') {
                    $mcpToolOverlay->parameterDescriptions[$parameterName] = trim($parameterDescription);
                }
            }
        }
        if (is_array($overlayEntry[self::KEY_EXAMPLES] ?? null)) {
            $mcpToolOverlay->examples = [];
            foreach ($overlayEntry[self::KEY_EXAMPLES] as $example) {
                if (is_array($example) || (is_string($example) && trim($example) !== '')) {
                    $mcpToolOverlay->examples[] = $example;
                }
            }
        }
        $mcpToolOverlay->unknownKeys = array_values(array_map('strval', array_diff(array_keys($overlayEntry), self::KEYS)));
        return $mcpToolOverlay;
    }

    /** @param array<array-key, mixed> $overlayEntry */
    protected static function readText(array $overlayEntry, string $key): ?string
    {
        $text = $overlayEntry[$key] ?? null;
        return is_string($text) && trim($text) !== '' ? trim($text) : null;
    }

    /** Whether the overlay replaces the tool's account rights */
    public function replacesAccountRights(): bool
    {
        return $this->accountRightNames !== null;
    }

    /** Whether the overlay hides parameter `$parameterName` (its name on the wire or its DTO property name) */
    public function hidesParameter(string $parameterName): bool
    {
        return in_array($parameterName, $this->hiddenParameterNames, true);
    }

    /** The description of parameter `$parameterName` the overlay sets, or null */
    public function findParameterDescription(string $parameterName): ?string
    {
        return $this->parameterDescriptions[$parameterName] ?? null;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->toolName);
    }
}
