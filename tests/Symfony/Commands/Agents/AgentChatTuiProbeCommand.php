<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\Common\Services\AccountsService;
use DDD\Symfony\Commands\Agents\AgentChatTuiCommand;

/**
 * The full-screen chat with its services handed in and without a terminal: the library check answers what the test
 * sets, a turn runs through the command's own turn code against an agent service that records it instead of calling a
 * model (inside envelopes that are recorded, not stored), and the screen loop records the conversation it was given
 * instead of drawing.
 */
class AgentChatTuiProbeCommand extends AgentChatTuiCommand
{
    /** @var string The name of the probe; its own, so the real command's registration is never touched */
    public const string PROBE_COMMAND_NAME = 'agents-test:chat-tui-probe';

    /** @var ScriptedAgentReplyServiceDouble The agent service every turn of the command reaches */
    public ScriptedAgentReplyServiceDouble $agentReplyServiceDouble;

    /** @var RecordingUsageEnvelopesServiceDouble The envelope service the turn envelopes are opened and closed through */
    public RecordingUsageEnvelopesServiceDouble $usageEnvelopesServiceDouble;

    /** @var AIConversation|null The conversation the screen loop was started with; null = the loop never started */
    public ?AIConversation $screenLoopConversation = null;

    /** @var bool|null Whether the screen loop started with tool results opened */
    public ?bool $screenLoopExpandsToolResults = null;

    /** @var int[] The conversation ids the command looked up */
    public array $lookedUpConversationIds = [];

    /**
     * @param AgentSpacesService $agentSpacesServiceDouble
     * @param bool $terminalLibraryInstalled what the library check answers
     * @param AIConversationMessagesService|null $messagesServiceDouble the messages service a resumed conversation loads through
     * @param AIConversation|null $storedConversation the one stored conversation the lookup finds (by its id)
     * @param AccountsService|null $accountsServiceDouble the accounts service `--accountId` is read through
     */
    public function __construct(
        protected AgentSpacesService $agentSpacesServiceDouble,
        protected bool $terminalLibraryInstalled = true,
        protected ?AIConversationMessagesService $messagesServiceDouble = null,
        protected ?AIConversation $storedConversation = null,
        protected ?AccountsService $accountsServiceDouble = null
    ) {
        $this->agentReplyServiceDouble = new ScriptedAgentReplyServiceDouble();
        $this->usageEnvelopesServiceDouble = new RecordingUsageEnvelopesServiceDouble();
        parent::__construct(self::PROBE_COMMAND_NAME);
    }

    protected function isTerminalLibraryInstalled(): bool
    {
        return $this->terminalLibraryInstalled;
    }

    protected function getAgentSpacesService(): AgentSpacesService
    {
        return $this->agentSpacesServiceDouble;
    }

    protected function getAIConversationMessagesService(): AIConversationMessagesService
    {
        return $this->messagesServiceDouble ?? parent::getAIConversationMessagesService();
    }

    protected function getAccountsService(): AccountsService
    {
        return $this->accountsServiceDouble ?? parent::getAccountsService();
    }

    protected function findStoredConversation(int $conversationId): ?AIConversation
    {
        $this->lookedUpConversationIds[] = $conversationId;
        return $this->storedConversation !== null && (int)$this->storedConversation->id === $conversationId ? $this->storedConversation : null;
    }

    protected function getAIConversationAgentService(): AIConversationAgentService
    {
        return $this->agentReplyServiceDouble;
    }

    protected function getUsageEnvelopesService(): AIUsageEnvelopesService
    {
        return $this->usageEnvelopesServiceDouble;
    }

    /** @return string[] the owner messages of the turns the command ran, in order */
    public function findTurnMessageTexts(): array
    {
        return array_map('strval', array_column($this->agentReplyServiceDouble->replyRequests, 'ownerMessage'));
    }

    protected function runScreenLoop(AIConversation $conversation, ?string $modelName, bool $expandToolResults): void
    {
        $this->screenLoopConversation = $conversation;
        $this->screenLoopExpandsToolResults = $expandToolResults;
    }
}
