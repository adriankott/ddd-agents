<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\RequestSnapshot;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Entities\Evals\AgentEvalArms;
use DDD\Domain\AI\Entities\Evals\AgentEvalCase;
use DDD\Domain\AI\Entities\Evals\AgentEvalHarnessToolCall;
use DDD\Domain\AI\Entities\Evals\AgentEvalNotApplicableReason;
use DDD\Domain\AI\Entities\Evals\AgentEvalRunOptions;
use DDD\Domain\AI\Entities\Evals\AgentEvalScope;
use DDD\Domain\AI\Entities\Evals\AgentEvalStateAfterRunCheck;
use DDD\Domain\AI\Entities\Mandates\AgentMandate;
use DDD\Domain\AI\Exceptions\Evals\AgentEvalPreconditionNotEstablishedException;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;
use DDD\Domain\AI\Services\Eval\AgentEvalRunHooksService;
use DDD\Domain\AI\Services\Eval\AgentEvalRunnerService;
use DDD\Domain\AI\Services\Mandates\AgentMandatesService;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use ReflectionClass;
use RuntimeException;

/**
 * What the evaluation runner does without a model call: the owner's idle time before a follow-up, the run identity of a
 * stored conversation, the case's tool block on the process and on the conversation, the harness tool calls and the
 * restore orchestration around the agent's run, the state check after a run, the teardown of the schedules and
 * standing permissions a stored conversation created, the applicability check's account and its scope lookup, the
 * recall run's list of seeded ids, and the messages trace. The agent's run itself, the reload of a stored
 * conversation, the eviction trajectory and the seeding of a recall run make model calls or read a database and are
 * not driven here.
 */
class AgentEvalRunnerServiceTest extends IntegrationTestCase
{
    protected AgentEvalRunHooksServiceRecordingDouble $hooksDouble;

    protected MCPToolExecutorServiceScriptedDouble $executorDouble;

    protected AgentEvalProgressLinesRecorder $progressLinesRecorder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hooksDouble = new AgentEvalRunHooksServiceRecordingDouble();
        $this->hooksDouble->harnessAgentSpace = $this->buildStoredAgentSpace(17);
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

    protected function buildStoredAgentSpace(int $agentSpaceId): AgentSpace
    {
        $agentSpace = new AgentSpace();
        $agentSpace->id = $agentSpaceId;
        return $agentSpace;
    }

    protected function buildScope(): AgentEvalScope
    {
        $ownerAccount = new Account();
        $ownerAccount->id = 5;
        return AgentEvalScope::forAgentSpace($this->buildStoredAgentSpace(17), $ownerAccount);
    }

    protected function buildOptions(): AgentEvalRunOptions
    {
        $agentEvalRunOptions = AgentEvalRunOptions::forArms();
        $agentEvalRunOptions->progressReporter = $this->progressLinesRecorder;
        return $agentEvalRunOptions;
    }

    protected function buildConversation(string $conversationClass = AIConversation::class): AIConversation
    {
        /** @var AIConversation $conversation */
        $conversation = (new ReflectionClass($conversationClass))->newInstanceWithoutConstructor();
        $conversation->messages = new AIConversationMessages();
        return $conversation;
    }

    protected function buildIdleRecordingMessage(?int $messageId, int $sentAtTimestamp): AgentEvalMessageRecordingPartialWrites
    {
        /** @var AgentEvalMessageRecordingPartialWrites $message */
        $message = (new ReflectionClass(AgentEvalMessageRecordingPartialWrites::class))->newInstanceWithoutConstructor();
        if ($messageId !== null) {
            $message->id = $messageId;
        }
        $requestSnapshot = new RequestSnapshot();
        $requestSnapshot->sentAt = DateTime::fromTimestamp($sentAtTimestamp);
        $message->requestSnapshot = $requestSnapshot;
        return $message;
    }

    protected function buildRunnerWithoutAgentRun(): AgentEvalRunnerServiceWithoutAgentRunDouble
    {
        return new AgentEvalRunnerServiceWithoutAgentRunDouble();
    }

    public function testTheRunnerIsResolvedThroughTheContainer(): void
    {
        self::assertInstanceOf(AgentEvalRunnerService::class, DDDService::instance()->getService(AgentEvalRunnerService::class));
    }

    public function testEveryRecordedCallMovesIntoThePastAndOnlyStoredRowsAreWritten(): void
    {
        $conversation = $this->buildConversation();
        $conversation->id = 4711;
        $storedCall = $this->buildIdleRecordingMessage(11, 1_800_000_000);
        $transientCall = $this->buildIdleRecordingMessage(null, 1_800_000_600);
        /** @var AgentEvalMessageRecordingPartialWrites $ownerMessage */
        $ownerMessage = (new ReflectionClass(AgentEvalMessageRecordingPartialWrites::class))->newInstanceWithoutConstructor();
        $ownerMessage->id = 12;
        // add() takes its elements by reference: the typed handles above stay for the assertions
        $storedCallElement = $storedCall;
        $transientCallElement = $transientCall;
        $ownerMessageElement = $ownerMessage;
        $conversation->messages->add($storedCallElement, $transientCallElement, $ownerMessageElement);

        (new AgentEvalRunnerService())->simulateOwnerIdleBeforeNextTurn($conversation, 45);

        self::assertSame(1_800_000_000 - 45 * 60, $storedCall->requestSnapshot->sentAt->getTimestamp());
        self::assertSame(1_800_000_600 - 45 * 60, $transientCall->requestSnapshot->sentAt->getTimestamp());
        self::assertSame([['requestSnapshot']], $storedCall->partialWrites);
        self::assertSame([], $transientCall->partialWrites, 'a row without an id is not written');
        self::assertSame([], $ownerMessage->partialWrites, 'a message without a snapshot stays untouched');
    }

    public function testNoIdleChangesNothing(): void
    {
        $conversation = $this->buildConversation();
        $conversation->id = 4711;
        $storedCall = $this->buildIdleRecordingMessage(11, 1_800_000_000);
        $storedCallElement = $storedCall;
        $conversation->messages->add($storedCallElement);

        (new AgentEvalRunnerService())->simulateOwnerIdleBeforeNextTurn($conversation, 0);

        self::assertSame(1_800_000_000, $storedCall->requestSnapshot->sentAt->getTimestamp());
        self::assertSame([], $storedCall->partialWrites);
    }

    public function testAStoredConversationWritesTheRunIdentityFieldsTheCaseSet(): void
    {
        /** @var AgentEvalConversationRecordingPartialWrites $conversation */
        $conversation = $this->buildConversation(AgentEvalConversationRecordingPartialWrites::class);
        $conversation->id = 4711;
        $conversation->purposeName = 'Schedule.Run';
        $conversation->reportChannel = AIConversationSchedule::REPORT_CHANNEL_EMAIL;
        $conversation->initiatorType = AIConversation::INITIATOR_TYPE_ADMIN_TASK;
        $runSettings = new RunSettings();
        $runSettings->maxStepsPerTurn = 25;
        $conversation->runSettings = $runSettings;

        $this->invokePersistRunIdentityIfPersisted($conversation);

        self::assertSame([['purposeName', 'reportChannel', 'initiatorType', 'runSettings']], $conversation->partialWrites);
    }

    public function testATransientConversationAndUnsetFieldsWriteNothingExtra(): void
    {
        /** @var AgentEvalConversationRecordingPartialWrites $transientConversation */
        $transientConversation = $this->buildConversation(AgentEvalConversationRecordingPartialWrites::class);
        $transientConversation->reportChannel = AIConversationSchedule::REPORT_CHANNEL_EMAIL;
        $this->invokePersistRunIdentityIfPersisted($transientConversation);
        self::assertSame([], $transientConversation->partialWrites);

        /** @var AgentEvalConversationRecordingPartialWrites $chatConversation */
        $chatConversation = $this->buildConversation(AgentEvalConversationRecordingPartialWrites::class);
        $chatConversation->id = 4712;
        $chatConversation->runSettings = new RunSettings();
        $this->invokePersistRunIdentityIfPersisted($chatConversation);
        self::assertSame([['runSettings']], $chatConversation->partialWrites, 'a chat case has no purpose, channel or initiator to write');
    }

    protected function invokePersistRunIdentityIfPersisted(AIConversation $conversation): void
    {
        $persistRunIdentityIfPersisted = (new ReflectionClass(AgentEvalRunnerService::class))->getMethod('persistRunIdentityIfPersisted');
        $persistRunIdentityIfPersisted->invoke(new AgentEvalRunnerService(), $conversation);
    }

    public function testTheCaseToolBlockGuardsTheProcessAndRidesOnTheRunSettings(): void
    {
        $this->hooksDouble->externalWriteToolNames = ['publishEntry', 'deleteEntry'];
        $agentEvalCase = AgentEvalCase::fromArray([
            'blockedTools' => ['rebuildSite'],
            'blockedToolSimulatedErrors' => ['rebuildSite' => 'The site service is unavailable.'],
            'allowExternalWrites' => ['deleteEntry'],
        ]);
        $agentEvalRunOptions = $this->buildOptions();
        $agentEvalRunOptions->everyWriteToolBlocked = true;
        $agentEvalRunnerService = new AgentEvalRunnerService();

        $agentEvalRunnerService->applyToolBlockOfCase($agentEvalCase, $agentEvalRunOptions);
        $runSettings = $agentEvalRunnerService->buildEvalRunSettings($agentEvalRunOptions);

        self::assertSame(['rebuildSite', 'publishEntry'], MCPToolExecutorService::$blockedToolNames);
        self::assertSame(['rebuildSite' => 'The site service is unavailable.'], MCPToolExecutorService::$blockedToolSimulatedErrors);
        self::assertTrue(MCPToolExecutorService::$everyWriteToolBlocked);
        self::assertTrue($runSettings->evalRun);
        self::assertSame(['rebuildSite', 'publishEntry'], $runSettings->evalBlockedToolNames);
        self::assertSame(['rebuildSite' => 'The site service is unavailable.'], $runSettings->evalBlockedToolSimulatedErrors);
        self::assertTrue($runSettings->evalEveryWriteToolBlocked);

        $agentEvalRunnerService->liftToolBlockOfCase($agentEvalRunOptions);
        self::assertSame([], MCPToolExecutorService::$blockedToolNames);
        self::assertSame([], MCPToolExecutorService::$blockedToolSimulatedErrors);
        self::assertTrue(MCPToolExecutorService::$everyWriteToolBlocked, 'the read-only switch is a setting of the whole run');
    }

    public function testTheLiftClearsTheBlockFromTheOptionsSoALaterConversationDoesNotCarryIt(): void
    {
        $agentEvalCase = AgentEvalCase::fromArray([
            'blockedTools' => ['rebuildSite'],
            'blockedToolSimulatedErrors' => ['rebuildSite' => 'The site service is unavailable.'],
        ]);
        $agentEvalRunOptions = $this->buildOptions();
        $agentEvalRunOptions->everyWriteToolBlocked = true;
        $agentEvalRunnerService = new AgentEvalRunnerService();
        $agentEvalRunnerService->applyToolBlockOfCase($agentEvalCase, $agentEvalRunOptions);

        $agentEvalRunnerService->liftToolBlockOfCase($agentEvalRunOptions);
        $runSettingsAfterTheLift = $agentEvalRunnerService->buildEvalRunSettings($agentEvalRunOptions);

        self::assertSame([], $agentEvalRunOptions->blockedToolNames);
        self::assertSame([], $agentEvalRunOptions->blockedToolSimulatedErrors);
        self::assertNull($runSettingsAfterTheLift->evalBlockedToolNames);
        self::assertNull($runSettingsAfterTheLift->evalBlockedToolSimulatedErrors);
        self::assertTrue($runSettingsAfterTheLift->evalEveryWriteToolBlocked, 'the read-only switch stays');
    }

    public function testTheRunSettingsCarryTheArmsOfTheOptions(): void
    {
        $agentEvalArms = AgentEvalArms::newInstance();
        $agentEvalArms->planDynamicsArm = AgentEvalArms::PLAN_DYNAMICS_ARM_GUIDE;
        $agentEvalArms->fixedReasoningEffort = 'low';

        $runSettings = (new AgentEvalRunnerService())->buildEvalRunSettings(AgentEvalRunOptions::forArms($agentEvalArms));

        self::assertTrue($runSettings->planDynamicsAskDecisionModel);
        self::assertTrue($runSettings->planDynamicsModelReceivesVerdict);
        self::assertSame('low', $runSettings->fixedReasoningEffort);
        self::assertNull($runSettings->evalBlockedToolNames, 'a run without a block leaves the row untouched');
    }

    public function testAHarnessCallLiftsTheCaseBlockOnlyWhenAskedAndPutsItBack(): void
    {
        MCPToolExecutorService::$blockedToolNames = ['restoreEntry'];
        MCPToolExecutorService::$blockedToolSimulatedErrors = ['restoreEntry' => 'Simulated.'];
        MCPToolExecutorService::$everyWriteToolBlocked = true;
        $provenance = AgentToolCallProvenance::forEvalHarness($this->buildStoredAgentSpace(17));
        $liftedCall = AgentEvalHarnessToolCall::forTool(AgentEvalHarnessToolCall::STEP_WRITE, 'restoreEntry', ['entryId' => 3, 'spaceKey' => 'case']);
        $unliftedCall = AgentEvalHarnessToolCall::forTool(AgentEvalHarnessToolCall::STEP_STATE_READ, 'getEntry', ['entryId' => 3], true);
        $this->executorDouble->scriptResult('restoreEntry', '{"restored":true}');
        $this->executorDouble->scriptResult('getEntry', 'refused', true);
        $agentEvalRunnerService = new AgentEvalRunnerService();

        $agentEvalRunnerService->executeHarnessToolCall($liftedCall, ['TestPackage'], $provenance, ['spaceKey' => 'filled'], true);
        $agentEvalRunnerService->executeHarnessToolCall($unliftedCall, ['TestPackage'], $provenance, ['spaceKey' => 'filled'], false);

        [$liftedExecution, $unliftedExecution] = $this->executorDouble->executedCalls;
        self::assertSame([], $liftedExecution['blockedToolNames']);
        self::assertSame([], $liftedExecution['blockedToolSimulatedErrors']);
        self::assertTrue($liftedExecution['everyWriteToolBlocked'], 'the read-only switch is never lifted');
        self::assertSame(['spaceKey' => 'filled', 'entryId' => 3], $liftedExecution['arguments'], 'the platform\'s argument wins over the case');
        self::assertSame($provenance, $liftedExecution['provenance']);
        self::assertSame(['restoreEntry'], $unliftedExecution['blockedToolNames']);
        self::assertSame(['spaceKey' => 'filled', 'outputFormat' => 'structured', 'entryId' => 3], $unliftedExecution['arguments']);
        self::assertSame(['restoreEntry'], MCPToolExecutorService::$blockedToolNames, 'the block is back after the lifted call');
        self::assertSame(['restoreEntry' => 'Simulated.'], MCPToolExecutorService::$blockedToolSimulatedErrors);
        self::assertSame('{"restored":true}', $liftedCall->getResultContent());
        self::assertTrue($unliftedCall->resultIsError);
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildRestoreCaseFields(): array
    {
        return [
            'id' => 'entry.edit',
            'prompt' => 'Change the entry title.',
            'blockedTools' => ['restoreEntry'],
            'restore' => [
                'setup' => [
                    ['tool' => 'createEntry', 'args' => ['title' => 'Seed A'], 'undo' => ['tool' => 'deleteEntry', 'argsFromResult' => ['entryId' => ['path' => 'entry.id']]]],
                    ['tool' => 'createEntry', 'args' => ['title' => 'Seed B'], 'undo' => ['tool' => 'deleteEntry', 'argsFromResult' => ['entryId' => ['path' => 'entry.id']]]],
                ],
                'readTool' => 'getEntry',
                'readArgs' => ['entryId' => 9],
                'capture' => ['entryId' => 'entry.id', 'title' => 'entry.title'],
                'writeTool' => 'restoreEntry',
                'after' => ['tool' => 'rebuildSite'],
            ],
        ];
    }

    protected function scriptRestoreCaseResults(): void
    {
        $this->executorDouble->scriptResult('createEntry', '{"structuredContent":{"entry":{"id":101}}}');
        $this->executorDouble->scriptResult('createEntry', '{"structuredContent":{"entry":{"id":102}}}');
        $this->executorDouble->scriptResult('getEntry', '{"structuredContent":{"entry":{"id":9,"title":"Before"}}}');
        $this->executorDouble->scriptResult('deleteEntry', '{"structuredContent":{"deleted":true}}');
        $this->executorDouble->scriptResult('deleteEntry', '{"structuredContent":{"deleted":false}}');
    }

    public function testARestoreCasePreparesCapturesRunsTheAgentAndRestoresInOrder(): void
    {
        $this->scriptRestoreCaseResults();
        $this->hooksDouble->harnessToolCallArguments = ['spaceKey' => 17];
        $agentEvalRunnerService = $this->buildRunnerWithoutAgentRun();

        $agentEvalRunnerService->runWithRestore($this->buildScope(), AgentEvalCase::fromArray($this->buildRestoreCaseFields()), $this->buildOptions());

        self::assertSame(['createEntry', 'createEntry', 'getEntry', 'restoreEntry', 'rebuildSite', 'deleteEntry', 'deleteEntry'], $this->executorDouble->getExecutedToolNames());
        self::assertSame(['Change the entry title.'], $agentEvalRunnerService->agentRuns);
        self::assertSame([['restoreEntry']], $agentEvalRunnerService->blockedToolNamesPerAgentRun, 'the case block guards the agent run');
        foreach ($this->executorDouble->executedCalls as $executedCall) {
            self::assertSame([], $executedCall['blockedToolNames'], 'the restore calls run with the block lifted: ' . $executedCall['toolName']);
            self::assertSame(17, $executedCall['arguments']['spaceKey']);
        }
        self::assertSame(['spaceKey' => 17, 'entryId' => 9, 'title' => 'Before'], $this->executorDouble->executedCalls[3]['arguments'], 'the revert writes the captured values');
        self::assertSame(['spaceKey' => 17, 'outputFormat' => 'structured', 'entryId' => 102], $this->executorDouble->executedCalls[5]['arguments'], 'the last created is removed first');
        self::assertSame(101, $this->executorDouble->executedCalls[6]['arguments']['entryId']);
        self::assertSame([
            'restore setup createEntry: {"structuredContent":{"entry":{"id":101}}}',
            'restore setup createEntry: {"structuredContent":{"entry":{"id":102}}}',
            'restore capture getEntry: {"entryId":9,"title":"Before"}',
            'restore write restoreEntry: {}',
            'restore after rebuildSite: {}',
            'restore undo deleteEntry: {"structuredContent":{"deleted":true}}',
            'restore undo deleteEntry: [ERROR] {"structuredContent":{"deleted":false}}',
            'restore release: nothing held',
        ], $this->progressLinesRecorder->progressLines);
        self::assertSame(
            ['findOrStoreAgentSpaceForHarnessToolCalls', 'getHarnessToolCallArguments', 'getExternalWriteToolNames', 'getExternalWriteToolNames', 'releaseHostHoldsAfterHarnessCalls'],
            $this->hooksDouble->hookCalls,
            'the space is stored once before the first harness call; the block is resolved for the case and for its agent run'
        );
        $harnessProvenance = $this->executorDouble->executedCalls[0]['provenance'];
        self::assertInstanceOf(AgentToolCallProvenance::class, $harnessProvenance);
        self::assertTrue($harnessProvenance->isEvalHarnessCall);
        self::assertSame(17, $harnessProvenance->agentSpaceId);
        self::assertSame([], MCPToolExecutorService::$blockedToolNames, 'the case block is lifted after the case');
        self::assertSame(5, AuthService::instance()->getAccount()?->id, 'the run works in the name of the scope\'s owner');
    }

    public function testAFailedPreparationStepEndsTheCaseBeforeTheAgentAndRemovesWhatWasCreated(): void
    {
        $this->executorDouble->scriptResult('createEntry', '{"structuredContent":{"entry":{"id":101}}}');
        $this->executorDouble->scriptResult('createEntry', '{"content":[{"text":"quota used up"}]}', true);
        $agentEvalRunnerService = $this->buildRunnerWithoutAgentRun();

        try {
            $agentEvalRunnerService->runWithRestore($this->buildScope(), AgentEvalCase::fromArray($this->buildRestoreCaseFields()), $this->buildOptions());
            self::fail('a failed preparation step must end the case');
        } catch (AgentEvalPreconditionNotEstablishedException $preconditionNotEstablished) {
            self::assertSame("the preparation step 'createEntry' of the case failed, the agent was not run: quota used up", $preconditionNotEstablished->getMessage());
        }
        self::assertSame([], $agentEvalRunnerService->agentRuns);
        self::assertSame(['createEntry', 'createEntry', 'deleteEntry'], $this->executorDouble->getExecutedToolNames());
        self::assertSame(101, $this->executorDouble->executedCalls[2]['arguments']['entryId']);
        self::assertNotContains('releaseHostHoldsAfterHarnessCalls', $this->hooksDouble->hookCalls, 'nothing ran after the preparation');
        self::assertSame([], MCPToolExecutorService::$blockedToolNames);
    }

    public function testACaptureThatLeavesAnIdUnreadEndsTheCase(): void
    {
        $this->executorDouble->scriptResult('createEntry', '{"structuredContent":{"entry":{"id":101}}}');
        $this->executorDouble->scriptResult('createEntry', '{"structuredContent":{"entry":{"id":102}}}');
        $this->executorDouble->scriptResult('getEntry', '{"structuredContent":{"entry":{"title":"Before"}}}');
        $agentEvalRunnerService = $this->buildRunnerWithoutAgentRun();

        try {
            $agentEvalRunnerService->runWithRestore($this->buildScope(), AgentEvalCase::fromArray($this->buildRestoreCaseFields()), $this->buildOptions());
            self::fail('a capture without the id must end the case');
        } catch (RuntimeException $captureFailure) {
            self::assertSame("The capture read 'getEntry' found no entryId, the case was not run.", $captureFailure->getMessage());
        }
        self::assertSame([], $agentEvalRunnerService->agentRuns);
        self::assertSame(['createEntry', 'createEntry', 'getEntry', 'deleteEntry', 'deleteEntry'], $this->executorDouble->getExecutedToolNames());
        self::assertStringStartsWith('restore capture getEntry: [ERROR] no value for entryId — ', $this->progressLinesRecorder->progressLines[2]);
    }

    public function testAnAgentRunThatThrowsIsRestoredAllTheSame(): void
    {
        $this->scriptRestoreCaseResults();
        $agentEvalRunnerService = $this->buildRunnerWithoutAgentRun();
        $agentEvalRunnerService->agentRunErrorMessage = 'model unavailable';

        try {
            $agentEvalRunnerService->runWithRestore($this->buildScope(), AgentEvalCase::fromArray($this->buildRestoreCaseFields()), $this->buildOptions());
            self::fail('the agent run error must reach the caller');
        } catch (RuntimeException $agentRunError) {
            self::assertSame('model unavailable', $agentRunError->getMessage());
        }
        self::assertSame(['createEntry', 'createEntry', 'getEntry', 'restoreEntry', 'rebuildSite', 'deleteEntry', 'deleteEntry'], $this->executorDouble->getExecutedToolNames());
        self::assertContains('releaseHostHoldsAfterHarnessCalls', $this->hooksDouble->hookCalls);
        self::assertSame([], MCPToolExecutorService::$blockedToolNames);
    }

    public function testRunCasePicksTheRestoreOrchestrationOnlyForACaseWithARestoreBlock(): void
    {
        $agentEvalRunnerService = $this->buildRunnerWithoutAgentRun();

        $agentEvalRunnerService->runCase($this->buildScope(), AgentEvalCase::fromArray(['prompt' => 'Read the entries.']), $this->buildOptions());

        self::assertSame(['Read the entries.'], $agentEvalRunnerService->agentRuns);
        self::assertSame([], $this->executorDouble->executedCalls);
        self::assertNotContains('findOrStoreAgentSpaceForHarnessToolCalls', $this->hooksDouble->hookCalls, 'a case without harness calls stores no agent space');
    }

    public function testTheStateCheckLiftsTheCaseBlockForItsReadAndCleanupAndAlwaysCleansUp(): void
    {
        MCPToolExecutorService::$blockedToolNames = ['getEntry', 'deleteEntry'];
        MCPToolExecutorService::$blockedToolSimulatedErrors = ['getEntry' => 'Simulated.'];
        $this->hooksDouble->harnessToolCallArguments = ['spaceKey' => 17];
        $this->executorDouble->scriptResult('getEntry', '{"structuredContent":{"entry":{"title":"A very long title"}}}');
        $this->executorDouble->scriptResult('deleteEntry', '{"structuredContent":{"deleted":true}}');
        $agentEvalStateAfterRunCheck = AgentEvalStateAfterRunCheck::fromStateAfterRunBlock([
            'readTool' => 'getEntry',
            'args' => ['entryId' => 7],
            'fields' => ['entry.title' => ['maxLength' => 5, 'matches' => 'title$', 'notMatches' => 'a/b']],
            'cleanup' => ['writeTool' => 'deleteEntry', 'args' => ['entryId' => 7]],
        ]);

        $failures = (new AgentEvalRunnerService())->evaluateStateAfterRun($this->buildConversation(), $this->buildScope(), $agentEvalStateAfterRunCheck, ['TestPackage'], $this->buildOptions());

        self::assertSame(['stateAfterRun[entry.title].maxLength(5) failed — value: "A very long title" (17 chars)'], $failures);
        self::assertSame(['getEntry', 'deleteEntry'], $this->executorDouble->getExecutedToolNames());
        self::assertSame(['spaceKey' => 17, 'outputFormat' => 'structured', 'entryId' => 7], $this->executorDouble->executedCalls[0]['arguments']);
        self::assertSame([], $this->executorDouble->executedCalls[0]['blockedToolNames'], 'the read runs with the case block lifted');
        self::assertSame([], $this->executorDouble->executedCalls[0]['blockedToolSimulatedErrors']);
        self::assertSame([], $this->executorDouble->executedCalls[1]['blockedToolNames'], 'the cleanup runs with the case block lifted');
        self::assertSame(['getEntry', 'deleteEntry'], MCPToolExecutorService::$blockedToolNames, 'the block is back after the check: the caller lifts it');
        self::assertSame(['getEntry' => 'Simulated.'], MCPToolExecutorService::$blockedToolSimulatedErrors);
        self::assertSame(['restore cleanup deleteEntry: {"structuredContent":{"deleted":true}}'], $this->progressLinesRecorder->progressLines);
    }

    public function testAnUnreadArgumentFailsTheStateCheckWithoutARead(): void
    {
        $agentEvalStateAfterRunCheck = AgentEvalStateAfterRunCheck::fromStateAfterRunBlock([
            'readTool' => 'getEntry',
            'argsFromToolResult' => ['entryId' => ['tool' => 'createEntry', 'regex' => '\\[#(\\d+)']],
            'cleanup' => ['writeTool' => 'deleteEntry', 'argsFromToolResult' => ['entryId' => ['tool' => 'createEntry', 'regex' => '\\[#(\\d+)']]],
        ]);

        $failures = (new AgentEvalRunnerService())->evaluateStateAfterRun($this->buildConversation(), $this->buildScope(), $agentEvalStateAfterRunCheck, ['TestPackage'], $this->buildOptions());

        self::assertSame(["stateAfterRun: could not resolve 'entryId' from the agent's tool results"], $failures);
        self::assertSame([], $this->executorDouble->executedCalls, 'no read, and no cleanup without a target');
        self::assertNotContains('findOrStoreAgentSpaceForHarnessToolCalls', $this->hooksDouble->hookCalls);
    }

    protected function buildMandate(int $mandateId, string $status, ?int $sourceConversationId, ?int $supersededByMandateId = null): AgentMandate
    {
        /** @var AgentMandate $agentMandate */
        $agentMandate = (new ReflectionClass(AgentMandate::class))->newInstanceWithoutConstructor();
        $agentMandate->id = $mandateId;
        $agentMandate->status = $status;
        $agentMandate->sourceConversationId = $sourceConversationId;
        $agentMandate->supersededByMandateId = $supersededByMandateId;
        $agentMandate->actionClass = 'replyToFeedback';
        $agentMandate->validUntil = null;
        return $agentMandate;
    }

    protected function buildSchedule(int $scheduleId, ?int $createdByConversationId): AIConversationSchedule
    {
        /** @var AIConversationSchedule $aIConversationSchedule */
        $aIConversationSchedule = (new ReflectionClass(AIConversationSchedule::class))->newInstanceWithoutConstructor();
        $aIConversationSchedule->id = $scheduleId;
        $aIConversationSchedule->createdByConversationId = $createdByConversationId;
        return $aIConversationSchedule;
    }

    public function testTheTeardownClosesWhatTheTreeCreatedAndReadsTheMandatesOnce(): void
    {
        $agentSpacesServiceDouble = new AgentSpacesServiceWithKnownSpacesDouble();
        $agentSpacesServiceDouble->agentSpacesById[17] = $this->buildStoredAgentSpace(17);
        $this->replaceService(AgentSpacesService::class, $agentSpacesServiceDouble);
        $schedulesServiceDouble = new AIConversationSchedulesServiceWithKnownSchedulesDouble();
        $schedulesServiceDouble->knownSchedules = [$this->buildSchedule(31, 900), $this->buildSchedule(32, 555), $this->buildSchedule(33, 902)];
        $this->replaceService(AIConversationSchedulesService::class, $schedulesServiceDouble);
        $mandatesServiceDouble = new AgentMandatesServiceWithKnownMandatesDouble();
        $mandatesServiceDouble->knownMandates = [
            $this->buildMandate(41, AgentMandate::STATUS_SUPERSEDED, 555, 42),
            $this->buildMandate(42, AgentMandate::STATUS_ACTIVE, 902),
            $this->buildMandate(43, AgentMandate::STATUS_ACTIVE, 555),
        ];
        $this->replaceService(AgentMandatesService::class, $mandatesServiceDouble);
        $this->hooksDouble->hostTeardownLines = ['entry 7 removed'];
        $conversation = $this->buildConversation();
        $conversation->id = 900;
        $conversation->accountId = 5;
        $conversation->agentSpaceId = 17;
        /** @var AIConversationMessage $childMessage */
        $childMessage = (new ReflectionClass(AIConversationMessage::class))->newInstanceWithoutConstructor();
        $childMessage->aiConversationId = 902;
        $conversation->messages->add($childMessage);
        $agentEvalRunOptions = $this->buildOptions();
        $agentEvalRunOptions->recordBuiltConversation($conversation);

        $closedLines = (new AgentEvalRunnerService())->tearDownResidueOfRun($agentEvalRunOptions);

        self::assertSame([31, 33], $schedulesServiceDouble->deletedScheduleIds);
        self::assertSame(1, $mandatesServiceDouble->mandateReads, 'the mandates are read once');
        self::assertSame([['id' => 42, 'status' => AgentMandate::STATUS_REVOKED], ['id' => 41, 'status' => AgentMandate::STATUS_ACTIVE]], $mandatesServiceDouble->mandateWrites);
        self::assertSame([[900, 902]], $this->hooksDouble->conversationIdsOfTreePerHostTeardown);
        self::assertSame([
            'schedule 31 deleted',
            'schedule 33 deleted',
            'mandate 42 replyToFeedback closed',
            'mandate 41 replyToFeedback active again',
            'entry 7 removed',
        ], $closedLines);
        self::assertSame([
            'restore teardown: schedule 31 deleted',
            'restore teardown: schedule 33 deleted',
            'restore teardown: mandate 42 replyToFeedback closed',
            'restore teardown: mandate 41 replyToFeedback active again',
            'restore teardown: entry 7 removed',
        ], $this->progressLinesRecorder->progressLines);
        self::assertSame([], (new AgentEvalRunnerService())->tearDownResidueOfRun($agentEvalRunOptions), 'the built conversation is torn down once');
    }

    public function testAFailedTeardownStepIsReportedAndTheNextOneRuns(): void
    {
        $agentSpacesServiceDouble = new AgentSpacesServiceWithKnownSpacesDouble();
        $agentSpacesServiceDouble->agentSpacesById[17] = $this->buildStoredAgentSpace(17);
        $this->replaceService(AgentSpacesService::class, $agentSpacesServiceDouble);
        $schedulesServiceDouble = new AIConversationSchedulesServiceWithKnownSchedulesDouble();
        $schedulesServiceDouble->readErrorMessage = 'schedules table unreachable';
        $this->replaceService(AIConversationSchedulesService::class, $schedulesServiceDouble);
        $mandatesServiceDouble = new AgentMandatesServiceWithKnownMandatesDouble();
        $this->replaceService(AgentMandatesService::class, $mandatesServiceDouble);
        $conversation = $this->buildConversation();
        $conversation->id = 900;
        $conversation->accountId = 5;
        $conversation->agentSpaceId = 17;

        $closedLines = (new AgentEvalRunnerService())->tearDownResidueOfConversation($conversation, $this->buildOptions());

        self::assertSame(['schedules: schedules table unreachable'], $closedLines);
        self::assertSame(['restore teardown: [ERROR] schedules: schedules table unreachable'], $this->progressLinesRecorder->progressLines);
        self::assertSame(1, $mandatesServiceDouble->mandateReads);
        self::assertContains('tearDownHostResidueOfConversation', $this->hooksDouble->hookCalls);
    }

    public function testATransientConversationLeavesNoMarkAndAConversationWithoutASpaceNothingToClose(): void
    {
        $transientConversation = $this->buildConversation();
        $transientConversation->agentSpaceId = 17;
        $accountLevelConversation = $this->buildConversation();
        $accountLevelConversation->id = 901;
        $agentEvalRunnerService = new AgentEvalRunnerService();

        self::assertSame([], $agentEvalRunnerService->tearDownResidueOfConversation($transientConversation, $this->buildOptions()));
        self::assertSame([], $agentEvalRunnerService->tearDownResidueOfConversation($accountLevelConversation, $this->buildOptions()));
        self::assertSame(['restore teardown: skipped — a transient conversation leaves no mark on what it created'], $this->progressLinesRecorder->progressLines);
        self::assertNotContains('tearDownHostResidueOfConversation', $this->hooksDouble->hookCalls);
    }

    public function testAFailedApplicabilityCheckLeavesTheCaseApplicableAndTheAccountAsItWas(): void
    {
        $previousAccount = new Account();
        $previousAccount->id = 77;
        AuthService::instance()->setAccount($previousAccount);
        $this->hooksDouble->ineligibleScopeErrorMessage = 'eligibility unreadable';

        $notApplicableReason = (new AgentEvalRunnerService())->findNotApplicableReasonForCase($this->buildScope(), AgentEvalCase::fromArray(['id' => 'c']), $this->buildOptions());

        self::assertNull($notApplicableReason);
        self::assertSame([5], $this->hooksDouble->authenticatedAccountIdsDuringEligibilityRead, 'the check reads in the name of the scope\'s owner');
        self::assertSame(77, AuthService::instance()->getAccount()?->id, 'the account authenticated before is authenticated again');
        self::assertSame(['applicability check failed (RuntimeException): eligibility unreadable'], $this->progressLinesRecorder->progressLines);
    }

    public function testAScopeThatCannotBeResolvedLeavesTheCaseApplicableAndIsReportedAsAFailedCheck(): void
    {
        $previousAccount = new Account();
        $previousAccount->id = 77;
        AuthService::instance()->setAccount($previousAccount);

        $notApplicableReason = (new AgentEvalRunnerService())->resolveScopeAndFindNotApplicableReasonForCase(AgentEvalCase::fromArray(['id' => 'c']), null, $this->buildOptions());

        self::assertNull($notApplicableReason);
        self::assertSame([], $this->hooksDouble->authenticatedAccountIdsDuringEligibilityRead, 'without a scope nothing is read');
        self::assertSame(77, AuthService::instance()->getAccount()?->id);
        self::assertSame(
            ["applicability check failed (RuntimeException): The case 'c' names no agent space and the caller gave none."],
            $this->progressLinesRecorder->progressLines
        );
    }

    public function testAResolvedScopeIsCheckedLikeAScopeTheCallerHolds(): void
    {
        $agentSpacesServiceDouble = new AgentSpacesServiceWithKnownSpacesDouble();
        $agentSpacesServiceDouble->agentSpacesById[17] = $this->buildStoredAgentSpace(17);
        $ownerAccount = new Account();
        $ownerAccount->id = 5;
        $agentSpacesServiceDouble->ownerAccountsByAgentSpaceId[17] = $ownerAccount;
        $this->replaceService(AgentSpacesService::class, $agentSpacesServiceDouble);
        $this->hooksDouble->ineligibleScopeReason = 'LOCKED (unpaid)';

        $notApplicableReason = (new AgentEvalRunnerService())->resolveScopeAndFindNotApplicableReasonForCase(AgentEvalCase::fromArray(['id' => 'c']), 17, $this->buildOptions());

        self::assertInstanceOf(AgentEvalNotApplicableReason::class, $notApplicableReason);
        self::assertSame(AgentEvalNotApplicableReason::REASON_SCOPE_NOT_ELIGIBLE, $notApplicableReason->getReason());
        self::assertSame([5], $this->hooksDouble->authenticatedAccountIdsDuringEligibilityRead, 'the caller\'s agent space is the scope');
        self::assertSame([], $this->progressLinesRecorder->progressLines);
    }

    public function testARecallRunStartsItsOwnListOfSeededIds(): void
    {
        $agentEvalRunOptions = $this->buildOptions();
        $agentEvalRunOptions->recordSeededConversationId(99);
        $agentEvalRunnerService = $this->buildRunnerWithoutAgentRun();

        $agentEvalRunOutcome = $agentEvalRunnerService->runCrossConversationRecallWithSeed(
            $this->buildScope(),
            AgentEvalCase::fromArray(['id' => 'recall', 'prompt' => 'What did I tell you about the dogs?']),
            $agentEvalRunOptions
        );

        self::assertSame([[]], $agentEvalRunnerService->seededConversationIdsPerDrive, 'the ids of an earlier call are gone before the drive');
        self::assertSame([], $agentEvalRunOptions->getSeededConversationIds());
        self::assertSame([], $agentEvalRunOutcome->getSeededConversationIds());
    }

    public function testAnIneligibleScopeMakesTheCaseNotApplicableAndTheAccountIsBack(): void
    {
        $noAccount = null;
        AuthService::instance()->setAccount($noAccount);
        $this->hooksDouble->ineligibleScopeReason = 'LOCKED (unpaid)';

        $notApplicableReason = (new AgentEvalRunnerService())->findNotApplicableReasonForCase($this->buildScope(), AgentEvalCase::fromArray(['id' => 'c']), $this->buildOptions());

        self::assertInstanceOf(AgentEvalNotApplicableReason::class, $notApplicableReason);
        self::assertSame(AgentEvalNotApplicableReason::REASON_SCOPE_NOT_ELIGIBLE, $notApplicableReason->getReason());
        self::assertNull(AuthService::instance()->getAccount());
        self::assertSame([], $this->progressLinesRecorder->progressLines);
    }

    public function testATransientConversationIsEvaluatedAsItIs(): void
    {
        $conversation = $this->buildConversation();

        $agentEvalRunOutcome = (new AgentEvalRunnerService())->reloadIfPersisted($conversation);

        self::assertSame($conversation, $agentEvalRunOutcome->getConversation());
        self::assertSame([], $agentEvalRunOutcome->getReloadDiagnostics());
    }

    public function testTheMessagesTraceLeavesOutTheConversationBackReference(): void
    {
        $conversation = $this->buildConversation();
        $conversation->id = 900;
        /** @var AIConversationMessage $ownerMessage */
        $ownerMessage = (new ReflectionClass(AIConversationMessage::class))->newInstanceWithoutConstructor();
        $ownerMessage->role = AIConversationMessage::ROLE_USER;
        $ownerMessage->messageType = AIConversationMessage::MESSAGE_TEXT;
        $ownerMessage->message = 'Hello';
        $ownerMessage->aiConversation = $conversation;
        $conversation->messages->add($ownerMessage);

        $messagesTrace = (new AgentEvalRunnerService())->buildMessagesTraceWithoutConversationBackReference($conversation->messages);

        self::assertIsArray($messagesTrace, 'the serializer gives the set as an array');
        self::assertCount(1, $messagesTrace['elements']);
        self::assertSame('Hello', $messagesTrace['elements'][0]['message']);
        self::assertSame('USER', $messagesTrace['elements'][0]['role']);
        self::assertArrayNotHasKey('aiConversation', $messagesTrace['elements'][0]);
    }
}
