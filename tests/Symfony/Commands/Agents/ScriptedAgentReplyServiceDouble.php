<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkerJobReceipt;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The agent service of a chat test: a reply appends one agent message and records with which settings it was asked
 * for (or, when the test says so, fails after recording); the worker-path dispatch and the purposed start are
 * recorded, the purposed conversation records its writes. Calls no model and stores nothing.
 */
class ScriptedAgentReplyServiceDouble extends AIConversationAgentService
{
    public const string REPLY_TEXT = 'Here is the answer.';

    public const int PURPOSED_CONVERSATION_ID = 77;

    /** @var array<int, array<string, mixed>> per reply: the owner message it answered and the settings it was asked with */
    public array $replyRequests = [];

    /** @var int[] the ids of the conversations dispatched to the worker path */
    public array $dispatchedConversationIds = [];

    /** @var array<int, array<string, mixed>> per purposed start: the space id, the purpose and the model */
    public array $purposedStarts = [];

    /** @var AIConversation|null the conversation of the newest reply */
    public ?AIConversation $lastConversation = null;

    /** @var string|null the message a reply fails with after it was recorded; null = every reply succeeds */
    public ?string $replyFailureMessage = null;

    /** @var StoredConversationRecordingWritesDouble|null the conversation the newest purposed start answered */
    public ?StoredConversationRecordingWritesDouble $purposedConversation = null;

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
        $this->lastConversation = $conversation;
        $this->replyRequests[] = [
            'ownerMessage' => $conversation->messages?->last()?->message,
            'accountId' => $conversation->accountId ?? null,
            'aiModelName' => $aiModelName,
            'full' => $full,
            'debug' => $debug,
            'compactionEnabled' => $compactionEnabled,
            'sideWorkEnabled' => $sideWorkEnabled,
        ];
        if ($this->replyFailureMessage !== null) {
            throw new RuntimeException($this->replyFailureMessage);
        }
        $conversation->appendMessage(
            AIConversationMessage::newInstance(),
            AIConversationMessage::MESSAGE_TEXT,
            self::REPLY_TEXT,
            role: AIConversationMessage::ROLE_ASSISTANT,
            authorType: AIConversationMessage::AUTHOR_AGENT
        );
        return $conversation;
    }

    public function resumeConversationIfReady(AIConversation $conversation, bool $async = false, ?AgentWorkerJobReceipt $agentWorkerJobReceipt = null, ?float $ownerRowSavedWallUs = null): void
    {
        $this->dispatchedConversationIds[] = (int)($conversation->id ?? 0);
    }

    public function getOrStartPurposedConversation(
        string $scope,
        AgentSpace $agentSpace,
        string $purposeName,
        bool $async = true,
        ?string $aiModelName = null,
        ?string $kickoffSignalAppendix = null,
        ?RunSettings $runSettings = null
    ): AIConversation {
        $this->purposedStarts[] = ['agentSpaceId' => (int)$agentSpace->id, 'purposeName' => $purposeName, 'async' => $async, 'aiModelName' => $aiModelName];
        $purposedConversation = new StoredConversationRecordingWritesDouble();
        $purposedConversation->id = self::PURPOSED_CONVERSATION_ID;
        $purposedConversation->budgetBypass = false;
        $this->purposedConversation = $purposedConversation;
        return $purposedConversation;
    }
}
