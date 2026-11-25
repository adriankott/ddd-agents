<?php

declare(strict_types=1);

namespace DDD\Testing\Agents\Surface;

/**
 * One value in which two surface documents ({@see AgentSurfaceDocumentBuilder}) differ: its path in the document and
 * the value on each side. A plain object on purpose: the comparison needs no kernel.
 */
class AgentSurfaceDifference
{
    /** The value is in the compared document only */
    public const string KIND_ADDED = 'ADDED';

    /** The value is in the reference document only */
    public const string KIND_REMOVED = 'REMOVED';

    /** Both documents hold the path with different values */
    public const string KIND_CHANGED = 'CHANGED';

    /** A value longer than this (as JSON) is cut in a failure line */
    public const int MAXIMUM_DESCRIBED_VALUE_CHARACTERS = 160;

    /**
     * @param string $kind KIND_*
     * @param string $path The path of the value, its keys joined by ` → ` (`packages → X → tools → y → description`)
     * @param mixed $referenceValue The value in the reference document; null when the path is not in it
     * @param mixed $comparedValue The value in the compared document; null when the path is not in it
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $path,
        public readonly mixed $referenceValue,
        public readonly mixed $comparedValue,
    ) {
    }

    /**
     * @return string One line for a failure message
     */
    public function describe(): string
    {
        return match ($this->kind) {
            self::KIND_ADDED => sprintf('+ %s: %s', $this->path, static::describeValue($this->comparedValue)),
            self::KIND_REMOVED => sprintf('- %s: %s', $this->path, static::describeValue($this->referenceValue)),
            default => sprintf('~ %s: %s => %s', $this->path, static::describeValue($this->referenceValue), static::describeValue($this->comparedValue)),
        };
    }

    /**
     * @return string The value as compact JSON, cut to a line's length
     */
    public static function describeValue(mixed $value): string
    {
        $valueJson = (string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return mb_strlen($valueJson) > static::MAXIMUM_DESCRIBED_VALUE_CHARACTERS
            ? mb_substr($valueJson, 0, static::MAXIMUM_DESCRIBED_VALUE_CHARACTERS - 3) . '...'
            : $valueJson;
    }
}
