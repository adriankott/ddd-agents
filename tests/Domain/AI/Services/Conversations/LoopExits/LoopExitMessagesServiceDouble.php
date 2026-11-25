<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\LoopExits;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Presentation\Mcp\Agents\AgentConsoleStream;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\RunRelease\RunReleaseMessagesServiceDouble;

/**
 * The messages service of the loop-exit stage: every row persisted under the run is recorded with its type, signal
 * source and the bytes of its text, in order, and never written.
 */
class LoopExitMessagesServiceDouble extends RunReleaseMessagesServiceDouble
{
    /** @var array<int, array{0: string|null, 1: string|null, 2: string}> type, signal source, text */
    public array $persistedRows = [];

    public function persistMessageUnderRun(
        AgentRun $agentRun,
        AIConversationMessage $message,
        ?AgentConsoleStream $consoleStream = null,
        bool $emitRealtimeSignal = false
    ): void {
        parent::persistMessageUnderRun($agentRun, $message, $consoleStream, $emitRealtimeSignal);
        $this->persistedRows[] = [$message->messageType ?? null, $message->signalSource ?? null, (string)($message->message ?? '')];
    }
}
