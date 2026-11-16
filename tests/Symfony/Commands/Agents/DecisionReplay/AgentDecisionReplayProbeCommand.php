<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\DecisionReplay;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Decisions\AgentDecisionModelCircuitBreakerService;
use DDD\Infrastructure\Services\AuthService;
use DDD\Symfony\Commands\Agents\AgentDecisionReplayCommand;
use DDD\Symfony\Commands\Agents\DecisionReplay\AgentDecisionReplayRow;
use DDD\Symfony\Commands\Agents\DecisionReplay\AgentDecisionReplayRows;
use DDD\Symfony\Commands\Agents\DecisionReplay\AgentDecisionReplayToolCall;
use DDD\Tests\Agents\Symfony\Commands\Agents\AgentSpacesServiceConsoleScopeDouble;
use DDD\Tests\Agents\Symfony\Commands\Agents\RecordingUsageEnvelopesServiceDouble;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A decision replay of an application in its smallest form: it replays the calls of one tool, "decides" by counting
 * the messages before the call, and answers no row for a call whose arguments say so. Its services are handed in.
 * Records what each replayed call saw.
 */
class AgentDecisionReplayProbeCommand extends AgentDecisionReplayCommand
{
    /** @var string The name of the probe; its own, so it never meets a real command */
    public const string PROBE_COMMAND_NAME = 'app:test:decision-replay-probe';

    /** @var string The tool whose calls are replayed */
    public const string REPLAYED_TOOL_NAME = 'removeThing';

    /** @var string[] What each replayed call saw, as `<row key>:envelope=<open|none>:account=<id>:harness=<on|off>:before=<n>` */
    public array $observedCalls = [];

    /** @var int How often the summary hook ran */
    public int $summaryPrintCount = 0;

    public function __construct(
        protected ConversationsServiceWithScriptedConversationsDouble $conversationsServiceDouble,
        protected ConversationMessagesServiceWithScriptedMessagesDouble $conversationMessagesServiceDouble,
        protected RecordingUsageEnvelopesServiceDouble $usageEnvelopesServiceDouble,
        protected AgentSpacesServiceConsoleScopeDouble $agentSpacesServiceDouble
    ) {
        parent::__construct(self::PROBE_COMMAND_NAME);
    }

    protected function isToolCallReplayed(AIConversationMessage $message): bool
    {
        return ($message->toolCall->name ?? '') === self::REPLAYED_TOOL_NAME;
    }

    protected function replayToolCall(AgentDecisionReplayToolCall $agentDecisionReplayToolCall, InputInterface $input, SymfonyStyle $symfonyStyle): ?AgentDecisionReplayRow
    {
        $this->observedCalls[] = sprintf(
            '%s:envelope=%s:account=%s:harness=%s:before=%d',
            $agentDecisionReplayToolCall->buildRowKey(),
            $this->usageEnvelopesServiceDouble->hasActiveEnvelope() ? 'open' : 'none',
            AuthService::instance()->getAccount()?->id ?? '-',
            AgentDecisionModelCircuitBreakerService::$harnessMode ? 'on' : 'off',
            $agentDecisionReplayToolCall->messagesBeforeCall->count()
        );
        if (($agentDecisionReplayToolCall->getToolCall()->arguments['skipRow'] ?? false) === true) {
            return null;
        }
        return AgentDecisionReplayRow::forKey($agentDecisionReplayToolCall->buildRowKey())
            ->setFieldValue('tool', $agentDecisionReplayToolCall->getToolName())
            ->setFieldValue('messagesBefore', $agentDecisionReplayToolCall->messagesBeforeCall->count())
            ->setFieldValue('consented', $agentDecisionReplayToolCall->messagesBeforeCall->count() > 1)
            ->setFieldValue('failure', null);
    }

    protected function printRowSummary(SymfonyStyle $symfonyStyle, AgentDecisionReplayRows $agentDecisionReplayRows): void
    {
        $this->summaryPrintCount++;
        $symfonyStyle->writeln('replayed: ' . $agentDecisionReplayRows->count());
    }

    protected function getConversationsService(): AIConversationsService
    {
        return $this->conversationsServiceDouble;
    }

    protected function getConversationMessagesService(): AIConversationMessagesService
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
}
