<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\DecisionReplay;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Services\Decisions\AgentDecisionModelCircuitBreakerService;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\AuthService;
use DDD\Symfony\Commands\Agents\AgentConsoleCommand;
use DDD\Symfony\Commands\Agents\AgentDecisionReplayCommand;
use DDD\Symfony\Commands\Agents\DecisionReplay\AgentDecisionReplayRow;
use DDD\Symfony\Commands\Agents\DecisionReplay\AgentDecisionReplayRows;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Tests\Agents\Symfony\Commands\Agents\AgentSpacesServiceConsoleScopeDouble;
use DDD\Tests\Agents\Symfony\Commands\Agents\CountingAuthServiceDouble;
use DDD\Tests\Agents\Symfony\Commands\Agents\RecordingUsageEnvelopesServiceDouble;
use ReflectionClass;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The base of a decision replay: it reads each stored conversation and its messages with the rights lifted, replays
 * every picked tool call once on the messages before it — as the conversation's account, inside an evaluation envelope,
 * with the circuit breaker in its harness mode — prints the rows as one table with the subclass's summary under it and
 * writes them to the result file in the order their fields were set. A conversation without an account is skipped with
 * a warning; a run without conversations fails.
 */
class AgentDecisionReplayCommandTest extends UnitTestCase
{
    public const int OWNER_ACCOUNT_ID = 5;

    protected ?Account $accountBeforeTest = null;

    protected ConversationsServiceWithScriptedConversationsDouble $conversationsServiceDouble;

    protected ConversationMessagesServiceWithScriptedMessagesDouble $conversationMessagesServiceDouble;

    protected RecordingUsageEnvelopesServiceDouble $usageEnvelopesServiceDouble;

    /** @var string[] The files a test wrote; removed in tearDown() */
    protected array $writtenFilePaths = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->accountBeforeTest = AuthService::instance()->getAccount();
        self::setSingletonInstance(AuthService::class, new CountingAuthServiceDouble());
        $this->conversationsServiceDouble = new ConversationsServiceWithScriptedConversationsDouble();
        $this->conversationMessagesServiceDouble = new ConversationMessagesServiceWithScriptedMessagesDouble();
        $this->usageEnvelopesServiceDouble = new RecordingUsageEnvelopesServiceDouble();
    }

    protected function tearDown(): void
    {
        foreach ($this->writtenFilePaths as $writtenFilePath) {
            if (is_file($writtenFilePath)) {
                unlink($writtenFilePath);
            }
        }
        AgentDecisionModelCircuitBreakerService::$harnessMode = false;
        AuthService::instance()->setAccount($this->accountBeforeTest);
        self::resetSingletonInstance(AuthService::class);
        parent::tearDown();
    }

    public function testTheBaseDeclaresTheReplayOptions(): void
    {
        self::assertTrue((new ReflectionClass(AgentDecisionReplayCommand::class))->isAbstract());
        $commandDefinition = $this->buildProbeCommand()->getDefinition();
        self::assertSame(
            [
                AgentDecisionReplayCommand::OPTION_CONVERSATIONS,
                AgentConsoleCommand::OPTION_AGENT_SPACE_ID,
                AgentSpacesServiceConsoleScopeDouble::SCOPE_OPTION_NAME,
                AgentDecisionReplayCommand::OPTION_RESULT_FILE,
                AgentDecisionReplayCommand::OPTION_SHOW_STATE,
                AgentConsoleCommand::OPTION_IGNORE_BUDGET,
            ],
            array_keys($commandDefinition->getOptions())
        );
    }

    public function testEveryPickedCallIsReplayedOnceOnTheMessagesBeforeItAndWritten(): void
    {
        $this->scriptConversation(11, self::OWNER_ACCOUNT_ID, [
            [0, null, []],
            [1, AgentDecisionReplayProbeCommand::REPLAYED_TOOL_NAME, []],
            [2, 'readThing', []],
            [3, AgentDecisionReplayProbeCommand::REPLAYED_TOOL_NAME, ['skipRow' => true]],
            [4, AgentDecisionReplayProbeCommand::REPLAYED_TOOL_NAME, []],
        ]);
        $this->scriptConversation(12, null, [[0, AgentDecisionReplayProbeCommand::REPLAYED_TOOL_NAME, []]]);
        $probeCommand = $this->buildProbeCommand();
        $resultFilePath = (string)tempnam(sys_get_temp_dir(), 'decision-replay-result');
        $this->writtenFilePaths[] = $resultFilePath;

        $commandTester = new CommandTester($probeCommand);
        $exitCode = $commandTester->execute(['--conversations' => '11,12,0,99', '--resultFile' => $resultFilePath]);
        $display = $commandTester->getDisplay();

        self::assertSame(0, $exitCode);
        $ownerAccountId = self::OWNER_ACCOUNT_ID;
        self::assertSame(
            [
                "11:1:envelope=open:account={$ownerAccountId}:harness=on:before=1",
                "11:3:envelope=open:account={$ownerAccountId}:harness=on:before=3",
                "11:4:envelope=open:account={$ownerAccountId}:harness=on:before=4",
            ],
            $probeCommand->observedCalls,
            'each picked call once, on the messages with a lower index; the other tool is not replayed'
        );
        self::assertSame([false, false], $this->conversationMessagesServiceDouble->rightsAppliedPerRead, 'the messages of both stored conversations are read with the rights lifted');
        self::assertTrue(DBEntity::getApplyRightsRestrictions());
        self::assertFalse(AgentDecisionModelCircuitBreakerService::$harnessMode, 'the harness mode of before the run is back');
        self::assertCount(3, $this->usageEnvelopesServiceDouble->openedEnvelopeContexts);
        self::assertCount(3, $this->usageEnvelopesServiceDouble->closedEnvelopeContexts);
        foreach ($this->usageEnvelopesServiceDouble->openedEnvelopeContexts as $openedEnvelopeContext) {
            self::assertSame(AgentAIUsageScope::ENVELOPE_AI_AGENT_EVALUATION, $openedEnvelopeContext->scope);
            self::assertSame($ownerAccountId, $openedEnvelopeContext->accountId);
        }
        self::assertStringContainsString('conversation 12: not found or no account', $display);
        self::assertStringContainsString('conversation 99: not found or no account', $display);
        self::assertMatchesRegularExpression('/^\s*key\s+tool\s+messagesBefore\s+consented\s+failure\s*$/m', $display);
        self::assertMatchesRegularExpression('/^\s*11:1\s+removeThing\s+1\s+no\s*$/m', $display);
        self::assertMatchesRegularExpression('/^\s*11:4\s+removeThing\s+4\s+yes\s*$/m', $display);
        self::assertSame(1, $probeCommand->summaryPrintCount);
        self::assertStringContainsString("replayed: 2\nrows written to {$resultFilePath}", $display);
        $writtenRows = json_decode((string)file_get_contents($resultFilePath), true);
        self::assertSame(
            [
                ['key' => '11:1', 'tool' => 'removeThing', 'messagesBefore' => 1, 'consented' => false, 'failure' => null],
                ['key' => '11:4', 'tool' => 'removeThing', 'messagesBefore' => 4, 'consented' => true, 'failure' => null],
            ],
            $writtenRows
        );
        self::assertNull(AuthService::instance()->getAccount(), 'the run acted as the owner; the account of before the run is authenticated again');
    }

    public function testARunWithoutConversationsFails(): void
    {
        $commandTester = new CommandTester($this->buildProbeCommand());

        self::assertSame(1, $commandTester->execute(['--conversations' => '0,x']));
        self::assertStringContainsString('Pass --conversations.', $commandTester->getDisplay());
        self::assertSame([], $this->usageEnvelopesServiceDouble->openedEnvelopeContexts);
    }

    public function testTheRowsKeepTheirFieldOrderAndTheSetWritesThemAsTheResultFile(): void
    {
        $firstRow = AgentDecisionReplayRow::forKey('a')->setFieldValue('label', true)->setFieldValue('threshold', 0.75);
        $secondRow = AgentDecisionReplayRow::forKey('a')->setFieldValue('label', null);
        $secondRow->setFieldValue(AgentDecisionReplayRow::FIELD_KEY, 'b');
        $agentDecisionReplayRows = AgentDecisionReplayRows::newInstance();
        $agentDecisionReplayRows->add($firstRow, $secondRow);

        self::assertSame(2, $agentDecisionReplayRows->count(), 'rows with the same key are two rows');
        self::assertSame(['key', 'label', 'threshold'], $agentDecisionReplayRows->buildFieldNameHeaders());
        self::assertSame(['a', 'yes', '0.75'], $firstRow->buildTableCells());
        self::assertSame('b', $secondRow->getRowKey());
        self::assertTrue($secondRow->hasField('label'));
        self::assertNull($secondRow->getFieldValue('label'));
        self::assertSame("[\n    {\n        \"key\": \"a\",\n        \"label\": true,\n        \"threshold\": 0.75\n    },\n    {\n        \"key\": \"b\",\n        \"label\": null\n    }\n]", $agentDecisionReplayRows->buildResultFileJson());
    }

    protected function buildProbeCommand(): AgentDecisionReplayProbeCommand
    {
        return new AgentDecisionReplayProbeCommand($this->conversationsServiceDouble, $this->conversationMessagesServiceDouble, $this->usageEnvelopesServiceDouble, new AgentSpacesServiceConsoleScopeDouble());
    }

    /**
     * Scripts a stored conversation and its messages: one message per `[index, tool name or null, arguments]`.
     *
     * @param int $conversationId
     * @param int|null $accountId null = a conversation without an account
     * @param array<int, array{0: int, 1: string|null, 2: array<string, mixed>}> $messageScripts
     */
    protected function scriptConversation(int $conversationId, ?int $accountId, array $messageScripts): void
    {
        $conversation = AIConversation::newInstance();
        $conversation->id = $conversationId;
        $conversation->accountId = $accountId;
        $conversation->account = null;
        if ($accountId !== null) {
            $account = Account::newInstance();
            $account->id = $accountId;
            $conversation->account = $account;
        }
        $agentSpace = AgentSpace::newInstance();
        $agentSpace->id = AgentSpacesServiceConsoleScopeDouble::STORED_AGENT_SPACE_ID;
        $conversation->agentSpace = $agentSpace;
        $aIConversationMessages = AIConversationMessages::newInstance();
        foreach ($messageScripts as [$indexInConversation, $toolName, $toolArguments]) {
            $message = AIConversationMessage::newInstance();
            $message->indexInConversation = $indexInConversation;
            $message->toolCall = null;
            if ($toolName !== null) {
                $toolCall = ToolCall::newInstance();
                $toolCall->name = $toolName;
                $toolCall->arguments = $toolArguments;
                $message->toolCall = $toolCall;
            }
            $aIConversationMessages->add($message);
        }
        $this->conversationsServiceDouble->conversationsById[$conversationId] = $conversation;
        $this->conversationMessagesServiceDouble->messagesByConversationId[$conversationId] = $aIConversationMessages;
    }
}
