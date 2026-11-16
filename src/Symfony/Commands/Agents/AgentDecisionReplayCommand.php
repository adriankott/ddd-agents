<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Entities\UsageLogs\AIUsageEnvelopeContext;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Decisions\AgentDecisionModelCircuitBreakerService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Symfony\Commands\Agents\DecisionReplay\AgentDecisionReplayRow;
use DDD\Symfony\Commands\Agents\DecisionReplay\AgentDecisionReplayRows;
use DDD\Symfony\Commands\Agents\DecisionReplay\AgentDecisionReplayScope;
use DDD\Symfony\Commands\Agents\DecisionReplay\AgentDecisionReplayToolCall;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The base of a command that replays one decision on stored conversations: for every tool call the subclass picks
 * ({@see isToolCallReplayed()}), the decision is made again on the conversation as it stood before the call
 * ({@see replayToolCall()}), and the replayed decisions are printed as one table and written to the result file.
 *
 * What the base does: reads each conversation and its messages with the entity rights lifted, acts as the
 * conversation's account, hands the subclass the messages before each picked call and whom the decision is made for
 * (the account, the billing entity of the conversation's agent space, the space), opens an evaluation envelope around
 * each replayed decision, holds the decision model's circuit breaker in its harness mode for the run (a slow replay
 * never benches the decision model for the live loop) and writes the rows. Nothing is written to a conversation.
 *
 * What the subclass does: names the command (`#[AsCommand]`), picks the calls, rebuilds the decision's input of a
 * call and makes the decision ({@see replayToolCall()}, one row per call), and — when it has more to say — its table
 * columns ({@see getRowTableHeaders()} with the cells of its row class), the lines under the table
 * ({@see printRowSummary()}) and work beyond the stored conversations ({@see executeReplayWork()}, e.g. a labelled
 * corpus; it opens the evaluation envelope of such a call with {@see openEvaluationEnvelope()}).
 */
abstract class AgentDecisionReplayCommand extends AgentConsoleCommand
{
    /** @var string The option that names the conversations to replay */
    public const string OPTION_CONVERSATIONS = 'conversations';

    /** @var string The option that names the result file */
    public const string OPTION_RESULT_FILE = 'resultFile';

    /** @var string The option that prints the input of every decision */
    public const string OPTION_SHOW_STATE = 'showState';

    /** @var bool The harness mode of the circuit breaker before this run */
    protected bool $circuitBreakerHarnessModeBeforeRun = false;

    protected function configure(): void
    {
        $this->configureReplayOptions();
    }

    /**
     * Declares the options of the replay: `--conversations`, the space options ({@see configureAgentSpaceOptions()}),
     * `--resultFile`, `--showState` and `--ignore-budget`. A subclass that adds options of its own declares them before
     * or after calling it.
     *
     * @return void
     */
    protected function configureReplayOptions(): void
    {
        $this->addOption(self::OPTION_CONVERSATIONS, null, InputOption::VALUE_REQUIRED, 'Comma-separated conversation ids to replay');
        $this->configureAgentSpaceOptions();
        $this->addOption(self::OPTION_RESULT_FILE, null, InputOption::VALUE_REQUIRED, 'Write every row as JSON here');
        $this->addOption(self::OPTION_SHOW_STATE, null, InputOption::VALUE_NONE, 'Print the input sent per call');
        $this->configureIgnoreBudgetOption();
    }

    /**
     * Runs {@see executeReplayWork()} with the circuit breaker in its harness mode; the mode of before the run is set
     * again when the run ends.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws Throwable
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '2048M');
        set_time_limit(3600);
        $symfonyStyle = new SymfonyStyle($input, $output);
        $this->circuitBreakerHarnessModeBeforeRun = AgentDecisionModelCircuitBreakerService::$harnessMode;
        AgentDecisionModelCircuitBreakerService::$harnessMode = true;
        try {
            return $this->executeReplayWork($input, $symfonyStyle);
        } finally {
            AgentDecisionModelCircuitBreakerService::$harnessMode = $this->circuitBreakerHarnessModeBeforeRun;
        }
    }

    /**
     * The work of the run. The default replays the conversations of `--conversations`; without one it prints
     * {@see getMissingConversationsMessage()} and fails. A subclass with work of its own (a labelled corpus) overrides
     * it and calls the parent for the stored conversations.
     *
     * @param InputInterface $input
     * @param SymfonyStyle $symfonyStyle
     * @return int
     * @throws Throwable
     */
    protected function executeReplayWork(InputInterface $input, SymfonyStyle $symfonyStyle): int
    {
        $conversationIds = $this->findConversationIdsOfInput($input);
        if ($conversationIds === []) {
            $symfonyStyle->error($this->getMissingConversationsMessage());
            return Command::FAILURE;
        }
        return $this->replayConversations($input, $symfonyStyle, $conversationIds);
    }

    /**
     * The error when the run names no conversation. The default: `Pass --conversations.`
     *
     * @return string
     */
    protected function getMissingConversationsMessage(): string
    {
        return 'Pass --' . self::OPTION_CONVERSATIONS . '.';
    }

    /**
     * The positive conversation ids of `--conversations`, in the order given.
     *
     * @param InputInterface $input
     * @return int[]
     */
    protected function findConversationIdsOfInput(InputInterface $input): array
    {
        return array_values(array_filter(array_map('intval', explode(',', (string)$input->getOption(self::OPTION_CONVERSATIONS)))));
    }

    /**
     * Replays the picked tool calls of every conversation in `$conversationIds`, then prints and writes the rows. A
     * conversation that is not found or has no account is skipped with a warning.
     *
     * @param InputInterface $input
     * @param SymfonyStyle $symfonyStyle
     * @param int[] $conversationIds
     * @return int `SUCCESS`
     * @throws Throwable
     */
    protected function replayConversations(InputInterface $input, SymfonyStyle $symfonyStyle, array $conversationIds): int
    {
        $agentDecisionReplayRows = AgentDecisionReplayRows::newInstance();
        foreach ($conversationIds as $conversationId) {
            $this->replayConversation($conversationId, $input, $symfonyStyle, $agentDecisionReplayRows);
        }
        $this->printReport($symfonyStyle, $agentDecisionReplayRows, (string)($input->getOption(self::OPTION_RESULT_FILE) ?? ''));
        return Command::SUCCESS;
    }

    /**
     * Replays the picked tool calls of one stored conversation, each on the messages before it, as the conversation's
     * account, and adds a row per replayed decision.
     *
     * @param int $conversationId
     * @param InputInterface $input
     * @param SymfonyStyle $symfonyStyle
     * @param AgentDecisionReplayRows $agentDecisionReplayRows
     * @return void
     * @throws Throwable
     */
    protected function replayConversation(int $conversationId, InputInterface $input, SymfonyStyle $symfonyStyle, AgentDecisionReplayRows $agentDecisionReplayRows): void
    {
        $storedConversation = null;
        $allMessages = null;
        $agentDecisionReplayScope = null;
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $storedConversation = $this->findStoredConversation($conversationId);
            if ($storedConversation instanceof AIConversation) {
                $allMessages = $this->findMessagesOfConversation($storedConversation);
                $agentDecisionReplayScope = $this->findReplayScopeOfConversation($storedConversation);
            }
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        if (!$storedConversation instanceof AIConversation || $allMessages === null || $agentDecisionReplayScope === null) {
            $symfonyStyle->warning("conversation $conversationId: not found or no account");
            return;
        }
        $this->actAsAccount($agentDecisionReplayScope->ownerAccount);
        foreach ($allMessages->getElements() as $message) {
            if (($message->toolCall ?? null) === null || !$this->isToolCallReplayed($message)) {
                continue;
            }
            $agentDecisionReplayToolCall = new AgentDecisionReplayToolCall($storedConversation, $message, $this->buildMessagesBeforeCall($allMessages, $message), $agentDecisionReplayScope);
            $agentDecisionReplayRow = $this->replayToolCallInsideEvaluationEnvelope($agentDecisionReplayToolCall, $input, $symfonyStyle);
            if ($agentDecisionReplayRow !== null) {
                $agentDecisionReplayRows->add($agentDecisionReplayRow);
            }
        }
    }

    /**
     * The stored conversation `$conversationId`, read past the entity registry; asked with the rights lifted.
     *
     * @param int $conversationId
     * @return AIConversation|null
     * @throws Throwable
     */
    protected function findStoredConversation(int $conversationId): ?AIConversation
    {
        $aIConversationsService = $this->getConversationsService();
        $storedConversation = $aIConversationsService->find($conversationId, false);
        return $storedConversation instanceof AIConversation ? $storedConversation : null;
    }

    /**
     * Every message of `$conversation` in order, read past the entity registry (the whole history: the replay needs
     * the messages before every call); asked with the rights lifted.
     *
     * @param AIConversation $conversation
     * @return AIConversationMessages|null
     * @throws Throwable
     */
    protected function findMessagesOfConversation(AIConversation $conversation): ?AIConversationMessages
    {
        $aIConversationMessagesService = $this->getConversationMessagesService();
        return $aIConversationMessagesService->findAllMessagesForConversationInOrder($conversation, useEntityRegistrCache: false);
    }

    /**
     * Whom the replayed decisions of `$conversation` are made for: its account, the billing entity of its agent space
     * and the space; asked with the rights lifted. Null when the conversation has no account.
     *
     * @param AIConversation $conversation
     * @return AgentDecisionReplayScope|null
     * @throws Throwable
     */
    protected function findReplayScopeOfConversation(AIConversation $conversation): ?AgentDecisionReplayScope
    {
        $ownerAccount = $conversation->account ?? null;
        if (!$ownerAccount instanceof Account) {
            return null;
        }
        $agentSpace = $conversation->agentSpace ?? null;
        $agentSpace = $agentSpace instanceof AgentSpace ? $agentSpace : null;
        return new AgentDecisionReplayScope($ownerAccount, $agentSpace?->findBillingEntity(), $agentSpace);
    }

    /**
     * The messages of `$allMessages` whose index is lower than the index of `$callMessage`, in order.
     *
     * @param AIConversationMessages $allMessages
     * @param AIConversationMessage $callMessage
     * @return AIConversationMessages
     */
    protected function buildMessagesBeforeCall(AIConversationMessages $allMessages, AIConversationMessage $callMessage): AIConversationMessages
    {
        $callIndex = (int)($callMessage->indexInConversation ?? -1);
        $messagesBeforeCall = AIConversationMessages::newInstance();
        foreach ($allMessages->getElements() as $earlierMessage) {
            if ((int)($earlierMessage->indexInConversation ?? -1) < $callIndex) {
                $messagesBeforeCall->add($earlierMessage);
            }
        }
        return $messagesBeforeCall;
    }

    /**
     * Whether the decision of the tool call that `$message` carries is replayed (asked only for a message with a tool
     * call).
     *
     * @param AIConversationMessage $message
     * @return bool
     */
    abstract protected function isToolCallReplayed(AIConversationMessage $message): bool;

    /**
     * Makes the decision of one stored tool call again, on the messages before the call, and returns its row; null =
     * no row for this call. Runs inside the evaluation envelope of the call, as the conversation's account.
     *
     * @param AgentDecisionReplayToolCall $agentDecisionReplayToolCall
     * @param InputInterface $input
     * @param SymfonyStyle $symfonyStyle
     * @return AgentDecisionReplayRow|null
     */
    abstract protected function replayToolCall(AgentDecisionReplayToolCall $agentDecisionReplayToolCall, InputInterface $input, SymfonyStyle $symfonyStyle): ?AgentDecisionReplayRow;

    /**
     * {@see replayToolCall()} inside an evaluation envelope opened for the call's account and closed in a `finally`.
     *
     * @param AgentDecisionReplayToolCall $agentDecisionReplayToolCall
     * @param InputInterface $input
     * @param SymfonyStyle $symfonyStyle
     * @return AgentDecisionReplayRow|null
     * @throws InternalErrorException when the envelope cannot be opened or closed
     */
    protected function replayToolCallInsideEvaluationEnvelope(AgentDecisionReplayToolCall $agentDecisionReplayToolCall, InputInterface $input, SymfonyStyle $symfonyStyle): ?AgentDecisionReplayRow
    {
        $usageEnvelopeContext = $this->openEvaluationEnvelope($agentDecisionReplayToolCall->agentDecisionReplayScope);
        try {
            return $this->replayToolCall($agentDecisionReplayToolCall, $input, $symfonyStyle);
        } finally {
            $this->closeUsageEnvelope($usageEnvelopeContext);
        }
    }

    /**
     * Opens the evaluation envelope a replayed decision is booked under, for the scope's account; close it with
     * {@see closeUsageEnvelope()} in a `finally`.
     *
     * @param AgentDecisionReplayScope $agentDecisionReplayScope
     * @return AIUsageEnvelopeContext
     * @throws InternalErrorException
     */
    protected function openEvaluationEnvelope(AgentDecisionReplayScope $agentDecisionReplayScope): AIUsageEnvelopeContext
    {
        return $this->openUsageEnvelope($agentDecisionReplayScope->getOwnerAccountId(), AgentAIUsageScope::ENVELOPE_AI_AGENT_EVALUATION);
    }

    /**
     * Whom a decision outside a stored conversation is made for (e.g. a labelled corpus): the space the space options
     * name, its owner and its billing entity, read with the rights lifted; null when no option names a space or the
     * space has no owner. The run acts as the owner from then on.
     *
     * @param InputInterface $input
     * @return AgentDecisionReplayScope|null
     * @throws Throwable
     */
    protected function resolveBillingScope(InputInterface $input): ?AgentDecisionReplayScope
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $agentSpace = $this->resolveAgentSpaceFromInput($input);
            $ownerAccount = $agentSpace !== null ? $this->findOwnerAccountOfAgentSpace($agentSpace) : null;
            $agentDecisionReplayScope = $agentSpace !== null && $ownerAccount instanceof Account
                ? new AgentDecisionReplayScope($ownerAccount, $agentSpace->findBillingEntity(), $agentSpace)
                : null;
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        if ($agentDecisionReplayScope !== null) {
            $this->actAsAccount($agentDecisionReplayScope->ownerAccount);
        }
        return $agentDecisionReplayScope;
    }

    /**
     * Prints the table of the rows, then {@see printRowSummary()}, then writes the result file when one was named.
     *
     * @param SymfonyStyle $symfonyStyle
     * @param AgentDecisionReplayRows $agentDecisionReplayRows
     * @param string $resultFile empty = none
     * @return void
     */
    protected function printReport(SymfonyStyle $symfonyStyle, AgentDecisionReplayRows $agentDecisionReplayRows, string $resultFile): void
    {
        $agentDecisionReplayRows->renderTable($symfonyStyle, $this->getRowTableHeaders($agentDecisionReplayRows));
        $this->printRowSummary($symfonyStyle, $agentDecisionReplayRows);
        if ($resultFile !== '') {
            file_put_contents($resultFile, $agentDecisionReplayRows->buildResultFileJson());
            $symfonyStyle->writeln("rows written to $resultFile");
        }
    }

    /**
     * The headers of the row table. The default: the field names of the first row (the default cells of
     * {@see AgentDecisionReplayRow::buildTableCells()}); a subclass whose row class has columns of its own returns
     * their headers.
     *
     * @param AgentDecisionReplayRows $agentDecisionReplayRows
     * @return string[]
     */
    protected function getRowTableHeaders(AgentDecisionReplayRows $agentDecisionReplayRows): array
    {
        return $agentDecisionReplayRows->buildFieldNameHeaders();
    }

    /**
     * Override point: the lines printed under the row table (rates, a gate). The default: none.
     *
     * @param SymfonyStyle $symfonyStyle
     * @param AgentDecisionReplayRows $agentDecisionReplayRows
     * @return void
     */
    protected function printRowSummary(SymfonyStyle $symfonyStyle, AgentDecisionReplayRows $agentDecisionReplayRows): void
    {
    }

    /**
     * The conversations service (the application's class, when it replaces it).
     *
     * @return AIConversationsService
     */
    protected function getConversationsService(): AIConversationsService
    {
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
        return $aIConversationsService;
    }

    /**
     * The conversation messages service (the application's class, when it replaces it).
     *
     * @return AIConversationMessagesService
     */
    protected function getConversationMessagesService(): AIConversationMessagesService
    {
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        return $aIConversationMessagesService;
    }
}
