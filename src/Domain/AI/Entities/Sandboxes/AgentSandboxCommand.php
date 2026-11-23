<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * One command the agent ran in a workspace, with the tails of its output. Command and output are foreign text,
 * truncated by the host; secrets of the session's environment are masked by the host.
 */
class AgentSandboxCommand extends ValueObject
{
    /** @var int The command's sequence number in the session */
    public int $seq = 0;

    /** @var string The command (truncated) */
    public string $command = '';

    /** @var DateTime|null When it started */
    public ?DateTime $startedAt = null;

    /** @var int|null How long it ran, in milliseconds; null while it runs */
    public ?int $durationMs = null;

    /** @var int|null The exit code; null while it runs or when it was killed without one */
    public ?int $exitCode = null;

    /** @var string|null The end of its standard output */
    public ?string $stdoutTail = null;

    /** @var string|null The end of its error output */
    public ?string $stderrTail = null;

    /** @var bool The command or an output was cut */
    public bool $isTruncated = false;

    /** @var string|null The background job of the command */
    public ?string $jobId = null;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)$this->seq);
    }
}
