<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\RunRelease;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessageWriteScope;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Presentation\Mcp\Agents\AgentConsoleStream;
use Throwable;

/**
 * The messages service of the release stage. The tail load answers the conversation's own set: the first load can
 * throw (the pre-drive failure), and the first load after the drive adds the rows another writer persisted while the
 * turn ran. A row persisted under the run is recorded (type and signal source on the log, its text in
 * {@see $persistedMessageTexts}), never written. A claim-fenced write runs unfenced: no transaction, no lock.
 */
class RunReleaseMessagesServiceDouble extends AIConversationMessagesService
{
    public RunReleaseEventLog $eventLog;

    /** Thrown by the first tail load (the throw before the drive); every later load succeeds. */
    public ?Throwable $firstTailLoadFailure = null;

    /** Whether the drive ran: the next tail load adds {@see $rowsPersistedByOtherWritersDuringTheTurn}. */
    public bool $driveRan = false;

    /** @var string[] rows another writer persisted during the turn ({@see RunReleaseScriptedRows} names) */
    public array $rowsPersistedByOtherWritersDuringTheTurn = [];

    /** @var string[] the texts of the rows persisted under the run, in order */
    public array $persistedMessageTexts = [];

    protected bool $firstTailLoadFailureThrown = false;

    public function __construct(RunReleaseEventLog $eventLog)
    {
        // The parent service is container-managed and holds no constructor state.
        $this->eventLog = $eventLog;
    }

    public function findContextTailMessages(AIConversation $conversation): AIConversationMessages
    {
        if ($this->firstTailLoadFailure !== null && !$this->firstTailLoadFailureThrown) {
            $this->firstTailLoadFailureThrown = true;
            throw $this->firstTailLoadFailure;
        }
        if ($this->driveRan && $this->rowsPersistedByOtherWritersDuringTheTurn !== []) {
            foreach ($this->rowsPersistedByOtherWritersDuringTheTurn as $scriptedRowName) {
                RunReleaseScriptedRows::append($conversation, $scriptedRowName);
            }
            $this->rowsPersistedByOtherWritersDuringTheTurn = [];
        }
        return $conversation->messages;
    }

    public function persistMessageUnderRun(
        AgentRun $agentRun,
        AIConversationMessage $message,
        ?AgentConsoleStream $consoleStream = null,
        bool $emitRealtimeSignal = false
    ): void {
        $this->eventLog->record('persist ' . $message->messageType . (($message->signalSource ?? null) !== null ? ' ' . $message->signalSource : ''));
        $this->persistedMessageTexts[] = (string)$message->message;
    }

    public function openClaimFencedWrite(AIConversation $conversation, ?string $runToken): AIConversationMessageWriteScope
    {
        return AIConversationMessageWriteScope::unfenced();
    }
}
