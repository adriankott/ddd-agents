<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\RunRelease;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkerJobReceipt;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentLoopStopReason;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * The REAL {@see AIConversationAgentService::resumeConversationIfReady()} with only two of its own steps replaced: the
 * drive ({@see generateAgentReply()}) records itself, appends a scripted list of rows, leaves a scripted stop reason on
 * the run and throws a scripted error; and the async re-fire is recorded instead of dispatched. Every branch decision,
 * every release, the stall give-up reply, the replay of a late signal and the follow-ups of an answered turn are the
 * package's own; what they reach in other services is the release stage's doubles.
 */
class RunReleaseAgentServiceDouble extends AIConversationAgentService
{
    public RunReleaseEventLog $eventLog;

    public RunReleaseMessagesServiceDouble $messagesServiceDouble;

    /** @var string[] the rows the drive appends ({@see RunReleaseScriptedRows} names), in order */
    public array $turnRows = [];

    /** Thrown by the drive after its rows are appended (null: the drive returns). */
    public ?Throwable $turnFailure = null;

    /** The stop reason the drive leaves on the run (null: none, as a drive that never reached a stop). */
    public ?AgentLoopStopReason $loopStopReason = null;

    public function __construct(RunReleaseEventLog $eventLog, RunReleaseMessagesServiceDouble $messagesServiceDouble)
    {
        // The parent service is container-managed and holds no constructor state.
        $this->eventLog = $eventLog;
        $this->messagesServiceDouble = $messagesServiceDouble;
    }

    public function generateAgentReply(
        AIConversation $conversation,
        ?OutputInterface $output = null,
        bool $full = false,
        bool $debug = false,
        bool $collapseThreads = false,
        ?string $aiModelName = null,
        bool $timings = false,
        bool $emitRealtimeSignal = false,
        bool $asyncDelegations = false,
        ?string $runToken = null,
        bool $compactionEnabled = true,
        bool $compactAsync = false,
        bool $sideWorkEnabled = true,
        ?AgentRun $agentRun = null
    ): AIConversation {
        $this->eventLog->record('drive');
        $this->messagesServiceDouble->driveRan = true;
        foreach ($this->turnRows as $scriptedRowName) {
            RunReleaseScriptedRows::append($conversation, $scriptedRowName);
        }
        if ($agentRun !== null) {
            $agentRun->lastLoopStopReason = $this->loopStopReason;
        }
        if ($this->turnFailure !== null) {
            throw $this->turnFailure;
        }
        return $conversation;
    }

    public function resumeConversationIfReady(AIConversation $conversation, bool $async = false, ?AgentWorkerJobReceipt $agentWorkerJobReceipt = null, ?float $ownerRowSavedWallUs = null): void
    {
        if ($async) {
            $this->eventLog->record('reFire async');
            return;
        }
        parent::resumeConversationIfReady($conversation, false, $agentWorkerJobReceipt, $ownerRowSavedWallUs);
    }
}
