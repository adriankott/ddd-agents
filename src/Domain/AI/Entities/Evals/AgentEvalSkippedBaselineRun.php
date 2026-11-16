<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A former suite run the choice of the latest baseline skipped ({@see AgentEvalBaselineChoice}): its folder and the
 * planned cases it shares with this run, fewer than the choice needs.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalSkippedBaselineRun extends ValueObject
{
    /** @var string The folder of the former suite run */
    public string $directory = '';

    /** @var int The planned cases it shares with this run */
    public int $commonCases = 0;

    /** The skipped run of the folder `$directory`. */
    public static function forDirectory(string $directory, int $commonCases): static
    {
        $agentEvalSkippedBaselineRun = static::newInstance();
        $agentEvalSkippedBaselineRun->directory = $directory;
        $agentEvalSkippedBaselineRun->commonCases = $commonCases;
        return $agentEvalSkippedBaselineRun;
    }

    /** `<folder name> (<n> common)`, the form the choice's line names it in. */
    public function describe(): string
    {
        return basename($this->directory) . ' (' . $this->commonCases . ' common)';
    }

    /**
     * @return array{directory: string, commonCases: int}
     */
    public function toArray(): array
    {
        return ['directory' => $this->directory, 'commonCases' => $this->commonCases];
    }
}
