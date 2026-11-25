<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\CallLife;

use DDD\Domain\AI\Entities\Conversations\TraceEvents\AIConversationTraceEvent;
use DDD\Domain\AI\Services\Conversations\TraceEvents\AIConversationTraceEventsService;
use RuntimeException;

/** The trace event writer of the package, recording each event instead of writing it; can be made to fail. */
class RecordingAIConversationTraceEventsService extends AIConversationTraceEventsService
{
    /** @var AIConversationTraceEvent[] */
    public array $recordedEvents = [];

    public ?string $failureMessage = null;

    public function recordEvents(array $traceEvents): int
    {
        if ($this->failureMessage !== null) {
            throw new RuntimeException($this->failureMessage);
        }
        foreach ($traceEvents as $traceEvent) {
            $this->recordedEvents[] = $traceEvent;
        }

        return count($traceEvents);
    }
}
