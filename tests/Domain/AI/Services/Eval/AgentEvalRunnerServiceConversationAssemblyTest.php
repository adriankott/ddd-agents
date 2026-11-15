<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\OwnerDevice;
use DDD\Domain\AI\Entities\Evals\AgentEvalArms;
use DDD\Domain\AI\Entities\Evals\AgentEvalCase;
use DDD\Domain\AI\Entities\Evals\AgentEvalJudgedRunOptions;
use DDD\Domain\AI\Entities\Evals\AgentEvalRunOptions;
use DDD\Domain\AI\Entities\Evals\AgentEvalScope;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Eval\AgentEvalRunHooksService;
use DDD\Domain\AI\Services\Eval\AgentEvalRunnerService;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use ReflectionClass;

/**
 * How the runner assembles an evaluated conversation and writes the owner's messages, without a model call: every
 * conversation starts the same way (recorded for the teardown, attached to the scope, the run's settings and the arms'
 * creation stamps — with no arm chosen exactly the settings an evaluated conversation started with before), and every
 * owner message after the first is stamped like a live one (its creation time, the simulated device, and on a stored
 * conversation the row and the inbound-activity stamp after it).
 */
class AgentEvalRunnerServiceConversationAssemblyTest extends IntegrationTestCase
{
    protected AgentEvalRunHooksServiceRecordingDouble $hooksDouble;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hooksDouble = new AgentEvalRunHooksServiceRecordingDouble();
        $this->replaceService(AgentEvalRunHooksService::class, $this->hooksDouble);
        MCPToolExecutorService::resetToolBlocksForNewJob();
        StaticRegistry::$entityServices = [];
    }

    protected function onTearDown(): void
    {
        MCPToolExecutorService::resetToolBlocksForNewJob();
        StaticRegistry::$entityServices = [];
    }

    protected function buildScope(): AgentEvalScope
    {
        $agentSpace = new AgentSpace();
        $agentSpace->id = 17;
        $ownerAccount = new Account();
        $ownerAccount->id = 5;
        return AgentEvalScope::forAgentSpace($agentSpace, $ownerAccount);
    }

    protected function invokeAssembleConversation(AgentEvalRunnerService $agentEvalRunnerService, AgentEvalRunOptions $agentEvalRunOptions): AIConversation
    {
        $assembleConversation = (new ReflectionClass(AgentEvalRunnerService::class))->getMethod('assembleConversation');
        /** @var AIConversation $conversation */
        $conversation = $assembleConversation->invoke($agentEvalRunnerService, $this->buildScope(), $agentEvalRunOptions);
        return $conversation;
    }

    protected function invokeAppendOwnerMessage(AIConversation $conversation, string $ownerMessageText, AgentEvalRunOptions $agentEvalRunOptions): void
    {
        $appendOwnerMessage = (new ReflectionClass(AgentEvalRunnerService::class))->getMethod('appendOwnerMessage');
        $appendOwnerMessage->invoke(new AgentEvalRunnerService(), $conversation, $ownerMessageText, $agentEvalRunOptions);
    }

    protected function buildConversation(): AIConversation
    {
        /** @var AIConversation $conversation */
        $conversation = (new ReflectionClass(AIConversation::class))->newInstanceWithoutConstructor();
        $conversation->messages = new AIConversationMessages();
        return $conversation;
    }

    public function testTheDefaultArmsGiveTheRunSettingsOfAnEvaluatedConversationAndThePlatformsCreationStamps(): void
    {
        $agentEvalRunOptions = AgentEvalRunOptions::forArms();

        $conversation = $this->invokeAssembleConversation(new AgentEvalRunnerService(), $agentEvalRunOptions);

        $runSettings = $conversation->runSettings;
        self::assertTrue($runSettings->evalRun, 'the evaluation marker');
        foreach (AgentEvalArms::RUN_SETTINGS_ROW_NAMES as $rowName) {
            self::assertNull($runSettings->{$rowName}, 'no arm sets the row ' . $rowName);
        }
        self::assertNull($runSettings->requestedSkillLoadingStrategy);
        self::assertNull($runSettings->requestedRebuildEpochOnSituationChange);
        self::assertNull($runSettings->requestedStateChangeDelivery);
        self::assertNull($runSettings->evalBlockedToolNames, 'no tool block without a case block');
        self::assertNull($runSettings->evalBlockedToolSimulatedErrors);
        self::assertNull($runSettings->evalEveryWriteToolBlocked);
        self::assertEquals((new AgentEvalRunnerService())->buildEvalRunSettings(AgentEvalRunOptions::forArms()), $runSettings);
        self::assertEquals(
            ContextRetentionSettings::buildForNewConversation(null, null),
            $conversation->contextRetentionSettings,
            'with no arm chosen the conversation carries the stamps a live new conversation gets'
        );
        self::assertTrue($conversation->contextRetentionSettings->isOnlyTheSkillLoadingStrategyStamp(), 'a purpose may still apply its retention settings');
        self::assertFalse($conversation->budgetBypass, 'no console grant, no budget lift');
        self::assertSame(5, $conversation->accountId);
        self::assertSame(0, $conversation->messages->count(), 'an assembled conversation has no message yet');
        self::assertFalse(isset($conversation->id), 'assembling stores nothing');
        self::assertSame(['attachNewConversationToScope'], $this->hooksDouble->hookCalls, 'attached through the hooks, no space stored');
        self::assertSame($conversation, $agentEvalRunOptions->takeBuiltConversation(), 'recorded for the teardown');
    }

    public function testTheArmsAndTheCaseBlockReachAnAssembledConversation(): void
    {
        $agentEvalArms = AgentEvalArms::newInstance();
        $agentEvalArms->skillLoadingStrategy = ContextRetentionSettings::SKILL_LOADING_STRATEGY_CONVERSATION_APPEND;
        $agentEvalArms->stateChangeDelivery = ContextRetentionSettings::STATE_CHANGE_DELIVERY_ONCE_WHEN_CHANGED;
        $agentEvalArms->fixedReasoningEffort = 'low';
        $agentEvalJudgedRunOptions = AgentEvalJudgedRunOptions::forArms($agentEvalArms);
        $agentEvalRunnerService = new AgentEvalRunnerService();
        $agentEvalRunnerService->applyToolBlockOfCase(AgentEvalCase::fromArray(['blockedTools' => ['publishEntry']]), $agentEvalJudgedRunOptions);

        $conversation = $this->invokeAssembleConversation($agentEvalRunnerService, $agentEvalJudgedRunOptions);

        self::assertSame('low', $conversation->runSettings->fixedReasoningEffort);
        self::assertSame(['publishEntry'], $conversation->runSettings->evalBlockedToolNames);
        self::assertSame(ContextRetentionSettings::SKILL_LOADING_STRATEGY_CONVERSATION_APPEND, $conversation->contextRetentionSettings->skillLoadingStrategy);
        self::assertSame(ContextRetentionSettings::STATE_CHANGE_DELIVERY_ONCE_WHEN_CHANGED, $conversation->contextRetentionSettings->stateChangeDelivery);
        self::assertSame($conversation, $agentEvalJudgedRunOptions->takeBuiltConversation(), 'the options of a judged run record it the same way');
    }

    public function testAnOwnerMessageOfATransientConversationIsStampedAndNothingIsWritten(): void
    {
        $messagesServiceDouble = new AIConversationMessagesServiceRecordingInboundRowsDouble();
        $this->replaceService(AIConversationMessagesService::class, $messagesServiceDouble);
        $conversationsServiceDouble = new AIConversationsServiceRecordingInboundActivityDouble();
        $this->replaceService(AIConversationsService::class, $conversationsServiceDouble);
        $conversation = $this->buildConversation();
        $conversation->reportDeliveredAt = new DateTime();
        $agentEvalRunOptions = AgentEvalRunOptions::forArms();
        $agentEvalRunOptions->simulatedOwnerDevice = OwnerDevice::phoneCall();

        $this->invokeAppendOwnerMessage($conversation, 'And the opening hours?', $agentEvalRunOptions);

        $ownerMessage = $conversation->messages->last();
        self::assertInstanceOf(AIConversationMessage::class, $ownerMessage);
        self::assertSame('And the opening hours?', $ownerMessage->message);
        self::assertSame(AIConversationMessage::ROLE_USER, $ownerMessage->role);
        self::assertSame(AIConversationMessage::AUTHOR_USER, $ownerMessage->authorType);
        self::assertSame(AIConversationMessage::MESSAGE_TEXT, $ownerMessage->messageType);
        self::assertInstanceOf(DateTime::class, $ownerMessage->getChangeHistory()->createdTime, 'the wire timestamp needs a creation time');
        self::assertSame($agentEvalRunOptions->simulatedOwnerDevice, $ownerMessage->ownerDevice);
        self::assertNull($conversation->reportDeliveredAt, 'a new owner turn re-arms the report obligation in memory');
        self::assertSame([], $messagesServiceDouble->storedInboundMessageTexts, 'a transient conversation stores no row');
        self::assertSame([], $conversationsServiceDouble->stampedConversationIds, 'and writes no inbound-activity stamp');
    }

    public function testAnOwnerMessageOfAStoredConversationIsStoredAndThenStampsTheInboundActivity(): void
    {
        $messagesServiceDouble = new AIConversationMessagesServiceRecordingInboundRowsDouble();
        $this->replaceService(AIConversationMessagesService::class, $messagesServiceDouble);
        $conversationsServiceDouble = new AIConversationsServiceRecordingInboundActivityDouble();
        $conversationsServiceDouble->messagesServiceDouble = $messagesServiceDouble;
        $this->replaceService(AIConversationsService::class, $conversationsServiceDouble);
        $conversation = $this->buildConversation();
        $conversation->id = 4711;
        $reportDeliveredAt = new DateTime();
        $conversation->reportDeliveredAt = $reportDeliveredAt;

        $this->invokeAppendOwnerMessage($conversation, 'Please go on.', AgentEvalRunOptions::forArms());

        $ownerMessage = $conversation->messages->last();
        self::assertSame(['Please go on.'], $messagesServiceDouble->storedInboundMessageTexts);
        self::assertSame(4711, $ownerMessage->aiConversationId);
        self::assertInstanceOf(DateTime::class, $ownerMessage->getChangeHistory()->createdTime);
        self::assertSame([4711], $conversationsServiceDouble->stampedConversationIds);
        self::assertSame([1], $conversationsServiceDouble->storedOwnerRowsBeforeStamp, 'the row is stored before the stamp');
        self::assertInstanceOf(DateTime::class, $conversation->lastInboundMessageAt);
        self::assertSame($reportDeliveredAt, $conversation->reportDeliveredAt, 'a stored conversation re-arms the obligation on its row, not here');
    }

    public function testEveryFollowUpOfACaseIsWrittenLikeAnOwnerMessageOnTheBuiltConversation(): void
    {
        $messagesServiceDouble = new AIConversationMessagesServiceRecordingInboundRowsDouble();
        $this->replaceService(AIConversationMessagesService::class, $messagesServiceDouble);
        $conversationsServiceDouble = new AIConversationsServiceRecordingInboundActivityDouble();
        $this->replaceService(AIConversationsService::class, $conversationsServiceDouble);
        $agentEvalRunOptions = AgentEvalRunOptions::forArms();
        $agentEvalRunOptions->simulatedOwnerDevice = OwnerDevice::phoneCall();
        $agentEvalRunnerService = new AgentEvalRunnerServiceWithoutModelCallDouble();

        $agentEvalRunOutcome = $agentEvalRunnerService->run(
            $this->buildScope(),
            AgentEvalCase::fromArray(['prompt' => 'Show me the entries.', 'followUpPrompts' => ['  ', 'Only the newest one.']]),
            $agentEvalRunOptions
        );

        $conversation = $agentEvalRunOutcome->getConversation();
        self::assertSame([['Show me the entries.'], ['Show me the entries.', 'Only the newest one.']], $agentEvalRunnerService->ownerTextsPerDrive, 'a blank follow-up is skipped');
        $followUpMessage = $conversation->messages->last();
        self::assertSame(1, $followUpMessage->indexInConversation);
        self::assertSame($agentEvalRunOptions->simulatedOwnerDevice, $followUpMessage->ownerDevice);
        self::assertInstanceOf(DateTime::class, $followUpMessage->getChangeHistory()->createdTime);
        self::assertSame([], $conversationsServiceDouble->stampedConversationIds, 'a transient run writes no stamp');
        self::assertSame($conversation, $agentEvalRunOptions->takeBuiltConversation(), 'the run\'s conversation is recorded for the teardown');
    }
}
