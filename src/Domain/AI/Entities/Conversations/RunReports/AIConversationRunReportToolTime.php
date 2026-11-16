<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\RunReports;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The time ONE tool took over the tool calls of a run: the seconds summed over its calls, how many calls, the longest
 * call. A row of the slowest tools of {@see AIConversationRunReport}.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationRunReportToolTime extends ValueObject
{
    /** @var string The tool (the call row's tool name) */
    public string $toolName = '';

    /** @var float The execution seconds summed over the tool's calls */
    public float $seconds = 0.0;

    /** @var int The tool's calls */
    public int $calls = 0;

    /** @var float The longest single call, in seconds */
    public float $maxSeconds = 0.0;

    /**
     * The time of a tool before its first call is counted.
     *
     * @param string $toolName
     * @return static
     */
    public static function forTool(string $toolName): static
    {
        $toolTime = static::newInstance();
        $toolTime->toolName = $toolName;
        return $toolTime;
    }

    /** The key of the time of a tool in its set. */
    public static function uniqueKeyForToolName(string $toolName): string
    {
        return self::class . '_' . $toolName;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyForToolName($this->toolName);
    }

    /**
     * Counts one call of the tool.
     *
     * @param float $durationSeconds the call's execution seconds
     * @return void
     */
    public function addCall(float $durationSeconds): void
    {
        $this->seconds += $durationSeconds;
        $this->calls++;
        $this->maxSeconds = max($this->maxSeconds, $durationSeconds);
    }
}
