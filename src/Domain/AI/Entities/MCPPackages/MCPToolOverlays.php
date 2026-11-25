<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpaceToolArgument;
use DDD\Domain\Base\Entities\ObjectSet;
use DDD\Infrastructure\Libs\Config;

/**
 * The tool overlays of the application (`AI.toolOverlays`, `config/app/AI/toolOverlays.php`, replaced whole): per tool
 * NAME its account rights and texts, read where the tool schema is built ({@see MCPTool}, {@see MCPSchema}), where the
 * entitlement is checked ({@see MCPToolEntitlement}) and where the run report names a write
 * ({@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentRunReport::ownerFacingTitleForWriteTool()}). A host changes a
 * package tool's words or right without redeclaring its method.
 *
 * Read once per process ({@see getApplicationOverlays()}); the cached tool documentation carries {@see getFingerprint()} in its key,
 * so a changed overlay reaches the documentation cache after a deploy without a manual clear.
 *
 * @property MCPToolOverlay[] $elements
 * @method MCPToolOverlay[] getElements()
 * @method MCPToolOverlay|null getByUniqueKey(string $uniqueKey)
 *
 * @phpstan-consistent-constructor
 */
class MCPToolOverlays extends ObjectSet
{
    /** @var string The config path of the overlays (tool name => overlay entry) */
    public const string CONFIG_PATH = 'AI.toolOverlays';

    /** @var self|null The overlays of this process (null = not read yet) */
    protected static ?self $currentToolOverlays = null;

    /** The overlays of this process, read once */
    public static function getApplicationOverlays(): static
    {
        if (!static::$currentToolOverlays instanceof static) {
            static::$currentToolOverlays = static::fromConfigEntries(Config::get(static::CONFIG_PATH));
        }
        return static::$currentToolOverlays;
    }

    /** Forgets the overlays read (a test, a changed config in a long process) */
    public static function resetCurrent(): void
    {
        static::$currentToolOverlays = null;
    }

    /** Replaces the overlays of this process (a test) */
    public static function replaceCurrent(?self $mcpToolOverlays): void
    {
        static::$currentToolOverlays = $mcpToolOverlays;
    }

    /** The overlays of a config value (tool name => entry); anything else is no overlay */
    public static function fromConfigEntries(mixed $configEntries): static
    {
        $mcpToolOverlays = new static();
        if (!is_array($configEntries)) {
            return $mcpToolOverlays;
        }
        foreach ($configEntries as $toolName => $overlayEntry) {
            if (!is_string($toolName) || trim($toolName) === '' || !is_array($overlayEntry)) {
                continue;
            }
            $mcpToolOverlay = MCPToolOverlay::fromConfigEntry(trim($toolName), $overlayEntry);
            $mcpToolOverlays->add($mcpToolOverlay);
        }
        return $mcpToolOverlays;
    }

    /** The overlay of `$toolName`, or null */
    public function findOverlayOfTool(string $toolName): ?MCPToolOverlay
    {
        return $this->getByUniqueKey(MCPToolOverlay::uniqueKeyStatic($toolName));
    }

    /**
     * The tool names the overlays name, in config order.
     *
     * @return string[]
     */
    public function getToolNames(): array
    {
        $toolNames = [];
        foreach ($this->getElements() as $mcpToolOverlay) {
            $toolNames[] = $mcpToolOverlay->toolName;
        }
        return $toolNames;
    }

    /**
     * The fingerprint of everything an application configures into the tool schemas — the overlays and the space
     * argument ({@see AgentSpaceToolArgument}); '' while both are the package's: the cache keys stay as they were.
     */
    public static function getSchemaConfigurationFingerprint(): string
    {
        $fingerprintParts = array_filter([static::getApplicationOverlays()->getFingerprint(), AgentSpaceToolArgument::current()->getFingerprint()], 'strlen');
        return implode('-', $fingerprintParts);
    }

    /** A short hash over the overlays: part of the documentation cache keys ('' without an overlay) */
    public function getFingerprint(): string
    {
        if ($this->count() === 0) {
            return '';
        }
        $overlayEntries = [];
        foreach ($this->getElements() as $mcpToolOverlay) {
            $overlayEntries[$mcpToolOverlay->toolName] = [
                $mcpToolOverlay->accountRightNames,
                $mcpToolOverlay->description,
                $mcpToolOverlay->summary,
                $mcpToolOverlay->parameterDescriptions,
                $mcpToolOverlay->ownerFacingSubject,
                $mcpToolOverlay->hiddenParameterNames,
                $mcpToolOverlay->examples,
            ];
        }
        ksort($overlayEntries);
        return substr(sha1((string)json_encode($overlayEntries)), 0, 12);
    }
}
