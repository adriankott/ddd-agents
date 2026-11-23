<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\Chat;

use DDD\Domain\AI\Services\Conversations\Agent\AIConversationTurnEndFollowUpsService;
use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNodes;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationModelCallsService;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Services\AccountsService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Symfony\Commands\Agents\AgentConsoleCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Whom a console chat talks as and where, and the rules of a conversation both console chats drive, shared by the line
 * chat and the full-screen chat: the account and the space of the run ({@see resolveChatScope()}), the text that names
 * the space options ({@see describeAgentSpaceOptions()}), the stored conversation `--conversationId` resumes or a new
 * one in the run's space ({@see findOrCreateConversation()}, {@see findStoredConversation()},
 * {@see createConversationInAgentSpace()}), what a resumed conversation loads before its next turn
 * ({@see loadStateOfResumedConversation()}) and one agent turn inside the turn envelope
 * ({@see generateAgentReplyInsideTurnEnvelope()}).
 *
 * For a command on {@see AgentConsoleCommand} that declares the account option ({@see OPTION_ACCOUNT_ID}) and the
 * space options ({@see AgentConsoleCommand::configureAgentSpaceOptions()}). An application that names the scope of a
 * chat in its own words overrides these methods once, in a trait of its own both of its chat commands use.
 */
trait AgentConsoleChatScopeTrait
{
    /** @var string The option that names the account of the run */
    public const string OPTION_ACCOUNT_ID = 'accountId';

    /** @var string The option that names the stored conversation the run resumes */
    public const string OPTION_CONVERSATION_ID = 'conversationId';

    /**
     * Whom the run talks as and where: the account of `--accountId`, else the owner of the space the options name
     * ({@see AgentConsoleCommand::resolveAgentSpaceFromInput()}); the space is where a new conversation is created.
     * Account and space are read with the entity rights lifted (no account is authenticated yet). An application that
     * names its scope by options of its own overrides this method.
     *
     * @param InputInterface $input
     * @return AgentConsoleChatScope
     * @throws Throwable
     */
    protected function resolveChatScope(InputInterface $input): AgentConsoleChatScope
    {
        $accountId = $this->readIdOption($input, self::OPTION_ACCOUNT_ID);
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $agentSpace = $this->resolveAgentSpaceFromInput($input);
            if ($accountId === null && $agentSpace === null) {
                return AgentConsoleChatScope::refused(
                    'Provide --' . self::OPTION_ACCOUNT_ID . ' or ' . $this->describeAgentSpaceOptions() . ' (the account is the owner of the space when --' . self::OPTION_ACCOUNT_ID . ' is omitted)',
                    Command::INVALID
                );
            }
            $account = $accountId !== null ? $this->findAccount($accountId) : $this->findOwnerAccountOfAgentSpace($agentSpace);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        if (!$account instanceof Account) {
            return AgentConsoleChatScope::refused(
                $accountId !== null ? "Account $accountId not found" : 'Could not resolve the owner account of space ' . ($agentSpace->id ?? '?'),
                Command::FAILURE
            );
        }
        return AgentConsoleChatScope::forAccountInAgentSpace($account, $agentSpace);
    }

    /**
     * The options that name the space of the run, as an error text names them.
     *
     * @return string
     */
    protected function describeAgentSpaceOptions(): string
    {
        $optionNames = ['--' . self::OPTION_AGENT_SPACE_ID];
        foreach ($this->agentSpaceScopeOptionNames as $scopeOptionName) {
            $optionNames[] = '--' . $scopeOptionName;
        }
        return implode(' or ', $optionNames);
    }

    /**
     * The stored conversation `$conversationId` when given and found ({@see findStoredConversation()}), with what its
     * next turn reads ({@see loadStateOfResumedConversation()}: the compaction tree and the bounded tail of messages the
     * worker loads too, never the whole history), else a new conversation ({@see createConversationInAgentSpace()}). An
     * ephemeral run never resumes; an id that names no stored conversation starts a new one.
     *
     * @param int|null $conversationId the id of `--conversationId` ({@see readIdOption()}); null = a new conversation
     * @param AgentConsoleChatScope $chatScope
     * @param bool $ephemeral
     * @param string|null $requestedSkillLoadingStrategy the strategy a new conversation is stamped with; null = the default
     * @return AIConversation
     * @throws Throwable
     */
    protected function findOrCreateConversation(?int $conversationId, AgentConsoleChatScope $chatScope, bool $ephemeral, ?string $requestedSkillLoadingStrategy): AIConversation
    {
        if ($conversationId !== null && !$ephemeral) {
            $storedConversation = $this->findStoredConversation($conversationId);
            if ($storedConversation instanceof AIConversation) {
                $this->loadStateOfResumedConversation($storedConversation);
                return $storedConversation;
            }
        }
        return $this->createConversationInAgentSpace($chatScope, $ephemeral, $requestedSkillLoadingStrategy);
    }

    /**
     * The stored conversation with this id, read through the entity registry under the rights of the run's account;
     * null when there is none.
     *
     * @param int $conversationId
     * @return AIConversation|null
     * @throws Throwable
     */
    protected function findStoredConversation(int $conversationId): ?AIConversation
    {
        $aIConversationsService = $this->getAIConversationsService();
        $storedConversation = $aIConversationsService->find($conversationId);
        return $storedConversation instanceof AIConversation ? $storedConversation : null;
    }

    /**
     * A new conversation of the scope's account in the space {@see findAgentSpaceForNewConversation()} names (none =
     * account level), stored unless ephemeral (an ephemeral conversation is driven like a stored one and gets the stored
     * space too). An application that places a conversation by a key of its own overrides this method and builds on
     * {@see buildNewConversation()} and {@see storeNewConversation()}.
     *
     * @param AgentConsoleChatScope $chatScope
     * @param bool $ephemeral
     * @param string|null $requestedSkillLoadingStrategy the strategy the conversation is stamped with; null = the default
     * @return AIConversation
     * @throws Throwable
     */
    protected function createConversationInAgentSpace(AgentConsoleChatScope $chatScope, bool $ephemeral, ?string $requestedSkillLoadingStrategy): AIConversation
    {
        $conversation = $this->buildNewConversation($chatScope, $requestedSkillLoadingStrategy);
        $agentSpace = $this->findAgentSpaceForNewConversation($chatScope);
        if ($agentSpace !== null) {
            $conversation->agentSpaceId = AgentSpace::getIdOfStoredAgentSpace($agentSpace);
            $conversation->agentSpace = $agentSpace;
        }
        $this->storeNewConversation($conversation, $ephemeral);
        return $conversation;
    }

    /**
     * The space a new conversation of the run is created in; null = account level. Asked after the run's account is
     * authenticated. The default: the scope's space.
     *
     * @param AgentConsoleChatScope $chatScope
     * @return AgentSpace|null
     * @throws Throwable
     */
    protected function findAgentSpaceForNewConversation(AgentConsoleChatScope $chatScope): ?AgentSpace
    {
        return $chatScope->agentSpace;
    }

    /**
     * A new conversation of the scope's account in memory: no messages, the context-retention settings stamped as on
     * every creation path.
     *
     * @param AgentConsoleChatScope $chatScope
     * @param string|null $requestedSkillLoadingStrategy
     * @return AIConversation
     */
    protected function buildNewConversation(AgentConsoleChatScope $chatScope, ?string $requestedSkillLoadingStrategy): AIConversation
    {
        $conversation = AIConversation::newInstance();
        $conversation->accountId = (int)$chatScope->account?->id;
        $conversation->messages = AIConversationMessages::newInstance();
        $conversation->contextRetentionSettings = ContextRetentionSettings::buildForNewConversation(
            null,
            $requestedSkillLoadingStrategy,
            agentWireApi: $this->resolveAgentWireApiOfNewConversation()
        );
        return $conversation;
    }

    /**
     * The wire API of the agentic default model, which a new console conversation runs on unless a model is pinned
     * later: a Chat Completions model is stamped with the system-prompt swap, never the append-only default it cannot
     * run. Null when the model catalog cannot answer (the run then stamps the strategy before its first call).
     *
     * @return string|null
     */
    protected function resolveAgentWireApiOfNewConversation(): ?string
    {
        try {
            /** @var AIConversationModelCallsService $aIConversationModelCallsService */
            $aIConversationModelCallsService = DDDService::instance()->getService(AIConversationModelCallsService::class);
            return $aIConversationModelCallsService->resolveAgentWireApi(null, null);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Loads the relations of the conversation's space, then stores the conversation unless ephemeral (the id lets the
     * loop store its messages and a later call resume it).
     *
     * @param AIConversation $conversation
     * @param bool $ephemeral
     * @return void
     * @throws Throwable
     */
    protected function storeNewConversation(AIConversation $conversation, bool $ephemeral): void
    {
        $agentSpacesService = $this->getAgentSpacesService();
        $agentSpacesService->attachAgentSpaceRelations($conversation, false);
        if (!$ephemeral) {
            $conversation->update();
        }
    }

    /**
     * Loads what the next turn of a resumed conversation reads: the whole node tree of the compaction (the console
     * compacts inline) and the bounded message tail above the cover edge the worker loads too — never the lazily
     * loaded `messages` relation, which holds the oldest rows only — then the relations of its space.
     *
     * @param AIConversation $conversation a stored conversation
     * @return void
     * @throws Throwable
     */
    protected function loadStateOfResumedConversation(AIConversation $conversation): void
    {
        $conversation->compactionNodes = $conversation->findCompactionNodesInOrder() ?? AIConversationCompactionNodes::newInstance();
        $aIConversationMessagesService = $this->getAIConversationMessagesService();
        $conversation->messages = $aIConversationMessagesService->findContextTailMessages($conversation);
        $agentSpacesService = $this->getAgentSpacesService();
        $agentSpacesService->attachAgentSpaceRelations($conversation, false);
    }

    /**
     * One agent turn inside the turn envelope of the conversation's account, the envelope closed whatever the turn
     * throws. `$output` streams every message as it is produced (null = nothing is printed); the run options give the
     * model and whether the compaction and the side work run inline, the render options how the messages are printed.
     *
     * @param AIConversation $conversation
     * @param OutputInterface|null $output
     * @param AgentConsoleChatRunOptions $runOptions
     * @param AgentConsoleChatRenderOptions $renderOptions
     * @return void
     * @throws Throwable what the turn throws
     */
    protected function generateAgentReplyInsideTurnEnvelope(
        AIConversation $conversation,
        ?OutputInterface $output,
        AgentConsoleChatRunOptions $runOptions,
        AgentConsoleChatRenderOptions $renderOptions
    ): void {
        $usageEnvelopeContext = $this->openUsageEnvelope($conversation->accountId ?? null, AgentAIUsageScope::ENVELOPE_AI_CONVERSATION_TURN);
        try {
            $aIConversationAgentService = $this->getAIConversationAgentService();
            $aIConversationAgentService->generateAgentReply(
                $conversation,
                $output,
                $renderOptions->full,
                $renderOptions->debug,
                $renderOptions->collapseThreads,
                $runOptions->aiModelName,
                $renderOptions->timings,
                compactionEnabled: $runOptions->compactionEnabled,
                sideWorkEnabled: $runOptions->sideWorkEnabled
            );
        } finally {
            $this->closeUsageEnvelope($usageEnvelopeContext);
        }
        // the turn end a worker's release runs: the host's settled-turn listeners (e.g. a workspace commit)
        /** @var AIConversationTurnEndFollowUpsService $aIConversationTurnEndFollowUpsService */
        $aIConversationTurnEndFollowUpsService = DDDService::instance()->getService(AIConversationTurnEndFollowUpsService::class);
        $aIConversationTurnEndFollowUpsService->runSettledTurnListenersAfterInlineTurn($conversation);
    }

    /**
     * The value of an id option as a number; null when the command has no such option, or it is not passed or blank.
     *
     * @param InputInterface $input
     * @param string $optionName
     * @return int|null
     */
    protected function readIdOption(InputInterface $input, string $optionName): ?int
    {
        if (!$input->hasOption($optionName)) {
            return null;
        }
        $optionValue = $input->getOption($optionName);
        return is_scalar($optionValue) && trim((string)$optionValue) !== '' ? (int)$optionValue : null;
    }

    /**
     * @param int $accountId
     * @return Account|null
     * @throws Throwable
     */
    protected function findAccount(int $accountId): ?Account
    {
        $accountsService = $this->getAccountsService();
        $account = $accountsService->find($accountId);
        return $account instanceof Account ? $account : null;
    }

    /** @return AccountsService */
    protected function getAccountsService(): AccountsService
    {
        /** @var AccountsService $accountsService */
        $accountsService = DDDService::instance()->getService(AccountsService::class);
        return $accountsService;
    }

    /** @return AIConversationAgentService the agent service (the application's class, when it replaces it) */
    protected function getAIConversationAgentService(): AIConversationAgentService
    {
        /** @var AIConversationAgentService $aIConversationAgentService */
        $aIConversationAgentService = DDDService::instance()->getService(AIConversationAgentService::class);
        return $aIConversationAgentService;
    }

    /** @return AIConversationsService the conversations service (the application's class, when it replaces it) */
    protected function getAIConversationsService(): AIConversationsService
    {
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
        return $aIConversationsService;
    }

    /** @return AIConversationMessagesService the messages service (the application's class, when it replaces it) */
    protected function getAIConversationMessagesService(): AIConversationMessagesService
    {
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        return $aIConversationMessagesService;
    }
}
