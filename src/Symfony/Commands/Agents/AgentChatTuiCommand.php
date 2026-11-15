<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Environment\AgentApplicationNames;
use DDD\Presentation\Mcp\Agent\ConversationHtmlTranscript;
use DDD\Presentation\Mcp\Agent\Tui\ConsoleViewModel;
use DDD\Presentation\Mcp\Agent\Tui\ConsoleViewModelBuilder;
use DDD\Symfony\Commands\AI\ModelTierOptionTrait;
use DDD\Symfony\Commands\Agents\Chat\AgentConsoleChatRenderOptions;
use DDD\Symfony\Commands\Agents\Chat\AgentConsoleChatRunOptions;
use DDD\Symfony\Commands\Agents\Chat\AgentConsoleChatScopeTrait;
use DDD\Symfony\Commands\Agents\Chat\ChatScreenState;
use PhpTui\Term\Actions;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\MouseButton;
use PhpTui\Term\MouseEventKind;
use PhpTui\Term\Terminal;
use PhpTui\Tui\Bridge\PhpTerm\PhpTermBackend;
use PhpTui\Tui\Display\Display;
use PhpTui\Tui\DisplayBuilder;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Text\Text;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * The agent chat on a full screen: a scrollable transcript with tool results that open and close on a click, a pinned
 * status line and an input line. The line-oriented `app:ai-agent:chat` is the one a script or an evaluation drives;
 * this one is for a person at a terminal. The transcript, the scrolling and the click hit-test live in the tested
 * {@see ConsoleViewModel}; this command is the terminal shell around it.
 *
 * It draws with the library `php-tui/php-tui`, which the module suggests but does not require: the library's classes
 * appear only inside method bodies, so this class loads (and the console lists the command) without it, and a run
 * without it ends with one line naming the library ({@see self::isTerminalLibraryInstalled()}).
 *
 * The chat works as the account `--accountId` names, or as the owner of the space the space options name; a new
 * conversation is put into that space; `--conversationId` resumes a stored conversation; a turn runs inside the turn
 * envelope. Each of these rules is the line chat's, shared through {@see AgentConsoleChatScopeTrait}
 * ({@see self::resolveChatScope()}, {@see self::findOrCreateConversation()},
 * {@see self::generateAgentReplyInsideTurnEnvelope()}).
 *
 *   php bin/console app:ai-agent:chat-tui --agentSpaceId=12 --ephemeral --ignore-budget
 *
 * Keys: type and Enter to send · a click on a `→ tool [...] ▸ N chars` line opens or closes its result · mouse wheel,
 * PgUp, PgDn and the arrows scroll · Esc quits. The interactive loop needs a real terminal (raw mode, mouse capture,
 * the alternate screen); everything it decides on is a method of its own.
 */
#[AsCommand(name: 'app:ai-agent:chat-tui', description: 'Full-screen TUI for the agent chat (mouse, click-to-expand).')]
class AgentChatTuiCommand extends AgentConsoleCommand
{
    use AgentConsoleChatScopeTrait;
    use ModelTierOptionTrait;

    /** @var string The Composer package the full screen is drawn with */
    public const string TERMINAL_LIBRARY_PACKAGE = 'php-tui/php-tui';

    /** @var int The rows of the screen that are no transcript: the status line, the separator and the input line */
    public const int SCREEN_ROWS_AROUND_TRANSCRIPT = 3;

    /** @var int The rows one turn of the mouse wheel scrolls */
    public const int MOUSE_WHEEL_SCROLL_ROWS = 3;

    /** @var string The prompt in front of the input line */
    public const string INPUT_LINE_PROMPT = 'you> ';

    /** @var string The block drawn at the end of the input line in place of the hidden terminal cursor */
    public const string INPUT_LINE_CURSOR = '█';

    /** @var string|null The file the HTML transcript is written to after every turn (`--html`); null = none */
    protected ?string $htmlTranscriptPath = null;

    protected function configure(): void
    {
        $this
            ->addOption(self::OPTION_ACCOUNT_ID, null, InputOption::VALUE_OPTIONAL, 'Account id; the owner of the space when omitted')
            ->addOption('message', 'm', InputOption::VALUE_OPTIONAL, 'Optional opening message — sent as the first turn when the TUI starts')
            ->addOption(self::OPTION_CONVERSATION_ID, null, InputOption::VALUE_OPTIONAL, 'Resume an existing conversation by id (ignored with --ephemeral)')
            ->addOption('ephemeral', null, InputOption::VALUE_NONE, 'Do not persist the conversation')
            ->addOption('full', null, InputOption::VALUE_NONE, 'Start with tool results EXPANDED (otherwise collapsed; click to expand)')
            ->addOption('collapse-threads', null, InputOption::VALUE_NONE, 'Accepted for parity with app:ai-agent:chat — the TUI already shows only the parent thread (delegations cross back as their verdict)')
            ->addOption('debug', null, InputOption::VALUE_NONE, 'Accepted for parity with app:ai-agent:chat — request payloads are not block-rendered in the TUI yet')
            ->addOption('html', null, InputOption::VALUE_OPTIONAL, 'Also write a self-contained HTML transcript to this path, refreshed after every turn')
            ->addOption('model', null, InputOption::VALUE_OPTIONAL, 'Override the agent AIModel by name (e.g. OPENAI.GPT5_MINI); default = the conversation\'s configured model');
        $this->configureAgentSpaceOptions();
        $this->configureIgnoreBudgetOption();
        // --tier / --vendor (resolveEffectiveModelName() also reads --model, declared above)
        $this->addModelTierOptions();
    }

    /**
     * Resolves the account and the conversation, sends the opening message, then runs the screen loop.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws Throwable
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->isTerminalLibraryInstalled()) {
            $output->writeln('<error>The full-screen chat needs the library ' . self::TERMINAL_LIBRARY_PACKAGE . ' (composer require ' . self::TERMINAL_LIBRARY_PACKAGE . ').</error>');
            return Command::FAILURE;
        }
        $chatScope = $this->resolveChatScope($input);
        if ($chatScope->isRefused()) {
            // the full-screen chat ends every refusal with FAILURE, whatever the refusal's own exit code
            $output->writeln('<error>' . $chatScope->refusalText . '</error>');
            return Command::FAILURE;
        }
        $this->actAsAccount($chatScope->account);
        $modelName = $this->resolveEffectiveModelName($input);
        $htmlPathOption = $input->getOption('html');
        $this->htmlTranscriptPath = is_string($htmlPathOption) && $htmlPathOption !== '' ? $htmlPathOption : null;

        // a new conversation is stamped with the default skill-loading strategy
        $conversation = $this->findOrCreateConversation($this->readIdOption($input, self::OPTION_CONVERSATION_ID), $chatScope, (bool)$input->getOption('ephemeral'), null);

        // the optional opening message (-m) is sent as the first turn before the screen loop takes over
        $openingMessage = $input->getOption('message');
        if (is_string($openingMessage) && trim($openingMessage) !== '') {
            $this->sendTurn($conversation, trim($openingMessage), $modelName);
        }

        $this->runScreenLoop($conversation, $modelName, (bool)$input->getOption('full'));
        return Command::SUCCESS;
    }

    /**
     * Whether the library the screen is drawn with is installed. Asking the class loader for one class of each of its
     * two parts (terminal and drawing) loads nothing else of the command.
     *
     * @return bool
     */
    protected function isTerminalLibraryInstalled(): bool
    {
        return class_exists(Terminal::class) && class_exists(DisplayBuilder::class);
    }

    /**
     * The screen loop on a real terminal: raw mode, mouse capture and the alternate screen, restored whatever ends the
     * loop. Per round it draws the screen ({@see self::buildScreenText()}) and applies one terminal event
     * ({@see self::applyTerminalEvent()}).
     *
     * @param AIConversation $conversation
     * @param string|null $modelName
     * @param bool $expandToolResults true = tool results start opened
     * @return void
     */
    protected function runScreenLoop(AIConversation $conversation, ?string $modelName, bool $expandToolResults): void
    {
        $terminal = Terminal::new();
        $display = DisplayBuilder::default(PhpTermBackend::new())->fullscreen()->build();
        $consoleViewModelBuilder = new ConsoleViewModelBuilder();

        $terminal->enableRawMode();
        $terminal->execute(Actions::alternateScreenEnable(), Actions::enableMouseCapture(), Actions::cursorHide());
        $chatScreenState = ChatScreenState::atStart();
        try {
            while ($chatScreenState->isRunning) {
                $consoleViewModel = $consoleViewModelBuilder->fromConversation($conversation, $expandToolResults);
                $chatScreenState = $this->drawScreen($display, $conversation, $modelName, $consoleViewModel, $chatScreenState);
                $chatScreenState = $this->applyTerminalEvent(
                    $terminal->events()->next(),
                    $conversation,
                    $modelName,
                    $consoleViewModel,
                    $chatScreenState,
                    $this->findTranscriptHeight($display->buffer()->area()->height)
                );
            }
        } finally {
            $terminal->execute(Actions::disableMouseCapture(), Actions::cursorShow(), Actions::alternateScreenDisable());
            $terminal->disableRawMode();
        }
    }

    /**
     * Draws one screen and returns the state with its scroll offset clamped to the transcript.
     *
     * @param Display $display the display of `php-tui/php-tui` (typed by docblock only: the library is optional)
     * @param AIConversation $conversation
     * @param string|null $modelName
     * @param ConsoleViewModel $consoleViewModel
     * @param ChatScreenState $chatScreenState
     * @return ChatScreenState
     */
    protected function drawScreen(
        object $display,
        AIConversation $conversation,
        ?string $modelName,
        ConsoleViewModel $consoleViewModel,
        ChatScreenState $chatScreenState
    ): ChatScreenState {
        $screenArea = $display->buffer()->area();
        $transcriptHeight = $this->findTranscriptHeight($screenArea->height);
        $chatScreenState = $chatScreenState->withScrollOffsetAtMost($consoleViewModel->maxScrollOffset($transcriptHeight));
        $screenText = $this->buildScreenText($conversation, $modelName, $consoleViewModel, $chatScreenState, $screenArea->width, $transcriptHeight);
        $display->draw(ParagraphWidget::fromText(Text::fromString($screenText)));
        return $chatScreenState;
    }

    /**
     * @param int $screenHeight the rows of the screen
     * @return int the rows of the transcript (at least one)
     */
    protected function findTranscriptHeight(int $screenHeight): int
    {
        return max(1, $screenHeight - self::SCREEN_ROWS_AROUND_TRANSCRIPT);
    }

    /**
     * The text of one screen: row 0 the pinned status line, then the transcript rows in view, the separator and the
     * input line. The terminal cursor is hidden (it would fight the redraw), so a block marks the end of the input.
     *
     * @param AIConversation $conversation
     * @param string|null $modelName
     * @param ConsoleViewModel $consoleViewModel
     * @param ChatScreenState $chatScreenState a state whose scroll offset is clamped already
     * @param int $screenWidth
     * @param int $transcriptHeight
     * @return string
     */
    protected function buildScreenText(
        AIConversation $conversation,
        ?string $modelName,
        ConsoleViewModel $consoleViewModel,
        ChatScreenState $chatScreenState,
        int $screenWidth,
        int $transcriptHeight
    ): string {
        $screenLines = [$this->buildStatusLine($conversation, $modelName, $screenWidth)];
        foreach ($consoleViewModel->viewportLines($chatScreenState->scrollOffset, $transcriptHeight) as $transcriptLine) {
            $screenLines[] = $transcriptLine;
        }
        $screenLines[] = str_repeat('─', max(1, $screenWidth));
        $screenLines[] = self::INPUT_LINE_PROMPT . $chatScreenState->inputText . self::INPUT_LINE_CURSOR;
        return implode("\n", $screenLines);
    }

    /**
     * The pinned status line (it never scrolls): the assistant's console label, the model, the running total of
     * tokens and cost, the loaded skills; cut to the screen width.
     *
     * @param AIConversation $conversation
     * @param string|null $modelName
     * @param int $screenWidth
     * @return string
     */
    protected function buildStatusLine(AIConversation $conversation, ?string $modelName, int $screenWidth): string
    {
        $messages = $conversation->messages ?? null;
        $loadedSkillNames = implode(', ', $messages?->getLoadedSkillNames() ?? []);
        $statusLine = sprintf(
            ' %s · %s · %d tok · $%.4f%s',
            strtoupper(AgentApplicationNames::getConsoleAssistantLabel()),
            $modelName ?? 'default',
            $messages?->getTotalTokens() ?? 0,
            $messages?->getTotalCostUsd() ?? 0.0,
            $loadedSkillNames !== '' ? ' · skills [' . $loadedSkillNames . ']' : '',
        );
        return mb_substr($statusLine, 0, max(1, $screenWidth));
    }

    /**
     * Applies one terminal event: a character is typed, a coded key edits, scrolls, sends or quits, a mouse event
     * scrolls or opens a block; any other event (a resize) only redraws.
     *
     * @param object|null $terminalEvent the event of `php-tui/php-tui` (typed by docblock only: the library is optional)
     * @param AIConversation $conversation
     * @param string|null $modelName
     * @param ConsoleViewModel $consoleViewModel
     * @param ChatScreenState $chatScreenState
     * @param int $transcriptHeight
     * @return ChatScreenState
     */
    protected function applyTerminalEvent(
        ?object $terminalEvent,
        AIConversation $conversation,
        ?string $modelName,
        ConsoleViewModel $consoleViewModel,
        ChatScreenState $chatScreenState,
        int $transcriptHeight
    ): ChatScreenState {
        if ($terminalEvent instanceof CharKeyEvent) {
            return $chatScreenState->withTypedText($terminalEvent->char);
        }
        if ($terminalEvent instanceof CodedKeyEvent) {
            $maxScrollOffset = $consoleViewModel->maxScrollOffset($transcriptHeight);
            return $this->applyCodedKey($terminalEvent->code, $conversation, $modelName, $chatScreenState, $transcriptHeight, $maxScrollOffset);
        }
        if ($terminalEvent instanceof MouseEvent) {
            return $this->applyMouseEvent($terminalEvent, $consoleViewModel, $chatScreenState, $transcriptHeight);
        }
        return $chatScreenState;
    }

    /**
     * Esc quits, Backspace deletes, PgUp/PgDn scroll a page, the arrows a row, Enter sends the input line.
     *
     * @param KeyCode $keyCode the key of `php-tui/php-tui` (typed by docblock only: the library is optional)
     * @param AIConversation $conversation
     * @param string|null $modelName
     * @param ChatScreenState $chatScreenState
     * @param int $transcriptHeight
     * @param int $maxScrollOffset
     * @return ChatScreenState
     */
    protected function applyCodedKey(
        object $keyCode,
        AIConversation $conversation,
        ?string $modelName,
        ChatScreenState $chatScreenState,
        int $transcriptHeight,
        int $maxScrollOffset
    ): ChatScreenState {
        return match ($keyCode) {
            KeyCode::Esc => $chatScreenState->stopped(),
            KeyCode::Backspace => $chatScreenState->withoutLastTypedCharacter(),
            KeyCode::PageUp => $chatScreenState->withScrollOffset($chatScreenState->scrollOffset - $transcriptHeight),
            KeyCode::PageDown => $chatScreenState->withScrollOffset(min($maxScrollOffset, $chatScreenState->scrollOffset + $transcriptHeight)),
            KeyCode::Up => $chatScreenState->withScrollOffset($chatScreenState->scrollOffset - 1),
            KeyCode::Down => $chatScreenState->withScrollOffset(min($maxScrollOffset, $chatScreenState->scrollOffset + 1)),
            KeyCode::Enter => $this->sendInputLine($conversation, $modelName, $chatScreenState),
            default => $chatScreenState,
        };
    }

    /**
     * Sends the input line as a turn (nothing when it is blank), then empties it and jumps to the bottom.
     *
     * @param AIConversation $conversation
     * @param string|null $modelName
     * @param ChatScreenState $chatScreenState
     * @return ChatScreenState
     */
    protected function sendInputLine(AIConversation $conversation, ?string $modelName, ChatScreenState $chatScreenState): ChatScreenState
    {
        if (trim($chatScreenState->inputText) !== '') {
            $this->sendTurn($conversation, trim($chatScreenState->inputText), $modelName);
        }
        return $chatScreenState->afterInputSent();
    }

    /**
     * A left click on a transcript row opens or closes the block there (row 0 of the screen is the status line, so the
     * transcript starts at screen row 1); the wheel scrolls.
     *
     * @param MouseEvent $mouseEvent the mouse event of `php-tui/php-tui` (typed by docblock only: the library is optional)
     * @param ConsoleViewModel $consoleViewModel
     * @param ChatScreenState $chatScreenState
     * @param int $transcriptHeight
     * @return ChatScreenState
     */
    protected function applyMouseEvent(object $mouseEvent, ConsoleViewModel $consoleViewModel, ChatScreenState $chatScreenState, int $transcriptHeight): ChatScreenState
    {
        if ($mouseEvent->kind === MouseEventKind::Down && $mouseEvent->button === MouseButton::Left) {
            $transcriptRow = $mouseEvent->row - 1;
            if ($transcriptRow >= 0 && $transcriptRow < $transcriptHeight) {
                $consoleViewModel->toggleAtRow($chatScreenState->scrollOffset + $transcriptRow);
            }
            return $chatScreenState;
        }
        return match ($mouseEvent->kind) {
            MouseEventKind::ScrollUp => $chatScreenState->withScrollOffset($chatScreenState->scrollOffset - self::MOUSE_WHEEL_SCROLL_ROWS),
            MouseEventKind::ScrollDown => $chatScreenState->withScrollOffset($chatScreenState->scrollOffset + self::MOUSE_WHEEL_SCROLL_ROWS),
            default => $chatScreenState,
        };
    }

    /**
     * Appends the owner message and runs one agent turn inside the turn envelope of the conversation's account
     * ({@see self::generateAgentReplyInsideTurnEnvelope()} with the run's model and the default switches; no console
     * streaming: the screen is rebuilt after it). A failed turn is shown as an error row of the transcript.
     * The HTML transcript is refreshed afterwards when `--html` names a file.
     *
     * @param AIConversation $conversation
     * @param string $messageText
     * @param string|null $modelName
     * @return void
     */
    protected function sendTurn(AIConversation $conversation, string $messageText, ?string $modelName): void
    {
        $conversation->appendMessage(
            AIConversationMessage::newInstance(),
            AIConversationMessage::MESSAGE_TEXT,
            $messageText,
            role: AIConversationMessage::ROLE_USER,
            authorType: AIConversationMessage::AUTHOR_USER
        );
        try {
            $this->generateAgentReplyInsideTurnEnvelope($conversation, null, new AgentConsoleChatRunOptions(aiModelName: $modelName), new AgentConsoleChatRenderOptions());
        } catch (Throwable $turnError) {
            $errorMessage = AIConversationMessage::newInstance();
            $errorMessage->messageType = AIConversationMessage::MESSAGE_ERROR;
            $errorMessage->message = 'turn failed: ' . $turnError->getMessage();
            $conversation->messages?->add($errorMessage);
        }
        if ($this->htmlTranscriptPath !== null) {
            $this->writeHtmlTranscript($conversation, $this->htmlTranscriptPath);
        }
    }

    /**
     * Writes or refreshes the self-contained HTML transcript (`--html`); a failed write never interrupts the chat.
     *
     * @param AIConversation $conversation
     * @param string $htmlTranscriptPath
     * @return void
     */
    protected function writeHtmlTranscript(AIConversation $conversation, string $htmlTranscriptPath): void
    {
        try {
            file_put_contents($htmlTranscriptPath, (new ConversationHtmlTranscript())->render($conversation));
        } catch (Throwable) {
            // the transcript is a side output: the chat goes on without it
        }
    }
}
