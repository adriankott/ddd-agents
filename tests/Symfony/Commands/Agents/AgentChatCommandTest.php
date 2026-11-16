<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Entities\Conversations\Messages\CompactionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRun;
use DDD\Domain\AI\Entities\UsageLogs\AIUsageEnvelopeContext;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\AuthService;
use DDD\Symfony\Commands\Agents\AgentChatCommand;
use DDD\Symfony\Commands\Agents\AgentConsoleCommand;
use DDD\Symfony\Commands\Agents\Chat\AgentConsoleChatRecordedRun;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The chat command of the module, driven against doubles of every service it calls: whom a run talks as, where a new
 * conversation is created, the resume of a stored conversation and the columns written onto it, the budget lift on a
 * stored and on a purposed conversation, the envelopes of the turn and of the memory curation, a failed turn, the side
 * work, the refusals before anything is written, several turns of a file, and a recorded run through the two override
 * points.
 */
class AgentChatCommandTest extends UnitTestCase
{
    /** @var string[] Every option the chat command accepts */
    public const array EXPECTED_OPTION_NAMES = [
        'accountId', 'agentSpaceId', 'conversationId', 'purpose', 'ephemeral', 'message', 'interactive', 'turns-file',
        'async', 'model', 'tier', 'vendor', 'provider', 'no-compact', 'no-side-work', 'debug', 'full', 'collapse-threads',
        'html', 'timings', 'memory', 'context-composition', 'capabilities', 'max-steps', 'max-turn-cost', 'store-snapshot',
        'hint-recent-tool-calls', 'agent-wire-api', 'previous-reasoning-carry-over', 'previous-reasoning-cap-tokens',
        'eager', 'evict-soft', 'evict-hard', 'evict-ref', 'evict-tool-tokens', 'evict-tool-turns', 'evict-skill-tokens',
        'evict-skill-turns', 'evict-min-residency', 'unload-granularity', 'skill-loading-strategy',
        'rebuild-epoch-on-situation-change', 'state-change-delivery', 'compact-trigger-tokens', 'compact-keep-messages',
        'compact-segment-messages', 'compact-fan-in', 'compact-retention', 'ignore-budget',
        'record-run-for-comparison', 'replay-run-and-compare',
    ];

    /** @var string[] The top-level keys of a chat recording, in stored order */
    public const array CHAT_RECORDING_KEYS = [
        'version', 'toolsRunLive', 'recordedConversationId', 'scopeIds', 'idSeed', 'harness', 'agentSpaceId', 'async',
        'turns', 'agentWireApi', 'skillLoadingStrategy', 'previousReasoningCarryOver', 'previousReasoningCapTokens',
        'stateChangeDelivery', 'idNormalization', 'createdIdReadReport', 'calls', 'toolResults', 'snapshot',
    ];

    protected ?Account $accountBeforeTest = null;

    protected AgentChatProbeCommand $probeCommand;

    /** @var string[] temporary files a test wrote */
    protected array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->accountBeforeTest = AuthService::instance()->getAccount();
        self::setSingletonInstance(AuthService::class, new CountingAuthServiceDouble());
        $this->probeCommand = new AgentChatProbeCommand(
            new ScriptedAgentReplyServiceDouble(),
            new RecordingAgentMessagePrinterDouble(),
            new RecordingAgentMemoriesServiceDouble(),
            new RecordingConversationMessagesServiceDouble(),
            new RecordingUsageEnvelopesServiceDouble(),
            new AgentSpacesServiceConsoleScopeDouble(),
            new AccountsServiceWithOneAccountDouble(),
            new DelegationSpawnServiceRecordingContinuationsDouble()
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $temporaryFile) {
            if (is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
        }
        AuthService::instance()->setAccount($this->accountBeforeTest);
        self::resetSingletonInstance(AuthService::class);
        parent::tearDown();
    }

    public function testTheCommandKeepsItsNameRunsOnTheBaseAndAcceptsEveryOption(): void
    {
        $asCommandAttributes = (new ReflectionClass(AgentChatCommand::class))->getAttributes(AsCommand::class);
        self::assertSame('app:ai-agent:chat', $asCommandAttributes[0]->newInstance()->name);
        self::assertInstanceOf(AgentConsoleCommand::class, $this->probeCommand);

        $missingOptionNames = array_values(array_filter(
            self::EXPECTED_OPTION_NAMES,
            fn (string $optionName): bool => !$this->probeCommand->getDefinition()->hasOption($optionName)
        ));
        self::assertSame([], $missingOptionNames);
        self::assertSame('m', $this->probeCommand->getDefinition()->getOption('message')->getShortcut());
        self::assertSame('i', $this->probeCommand->getDefinition()->getOption('interactive')->getShortcut());
    }

    public function testOneEphemeralTurnRunsInsideTheTurnEnvelopeOfTheAccountAndStoresNothing(): void
    {
        $commandTester = new CommandTester($this->probeCommand);
        $exitCode = $commandTester->execute([
            '--accountId' => (string)AccountsServiceWithOneAccountDouble::KNOWN_ACCOUNT_ID,
            '--ephemeral' => true,
            '--model' => 'VENDOR.SOME_MODEL',
            '-m' => "Hello\x07 there",
        ]);
        $display = $commandTester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode, $display);
        self::assertSame([[
            'ownerMessage' => 'Hello there',
            'accountId' => AccountsServiceWithOneAccountDouble::KNOWN_ACCOUNT_ID,
            'aiModelName' => 'VENDOR.SOME_MODEL',
            'full' => false,
            'debug' => false,
            'compactionEnabled' => true,
            'sideWorkEnabled' => true,
        ]], $this->probeCommand->agentReplyServiceDouble->replyRequests);
        self::assertSame([AgentAIUsageScope::ENVELOPE_AI_CONVERSATION_TURN], $this->findOpenedEnvelopeScopes());
        self::assertSame([AccountsServiceWithOneAccountDouble::KNOWN_ACCOUNT_ID], $this->findOpenedEnvelopeAccountIds());
        self::assertCount(1, $this->probeCommand->usageEnvelopesServiceDouble->closedEnvelopeContexts);
        self::assertSame(['Hello there'], $this->probeCommand->agentMessagePrinterDouble->renderedMessageTexts, 'single-shot echoes the owner message');
        self::assertSame([], $this->probeCommand->storedConversations);
        self::assertSame([], $this->probeCommand->conversationMessagesServiceDouble->storedInboundMessageTexts);
        self::assertSame([], $this->probeCommand->agentMemoriesServiceDouble->curations, 'an ephemeral turn curates no memory');
        self::assertStringContainsString('conversation: ephemeral (not persisted)', $display);
        self::assertStringContainsString('--model pin', $display);
        self::assertNull($this->probeCommand->agentReplyServiceDouble->lastConversation?->aiModelNameOverride ?? null, 'an inline turn gets the model as its argument: an ephemeral conversation is not pinned');
    }

    public function testAResumedConversationLoadsItsBoundedTailAndStoresEachOptionAsItsOwnColumn(): void
    {
        $resumableConversation = $this->buildResumableConversation();
        $this->probeCommand->resumableConversation = $resumableConversation;
        $commandTester = new CommandTester($this->probeCommand);

        $exitCode = $commandTester->execute([
            '--accountId' => (string)AccountsServiceWithOneAccountDouble::KNOWN_ACCOUNT_ID,
            '--conversationId' => ' 31 ',
            '--ignore-budget' => true,
            '--model' => 'VENDOR.SOME_MODEL',
            '--compact-keep-messages' => '2',
            '--max-steps' => '5',
            '-m' => 'Hi again',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode, $commandTester->getDisplay());
        self::assertSame([31], $this->probeCommand->resumeLookupIds);
        self::assertSame([31], $this->probeCommand->conversationMessagesServiceDouble->contextTailConversationIds, 'the bounded tail, not the whole history');
        self::assertSame(0, $resumableConversation->lazyMessagesLoadCount);
        self::assertSame([], $this->probeCommand->storedConversations, 'no new conversation beside the resumed one');
        self::assertSame($resumableConversation, $this->probeCommand->agentReplyServiceDouble->lastConversation);
        self::assertSame(
            ['update(0)', 'aiModelNameOverride', 'compactionSettings', 'runSettings'],
            $resumableConversation->recordedWrites,
            'the lift first, then one targeted write per changed column'
        );
        self::assertTrue($resumableConversation->budgetBypass, 'the stored lift reaches the workers of later turns');
        self::assertSame('VENDOR.SOME_MODEL', $resumableConversation->aiModelNameOverride);
        self::assertSame(200, $resumableConversation->compactionSettings?->triggerTokens, 'a --compact-* option merges onto the stored settings');
        self::assertSame(2, $resumableConversation->compactionSettings?->keepRecentMessages);
        self::assertSame(5, $resumableConversation->runSettings?->maxStepsPerTurn);
        self::assertSame(['Hi again'], $this->probeCommand->conversationMessagesServiceDouble->storedInboundMessageTexts);
        self::assertStringContainsString('conversation: 31  (resume: --conversationId=31)', $commandTester->getDisplay());
    }

    public function testAConversationIdThatNamesNoStoredConversationStartsANewOne(): void
    {
        $commandTester = new CommandTester($this->probeCommand);

        self::assertSame(Command::SUCCESS, $commandTester->execute(['--agentSpaceId' => '7', '--conversationId' => '31', '-m' => 'Hi']));
        self::assertSame([31], $this->probeCommand->resumeLookupIds);
        self::assertCount(1, $this->probeCommand->storedConversations);
    }

    public function testIgnoreBudgetMarksThePurposedConversationAsLifted(): void
    {
        $commandTester = new CommandTester($this->probeCommand);

        self::assertSame(Command::SUCCESS, $commandTester->execute(['--agentSpaceId' => '7', '--purpose' => 'Reports.Weekly', '--ignore-budget' => true]));
        $purposedConversation = $this->probeCommand->agentReplyServiceDouble->purposedConversation;
        self::assertNotNull($purposedConversation);
        self::assertTrue($purposedConversation->budgetBypass);
        self::assertSame(['budgetBypass'], $purposedConversation->recordedWrites, 'one targeted write of the lift');
    }

    public function testAFailedTurnIsPrintedAndTheEnvelopeIsClosed(): void
    {
        $this->probeCommand->agentReplyServiceDouble->replyFailureMessage = 'the model is unreachable';
        $commandTester = new CommandTester($this->probeCommand);

        self::assertSame(Command::SUCCESS, $commandTester->execute(['--accountId' => '3', '--ephemeral' => true, '-m' => 'Hi']));
        self::assertStringContainsString('turn failed: the model is unreachable', $commandTester->getDisplay());
        self::assertSame([AgentAIUsageScope::ENVELOPE_AI_CONVERSATION_TURN], $this->findOpenedEnvelopeScopes());
        self::assertCount(1, $this->probeCommand->usageEnvelopesServiceDouble->closedEnvelopeContexts, 'the turn envelope is closed also when the turn throws');
    }

    public function testAStoredTurnInTheOwnersSpaceStoresAndEmbedsTheOwnerMessageAndCuratesTheMemory(): void
    {
        $commandTester = new CommandTester($this->probeCommand);
        $exitCode = $commandTester->execute([
            '--agentSpaceId' => (string)AgentSpacesServiceConsoleScopeDouble::STORED_AGENT_SPACE_ID,
            '-m' => 'Hi',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode, $commandTester->getDisplay());
        $storedConversation = $this->probeCommand->storedConversations[0] ?? null;
        self::assertNotNull($storedConversation);
        self::assertSame(AgentSpacesServiceConsoleScopeDouble::STORED_AGENT_SPACE_ID, $storedConversation->agentSpaceId);
        self::assertSame(AgentSpacesServiceConsoleScopeDouble::OWNER_ACCOUNT_ID, $storedConversation->accountId, 'without --accountId the owner of the space');
        self::assertSame(['Hi'], $this->probeCommand->conversationMessagesServiceDouble->storedInboundMessageTexts);
        self::assertSame([['message' => 'Hi', 'async' => false]], $this->probeCommand->conversationMessagesServiceDouble->embeddings);
        self::assertSame([['conversationId' => AgentChatProbeCommand::STORED_CONVERSATION_ID, 'async' => false]], $this->probeCommand->agentMemoriesServiceDouble->curations);
        self::assertSame(
            [AgentAIUsageScope::ENVELOPE_AI_CONVERSATION_TURN, AgentAIUsageScope::ENVELOPE_AI_MEMORY_CURATE],
            $this->findOpenedEnvelopeScopes()
        );
        self::assertCount(2, $this->probeCommand->usageEnvelopesServiceDouble->closedEnvelopeContexts);
        self::assertStringContainsString('conversation: ' . AgentChatProbeCommand::STORED_CONVERSATION_ID . '  (resume: --conversationId=' . AgentChatProbeCommand::STORED_CONVERSATION_ID . ')', $commandTester->getDisplay());
    }

    public function testNoSideWorkSkipsTheEmbeddingTheCurationAndTheCompaction(): void
    {
        $commandTester = new CommandTester($this->probeCommand);
        $commandTester->execute([
            '--agentSpaceId' => (string)AgentSpacesServiceConsoleScopeDouble::STORED_AGENT_SPACE_ID,
            '--no-side-work' => true,
            '-m' => 'Hi',
        ]);

        self::assertSame(['Hi'], $this->probeCommand->conversationMessagesServiceDouble->storedInboundMessageTexts);
        self::assertSame([], $this->probeCommand->conversationMessagesServiceDouble->embeddings);
        self::assertSame([], $this->probeCommand->agentMemoriesServiceDouble->curations);
        self::assertFalse($this->probeCommand->agentReplyServiceDouble->replyRequests[0]['compactionEnabled']);
        self::assertFalse($this->probeCommand->agentReplyServiceDouble->replyRequests[0]['sideWorkEnabled']);
    }

    public function testAFailedCurationIsPrintedOnlyWithTheMemoryOption(): void
    {
        $this->probeCommand->agentMemoriesServiceDouble->curationFails = true;
        $silentTester = new CommandTester($this->probeCommand);
        self::assertSame(Command::SUCCESS, $silentTester->execute(['--agentSpaceId' => '7', '-m' => 'Hi']));
        self::assertStringNotContainsString('memory curate failed', $silentTester->getDisplay());

        $printingTester = new CommandTester($this->probeCommand);
        self::assertSame(Command::SUCCESS, $printingTester->execute(['--agentSpaceId' => '7', '--memory' => true, '-m' => 'Hi']));
        self::assertStringContainsString('memory curate failed: ' . RecordingAgentMemoriesServiceDouble::CURATION_FAILURE_MESSAGE, $printingTester->getDisplay());
        self::assertSame(
            [AgentAIUsageScope::ENVELOPE_AI_CONVERSATION_TURN, AgentAIUsageScope::ENVELOPE_AI_MEMORY_CURATE, AgentAIUsageScope::ENVELOPE_AI_CONVERSATION_TURN, AgentAIUsageScope::ENVELOPE_AI_MEMORY_CURATE],
            $this->findOpenedEnvelopeScopes()
        );
        self::assertCount(4, $this->probeCommand->usageEnvelopesServiceDouble->closedEnvelopeContexts, 'the curation envelope is closed also when the curation throws');
    }

    public function testWithoutAccountAndSpaceTheRunIsInvalid(): void
    {
        $commandTester = new CommandTester($this->probeCommand);

        self::assertSame(Command::INVALID, $commandTester->execute(['-m' => 'Hi']));
        self::assertStringContainsString('Provide --accountId or --agentSpaceId or --' . AgentSpacesServiceConsoleScopeDouble::SCOPE_OPTION_NAME . ' (the account is the owner of the space when --accountId is omitted)', $commandTester->getDisplay());
        self::assertSame([], $this->probeCommand->agentReplyServiceDouble->replyRequests);
    }

    public function testAnUnknownAccountFails(): void
    {
        $commandTester = new CommandTester($this->probeCommand);

        self::assertSame(Command::FAILURE, $commandTester->execute(['--accountId' => '99', '-m' => 'Hi']));
        self::assertStringContainsString('Account 99 not found', $commandTester->getDisplay());
    }

    public function testARefusedSettingsValueLeavesNoConversationBehind(): void
    {
        $commandTester = new CommandTester($this->probeCommand);
        try {
            $commandTester->execute(['--agentSpaceId' => '7', '--compact-fan-in' => '1', '-m' => 'Hi']);
            self::fail('a fan-in below its minimum is refused');
        } catch (InvalidOptionException $invalidOptionException) {
            self::assertStringContainsString('--compact-fan-in', $invalidOptionException->getMessage());
        }
        self::assertSame([], $this->probeCommand->storedConversations);
        self::assertSame([], $this->probeCommand->agentReplyServiceDouble->replyRequests);
    }

    public function testAnUnreadableReasoningCapIsRefusedBeforeAnythingIsWritten(): void
    {
        $commandTester = new CommandTester($this->probeCommand);
        $this->expectException(InvalidOptionException::class);
        try {
            $commandTester->execute(['--agentSpaceId' => '7', '--previous-reasoning-cap-tokens' => 'lots', '-m' => 'Hi']);
        } finally {
            self::assertSame([], $this->probeCommand->storedConversations);
        }
    }

    public function testTheWorkerPathNeedsAStoredConversation(): void
    {
        $commandTester = new CommandTester($this->probeCommand);

        self::assertSame(Command::INVALID, $commandTester->execute(['--accountId' => '3', '--ephemeral' => true, '--async' => true, '-m' => 'Hi']));
        self::assertStringContainsString('--async needs a persisted conversation', $commandTester->getDisplay());
        self::assertSame([], $this->probeCommand->agentReplyServiceDouble->dispatchedConversationIds);
    }

    public function testTheWorkerPathOpensTheContinuationAndDispatchesInsteadOfDrivingInline(): void
    {
        $commandTester = new CommandTester($this->probeCommand);

        self::assertSame(Command::SUCCESS, $commandTester->execute(['--agentSpaceId' => '7', '--async' => true, '-m' => 'Hi']));
        self::assertSame([AgentChatProbeCommand::STORED_CONVERSATION_ID], $this->probeCommand->agentReplyServiceDouble->dispatchedConversationIds);
        self::assertSame(
            [['conversationId' => AgentChatProbeCommand::STORED_CONVERSATION_ID, 'openingMessage' => 'Hi', 'continuedBy' => DelegationSubAgentRun::CONTINUED_BY_CLI]],
            $this->probeCommand->delegationSpawnServiceDouble->continuationRequests
        );
        self::assertSame([], $this->probeCommand->agentReplyServiceDouble->replyRequests);
        self::assertSame([], $this->probeCommand->agentMemoriesServiceDouble->curations, 'the worker curates, not the console');
    }

    public function testThePurposeNeedsASpace(): void
    {
        $commandTester = new CommandTester($this->probeCommand);

        self::assertSame(Command::INVALID, $commandTester->execute(['--accountId' => '3', '--purpose' => 'Reports.Weekly']));
        self::assertStringContainsString('--purpose needs --agentSpaceId', $commandTester->getDisplay());
        self::assertSame([], $this->probeCommand->agentReplyServiceDouble->purposedStarts);
    }

    public function testThePurposeStartsThePurposedConversationOfTheSpaceAndPrintsItsId(): void
    {
        $commandTester = new CommandTester($this->probeCommand);

        self::assertSame(Command::SUCCESS, $commandTester->execute(['--agentSpaceId' => '7', '--purpose' => 'Reports.Weekly', '--model' => 'VENDOR.SOME_MODEL']));
        self::assertSame(
            [['agentSpaceId' => AgentSpacesServiceConsoleScopeDouble::STORED_AGENT_SPACE_ID, 'purposeName' => 'Reports.Weekly', 'async' => false, 'aiModelName' => 'VENDOR.SOME_MODEL']],
            $this->probeCommand->agentReplyServiceDouble->purposedStarts
        );
        self::assertStringContainsString('conversation: 77  (resume: --conversationId=77)', $commandTester->getDisplay());
        self::assertSame([], $this->probeCommand->storedConversations, 'no chat conversation is created beside the purposed one');
    }

    public function testATurnsFileDrivesEveryNonEmptyLineOnTheStoredConversation(): void
    {
        $turnsFile = $this->writeTemporaryFile("First question\n\n  Second question  \n");
        $commandTester = new CommandTester($this->probeCommand);

        self::assertSame(Command::SUCCESS, $commandTester->execute(['--agentSpaceId' => '7', '--turns-file' => $turnsFile, '--full' => true, '--async' => true]));
        self::assertSame(['First question', 'Second question'], array_column($this->probeCommand->agentReplyServiceDouble->replyRequests, 'ownerMessage'));
        self::assertSame([false, false], array_column($this->probeCommand->agentReplyServiceDouble->replyRequests, 'full'), 'several turns print truncated');
        self::assertSame([], $this->probeCommand->agentReplyServiceDouble->dispatchedConversationIds, 'a turns file drives inline');
        self::assertCount(2, $this->probeCommand->agentMemoriesServiceDouble->curations);
    }

    public function testATurnsFileWithOnlyBlankLinesIsRefused(): void
    {
        $turnsFile = $this->writeTemporaryFile("   \n\t\n");
        $commandTester = new CommandTester($this->probeCommand);

        self::assertSame(Command::INVALID, $commandTester->execute(['--agentSpaceId' => '7', '--turns-file' => $turnsFile]));
        self::assertStringContainsString('--turns-file is missing or empty', $commandTester->getDisplay());
        self::assertSame([], $this->probeCommand->agentReplyServiceDouble->replyRequests);
    }

    public function testATurnsFileNeedsAStoredConversation(): void
    {
        $turnsFile = $this->writeTemporaryFile("First question\n");
        $commandTester = new CommandTester($this->probeCommand);

        self::assertSame(Command::INVALID, $commandTester->execute(['--accountId' => '3', '--ephemeral' => true, '--turns-file' => $turnsFile]));
        self::assertStringContainsString('need a persisted conversation', $commandTester->getDisplay());
        self::assertSame([], $this->probeCommand->agentReplyServiceDouble->replyRequests);
    }

    public function testARecordedRunDrivesItsOwnTurnsUnderTheSessionAndHandsTheRunToTheFinish(): void
    {
        $sessionDouble = new RecordingAgentRecordedRunSessionDouble();
        $sessionDouble->messagesRunPerCall = 1;
        $this->probeCommand->scriptedRecordedRun = AgentConsoleChatRecordedRun::forSession($sessionDouble, ['one', 'two'], true);
        $this->probeCommand->finishExitCode = Command::FAILURE;
        $commandTester = new CommandTester($this->probeCommand);

        self::assertSame(Command::FAILURE, $commandTester->execute(['--agentSpaceId' => '7']), 'the finish decides the exit code');
        self::assertSame(
            ['start', 'beginTurn(1)', 'runQueuedMessages', 'beginTurn(2)', 'runQueuedMessages', 'runQueuedMessages', 'stop(false)'],
            $sessionDouble->calls
        );
        self::assertSame([AgentChatProbeCommand::STORED_CONVERSATION_ID, AgentChatProbeCommand::STORED_CONVERSATION_ID], $this->probeCommand->agentReplyServiceDouble->dispatchedConversationIds, 'the recording\'s worker path drives every turn');
        self::assertSame([AgentChatProbeCommand::STORED_CONVERSATION_ID, AgentChatProbeCommand::STORED_CONVERSATION_ID, AgentChatProbeCommand::STORED_CONVERSATION_ID], $this->probeCommand->storedConversationReads, 'after each turn that ran messages and once at the end');
        self::assertSame([$this->probeCommand->scriptedRecordedRun], $this->probeCommand->finishedRecordedRuns);
    }

    public function testARecordedRunStopsItsSessionWithoutRunningTheQueueWhenATurnFails(): void
    {
        $sessionDouble = new RecordingAgentRecordedRunSessionDouble();
        $sessionDouble->runningQueuedMessagesFails = true;
        $this->probeCommand->scriptedRecordedRun = AgentConsoleChatRecordedRun::forSession($sessionDouble, ['one', 'two'], false);
        $commandTester = new CommandTester($this->probeCommand);
        try {
            $commandTester->execute(['--agentSpaceId' => '7']);
            self::fail('the failure of the queue reaches the caller');
        } catch (RuntimeException $runtimeException) {
            self::assertSame(RecordingAgentRecordedRunSessionDouble::QUEUE_FAILURE_MESSAGE, $runtimeException->getMessage());
        }
        self::assertSame(['start', 'beginTurn(1)', 'runQueuedMessages', 'stop(false)'], $sessionDouble->calls);
        self::assertSame([], $this->probeCommand->finishedRecordedRuns);
    }

    public function testARefusedRecordedRunIsInvalidAndDrivesNoTurn(): void
    {
        $this->probeCommand->scriptedRecordedRun = AgentConsoleChatRecordedRun::refused('recording.json: recorded under other settings');
        $commandTester = new CommandTester($this->probeCommand);

        self::assertSame(Command::INVALID, $commandTester->execute(['--agentSpaceId' => '7']));
        self::assertStringContainsString('recording.json: recorded under other settings', $commandTester->getDisplay());
        self::assertSame([], $this->probeCommand->agentReplyServiceDouble->replyRequests);
    }

    public function testARunIsRecordedThroughTheOptionsAndItsReplayIsIdentical(): void
    {
        $turnsFile = $this->writeTemporaryFile("First question\nSecond question\n");
        $recordingFile = $this->writeTemporaryFile('');
        unlink($recordingFile);
        $this->probeCommand->recordedRunThroughCommand = true;
        $recordTester = new CommandTester($this->probeCommand);

        self::assertSame(Command::SUCCESS, $recordTester->execute(['--agentSpaceId' => '7', '--turns-file' => $turnsFile, '--record-run-for-comparison' => $recordingFile]), $recordTester->getDisplay());
        $storedRecording = json_decode((string)file_get_contents($recordingFile), true);
        self::assertSame(self::CHAT_RECORDING_KEYS, array_keys($storedRecording), 'the stored keys keep their order');
        self::assertSame(3, $storedRecording['version']);
        self::assertSame('app:ai-agent:chat', $storedRecording['harness']);
        self::assertSame(7, $storedRecording['agentSpaceId']);
        self::assertSame(['First question', 'Second question'], $storedRecording['turns']);
        self::assertSame(['agentSpace' => 7, 'rootConversation' => AgentChatProbeCommand::STORED_CONVERSATION_ID], $storedRecording['scopeIds']);
        self::assertSame(16, strlen($storedRecording['idSeed']), 'a recording draws its id seed');
        self::assertStringContainsString('record/replay alone on the account — account-scoped tables: agentNotes (accountId)', $recordTester->getDisplay());
        self::assertStringContainsString("run recording for comparison: 2 turns, 0 Argus calls, 1 conversations, 0 dispatches → {$recordingFile}", $recordTester->getDisplay());
        self::assertSame(
            ['First question', 'Second question'],
            array_column($this->probeCommand->agentReplyServiceDouble->replyRequests, 'ownerMessage'),
            'the turns of the file ran inline'
        );

        $replayTester = new CommandTester($this->probeCommand);
        self::assertSame(Command::SUCCESS, $replayTester->execute(['--agentSpaceId' => '7', '--replay-run-and-compare' => $recordingFile]), $replayTester->getDisplay());
        self::assertStringContainsString('recorded-run comparison IDENTICAL: 2 turns, 0 Argus calls, 1 conversations, 0 dispatches (conversation ' . AgentChatProbeCommand::STORED_CONVERSATION_ID . ')', $replayTester->getDisplay());
        self::assertCount(4, $this->probeCommand->agentReplyServiceDouble->replyRequests, 'the replay drove the recorded turns');
    }

    public function testAReplayIsRefusedForAnotherHarnessAndForADifferingSpace(): void
    {
        $this->probeCommand->recordedRunThroughCommand = true;
        $recordingOfAnotherHarness = $this->writeTemporaryFile((string)json_encode(['version' => 3, 'harness' => 'app:other:harness', 'calls' => [], 'snapshot' => [], 'idNormalization' => []]));
        $commandTester = new CommandTester($this->probeCommand);

        self::assertSame(Command::INVALID, $commandTester->execute(['--agentSpaceId' => '7', '--replay-run-and-compare' => $recordingOfAnotherHarness]));
        self::assertStringContainsString("{$recordingOfAnotherHarness}: the recording comes from app:other:harness, not from app:ai-agent:chat", $commandTester->getDisplay());

        $recordingOfAnotherSpace = $this->writeTemporaryFile((string)json_encode(['version' => 3, 'harness' => 'app:ai-agent:chat', 'agentSpaceId' => 9, 'turns' => ['Hello'], 'calls' => [], 'snapshot' => [], 'idNormalization' => []]));
        self::assertSame(Command::INVALID, $commandTester->execute(['--agentSpaceId' => '7', '--replay-run-and-compare' => $recordingOfAnotherSpace]));
        self::assertStringContainsString('agentSpaceId differs from the recording (recorded 9, passed 7) — omit it or pass the recorded value', $commandTester->getDisplay());
        self::assertSame([], $this->probeCommand->agentReplyServiceDouble->replyRequests, 'a refused replay drives no turn');
    }

    public function testARecordingNeedsATurnsFileAndAReplayAReadableRecording(): void
    {
        $this->probeCommand->recordedRunThroughCommand = true;
        $commandTester = new CommandTester($this->probeCommand);

        self::assertSame(Command::INVALID, $commandTester->execute(['--agentSpaceId' => '7', '--record-run-for-comparison' => sys_get_temp_dir() . '/never-written.json']));
        self::assertStringContainsString('--turns-file is missing or empty', $commandTester->getDisplay());

        $missingRecording = sys_get_temp_dir() . '/no-such-recording-' . bin2hex(random_bytes(4)) . '.json';
        self::assertSame(Command::INVALID, $commandTester->execute(['--agentSpaceId' => '7', '--replay-run-and-compare' => $missingRecording]));
        self::assertStringContainsString("{$missingRecording} cannot be read", $commandTester->getDisplay());
    }

    public function testTheSettingsOptionsAreWrittenOntoAnEphemeralConversation(): void
    {
        $commandTester = new CommandTester($this->probeCommand);
        self::assertSame(Command::SUCCESS, $commandTester->execute([
            '--accountId' => '3',
            '--ephemeral' => true,
            '--compact-trigger-tokens' => '200',
            '--max-steps' => '30',
            '--store-snapshot' => true,
            '--eager' => true,
            '--evict-soft' => '1000',
            '-m' => 'Hi',
        ]));

        $conversation = $this->probeCommand->agentReplyServiceDouble->lastConversation;
        self::assertNotNull($conversation);
        self::assertSame(200, $conversation->compactionSettings->triggerTokens);
        self::assertSame(30, $conversation->runSettings->maxStepsPerTurn);
        self::assertTrue($conversation->contextRetentionSettings->storeRequestSnapshot);
        self::assertTrue($conversation->contextRetentionSettings->loadToolsOnSkillLoad);
        self::assertSame(1000, $conversation->contextRetentionSettings->pressureBudgetTokens);
    }

    public function testTheInteractiveRegistryOffersHelpAndCopy(): void
    {
        $slashCommandRegistry = self::invokeMethod($this->probeCommand, 'buildSlashCommandRegistry');

        self::assertSame(['help', 'copy'], $slashCommandRegistry->commands()->getNames());
    }

    /** @return StoredConversationRecordingWritesDouble conversation 31 of the account, stored with a compaction trigger of 200 tokens */
    protected function buildResumableConversation(): StoredConversationRecordingWritesDouble
    {
        $resumableConversation = new StoredConversationRecordingWritesDouble();
        $resumableConversation->id = 31;
        $resumableConversation->accountId = AccountsServiceWithOneAccountDouble::KNOWN_ACCOUNT_ID;
        $resumableConversation->budgetBypass = false;
        $storedCompactionSettings = CompactionSettings::newInstance();
        $storedCompactionSettings->triggerTokens = 200;
        $resumableConversation->compactionSettings = $storedCompactionSettings;
        return $resumableConversation;
    }

    /** @return string[] the scopes of the envelopes opened, in order */
    protected function findOpenedEnvelopeScopes(): array
    {
        return array_map(
            static fn (AIUsageEnvelopeContext $envelopeContext): string => (string)$envelopeContext->scope,
            $this->probeCommand->usageEnvelopesServiceDouble->openedEnvelopeContexts
        );
    }

    /** @return array<int, int|null> the accounts of the envelopes opened, in order */
    protected function findOpenedEnvelopeAccountIds(): array
    {
        return array_map(
            static fn (AIUsageEnvelopeContext $envelopeContext): ?int => $envelopeContext->accountId,
            $this->probeCommand->usageEnvelopesServiceDouble->openedEnvelopeContexts
        );
    }

    protected function writeTemporaryFile(string $content): string
    {
        $temporaryFile = (string)tempnam(sys_get_temp_dir(), 'agent-chat-turns-');
        file_put_contents($temporaryFile, $content);
        $this->temporaryFiles[] = $temporaryFile;
        return $temporaryFile;
    }
}
