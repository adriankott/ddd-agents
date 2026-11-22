<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\OwnerDevice;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Entities\Evals\AgentEvalJudgedRunOptions;
use DDD\Domain\AI\Entities\Evals\AgentEvalScope;
use DDD\Domain\AI\Entities\Evals\AgentEvalSimCase;
use DDD\Domain\AI\Entities\Evals\AgentEvalSimRunResult;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Exceptions\Evals\AgentEvalHarnessModelCallFailedException;
use DDD\Domain\AI\Exceptions\Evals\AgentEvalUnknownModelNameException;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Eval\AgentEvalJudgedRunService;
use DDD\Domain\AI\Services\Eval\AgentEvalRunHooksService;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\AuthService;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * The owner turns of a judged run without a model call: the opening prompt, the case's scripted follow-ups, then the
 * driver's messages until it answers `[DONE]` or `[GIVE-UP]`, the conversation closes or the case's turns are used up;
 * the empty driver answer; the judge's verdict on the run; the usage scope of every model call; the case's tool block
 * around the agent's run; the conversation assembled like every evaluated conversation; and the restore bracket around
 * a judged edit case.
 */
class AgentEvalJudgedRunServiceRunTest extends IntegrationTestCase
{
    /** @var string The judge model of the options: one of the catalog, other than the default judge */
    protected const string JUDGE_MODEL_NAME = AIModel::MODEL_ANTHROPIC_CLAUDE_HAIKU_4_5;

    protected const string PASS_ANSWER = '{"verdict":"PASS","scorePercent":95,"goalMet":true,"reasons":"listed"}';

    protected AgentEvalRunHooksServiceRecordingDouble $hooksDouble;

    protected MCPToolExecutorServiceScriptedDouble $executorDouble;

    protected AgentEvalProgressLinesRecorder $progressLinesRecorder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hooksDouble = new AgentEvalRunHooksServiceRecordingDouble();
        $harnessAgentSpace = new AgentSpace();
        $harnessAgentSpace->id = 17;
        $this->hooksDouble->harnessAgentSpace = $harnessAgentSpace;
        $this->replaceService(AgentEvalRunHooksService::class, $this->hooksDouble);
        $this->executorDouble = new MCPToolExecutorServiceScriptedDouble();
        $this->replaceService(MCPToolExecutorService::class, $this->executorDouble);
        $this->progressLinesRecorder = new AgentEvalProgressLinesRecorder();
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
        $ownerAccount = new Account();
        $ownerAccount->id = 5;
        return AgentEvalScope::forAgentSpace(null, $ownerAccount);
    }

    protected function buildOptions(): AgentEvalJudgedRunOptions
    {
        $agentEvalJudgedRunOptions = AgentEvalJudgedRunOptions::forArms();
        $agentEvalJudgedRunOptions->judgeModelName = self::JUDGE_MODEL_NAME;
        $agentEvalJudgedRunOptions->progressReporter = $this->progressLinesRecorder;
        return $agentEvalJudgedRunOptions;
    }

    /**
     * @param array<string, mixed> $extraCaseFields
     */
    protected function buildSimCase(array $extraCaseFields = []): AgentEvalSimCase
    {
        return AgentEvalSimCase::fromArray($extraCaseFields + [
            'goal' => 'See the newest entries.',
            'openingPrompt' => 'Show me my entries.',
            'userFacts' => 'You keep a list of entries.',
            'successRubric' => 'The entries are listed.',
        ]);
    }

    /**
     * @param string[] $driverReplies
     * @param string[] $judgeAnswers
     */
    protected function buildService(array $driverReplies, array $judgeAnswers = [self::PASS_ANSWER]): AgentEvalJudgedRunServiceScriptedModelsDouble
    {
        $agentEvalJudgedRunService = new AgentEvalJudgedRunServiceScriptedModelsDouble();
        $agentEvalJudgedRunService->scriptedDriverReplies = $driverReplies;
        $agentEvalJudgedRunService->scriptedJudgeAnswers = $judgeAnswers;
        return $agentEvalJudgedRunService;
    }

    public function testTheOpeningPromptTheScriptedFollowUpsAndThenTheDriverWriteTheOwnerTurnsUntilDone(): void
    {
        $agentEvalJudgedRunService = $this->buildService(['  so, done yet? ', '[DONE]']);
        $simCase = $this->buildSimCase(['followUpPrompts' => ['  ', 'Only the newest one.'], 'maxTurns' => 5]);

        $agentEvalSimRunResult = $agentEvalJudgedRunService->runSim($this->buildScope(), $simCase, $this->buildOptions());

        self::assertSame([
            ['Show me my entries.'],
            ['Show me my entries.', 'Only the newest one.'],
            ['Show me my entries.', 'Only the newest one.', 'so, done yet?'],
        ], $agentEvalJudgedRunService->ownerTextsPerDrive, 'a blank follow-up is skipped, the driver takes over after the script');
        self::assertSame(AgentEvalSimRunResult::STOP_REASON_DONE, $agentEvalSimRunResult->getStopReason());
        self::assertSame(3, $agentEvalSimRunResult->getTurns());
        self::assertSame('PASS', $agentEvalSimRunResult->getAgentEvalJudgeVerdict()->getVerdict());
        self::assertSame(
            [AgentAIUsageScope::AI_AGENT_SIMULATE_OWNER, AgentAIUsageScope::AI_AGENT_SIMULATE_OWNER, AgentAIUsageScope::AI_AGENT_JUDGE_EVALUATION],
            $agentEvalJudgedRunService->getUsageScopesOfModelCalls()
        );
        self::assertSame(
            [AIModel::MODEL_ANTHROPIC_CLAUDE_SONNET_5, AIModel::MODEL_ANTHROPIC_CLAUDE_SONNET_5, self::JUDGE_MODEL_NAME],
            array_column($agentEvalJudgedRunService->modelCalls, 'aiModelName'),
            'the driver runs on the default driver model, the judge on the model of the options'
        );
        self::assertSame(3, $agentEvalJudgedRunService->connectionHeals, 'once per owner turn');
        self::assertSame(1, $agentEvalJudgedRunService->caseFixtureApplications);
        self::assertSame(5, AuthService::instance()->getAccount()?->id, 'the run works in the name of the scope\'s owner');
    }

    public function testTheDriverReadsTheGoalTheFactsAndWhatTheOwnerSawAndTheJudgeTheTrace(): void
    {
        $agentEvalJudgedRunService = $this->buildService(['[DONE]']);

        $agentEvalJudgedRunService->runSim($this->buildScope(), $this->buildSimCase(), $this->buildOptions());

        $driverPromptText = $agentEvalJudgedRunService->modelCalls[0]['promptText'];
        self::assertStringContainsString('YOUR GOAL: See the newest entries.', $driverPromptText);
        self::assertStringContainsString('You keep a list of entries.', $driverPromptText);
        self::assertStringContainsString("OWNER: Show me my entries.\n\nASSISTANT: Answer 1", $driverPromptText);
        $verdictPromptText = $agentEvalJudgedRunService->modelCalls[1]['promptText'];
        self::assertStringContainsString("OWNER'S GOAL: See the newest entries.", $verdictPromptText);
        self::assertStringContainsString('OPENING MESSAGE: Show me my entries.', $verdictPromptText);
        self::assertStringContainsString('SUCCESS RUBRIC: The entries are listed.', $verdictPromptText);
        self::assertStringContainsString('[0] TEXT USER (owner reply 1): Show me my entries.', $verdictPromptText);
        self::assertStringContainsString('[1] TEXT ASSISTANT: Answer 1', $verdictPromptText);
        self::assertStringContainsString('[COUNTS — machine-counted and AUTHORITATIVE, never recount]', $verdictPromptText);
    }

    public function testAGiveUpEndsTheTurnsAndAnEmptyDriverAnswerBecomesTheGoOnMessage(): void
    {
        $agentEvalJudgedRunService = $this->buildService(['   ', 'Nothing works. [GIVE-UP]']);

        $agentEvalSimRunResult = $agentEvalJudgedRunService->runSim($this->buildScope(), $this->buildSimCase(), $this->buildOptions());

        self::assertSame(['Show me my entries.', 'Please go on.'], $agentEvalJudgedRunService->ownerTextsPerDrive[1]);
        self::assertSame(AgentEvalSimRunResult::STOP_REASON_GIVE_UP, $agentEvalSimRunResult->getStopReason());
        self::assertSame(2, $agentEvalSimRunResult->getTurns());
    }

    public function testAJudgeOrDriverModelTheCatalogDoesNotKnowIsRefusedBeforeTheFirstTurn(): void
    {
        foreach (['judgeModelName', 'driverModelName'] as $modelOptionName) {
            $agentEvalJudgedRunService = $this->buildService(['[DONE]']);
            $agentEvalJudgedRunOptions = $this->buildOptions();
            $agentEvalJudgedRunOptions->{$modelOptionName} = 'ALIBABA.QWEN4_MAX.json';
            try {
                $agentEvalJudgedRunService->runSim($this->buildScope(), $this->buildSimCase(), $agentEvalJudgedRunOptions);
                self::fail("an unknown {$modelOptionName} must be refused");
            } catch (AgentEvalUnknownModelNameException $unknownModelName) {
                self::assertSame('ALIBABA.QWEN4_MAX.json', $unknownModelName->getUnknownModelName());
            }
            self::assertSame([], $agentEvalJudgedRunService->ownerTextsPerDrive, "{$modelOptionName}: no turn was driven");
            self::assertSame([], $agentEvalJudgedRunService->modelCalls, "{$modelOptionName}: no model was called");
        }
    }

    public function testAFailedDriverCallEndsTheCaseWithTheProvidersMessageAndNoVerdict(): void
    {
        $agentEvalJudgedRunService = $this->buildService(['never read']);
        $agentEvalJudgedRunService->driverCallFailureReason = 'http 404 from the upstream call: No endpoints found';

        try {
            $agentEvalJudgedRunService->runSim($this->buildScope(), $this->buildSimCase(), $this->buildOptions());
            self::fail('a failed driver call must end the case, never become an owner turn');
        } catch (AgentEvalHarnessModelCallFailedException $driverCallFailure) {
            self::assertSame(
                'driver call failed: ' . AIModel::MODEL_ANTHROPIC_CLAUDE_SONNET_5 . ': http 404 from the upstream call: No endpoints found',
                $driverCallFailure->getMessage(),
                'the case\'s error row carries the provider\'s message'
            );
        }
        self::assertCount(1, $agentEvalJudgedRunService->ownerTextsPerDrive, 'the opening turn ran, no "please go on" turn followed');
        self::assertSame([AgentAIUsageScope::AI_AGENT_SIMULATE_OWNER], $agentEvalJudgedRunService->getUsageScopesOfModelCalls(), 'no judge call: the run has no verdict');
        self::assertSame([], MCPToolExecutorService::$blockedToolNames, 'the case\'s tool block is lifted');
    }

    public function testTheTurnsStopAtTheCasesLimit(): void
    {
        $agentEvalJudgedRunService = $this->buildService(['and now?', 'and then?', 'never asked']);

        $agentEvalSimRunResult = $agentEvalJudgedRunService->runSim($this->buildScope(), $this->buildSimCase(['maxTurns' => 2]), $this->buildOptions());

        self::assertSame(AgentEvalSimRunResult::STOP_REASON_MAX_TURNS, $agentEvalSimRunResult->getStopReason());
        self::assertSame(2, $agentEvalSimRunResult->getTurns());
        self::assertCount(2, $agentEvalJudgedRunService->ownerTextsPerDrive);
        self::assertSame(['never asked'], $agentEvalJudgedRunService->scriptedDriverReplies, 'the driver is asked after each turn, its last answer is never sent');
    }

    public function testAClosedConversationEndsTheCaseAsDoneWithoutAskingTheDriver(): void
    {
        $agentEvalJudgedRunService = $this->buildService(['never asked']);
        $agentEvalJudgedRunService->closeConversationAfterTurn = 1;

        $agentEvalSimRunResult = $agentEvalJudgedRunService->runSim($this->buildScope(), $this->buildSimCase(['followUpPrompts' => ['never sent']]), $this->buildOptions());

        self::assertSame(AgentEvalSimRunResult::STOP_REASON_DONE, $agentEvalSimRunResult->getStopReason());
        self::assertSame(1, $agentEvalSimRunResult->getTurns());
        self::assertSame([AgentAIUsageScope::AI_AGENT_JUDGE_EVALUATION], $agentEvalJudgedRunService->getUsageScopesOfModelCalls());
    }

    public function testEveryOwnerMessageIsWrittenLikeALiveOne(): void
    {
        $agentEvalJudgedRunService = $this->buildService(['and the oldest?', '[DONE]']);
        $agentEvalJudgedRunOptions = $this->buildOptions();
        $agentEvalJudgedRunOptions->simulatedOwnerDevice = OwnerDevice::phoneCall();

        $conversation = $agentEvalJudgedRunService->runSim($this->buildScope(), $this->buildSimCase(), $agentEvalJudgedRunOptions)->getConversation();

        $ownerMessages = array_values(array_filter(
            $conversation->messages->getElements(),
            static fn (AIConversationMessage $message): bool => $message->role === AIConversationMessage::ROLE_USER
        ));
        self::assertCount(2, $ownerMessages);
        foreach ($ownerMessages as $ownerMessage) {
            self::assertInstanceOf(DateTime::class, $ownerMessage->getChangeHistory()->createdTime);
            self::assertSame($agentEvalJudgedRunOptions->simulatedOwnerDevice, $ownerMessage->ownerDevice);
            self::assertSame(AIConversationMessage::AUTHOR_USER, $ownerMessage->authorType);
        }
    }

    public function testTheCaseToolBlockGuardsTheAgentRunAndIsLiftedAfterIt(): void
    {
        $agentEvalJudgedRunService = $this->buildService(['and the oldest?', '[DONE]']);
        $agentEvalJudgedRunOptions = $this->buildOptions();

        $agentEvalJudgedRunService->runSim($this->buildScope(), $this->buildSimCase(['blockedTools' => ['publishEntry']]), $agentEvalJudgedRunOptions);

        self::assertSame([['publishEntry'], ['publishEntry']], $agentEvalJudgedRunService->blockedToolNamesPerDrive);
        self::assertSame([], MCPToolExecutorService::$blockedToolNames);
        self::assertSame([], $agentEvalJudgedRunOptions->blockedToolNames);
    }

    public function testTheJudgedConversationIsAssembledLikeEveryEvaluatedConversationAndTheDefaultArmsGiveTheRunSettingsOfEveryJudgedRun(): void
    {
        $agentEvalJudgedRunService = $this->buildService(['[DONE]']);
        $agentEvalJudgedRunOptions = $this->buildOptions();

        $conversation = $agentEvalJudgedRunService->runSim($this->buildScope(), $this->buildSimCase(), $agentEvalJudgedRunOptions)->getConversation();

        $expectedRunSettings = (new AgentEvalJudgedRunService())->buildEvalRunSettings(AgentEvalJudgedRunOptions::forArms());
        self::assertMatchesRegularExpression('/^eval-[a-z0-9-]+-r1-[a-f0-9]{8}$/', (string)$conversation->runSettings->workspaceKey, 'the case\'s own workspace');
        $expectedRunSettings->workspaceKey = $conversation->runSettings->workspaceKey;
        self::assertEquals($expectedRunSettings, $conversation->runSettings, 'the evaluation marker and no arm');
        self::assertEquals(ContextRetentionSettings::buildForNewConversation(null, null), $conversation->contextRetentionSettings, 'the platform\'s creation stamps');
        self::assertFalse(isset($conversation->id), 'a transient run stores nothing');
        self::assertSame(5, $conversation->accountId);
        self::assertSame($conversation, $agentEvalJudgedRunOptions->takeBuiltConversation(), 'recorded for the teardown');
        self::assertContains('attachNewConversationToScope', $this->hooksDouble->hookCalls);
    }

    public function testTheCasesSettingsBlocksReachTheConversation(): void
    {
        $agentEvalJudgedRunService = $this->buildService(['[DONE]']);
        $simCase = $this->buildSimCase([
            'runSettings' => ['maxStepsPerTurn' => 3],
            'contextRetentionSettings' => ['storeRequestSnapshot' => true],
            'compactionSettings' => ['triggerTokens' => 1200, 'segmentMessages' => 4],
        ]);

        $conversation = $agentEvalJudgedRunService->runSim($this->buildScope(), $simCase, $this->buildOptions())->getConversation();

        self::assertSame(3, $conversation->runSettings->maxStepsPerTurn);
        self::assertTrue($conversation->runSettings->evalRun, 'merged into the run settings of the assembly');
        self::assertTrue($conversation->contextRetentionSettings->storeRequestSnapshot);
        self::assertSame(1200, $conversation->compactionSettings->triggerTokens);
        self::assertSame(4, $conversation->compactionSettings->segmentMessages);
    }

    public function testAScheduleFiringOrAReportChannelRunsInTheScheduleRunPurposeUnlessTheCaseNamesOne(): void
    {
        $agentEvalJudgedRunService = $this->buildService([]);

        self::assertNull($this->resolvePurposeNameOfSimCase($agentEvalJudgedRunService, $this->buildSimCase()));
        self::assertSame(AIConversationPurpose::SCHEDULE_RUN, $this->resolvePurposeNameOfSimCase($agentEvalJudgedRunService, $this->buildSimCase(['scheduledRun' => true])));
        self::assertSame(AIConversationPurpose::SCHEDULE_RUN, $this->resolvePurposeNameOfSimCase($agentEvalJudgedRunService, $this->buildSimCase(['reportChannel' => 'EMAIL'])));
        self::assertSame('Own.Purpose', $this->resolvePurposeNameOfSimCase($agentEvalJudgedRunService, $this->buildSimCase(['reportChannel' => 'EMAIL', 'purposeName' => 'Own.Purpose'])));
    }

    protected function resolvePurposeNameOfSimCase(AgentEvalJudgedRunService $agentEvalJudgedRunService, AgentEvalSimCase $agentEvalSimCase): ?string
    {
        /** @var string|null $purposeName */
        $purposeName = (new ReflectionMethod(AgentEvalJudgedRunService::class, 'resolvePurposeNameOfSimCase'))->invoke($agentEvalJudgedRunService, $agentEvalSimCase);
        return $purposeName;
    }


    public function testAScriptedFollowUpWaitsItsIdleMinutesAndThePurposeIsAppliedOnTheFirstOwnerMessage(): void
    {
        $agentEvalJudgedRunService = $this->buildService(['[DONE]']);
        $simCase = $this->buildSimCase(['followUpPrompts' => ['And the oldest?'], 'followUpIdleMinutes' => [30], 'purposeName' => 'Own.Purpose']);

        $conversation = $agentEvalJudgedRunService->runSim($this->buildScope(), $simCase, $this->buildOptions())->getConversation();

        self::assertSame([['purposeName' => 'Own.Purpose', 'anchorMessageText' => 'Show me my entries.']], $agentEvalJudgedRunService->appliedPurposes, 'once, on the first owner message');
        $firstAnswer = $conversation->messages->getElements()[1];
        $secondAnswer = $conversation->messages->getElements()[3];
        self::assertSame('Answer 1', $firstAnswer->message);
        self::assertSame(
            AgentEvalJudgedRunServiceScriptedModelsDouble::DRIVEN_CALL_SENT_AT - 30 * 60,
            $firstAnswer->requestSnapshot->sentAt->getTimestamp(),
            'the owner was away 30 minutes before the follow-up: the earlier call moves into the past'
        );
        self::assertSame(AgentEvalJudgedRunServiceScriptedModelsDouble::DRIVEN_CALL_SENT_AT, $secondAnswer->requestSnapshot->sentAt->getTimestamp(), 'the follow-up turn\'s own call stays');
        self::assertSame(0, $agentEvalJudgedRunService->processStateResets, 'a transient run keeps its process state');
    }

    public function testAStoredRunIsStoredWritesItsRunIdentityStartsEachLaterTurnFreshAndIsJudgedOnWhatWasStored(): void
    {
        $messagesServiceDouble = new AIConversationMessagesServiceWithStoredRowsDouble();
        $this->replaceService(AIConversationMessagesService::class, $messagesServiceDouble);
        $conversationsServiceDouble = new AIConversationsServiceWithStoredConversationDouble();
        $this->replaceService(AIConversationsService::class, $conversationsServiceDouble);
        $storedOwnerMessage = AIConversationMessage::newInstance();
        $storedWidgetRow = AIConversationMessage::newInstance();
        $storedMessages = new AIConversationMessages();
        $storedMessages->add($storedOwnerMessage);
        $storedMessages->add($storedWidgetRow);
        $storedOwnerMessage->indexInConversation = 0;
        $storedOwnerMessage->messageType = AIConversationMessage::MESSAGE_TEXT;
        $storedOwnerMessage->role = AIConversationMessage::ROLE_USER;
        $storedOwnerMessage->authorType = AIConversationMessage::AUTHOR_USER;
        $storedOwnerMessage->message = 'Show me my entries.';
        $storedWidgetRow->indexInConversation = 1;
        $storedWidgetRow->messageType = AIConversationMessage::MESSAGE_TOOL_MESSAGE;
        $storedWidgetRow->role = AIConversationMessage::ROLE_ASSISTANT;
        $storedWidgetRow->authorType = AIConversationMessage::AUTHOR_TOOL;
        $storedWidgetRow->message = 'a widget row a worker stored';
        $messagesServiceDouble->storedMessages = $storedMessages;
        $agentEvalJudgedRunService = $this->buildService(['and the oldest?', '[DONE]']);
        $agentEvalJudgedRunOptions = $this->buildOptions();
        $agentEvalJudgedRunOptions->persistConversations = true;

        $agentEvalSimRunResult = $agentEvalJudgedRunService->runSim($this->buildScope(), $this->buildSimCase(['runSettings' => ['maxStepsPerTurn' => 3]]), $agentEvalJudgedRunOptions);

        $storedConversation = $conversationsServiceDouble->storedConversation;
        self::assertInstanceOf(AIConversation::class, $storedConversation, 'stored before the first turn');
        self::assertSame([['runSettings']], $agentEvalJudgedRunService->runIdentityWrites, 'the run identity reaches the row');
        self::assertSame(['Show me my entries.', 'and the oldest?'], $messagesServiceDouble->storedInboundMessageTexts, 'every owner message is stored');
        self::assertSame([AIConversationsServiceWithStoredConversationDouble::STORED_CONVERSATION_ID, AIConversationsServiceWithStoredConversationDouble::STORED_CONVERSATION_ID], $conversationsServiceDouble->stampedConversationIds, 'and stamps the inbound activity');
        self::assertSame(2, $agentEvalJudgedRunService->processStateResets, 'the second turn and the reload start on fresh process state');
        self::assertSame(1, $conversationsServiceDouble->reads, 'read back once, before the judge');
        $judgedConversation = $agentEvalSimRunResult->getConversation();
        self::assertNotSame($storedConversation, $judgedConversation, 'the result is the conversation as read back');
        self::assertSame($storedMessages, $judgedConversation->messages);
        $verdictPromptText = $agentEvalJudgedRunService->modelCalls[2]['promptText'];
        self::assertStringContainsString('[1] TOOL_MESSAGE (a widget row written into the chat by ', $verdictPromptText, 'the judge reads the stored rows');
        self::assertStringContainsString('a widget row a worker stored', $verdictPromptText);
        self::assertStringContainsString("ASSISTANT: a widget row a worker stored", $agentEvalJudgedRunService->modelCalls[0]['promptText'], 'so does the driver');
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildRestoreCaseFields(): array
    {
        return [
            'id' => 'entry.edit',
            'blockedTools' => ['restoreEntry'],
            'restore' => [
                'setup' => [
                    ['tool' => 'createEntry', 'args' => ['title' => 'Seed'], 'undo' => ['tool' => 'deleteEntry', 'argsFromResult' => ['entryId' => ['path' => 'entry.id']]]],
                ],
                'writeTool' => 'restoreEntry',
                'writeArgs' => ['entryId' => 9, 'title' => 'Before'],
            ],
        ];
    }

    public function testAJudgedEditCaseRunsInsideTheRestoreBracket(): void
    {
        $this->executorDouble->scriptResult('createEntry', '{"structuredContent":{"entry":{"id":101}}}');
        $this->executorDouble->scriptResult('deleteEntry', '{"structuredContent":{"deleted":true}}');
        $agentEvalJudgedRunService = $this->buildService(['[DONE]']);

        $agentEvalSimRunResult = $agentEvalJudgedRunService->runSimWithRestore($this->buildScope(), $this->buildSimCase($this->buildRestoreCaseFields()), $this->buildOptions());

        self::assertSame('PASS', $agentEvalSimRunResult->getAgentEvalJudgeVerdict()->getVerdict());
        self::assertSame(['createEntry', 'restoreEntry', 'deleteEntry'], $this->executorDouble->getExecutedToolNames());
        self::assertSame([['restoreEntry']], $agentEvalJudgedRunService->blockedToolNamesPerDrive, 'the case block guards the agent run');
        self::assertSame('releaseHostHoldsAfterHarnessCalls', $this->hooksDouble->hookCalls[array_key_last($this->hooksDouble->hookCalls)]);
        self::assertSame([
            'restore setup createEntry: {"structuredContent":{"entry":{"id":101}}}',
            'restore write restoreEntry: {}',
            'restore undo deleteEntry: {"structuredContent":{"deleted":true}}',
            'restore release: nothing held',
        ], $this->progressLinesRecorder->progressLines);
        self::assertSame([], MCPToolExecutorService::$blockedToolNames);
    }

    public function testAJudgedEditCaseWhoseRunThrowsIsRestoredAllTheSame(): void
    {
        $this->executorDouble->scriptResult('createEntry', '{"structuredContent":{"entry":{"id":101}}}');
        $this->executorDouble->scriptResult('deleteEntry', '{"structuredContent":{"deleted":true}}');
        $agentEvalJudgedRunService = $this->buildService(['[DONE]']);
        $agentEvalJudgedRunService->agentRunErrorMessage = 'model unavailable';

        try {
            $agentEvalJudgedRunService->runSimWithRestore($this->buildScope(), $this->buildSimCase($this->buildRestoreCaseFields()), $this->buildOptions());
            self::fail('the agent run error must reach the caller');
        } catch (RuntimeException $agentRunError) {
            self::assertSame('model unavailable', $agentRunError->getMessage());
        }
        self::assertSame(['createEntry', 'restoreEntry', 'deleteEntry'], $this->executorDouble->getExecutedToolNames());
        self::assertContains('releaseHostHoldsAfterHarnessCalls', $this->hooksDouble->hookCalls);
        self::assertSame([], $agentEvalJudgedRunService->modelCalls, 'neither the driver nor the judge is asked');
        self::assertSame([], MCPToolExecutorService::$blockedToolNames);
    }
}
