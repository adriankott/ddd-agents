<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AIConversationDelegationTreeReport;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\RequestSnapshot;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetOrigin;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessage;
use DDD\Domain\AI\Services\Conversations\AgentMessagePrinterInterface;
use DDD\Domain\AI\Services\Conversations\AIConversationDelegationBatchesService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Services\AccountsService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Mcp\Agent\ConversationHtmlTranscript;
use DDD\Symfony\Commands\Agents\Inspect\ConversationHeaderRow;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Read-only inspector of one stored {@see AIConversation}: prints every relevant field, so the full state of a
 * conversation can be checked from the console without an HTTP call or a database client. Reads with the entity rights
 * lifted, so the conversation of any account is reachable.
 *
 * Prints, in order:
 *   1. a header (account, space, prompt extension, delegation anchor, summary, timestamps, totals),
 *   2. a table with one row per message (index, role, author, device, type, status, model, tokens, cost, pruned, tool
 *      call, widget origin, request snapshot, preview),
 *   3. the full transcript ({@see AgentMessagePrinterInterface::renderTranscript()}, the printer the live loop uses),
 *   4. the request snapshots (`--snapshots`),
 *   5. the delegation children (recursive, ids only), the sub-agents (one per `delegate` row, every run with its
 *      deadline row) and the legacy delegation batches.
 *
 * What the header says about the space of the conversation comes from {@see self::describeAgentSpaceRow()}: an
 * application that knows its space by an entity of its own prints that entity there.
 *
 *   php bin/console app:ai-agent:inspect 48
 *   php bin/console app:ai-agent:inspect 48 --include-truncated --full --debug
 *   php bin/console app:ai-agent:inspect 48 --html=/tmp/conversation-48.html
 */
#[AsCommand(
    name: 'app:ai-agent:inspect',
    description: 'Inspect one persisted agent conversation: metadata, per-message table, full transcript, delegation children.',
)]
class AgentInspectConversationCommand extends AgentConsoleCommand
{
    /** @var int The characters of a message preview in the table before it is cut */
    public const int MESSAGE_PREVIEW_LENGTH = 60;

    /** @var int The characters of the model's text a snapshot summary quotes */
    public const int RESPONSE_CONTENT_QUOTE_LENGTH = 500;

    /** @var int The characters of a tool call's arguments a snapshot summary quotes */
    public const int TOOL_CALL_ARGUMENTS_QUOTE_LENGTH = 300;

    protected function configure(): void
    {
        $this
            ->addArgument('conversationId', InputArgument::REQUIRED, 'The AIConversation id to inspect')
            ->addOption('include-truncated', null, InputOption::VALUE_NONE, 'Also load + show soft-deleted (truncatedAt) messages, marked as PRUNED')
            ->addOption('full', null, InputOption::VALUE_NONE, 'Do not truncate tool results in the transcript')
            ->addOption('debug', null, InputOption::VALUE_NONE, 'Dump message payloads (tool_calls/args) untruncated in the transcript')
            ->addOption('collapse-threads', null, InputOption::VALUE_NONE, 'Collapse delegations in the transcript — show the delegate call + verdict, hide each child conversation\'s internal steps')
            ->addOption('no-transcript', null, InputOption::VALUE_NONE, 'Skip the full rendered transcript (metadata + table + children only)')
            ->addOption('snapshots', null, InputOption::VALUE_NONE, 'Dump the DEBUG request snapshots (the full sent payload incl. tool_choice + the raw model response) for messages that stored them — only present when the conversation ran with contextRetentionSettings.storeRequestSnapshot. Combine with --full for untruncated JSON.')
            ->addOption('html', null, InputOption::VALUE_OPTIONAL, 'Also write a self-contained HTML transcript to this path');
    }

    protected function liftsEntityRightsRestrictions(): bool
    {
        return true;
    }

    /**
     * Loads the conversation with all its messages and prints the sections.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        $conversationId = (int)$input->getArgument('conversationId');
        $includeTruncated = (bool)$input->getOption('include-truncated');
        if ($includeTruncated) {
            DBAIConversationMessage::$includeTruncated = true;
        }
        try {
            $conversation = $this->findConversationWithAllMessages($conversationId);
            if ($conversation === null) {
                $output->writeln("<error>No AIConversation found with id $conversationId.</error>");
                return Command::FAILURE;
            }
            $this->printConversation($conversation, $input, $output);
        } finally {
            if ($includeTruncated) {
                DBAIConversationMessage::$includeTruncated = false;
            }
        }
        return Command::SUCCESS;
    }

    /**
     * The stored conversation with every message in order (past the entity registry) and the relations of its space
     * attached, so the header can name what the space stands for; null when there is no such conversation.
     *
     * @param int $conversationId
     * @return AIConversation|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    protected function findConversationWithAllMessages(int $conversationId): ?AIConversation
    {
        $aIConversationsService = $this->getAIConversationsService();
        $conversation = $aIConversationsService->find($conversationId);
        if (!$conversation instanceof AIConversation) {
            return null;
        }
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        $conversation->messages = $aIConversationMessagesService->findAllMessagesForConversationInOrder($conversation, useEntityRegistrCache: false)
            ?? AIConversationMessages::newInstance();
        $agentSpacesService = $this->getAgentSpacesService();
        $agentSpacesService->attachAgentSpaceRelations($conversation, false);
        return $conversation;
    }

    /**
     * Prints the sections of a loaded conversation, in the order the class docblock lists them, and writes the HTML
     * transcript when `--html` names a file.
     *
     * @param AIConversation $conversation a conversation with its messages loaded
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return void
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    protected function printConversation(AIConversation $conversation, InputInterface $input, OutputInterface $output): void
    {
        $messages = $conversation->messages ?? AIConversationMessages::newInstance();
        $this->printHeader($conversation, $messages, $output);
        $this->printMessageTable($messages, $output);

        if (!$input->getOption('no-transcript')) {
            $output->writeln("\n<fg=cyan>══ TRANSCRIPT ══</>");
            $agentMessagePrinter = $this->getAgentMessagePrinter();
            $output->writeln($agentMessagePrinter->renderTranscript(
                $conversation,
                full: (bool)$input->getOption('full'),
                debug: (bool)$input->getOption('debug'),
                collapseThreads: (bool)$input->getOption('collapse-threads'),
            ));
        }
        if ((bool)$input->getOption('snapshots')) {
            $this->printRequestSnapshots($messages, $output, (bool)$input->getOption('full'));
        }
        $this->printDelegationTree($conversation, $output);

        $htmlPath = $input->getOption('html');
        if (is_string($htmlPath) && $htmlPath !== '') {
            $this->writeHtmlTranscript($conversation, $htmlPath, $output);
        }
    }

    /**
     * Writes the self-contained HTML transcript to `$htmlPath` and prints where it went, or that the write failed.
     *
     * @param AIConversation $conversation
     * @param string $htmlPath
     * @param OutputInterface $output
     * @return void
     */
    protected function writeHtmlTranscript(AIConversation $conversation, string $htmlPath, OutputInterface $output): void
    {
        $htmlTranscript = (new ConversationHtmlTranscript())->render($conversation);
        if (file_put_contents($htmlPath, $htmlTranscript) === false) {
            $output->writeln("<error>Could not write HTML transcript to $htmlPath.</error>");
            return;
        }
        $output->writeln("\n<fg=gray>· html transcript: $htmlPath</>");
    }

    /**
     * @param AIConversation $conversation
     * @param AIConversationMessages $messages
     * @param OutputInterface $output
     * @return void
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    protected function printHeader(AIConversation $conversation, AIConversationMessages $messages, OutputInterface $output): void
    {
        $output->writeln("<fg=cyan>══ CONVERSATION #{$conversation->id} ══</>");
        $table = new Table($output);
        $table->setStyle('compact');
        foreach ($this->buildHeaderRows($conversation, $messages) as $conversationHeaderRow) {
            $table->addRow($conversationHeaderRow->getPrintedValues());
        }
        $table->render();
    }

    /**
     * The rows of the header, in the order they are printed.
     *
     * @param AIConversation $conversation
     * @param AIConversationMessages $messages
     * @return ConversationHeaderRow[]
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    protected function buildHeaderRows(AIConversation $conversation, AIConversationMessages $messages): array
    {
        $delegatedFromMessage = isset($conversation->delegatedFromMessage) ? $conversation->delegatedFromMessage : null;
        $delegatedFromMessageId = isset($delegatedFromMessage->id) ? $delegatedFromMessage->id : null;
        $delegationBatchId = $conversation->delegationBatchId ?? null;
        $createdAt = $conversation->createdAt ?? null;
        $modifiedAt = $conversation->modifiedAt ?? null;
        return [
            $this->describeAccountRow($conversation),
            $this->describeAgentSpaceRow($conversation),
            new ConversationHeaderRow('aiPromptExtensionId', (string)($conversation->aiPromptExtensionId ?? ConversationHeaderRow::NO_VALUE)),
            new ConversationHeaderRow('delegatedFromMessageId', $delegatedFromMessageId !== null ? "$delegatedFromMessageId (this is a DELEGATION child)" : '— (root dialog)'),
            new ConversationHeaderRow('runState', (string)$conversation->runState),
            new ConversationHeaderRow('runHeartbeatAt', isset($conversation->runHeartbeatAt) ? (string)$conversation->runHeartbeatAt : ConversationHeaderRow::NO_VALUE),
            new ConversationHeaderRow('delegationBatchId', $delegationBatchId !== null ? "{$delegationBatchId} (member of this batch)" : ConversationHeaderRow::NO_VALUE),
            new ConversationHeaderRow('summary', $this->describeSummary($conversation)),
            new ConversationHeaderRow('created', $createdAt !== null ? (string)$createdAt : ConversationHeaderRow::NO_VALUE),
            new ConversationHeaderRow('modified', $modifiedAt !== null ? (string)$modifiedAt : ConversationHeaderRow::NO_VALUE),
            new ConversationHeaderRow('messages (loaded)', (string)count($messages->getElements())),
            new ConversationHeaderRow('total tokens', (string)$messages->getTotalTokens()),
            new ConversationHeaderRow('total cost USD', sprintf('$%.4f', $messages->getTotalCostUsd())),
            new ConversationHeaderRow('loaded skills', implode(', ', $messages->getLoadedSkillNames()) ?: ConversationHeaderRow::NO_VALUE),
        ];
    }

    /**
     * The header row of the conversation's account: its id and e-mail address.
     *
     * @param AIConversation $conversation
     * @return ConversationHeaderRow
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    protected function describeAccountRow(AIConversation $conversation): ConversationHeaderRow
    {
        $accountId = $conversation->accountId ?? null;
        if ($accountId === null) {
            return new ConversationHeaderRow('account', ConversationHeaderRow::NO_VALUE);
        }
        /** @var AccountsService $accountsService */
        $accountsService = DDDService::instance()->getService(AccountsService::class);
        $account = $accountsService->find($accountId);
        $accountEmail = $account instanceof Account && isset($account->email) ? $account->email : null;
        return new ConversationHeaderRow('account', "$accountId" . ($accountEmail ? " ($accountEmail)" : ''));
    }

    /**
     * The header row of the conversation's space. The default prints the id of the space (`—` at account level); an
     * application that knows its space by an entity of its own prints that entity (the relations of the space are
     * attached before, {@see self::findConversationWithAllMessages()}).
     *
     * @param AIConversation $conversation
     * @return ConversationHeaderRow
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    protected function describeAgentSpaceRow(AIConversation $conversation): ConversationHeaderRow
    {
        $agentSpaceId = $conversation->agentSpaceId ?? null;
        return new ConversationHeaderRow('agent space', $agentSpaceId !== null ? (string)$agentSpaceId : ConversationHeaderRow::NO_VALUE);
    }

    /**
     * The summary fields of the conversation in one line: title, text, outcome, importance and the index it covers up to.
     *
     * @param AIConversation $conversation
     * @return string
     */
    protected function describeSummary(AIConversation $conversation): string
    {
        if (!isset($conversation->summary)) {
            return ConversationHeaderRow::NO_VALUE;
        }
        $summary = $conversation->summary;
        return sprintf(
            '%s | %s | outcome: %s | importance: %s | coveredUpTo: %s',
            $summary->title ?? ConversationHeaderRow::NO_VALUE,
            $summary->text ?? ConversationHeaderRow::NO_VALUE,
            $summary->outcome ?? ConversationHeaderRow::NO_VALUE,
            isset($summary->importance) ? (string)$summary->importance : ConversationHeaderRow::NO_VALUE,
            isset($summary->coveredUpToMessageIndex) ? (string)$summary->coveredUpToMessageIndex : ConversationHeaderRow::NO_VALUE
        );
    }

    /**
     * @param AIConversationMessages $messages
     * @param OutputInterface $output
     * @return void
     */
    protected function printMessageTable(AIConversationMessages $messages, OutputInterface $output): void
    {
        $output->writeln("\n<fg=cyan>══ MESSAGES ══</>");
        $table = new Table($output);
        $table->setHeaders(['idx', 'role', 'author', 'device', 'type', 'status', 'model', 'in', 'out', 'cost', 'pruned', 'tool', 'origin', 'req', 'preview']);
        foreach ($messages->getElements() as $message) {
            $table->addRow($this->buildMessageTableRow($message));
        }
        $table->render();
    }

    /**
     * The cells of one message's row in the message table.
     *
     * @param AIConversationMessage $message
     * @return array<int, string|int>
     */
    protected function buildMessageTableRow(AIConversationMessage $message): array
    {
        $toolLabel = ConversationHeaderRow::NO_VALUE;
        if (isset($message->toolCall)) {
            $toolName = $message->toolCall->name ?? '?';
            $toolStatus = $message->toolCall->status ?? $message->status ?? '';
            $toolLabel = $toolStatus !== '' ? "$toolName:$toolStatus" : $toolName;
        }
        $preview = trim(preg_replace('/\s+/', ' ', $message->message ?? '') ?? '');
        if (mb_strlen($preview) > self::MESSAGE_PREVIEW_LENGTH) {
            $preview = mb_substr($preview, 0, self::MESSAGE_PREVIEW_LENGTH - 3) . '…';
        }
        // the row's model cost plus what its tool call booked while it ran
        $costIncludingToolCall = $message->getCostInUSDIncludingToolCallExecution();
        return [
            $message->indexInConversation ?? ConversationHeaderRow::NO_VALUE,
            $message->role ?? ConversationHeaderRow::NO_VALUE,
            $message->authorType ?? ConversationHeaderRow::NO_VALUE,
            // the device an owner row came from (`?? null`: not initialized on a lean load)
            ($message->ownerDevice ?? null)?->toTraceLine() ?? ConversationHeaderRow::NO_VALUE,
            $message->messageType ?? ConversationHeaderRow::NO_VALUE,
            $message->status ?? ConversationHeaderRow::NO_VALUE,
            $message->aiModelName ?? ConversationHeaderRow::NO_VALUE,
            $message->inputTokens ?? ConversationHeaderRow::NO_VALUE,
            $message->outputTokens ?? ConversationHeaderRow::NO_VALUE,
            $costIncludingToolCall > 0 ? sprintf('$%.4f', $costIncludingToolCall) : ConversationHeaderRow::NO_VALUE,
            isset($message->truncatedAt) ? '<fg=red>YES</>' : ConversationHeaderRow::NO_VALUE,
            $toolLabel,
            $this->buildWidgetOriginCell($message),
            isset($message->requestSnapshot) ? '✓' : ConversationHeaderRow::NO_VALUE,
            $preview,
        ];
    }

    /**
     * The `origin` cell of a row that carries widget blocks: who wrote each block, in message order — `tool:<toolName>`,
     * `platform:<reason>` or `model` ({@see AIConversationMessage::buildWidgetBlockOrigins()}; only a tool row's block
     * can name a tool or the platform). `—` for a row without a widget block.
     *
     * @param AIConversationMessage $message
     * @return string
     */
    protected function buildWidgetOriginCell(AIConversationMessage $message): string
    {
        $widgetOriginLabels = array_map(
            static fn (ConversationWidgetOrigin $conversationWidgetOrigin): string => $conversationWidgetOrigin->toDisplayLabel(),
            $message->buildWidgetBlockOrigins()
        );
        return $widgetOriginLabels === [] ? ConversationHeaderRow::NO_VALUE : implode(', ', $widgetOriginLabels);
    }

    /**
     * Prints the debug request snapshots: the full sent payload and the raw model response stored per message when the
     * conversation ran with `contextRetentionSettings.storeRequestSnapshot`. Per snapshot the `tool_choice` actually
     * sent, the offered tool names and a summary of what the model returned (so an empty answer under `required` is
     * decidable); `--full` adds the whole request and response JSON.
     *
     * @param AIConversationMessages $messages
     * @param OutputInterface $output
     * @param bool $full
     * @return void
     */
    protected function printRequestSnapshots(AIConversationMessages $messages, OutputInterface $output, bool $full): void
    {
        $printedHeader = false;
        foreach ($messages->getElements() as $message) {
            if (!isset($message->requestSnapshot) || ($message->requestSnapshot->sentPayloadJson ?? null) === null) {
                continue;
            }
            if (!$printedHeader) {
                $output->writeln("\n<fg=cyan>══ REQUEST SNAPSHOTS (debug) ══</>");
                $printedHeader = true;
            }
            $this->printRequestSnapshot($message, $output, $full);
        }
        if (!$printedHeader) {
            $output->writeln("\n<fg=gray>· no request snapshots stored (run the conversation with contextRetentionSettings.storeRequestSnapshot=true)</>");
        }
    }

    /**
     * Prints the request snapshot of one message (one that carries a sent payload).
     *
     * @param AIConversationMessage $message
     * @param OutputInterface $output
     * @param bool $full true = the whole request and response JSON too
     * @return void
     */
    protected function printRequestSnapshot(AIConversationMessage $message, OutputInterface $output, bool $full): void
    {
        $requestSnapshot = $message->requestSnapshot;
        $sentPayload = json_decode((string)$requestSnapshot->sentPayloadJson, true);
        $requestBody = is_array($sentPayload) ? ($sentPayload['body'] ?? $sentPayload) : [];
        $toolChoice = $requestBody['tool_choice'] ?? '(none)';
        $offeredTools = is_array($requestBody['tools'] ?? null) ? $requestBody['tools'] : [];
        $offeredToolNames = array_values(array_filter(array_map(
            static fn ($offeredTool): string => (string)($offeredTool['function']['name'] ?? ($offeredTool['name'] ?? '')),
            $offeredTools
        )));
        // a Chat Completions body carries `messages`, a Responses body `input` items
        $wireMessageCount = is_array($requestBody['messages'] ?? null) ? count($requestBody['messages']) : (is_array($requestBody['input'] ?? null) ? count($requestBody['input']) : 0);
        $rawResponseJson = $requestSnapshot->rawResponseJson ?? null;
        $rawResponse = $rawResponseJson !== null ? json_decode($rawResponseJson, true) : null;

        $output->writeln(sprintf(
            "\n<fg=yellow>msg #%s</> (%s) — tool_choice=<fg=magenta>%s</> · %d tools [%s] · %d wire messages",
            (string)($message->indexInConversation ?? '?'),
            $message->messageType ?? '?',
            is_string($toolChoice) ? $toolChoice : (string)json_encode($toolChoice),
            count($offeredTools),
            implode(', ', $offeredToolNames),
            $wireMessageCount
        ));
        $output->writeln('  response: ' . $this->summariseRawResponse($rawResponse));
        // the wire API, the skill-loading strategy and what an append-only call appended
        $output->writeln(sprintf(
            '  wire=%s · skills=%s%s%s',
            $requestSnapshot->wireApi ?? 'chatCompletions',
            $requestSnapshot->skillLoadingStrategy ?? 'systemPromptSwap',
            $requestSnapshot->epochStartIndex !== null ? ' · epoch@' . $requestSnapshot->epochStartIndex . ($requestSnapshot->epochCoverFingerprint !== null ? '/' . $requestSnapshot->epochCoverFingerprint : '') : '',
            ($requestSnapshot->appendedSkillNames ?? $requestSnapshot->appendedToolNames) !== null
                ? ' · appended skills [' . implode(', ', $requestSnapshot->appendedSkillNames ?? []) . '] tools [' . implode(', ', $requestSnapshot->appendedToolNames ?? []) . ']'
                : ''
        ));
        foreach ($this->describeMessagesWireReplay($requestSnapshot) as $messagesWireLine) {
            $output->writeln('  ' . $messagesWireLine);
        }
        if ($full) {
            $output->writeln('<fg=gray>  ── sent payload ──</>');
            $output->writeln((string)json_encode($sentPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $output->writeln('<fg=gray>  ── raw response ──</>');
            $output->writeln((string)json_encode($rawResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * Everything the model returned (the Chat Completions shape), so a stall is decidable without `--full`: the finish
     * reason (and the provider's native one), the verbatim text of the answer, each tool call's name and arguments,
     * and the billed completion tokens. Flags two failure signatures by shape and token count only, never by the words
     * of the text: a tool call written as text (no tool call parsed, but the text ends in a function-call JSON tail)
     * and a token mismatch (far more completion tokens billed than the visible text holds: the provider stripped
     * generated output). An error object instead of a completion is reported as a failed call, not as a stall.
     *
     * @param mixed $rawResponse the decoded raw response
     * @return string the summary lines, joined for printing under the `response:` label
     */
    protected function summariseRawResponse(mixed $rawResponse): string
    {
        if (!is_array($rawResponse)) {
            return '(none captured)';
        }
        if (isset($rawResponse['error'])) {
            $responseError = $rawResponse['error'];
            return '⛔ ERROR RESPONSE (model call FAILED — NOT a stall): '
                . (is_array($responseError) ? (string)json_encode($responseError, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string)$responseError);
        }
        if (($rawResponse['type'] ?? null) === 'message' && is_array($rawResponse['content'] ?? null)) {
            return $this->summariseMessagesRawResponse($rawResponse);
        }
        $firstChoice = $rawResponse['choices'][0] ?? null;
        if (!is_array($firstChoice)) {
            return 'NON-STANDARD response — top-level keys: ' . implode(', ', array_keys($rawResponse));
        }
        $answerMessage = is_array($firstChoice['message'] ?? null) ? $firstChoice['message'] : [];
        $finishReason = (string)($firstChoice['finish_reason'] ?? '?');
        $nativeFinishReason = (string)($firstChoice['provider_specific_fields']['native_finish_reason'] ?? ($firstChoice['native_finish_reason'] ?? ''));
        $answerText = trim((string)($answerMessage['content'] ?? ''));
        $toolCalls = is_array($answerMessage['tool_calls'] ?? null) ? $answerMessage['tool_calls'] : [];
        $completionTokens = (int)($rawResponse['usage']['completion_tokens'] ?? 0);

        $summaryLines = [sprintf(
            'finish_reason=%s%s · completion_tokens=%d',
            $finishReason,
            ($nativeFinishReason !== '' && $nativeFinishReason !== $finishReason) ? " (native=$nativeFinishReason)" : '',
            $completionTokens
        )];
        $summaryLines[] = $this->describeAnswerText($answerText);
        foreach ($this->describeToolCalls($toolCalls) as $toolCallLine) {
            $summaryLines[] = $toolCallLine;
        }
        if ($toolCalls === [] && $answerText !== '' && str_ends_with($answerText, '}}') && str_contains($answerText, '"arguments"')) {
            $summaryLines[] = '⚠ LIKELY TOOL-CALL-AS-TEXT LEAK — 0 tool_calls parsed but content is a stripped function-call JSON tail';
        }
        $visibleTokenEstimate = (int)ceil(mb_strlen($answerText) / 4);
        if ($toolCalls === [] && $completionTokens > 0 && $visibleTokenEstimate > 0 && $completionTokens >= $visibleTokenEstimate * 2) {
            $summaryLines[] = sprintf('⚠ TOKEN MISMATCH — billed %d completion tokens but visible content ≈ %d tok → the provider stripped generated output', $completionTokens, $visibleTokenEstimate);
        }
        return implode("\n  ", $summaryLines);
    }

    /**
     * The summary of an answer of the Anthropic Messages API: the stop reason, the output and thinking tokens, the
     * thinking blocks it carries, its text, each call, and the blocks the provider dropped from the REQUEST.
     *
     * @param array<string, mixed> $rawResponse the decoded raw response (`type: "message"`)
     * @return string the summary lines, joined for printing under the `response:` label
     */
    protected function summariseMessagesRawResponse(array $rawResponse): string
    {
        $answerTexts = [];
        $toolCalls = [];
        $thinkingBlockCount = 0;
        foreach ($rawResponse['content'] as $contentBlock) {
            $blockType = is_array($contentBlock) ? ($contentBlock['type'] ?? null) : null;
            if ($blockType === 'text') {
                $answerTexts[] = (string)($contentBlock['text'] ?? '');
            } elseif ($blockType === 'tool_use') {
                $toolCalls[] = ['function' => ['name' => (string)($contentBlock['name'] ?? '?'), 'arguments' => (string)json_encode($contentBlock['input'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]];
            } elseif ($blockType === 'thinking' || $blockType === 'redacted_thinking') {
                $thinkingBlockCount++;
            }
        }
        $summaryLines = [sprintf(
            'stop_reason=%s · output_tokens=%d · thinking_tokens=%d · %d thinking block(s)',
            (string)($rawResponse['stop_reason'] ?? '?'),
            (int)($rawResponse['usage']['output_tokens'] ?? 0),
            (int)($rawResponse['usage']['output_tokens_details']['thinking_tokens'] ?? 0),
            $thinkingBlockCount
        )];
        $summaryLines[] = $this->describeAnswerText(trim(implode("\n", $answerTexts)));
        foreach ($this->describeToolCalls($toolCalls) as $toolCallLine) {
            $summaryLines[] = $toolCallLine;
        }
        foreach (is_array($rawResponse['input_transformations'] ?? null) ? $rawResponse['input_transformations'] : [] as $inputTransformation) {
            $summaryLines[] = sprintf(
                '⚠ request changed by the provider: %s at %s (%s)',
                (string)($inputTransformation['type'] ?? '?'),
                (string)($inputTransformation['path'] ?? '?'),
                (string)($inputTransformation['reason'] ?? '?')
            );
        }
        return implode("\n  ", $summaryLines);
    }

    /**
     * The lines of a call on the Messages API: the frozen part and the thinking floor it rendered with, the thinking
     * tokens of its answer and every thinking block the provider dropped from its request. Empty on another wire API.
     *
     * @return string[]
     */
    protected function describeMessagesWireReplay(RequestSnapshot $requestSnapshot): array
    {
        if (($requestSnapshot->wireApi ?? null) !== RunSettings::AGENT_WIRE_API_MESSAGES) {
            return [];
        }
        $messagesWireLines = [sprintf(
            'messages wire: frozen from msg %s · thinking from msg %s · answer stored %s · thinking tokens %s',
            $requestSnapshot->messagesWireFrozenFromMessageIndex !== null ? '#' . $requestSnapshot->messagesWireFrozenFromMessageIndex : '— (carry-over off)',
            $requestSnapshot->messagesWireThinkingFromMessageIndex !== null ? '#' . $requestSnapshot->messagesWireThinkingFromMessageIndex : '—',
            $requestSnapshot->wireReasoningItemsJson !== null ? 'yes' : 'no',
            $requestSnapshot->wireReasoningTokens !== null ? (string)$requestSnapshot->wireReasoningTokens : '?'
        )];
        foreach ($requestSnapshot->inputTransformations ?? [] as $inputTransformation) {
            $messagesWireLines[] = sprintf(
                '<fg=red>dropped by the provider:</> %s at %s (%s)',
                (string)($inputTransformation['type'] ?? '?'),
                (string)($inputTransformation['path'] ?? '?'),
                (string)($inputTransformation['reason'] ?? '?')
            );
        }
        return $messagesWireLines;
    }

    /**
     * @param string $answerText the trimmed text of the model's answer
     * @return string the summary line of the text: empty, or its length and a quote of its start
     */
    protected function describeAnswerText(string $answerText): string
    {
        if ($answerText === '') {
            return 'content: <EMPTY — no text returned>';
        }
        $quotedText = mb_substr($answerText, 0, self::RESPONSE_CONTENT_QUOTE_LENGTH);
        return 'content (' . mb_strlen($answerText) . ' chars): "' . str_replace("\n", '\\n', $quotedText)
            . (mb_strlen($answerText) > self::RESPONSE_CONTENT_QUOTE_LENGTH ? '…' : '') . '"';
    }

    /**
     * @param array<int, mixed> $toolCalls the `tool_calls` of the model's answer
     * @return string[] one summary line per tool call, or one line saying there is none
     */
    protected function describeToolCalls(array $toolCalls): array
    {
        if ($toolCalls === []) {
            return ['tool_calls: NONE'];
        }
        $toolCallLines = [];
        foreach ($toolCalls as $toolCall) {
            $toolCallArguments = (string)($toolCall['function']['arguments'] ?? '');
            $toolCallLines[] = 'tool_call: ' . (string)($toolCall['function']['name'] ?? '?') . '('
                . mb_substr($toolCallArguments, 0, self::TOOL_CALL_ARGUMENTS_QUOTE_LENGTH)
                . (mb_strlen($toolCallArguments) > self::TOOL_CALL_ARGUMENTS_QUOTE_LENGTH ? '…' : '') . ')';
        }
        return $toolCallLines;
    }

    /**
     * Prints the child conversations the rows spawned (recursive, ids only — `app:ai-agent:inspect <childId>` shows a
     * child in detail), the sub-agents (one per `delegate` row, read from the loaded rows' records, every run with its
     * deadline row) and the legacy delegation batches the conversation forked: per batch the wake condition, expected
     * against the live member tally (completed / failed / running / queued) and the settled / folded / deadline state.
     * A deadline row of a sub-agent run is listed under its sub-agent, never as a batch.
     *
     * @param AIConversation $conversation
     * @param OutputInterface $output
     * @return void
     */
    protected function printDelegationTree(AIConversation $conversation, OutputInterface $output): void
    {
        /** @var AIConversationDelegationBatchesService $aIConversationDelegationBatchesService */
        $aIConversationDelegationBatchesService = DDDService::instance()->getService(AIConversationDelegationBatchesService::class);
        // the report queries the batches itself: the lazy inverse relation of the forked batches does not resolve here
        $delegationTreeReport = AIConversationDelegationTreeReport::buildFor($conversation, $aIConversationDelegationBatchesService);
        $this->printSectionLines('DELEGATION CHILDREN', $delegationTreeReport->childConversationLines, $output);
        $this->printSectionLines('SUB-AGENTS (one per delegate call)', $delegationTreeReport->subAgentLines, $output);
        if ($delegationTreeReport->legacyBatchRows === []) {
            return;
        }
        $output->writeln("\n<fg=cyan>══ DELEGATION BATCHES (forked by this conversation) ══</>");
        $table = new Table($output);
        $table->setHeaders(['batch', 'wake', 'expected', 'completed', 'failed', 'running', 'queued', 'settled', 'folded', 'deadline']);
        foreach ($delegationTreeReport->legacyBatchRows as $legacyBatchRow) {
            $table->addRow(array_values($legacyBatchRow));
        }
        $table->render();
    }

    /**
     * Prints a section title and its lines; nothing when there are no lines.
     *
     * @param string $sectionTitle
     * @param string[] $sectionLines
     * @param OutputInterface $output
     * @return void
     */
    protected function printSectionLines(string $sectionTitle, array $sectionLines, OutputInterface $output): void
    {
        if ($sectionLines === []) {
            return;
        }
        $output->writeln("\n<fg=cyan>══ {$sectionTitle} ══</>");
        foreach ($sectionLines as $sectionLine) {
            $output->writeln($sectionLine);
        }
    }

    /**
     * The conversations service (the application's class, when it replaces it).
     *
     * @return AIConversationsService
     */
    protected function getAIConversationsService(): AIConversationsService
    {
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
        return $aIConversationsService;
    }

    /**
     * The printer the live loop streams through (the application's, when it replaces it).
     *
     * @return AgentMessagePrinterInterface
     */
    protected function getAgentMessagePrinter(): AgentMessagePrinterInterface
    {
        /** @var AgentMessagePrinterInterface $agentMessagePrinter */
        $agentMessagePrinter = DDDService::instance()->getService(AgentMessagePrinterInterface::class);
        return $agentMessagePrinter;
    }
}
