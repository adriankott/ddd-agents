<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationSpawnService;
use DDD\Domain\AI\Services\Conversations\AgentMessagePrinterInterface;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoriesService;
use DDD\Domain\Common\Services\AccountsService;
use DDD\Symfony\Commands\Agents\AgentChatCommand;
use DDD\Symfony\Commands\Agents\Chat\AgentConsoleChatRecordedRun;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The chat command with every service handed in through the command's own accessors, a stored conversation that gets
 * an id instead of a row, one stored conversation `--conversationId` can resume, and a recorded run the test scripts.
 * Reads and writes no database.
 */
class AgentChatProbeCommand extends AgentChatCommand
{
    /** @var string The name of the probe */
    public const string PROBE_COMMAND_NAME = 'app:test:chat-probe';

    /** @var int The id a conversation gets when the command stores it */
    public const int STORED_CONVERSATION_ID = 501;

    /** @var AgentConsoleChatRecordedRun|null What {@see startRecordedRunSession()} returns */
    public ?AgentConsoleChatRecordedRun $scriptedRecordedRun = null;

    /** @var int The exit code {@see finishRecordedRunSession()} returns */
    public int $finishExitCode = Command::SUCCESS;

    /** @var AgentConsoleChatRecordedRun[] The recorded runs {@see finishRecordedRunSession()} was called with */
    public array $finishedRecordedRuns = [];

    /** @var AIConversation[] The conversations stored, in order */
    public array $storedConversations = [];

    /** @var int[] The conversation ids read again as stored, past the entity registry */
    public array $storedConversationReads = [];

    /** @var AIConversation|null The one stored conversation the resume of `--conversationId` finds (by its id) */
    public ?AIConversation $resumableConversation = null;

    /** @var int[] The conversation ids the resume looked up */
    public array $resumeLookupIds = [];

    public function __construct(
        public ScriptedAgentReplyServiceDouble $agentReplyServiceDouble,
        public RecordingAgentMessagePrinterDouble $agentMessagePrinterDouble,
        public RecordingAgentMemoriesServiceDouble $agentMemoriesServiceDouble,
        public RecordingConversationMessagesServiceDouble $conversationMessagesServiceDouble,
        public RecordingUsageEnvelopesServiceDouble $usageEnvelopesServiceDouble,
        public AgentSpacesServiceConsoleScopeDouble $agentSpacesServiceDouble,
        public AccountsServiceWithOneAccountDouble $accountsServiceDouble,
        public DelegationSpawnServiceRecordingContinuationsDouble $delegationSpawnServiceDouble
    ) {
        parent::__construct(self::PROBE_COMMAND_NAME);
    }

    protected function storeNewConversation(AIConversation $conversation, bool $ephemeral): void
    {
        $agentSpacesService = $this->getAgentSpacesService();
        $agentSpacesService->attachAgentSpaceRelations($conversation, false);
        if (!$ephemeral) {
            $conversation->id = self::STORED_CONVERSATION_ID;
            $this->storedConversations[] = $conversation;
        }
    }

    protected function findStoredConversation(int $conversationId): ?AIConversation
    {
        $this->resumeLookupIds[] = $conversationId;
        return $this->resumableConversation !== null && (int)$this->resumableConversation->id === $conversationId ? $this->resumableConversation : null;
    }

    protected function findConversationAsStored(int $conversationId): ?AIConversation
    {
        $this->storedConversationReads[] = $conversationId;
        return null;
    }

    protected function startRecordedRunSession(InputInterface $input, AIConversation $conversation, OutputInterface $output): ?AgentConsoleChatRecordedRun
    {
        return $this->scriptedRecordedRun;
    }

    protected function finishRecordedRunSession(AgentConsoleChatRecordedRun $recordedRun, AIConversation $conversation, OutputInterface $output): int
    {
        $this->finishedRecordedRuns[] = $recordedRun;
        return $this->finishExitCode;
    }

    protected function getAIConversationAgentService(): AIConversationAgentService
    {
        return $this->agentReplyServiceDouble;
    }

    protected function getAgentMessagePrinter(): AgentMessagePrinterInterface
    {
        return $this->agentMessagePrinterDouble;
    }

    protected function getAgentMemoriesService(): AgentMemoriesService
    {
        return $this->agentMemoriesServiceDouble;
    }

    protected function getAIConversationMessagesService(): AIConversationMessagesService
    {
        return $this->conversationMessagesServiceDouble;
    }

    protected function getUsageEnvelopesService(): AIUsageEnvelopesService
    {
        return $this->usageEnvelopesServiceDouble;
    }

    protected function getAgentSpacesService(): AgentSpacesService
    {
        return $this->agentSpacesServiceDouble;
    }

    protected function getAccountsService(): AccountsService
    {
        return $this->accountsServiceDouble;
    }

    protected function getAIConversationDelegationSpawnService(): AIConversationDelegationSpawnService
    {
        return $this->delegationSpawnServiceDouble;
    }
}
