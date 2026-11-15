<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNodes;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Entities\UsageLogs\AIUsageEnvelopeContext;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\AuthService;
use DDD\Presentation\Mcp\Agent\Tui\ConsoleViewBlock;
use DDD\Presentation\Mcp\Agent\Tui\ConsoleViewModel;
use DDD\Symfony\Commands\Agents\AgentChatCommand;
use DDD\Symfony\Commands\Agents\AgentChatTuiCommand;
use DDD\Symfony\Commands\Agents\AgentConsoleCommand;
use DDD\Symfony\Commands\Agents\Chat\AgentConsoleChatScopeTrait;
use DDD\Symfony\Commands\Agents\Chat\ChatScreenState;
use DDD\Tests\Agents\Support\UnitTestCase;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;
use PhpTui\Term\MouseButton;
use PhpTui\Term\MouseEventKind;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;
use stdClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

/**
 * The full-screen chat: its name and options (two of them accepted and ignored), the line it ends with when the
 * terminal library is missing — also in a console whose class loader cannot load the library —, the account and
 * the space it works in, the resume and the turn envelope (the line chat's rules, one copy of them), the bounded load
 * of a resumed conversation, a failed turn as an error row, and what keys and mouse events do to the screen.
 */
class AgentChatTuiCommandTest extends UnitTestCase
{
    /** @var int The height of the transcript in the key and mouse tests */
    public const int TRANSCRIPT_HEIGHT = 10;

    protected ?Account $accountBeforeTest = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accountBeforeTest = AuthService::instance()->getAccount();
    }

    protected function tearDown(): void
    {
        AuthService::instance()->setAccount($this->accountBeforeTest);
        parent::tearDown();
    }

    public function testTheCommandKeepsItsNameAndItsOptions(): void
    {
        $asCommandAttributes = (new ReflectionClass(AgentChatTuiCommand::class))->getAttributes(AsCommand::class);
        self::assertSame('app:ai-agent:chat-tui', $asCommandAttributes[0]->newInstance()->name);

        $chatTuiProbeCommand = $this->buildProbeCommand();
        self::assertInstanceOf(AgentConsoleCommand::class, $chatTuiProbeCommand);
        $commandDefinition = $chatTuiProbeCommand->getDefinition();
        foreach (['accountId', AgentConsoleCommand::OPTION_AGENT_SPACE_ID, AgentSpacesServiceConsoleScopeDouble::SCOPE_OPTION_NAME, 'message', 'conversationId', 'ephemeral', AgentConsoleCommand::OPTION_IGNORE_BUDGET, 'full', 'collapse-threads', 'debug', 'html', 'model', 'tier', 'vendor'] as $optionName) {
            self::assertTrue($commandDefinition->hasOption($optionName), $optionName);
        }
        self::assertSame('m', $commandDefinition->getOption('message')->getShortcut());
    }

    public function testWithoutTheTerminalLibraryTheRunEndsWithALineNamingIt(): void
    {
        $chatTuiProbeCommand = $this->buildProbeCommand(terminalLibraryInstalled: false);
        $commandTester = new CommandTester($chatTuiProbeCommand);

        $exitCode = $commandTester->execute(['--' . AgentConsoleCommand::OPTION_AGENT_SPACE_ID => (string)AgentSpacesServiceConsoleScopeDouble::STORED_AGENT_SPACE_ID]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame(
            'The full-screen chat needs the library php-tui/php-tui (composer require php-tui/php-tui).',
            trim($commandTester->getDisplay())
        );
        self::assertNull($chatTuiProbeCommand->screenLoopConversation, 'nothing is resolved or created before the check');
    }

    public function testTheTwoOptionsKeptForScriptsAreAcceptedAndChangeNothing(): void
    {
        $chatTuiProbeCommand = $this->buildProbeCommand();
        $commandTester = new CommandTester($chatTuiProbeCommand);

        $exitCode = $commandTester->execute([
            '--' . AgentConsoleCommand::OPTION_AGENT_SPACE_ID => (string)AgentSpacesServiceConsoleScopeDouble::STORED_AGENT_SPACE_ID,
            '--ephemeral' => true,
            '--collapse-threads' => true,
            '--debug' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame('', $commandTester->getDisplay());
        self::assertNotNull($chatTuiProbeCommand->screenLoopConversation);
    }

    public function testTheLibraryClassesAppearInMethodBodiesOnly(): void
    {
        $commandReflection = new ReflectionClass(AgentChatTuiCommand::class);
        $libraryTypeNames = [];
        foreach ($commandReflection->getMethods() as $reflectionMethod) {
            foreach ($this->findTypeNamesOfSignature($reflectionMethod) as $typeName) {
                if (str_starts_with($typeName, 'PhpTui\\')) {
                    $libraryTypeNames[] = $reflectionMethod->getName() . ': ' . $typeName;
                }
            }
        }
        foreach ($commandReflection->getProperties() as $reflectionProperty) {
            $propertyType = $reflectionProperty->getType();
            if ($propertyType instanceof ReflectionNamedType && str_starts_with($propertyType->getName(), 'PhpTui\\')) {
                $libraryTypeNames[] = '$' . $reflectionProperty->getName() . ': ' . $propertyType->getName();
            }
        }

        self::assertSame([], $libraryTypeNames);
    }

    public function testAConsoleThatCannotLoadTheLibraryListsTheCommandAndRunsItToTheLineNamingTheLibrary(): void
    {
        $listProcess = $this->runConsoleWithoutTerminalLibrary('list', '--raw');
        self::assertSame(0, $listProcess->getExitCode(), $listProcess->getErrorOutput());
        self::assertMatchesRegularExpression('/^app:ai-agent:chat-tui\s/m', $listProcess->getOutput());

        $chatTuiProcess = $this->runConsoleWithoutTerminalLibrary('app:ai-agent:chat-tui', '--' . AgentConsoleCommand::OPTION_AGENT_SPACE_ID . '=1');
        self::assertSame(Command::FAILURE, $chatTuiProcess->getExitCode(), $chatTuiProcess->getErrorOutput());
        self::assertStringContainsString('needs the library php-tui/php-tui', $chatTuiProcess->getOutput());
    }

    public function testWithoutAnAccountOrASpaceTheRunNamesTheOptions(): void
    {
        $chatTuiProbeCommand = $this->buildProbeCommand();
        $commandTester = new CommandTester($chatTuiProbeCommand);

        self::assertSame(Command::FAILURE, $commandTester->execute([]), 'the full-screen chat ends every refusal with FAILURE');
        self::assertSame(
            'Provide --accountId or --agentSpaceId or --' . AgentSpacesServiceConsoleScopeDouble::SCOPE_OPTION_NAME . ' (the account is the owner of the space when --accountId is omitted)',
            trim($commandTester->getDisplay())
        );
        self::assertNull($chatTuiProbeCommand->screenLoopConversation);
    }

    public function testAnUnknownAccountEndsTheRunBeforeAnyConversation(): void
    {
        $chatTuiProbeCommand = $this->buildProbeCommand(accountsServiceDouble: new AccountsServiceWithOneAccountDouble());
        $commandTester = new CommandTester($chatTuiProbeCommand);

        self::assertSame(Command::FAILURE, $commandTester->execute(['--accountId' => '99', '--ephemeral' => true]));
        self::assertSame('Account 99 not found', trim($commandTester->getDisplay()));
        self::assertNull($chatTuiProbeCommand->screenLoopConversation);
    }

    public function testTheAccountOfTheOptionWorksInTheNamedSpaceWithTheStampOfEveryNewConversation(): void
    {
        $chatTuiProbeCommand = $this->buildProbeCommand(accountsServiceDouble: new AccountsServiceWithOneAccountDouble());
        $commandTester = new CommandTester($chatTuiProbeCommand);

        $exitCode = $commandTester->execute([
            '--accountId' => (string)AccountsServiceWithOneAccountDouble::KNOWN_ACCOUNT_ID,
            '--' . AgentConsoleCommand::OPTION_AGENT_SPACE_ID => (string)AgentSpacesServiceConsoleScopeDouble::STORED_AGENT_SPACE_ID,
            '--ephemeral' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $conversation = $chatTuiProbeCommand->screenLoopConversation;
        self::assertNotNull($conversation);
        self::assertSame(AccountsServiceWithOneAccountDouble::KNOWN_ACCOUNT_ID, (int)$conversation->accountId, '--accountId wins over the owner of the space');
        self::assertSame(AgentSpacesServiceConsoleScopeDouble::STORED_AGENT_SPACE_ID, (int)$conversation->agentSpaceId);
        self::assertNotNull($conversation->contextRetentionSettings?->skillLoadingStrategy, 'stamped as on every creation path');
    }

    public function testTheLineChatAndTheFullScreenChatShareOneScopeResolution(): void
    {
        $sharedMethodNames = [
            'resolveChatScope', 'describeAgentSpaceOptions', 'createConversationInAgentSpace', 'loadStateOfResumedConversation',
            'findOrCreateConversation', 'findStoredConversation', 'readIdOption', 'generateAgentReplyInsideTurnEnvelope',
            'getAIConversationAgentService', 'getAIConversationsService',
        ];
        foreach ([AgentChatCommand::class, AgentChatTuiCommand::class] as $chatCommandClass) {
            self::assertContains(AgentConsoleChatScopeTrait::class, class_uses($chatCommandClass), $chatCommandClass);
            foreach ($sharedMethodNames as $sharedMethodName) {
                self::assertSame(
                    AgentConsoleChatScopeTrait::class,
                    $this->findDeclaringTraitOfMethod($chatCommandClass, $sharedMethodName),
                    $chatCommandClass . '::' . $sharedMethodName . ' comes from the shared trait, not from a copy'
                );
            }
        }
    }

    public function testATurnRunsInsideTheTurnEnvelopeAndAFailedTurnBecomesAnErrorRow(): void
    {
        $chatTuiProbeCommand = $this->buildProbeCommand();
        $conversation = $this->buildConversation();

        self::invokeMethod($chatTuiProbeCommand, 'sendTurn', [$conversation, 'When do you open?', 'MODEL.X']);

        self::assertSame([[
            'ownerMessage' => 'When do you open?',
            'accountId' => AgentSpacesServiceConsoleScopeDouble::OWNER_ACCOUNT_ID,
            'aiModelName' => 'MODEL.X',
            'full' => false,
            'debug' => false,
            'compactionEnabled' => true,
            'sideWorkEnabled' => true,
        ]], $chatTuiProbeCommand->agentReplyServiceDouble->replyRequests);
        self::assertSame([AgentAIUsageScope::ENVELOPE_AI_CONVERSATION_TURN], $this->findOpenedEnvelopeScopes($chatTuiProbeCommand));
        self::assertSame([AgentSpacesServiceConsoleScopeDouble::OWNER_ACCOUNT_ID], array_map(
            static fn (AIUsageEnvelopeContext $envelopeContext): ?int => $envelopeContext->accountId,
            $chatTuiProbeCommand->usageEnvelopesServiceDouble->openedEnvelopeContexts
        ));
        self::assertCount(1, $chatTuiProbeCommand->usageEnvelopesServiceDouble->closedEnvelopeContexts);
        self::assertSame(ScriptedAgentReplyServiceDouble::REPLY_TEXT, $conversation->messages?->last()?->message);

        $chatTuiProbeCommand->agentReplyServiceDouble->replyFailureMessage = 'the model is unreachable';
        self::invokeMethod($chatTuiProbeCommand, 'sendTurn', [$conversation, 'And on Sundays?', null]);

        $errorRow = $conversation->messages?->last();
        self::assertSame(AIConversationMessage::MESSAGE_ERROR, $errorRow?->messageType);
        self::assertSame('turn failed: the model is unreachable', $errorRow?->message);
        self::assertCount(2, $chatTuiProbeCommand->usageEnvelopesServiceDouble->closedEnvelopeContexts, 'the envelope is closed also when the turn throws');
        self::assertSame(['When do you open?', 'And on Sundays?'], $chatTuiProbeCommand->findTurnMessageTexts());
    }

    public function testANewConversationWorksAsTheOwnerOfTheSpaceAndSendsTheOpeningMessage(): void
    {
        $chatTuiProbeCommand = $this->buildProbeCommand();
        $commandTester = new CommandTester($chatTuiProbeCommand);

        $exitCode = $commandTester->execute([
            '--' . AgentSpacesServiceConsoleScopeDouble::SCOPE_OPTION_NAME => AgentSpacesServiceConsoleScopeDouble::KNOWN_SCOPE_OPTION_VALUE,
            '--ephemeral' => true,
            '--full' => true,
            '-m' => '  When do you open?  ',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $conversation = $chatTuiProbeCommand->screenLoopConversation;
        self::assertNotNull($conversation);
        self::assertSame(AgentSpacesServiceConsoleScopeDouble::OWNER_ACCOUNT_ID, (int)$conversation->accountId);
        self::assertSame(AgentSpacesServiceConsoleScopeDouble::AGENT_SPACE_ID_OF_KNOWN_SCOPE_OPTION_VALUE, (int)$conversation->agentSpaceId);
        self::assertSame(AgentSpacesServiceConsoleScopeDouble::AGENT_SPACE_ID_OF_KNOWN_SCOPE_OPTION_VALUE, (int)$conversation->agentSpace?->id);
        self::assertSame(['When do you open?'], $chatTuiProbeCommand->findTurnMessageTexts());
        self::assertTrue($chatTuiProbeCommand->screenLoopExpandsToolResults);
        self::assertSame($this->accountBeforeTest, AuthService::instance()->getAccount(), 'the account of before the run is authenticated again');
    }

    public function testAResumedConversationLoadsTheBoundedTailAndTheNodeTreeNeverTheLazyMessages(): void
    {
        $storedConversation = new ConversationWithScriptedCompactionNodesDouble();
        $storedConversation->id = 31;
        $storedConversation->accountId = AgentSpacesServiceConsoleScopeDouble::OWNER_ACCOUNT_ID;
        $storedConversation->scriptedCompactionNodes = AIConversationCompactionNodes::newInstance();
        $messagesServiceDouble = new MessagesServiceWithScriptedContextTailDouble();
        $messagesServiceDouble->scriptedContextTail = AIConversationMessages::newInstance();
        $chatTuiProbeCommand = $this->buildProbeCommand(messagesServiceDouble: $messagesServiceDouble, storedConversation: $storedConversation);

        $exitCode = (new CommandTester($chatTuiProbeCommand))->execute([
            '--' . AgentConsoleCommand::OPTION_AGENT_SPACE_ID => (string)AgentSpacesServiceConsoleScopeDouble::STORED_AGENT_SPACE_ID,
            '--conversationId' => '31',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame($storedConversation, $chatTuiProbeCommand->screenLoopConversation);
        self::assertSame([31], $messagesServiceDouble->contextTailConversationIds);
        self::assertSame($messagesServiceDouble->scriptedContextTail, $storedConversation->messages);
        self::assertSame($storedConversation->scriptedCompactionNodes, $storedConversation->compactionNodes);
        self::assertSame(0, $storedConversation->lazyMessagesLoadCount, 'the lazy relation holds the oldest rows only');
    }

    public function testAnEphemeralChatNeverLooksUpTheConversationId(): void
    {
        $chatTuiProbeCommand = $this->buildProbeCommand();

        (new CommandTester($chatTuiProbeCommand))->execute([
            '--' . AgentConsoleCommand::OPTION_AGENT_SPACE_ID => (string)AgentSpacesServiceConsoleScopeDouble::STORED_AGENT_SPACE_ID,
            '--conversationId' => '31',
            '--ephemeral' => true,
        ]);

        self::assertSame([], $chatTuiProbeCommand->lookedUpConversationIds);
        self::assertFalse(isset($chatTuiProbeCommand->screenLoopConversation->id), 'a new conversation, not stored');
    }

    public function testKeysEditScrollQuitAndSendTheInputLine(): void
    {
        $chatTuiProbeCommand = $this->buildProbeCommand();
        $conversation = $this->buildConversation();
        $consoleViewModel = $this->buildViewModelWithRows(30);
        $chatScreenState = ChatScreenState::atStart();

        $chatScreenState = $this->applyEvent($chatTuiProbeCommand, CharKeyEvent::new('h'), $conversation, $consoleViewModel, $chatScreenState);
        $chatScreenState = $this->applyEvent($chatTuiProbeCommand, CharKeyEvent::new('i'), $conversation, $consoleViewModel, $chatScreenState);
        self::assertSame('hi', $chatScreenState->inputText);
        $chatScreenState = $this->applyEvent($chatTuiProbeCommand, CodedKeyEvent::new(KeyCode::Backspace), $conversation, $consoleViewModel, $chatScreenState);
        self::assertSame('h', $chatScreenState->inputText);

        $chatScreenState = $this->applyEvent($chatTuiProbeCommand, CodedKeyEvent::new(KeyCode::PageDown), $conversation, $consoleViewModel, $chatScreenState);
        self::assertSame(self::TRANSCRIPT_HEIGHT, $chatScreenState->scrollOffset);
        $chatScreenState = $this->applyEvent($chatTuiProbeCommand, CodedKeyEvent::new(KeyCode::PageDown), $conversation, $consoleViewModel, $chatScreenState);
        $chatScreenState = $this->applyEvent($chatTuiProbeCommand, CodedKeyEvent::new(KeyCode::PageDown), $conversation, $consoleViewModel, $chatScreenState);
        self::assertSame($consoleViewModel->maxScrollOffset(self::TRANSCRIPT_HEIGHT), $chatScreenState->scrollOffset, 'PgDn stops at the end');
        $chatScreenState = $this->applyEvent($chatTuiProbeCommand, CodedKeyEvent::new(KeyCode::Up), $conversation, $consoleViewModel, $chatScreenState);
        self::assertSame($consoleViewModel->maxScrollOffset(self::TRANSCRIPT_HEIGHT) - 1, $chatScreenState->scrollOffset);
        $chatScreenState = $this->applyEvent($chatTuiProbeCommand, CodedKeyEvent::new(KeyCode::PageUp), $conversation, $consoleViewModel, $chatScreenState);
        $chatScreenState = $this->applyEvent($chatTuiProbeCommand, CodedKeyEvent::new(KeyCode::PageUp), $conversation, $consoleViewModel, $chatScreenState);
        $chatScreenState = $this->applyEvent($chatTuiProbeCommand, CodedKeyEvent::new(KeyCode::PageUp), $conversation, $consoleViewModel, $chatScreenState);
        self::assertSame(0, $chatScreenState->scrollOffset, 'PgUp stops at the top');

        $chatScreenState = $this->applyEvent($chatTuiProbeCommand, CodedKeyEvent::new(KeyCode::Enter), $conversation, $consoleViewModel, $chatScreenState);
        self::assertSame(['h'], $chatTuiProbeCommand->findTurnMessageTexts());
        self::assertSame('', $chatScreenState->inputText);
        self::assertSame(ChatScreenState::SCROLL_OFFSET_BOTTOM, $chatScreenState->scrollOffset, 'jumps to the bottom, clamped on the next draw');
        $chatScreenState = $this->applyEvent($chatTuiProbeCommand, CodedKeyEvent::new(KeyCode::Enter), $conversation, $consoleViewModel, $chatScreenState);
        self::assertSame(['h'], $chatTuiProbeCommand->findTurnMessageTexts(), 'a blank line is not sent');

        self::assertTrue($chatScreenState->isRunning);
        $chatScreenState = $this->applyEvent($chatTuiProbeCommand, CodedKeyEvent::new(KeyCode::Esc), $conversation, $consoleViewModel, $chatScreenState);
        self::assertFalse($chatScreenState->isRunning);
        self::assertSame($chatScreenState, $this->applyEvent($chatTuiProbeCommand, new stdClass(), $conversation, $consoleViewModel, $chatScreenState), 'another event only redraws');
    }

    public function testAClickTogglesTheBlockUnderItAndTheWheelScrolls(): void
    {
        $chatTuiProbeCommand = $this->buildProbeCommand();
        $conversation = $this->buildConversation();
        $consoleViewModel = new ConsoleViewModel();
        $consoleViewModel->append(new ConsoleViewBlock('first', 'first line', [], collapsible: false));
        $consoleViewModel->append(new ConsoleViewBlock('tool', '→ tool ▸ 2 lines', ['result one', 'result two'], collapsible: true, expanded: false));
        $chatScreenState = ChatScreenState::atStart();

        // screen row 0 is the status line: screen row 2 is transcript row 1, the tool block
        $chatScreenState = $this->applyEvent($chatTuiProbeCommand, MouseEvent::new(MouseEventKind::Down, MouseButton::Left, 0, 2, KeyModifiers::NONE), $conversation, $consoleViewModel, $chatScreenState);
        self::assertSame(['first line', '→ tool ▸ 2 lines', 'result one', 'result two'], $consoleViewModel->viewportLines(0, self::TRANSCRIPT_HEIGHT));
        $chatScreenState = $this->applyEvent($chatTuiProbeCommand, MouseEvent::new(MouseEventKind::Down, MouseButton::Left, 0, 0, KeyModifiers::NONE), $conversation, $consoleViewModel, $chatScreenState);
        self::assertCount(4, $consoleViewModel->viewportLines(0, self::TRANSCRIPT_HEIGHT), 'a click on the status line toggles nothing');

        $chatScreenState = $this->applyEvent($chatTuiProbeCommand, MouseEvent::new(MouseEventKind::ScrollDown, MouseButton::None, 0, 0, KeyModifiers::NONE), $conversation, $consoleViewModel, $chatScreenState);
        self::assertSame(AgentChatTuiCommand::MOUSE_WHEEL_SCROLL_ROWS, $chatScreenState->scrollOffset);
        $chatScreenState = $this->applyEvent($chatTuiProbeCommand, MouseEvent::new(MouseEventKind::ScrollUp, MouseButton::None, 0, 0, KeyModifiers::NONE), $conversation, $consoleViewModel, $chatScreenState);
        $chatScreenState = $this->applyEvent($chatTuiProbeCommand, MouseEvent::new(MouseEventKind::ScrollUp, MouseButton::None, 0, 0, KeyModifiers::NONE), $conversation, $consoleViewModel, $chatScreenState);
        self::assertSame(0, $chatScreenState->scrollOffset);
    }

    public function testTheScreenShowsTheStatusLineTheTranscriptInViewAndTheInputLine(): void
    {
        $chatTuiProbeCommand = $this->buildProbeCommand();
        $conversation = $this->buildConversation();
        $consoleViewModel = $this->buildViewModelWithRows(5);
        $chatScreenState = (new ChatScreenState(true, 'draft', 2))->withScrollOffsetAtMost($consoleViewModel->maxScrollOffset(2));

        $screenText = (string)self::invokeMethod($chatTuiProbeCommand, 'buildScreenText', [$conversation, 'MODEL.X', $consoleViewModel, $chatScreenState, 40, 2]);

        self::assertSame(
            [' AGENT · MODEL.X · 0 tok · $0.0000', 'row 2', 'row 3', str_repeat('─', 40), 'you> draft█'],
            explode("\n", $screenText)
        );
    }

    protected function buildProbeCommand(
        bool $terminalLibraryInstalled = true,
        ?MessagesServiceWithScriptedContextTailDouble $messagesServiceDouble = null,
        ?AIConversation $storedConversation = null,
        ?AccountsServiceWithOneAccountDouble $accountsServiceDouble = null
    ): AgentChatTuiProbeCommand {
        return new AgentChatTuiProbeCommand(new AgentSpacesServiceConsoleScopeDouble(), $terminalLibraryInstalled, $messagesServiceDouble, $storedConversation, $accountsServiceDouble);
    }

    /**
     * @param AgentChatTuiProbeCommand $chatTuiProbeCommand
     * @return string[] the scopes of the envelopes the command opened, in order
     */
    protected function findOpenedEnvelopeScopes(AgentChatTuiProbeCommand $chatTuiProbeCommand): array
    {
        return array_map(
            static fn (AIUsageEnvelopeContext $envelopeContext): string => (string)$envelopeContext->scope,
            $chatTuiProbeCommand->usageEnvelopesServiceDouble->openedEnvelopeContexts
        );
    }

    /**
     * The trait whose method `$methodName` of `$className` is: the method body PHP copies into a class keeps the file
     * and the lines of its trait.
     *
     * @param string $className
     * @param string $methodName
     * @return string|null null = the class declares the method itself
     */
    protected function findDeclaringTraitOfMethod(string $className, string $methodName): ?string
    {
        $reflectionMethod = new ReflectionMethod($className, $methodName);
        foreach ((new ReflectionClass($className))->getTraits() as $traitReflection) {
            if ($traitReflection->hasMethod($methodName)
                && $traitReflection->getMethod($methodName)->getFileName() === $reflectionMethod->getFileName()
                && $traitReflection->getMethod($methodName)->getStartLine() === $reflectionMethod->getStartLine()) {
                return $traitReflection->getName();
            }
        }
        return null;
    }

    protected function buildConversation(): AIConversation
    {
        $conversation = new AIConversation();
        $conversation->accountId = AgentSpacesServiceConsoleScopeDouble::OWNER_ACCOUNT_ID;
        $conversation->messages = AIConversationMessages::newInstance();
        return $conversation;
    }

    protected function buildViewModelWithRows(int $rowCount): ConsoleViewModel
    {
        $consoleViewModel = new ConsoleViewModel();
        for ($rowIndex = 0; $rowIndex < $rowCount; $rowIndex++) {
            $consoleViewModel->append(new ConsoleViewBlock('row-' . $rowIndex, 'row ' . $rowIndex, [], collapsible: false));
        }
        return $consoleViewModel;
    }

    protected function applyEvent(
        AgentChatTuiProbeCommand $chatTuiProbeCommand,
        object $terminalEvent,
        AIConversation $conversation,
        ConsoleViewModel $consoleViewModel,
        ChatScreenState $chatScreenState
    ): ChatScreenState {
        /** @var ChatScreenState $nextChatScreenState */
        $nextChatScreenState = self::invokeMethod(
            $chatTuiProbeCommand,
            'applyTerminalEvent',
            [$terminalEvent, $conversation, null, $consoleViewModel, $chatScreenState, self::TRANSCRIPT_HEIGHT]
        );
        return $nextChatScreenState;
    }

    /**
     * @param ReflectionMethod $reflectionMethod
     * @return string[] the class names in the parameter and return types of the method
     */
    protected function findTypeNamesOfSignature(ReflectionMethod $reflectionMethod): array
    {
        $signatureTypes = [$reflectionMethod->getReturnType()];
        foreach ($reflectionMethod->getParameters() as $reflectionParameter) {
            $signatureTypes[] = $reflectionParameter->getType();
        }
        $typeNames = [];
        foreach ($signatureTypes as $signatureType) {
            $namedTypes = $signatureType instanceof ReflectionUnionType ? $signatureType->getTypes() : [$signatureType];
            foreach ($namedTypes as $namedType) {
                if ($namedType instanceof ReflectionNamedType) {
                    $typeNames[] = $namedType->getName();
                }
            }
        }
        return $typeNames;
    }

    protected function runConsoleWithoutTerminalLibrary(string ...$consoleArguments): Process
    {
        $consoleProcess = new Process(
            [PHP_BINARY, dirname(__DIR__, 3) . '/Fixtures/Console/console-without-terminal-library.php', ...$consoleArguments, '--no-ansi'],
            dirname(__DIR__, 4)
        );
        $consoleProcess->setTimeout(120);
        $consoleProcess->run();
        return $consoleProcess;
    }
}
