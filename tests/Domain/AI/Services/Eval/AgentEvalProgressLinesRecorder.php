<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Interfaces\AIConversationProgressReporterInterface;

/** Keeps every progress line an evaluation hook reports, in order. */
class AgentEvalProgressLinesRecorder implements AIConversationProgressReporterInterface
{
    /** @var string[] The reported lines */
    public array $progressLines = [];

    public function reportProgress(string $progressLine): void
    {
        $this->progressLines[] = $progressLine;
    }
}
