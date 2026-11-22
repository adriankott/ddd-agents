<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentApplicationWording;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\RequestSnapshot;
use DDD\Domain\AI\Entities\Conversations\SignalSources\ConversationSignalSources;
use DDD\Domain\AI\Services\Conversations\AIConversationAuditRenderService;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Services\DDDService;

/**
 * The lines of the messages in the trace the judge of a judged run reads, and the headline of the agent's system prompt
 * above them. The markers are written here, never by the agent; the verdict prompt's legend names them.
 *
 * Per message, in order: the lines of its request snapshot — a CLOCK line with the "now" the call was given, in the
 * space's zone (local date, weekday, time: the judge counts "today" and "tomorrow" from it, never from a UTC date), a
 * VARIANTS line where the situation of the skill sections changes (each changed dimension before and after, the
 * evidence row left out), the skills an append-only call appended, a started state epoch, the state notes the call
 * delivered — then its own line:
 * - a tool call: `[N] TOOL name(arguments) [status]` with the skills loaded on the row, the skill sections of the call
 *   that made it, `(READ — persists nothing)` on a call that stores nothing, and the result as the model received it
 *   (never cut: the judge checks every number against it);
 * - a repeat the loop suppressed: a SYSTEM NOTE (bookkeeping, no missing delivery);
 * - the turn's terminal report: OWNER_REPORT;
 * - a widget row: TOOL_MESSAGE with its writer, read from its blocks;
 * - a signal: an owner action (owner evidence), a loop corrective (its text left out) or platform machinery;
 * - an owner message: numbered `(owner reply k)`;
 * - anything else: its type and role.
 * A message without text has no line of its own. `[N]` is the stored index, never the position in the loaded set: a
 * steer or a provenance suffix cites the stored index.
 *
 * The words for the space are the application's ({@see AgentApplicationWording}); whether a tool reads and who wrote a
 * widget come from the audit render service (resolved through the container). Reads no database.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalJudgeTraceMessageLines extends ValueObject
{
    /** @var string The headline of the system prompt; `%s` = the label of the space's context block */
    public const string SYSTEM_PROMPT_HEADLINE = "[SYSTEM PROMPT — the base data it carries (in particular in its '%s' block) counts as GROUNDED, also without a tool call]";

    /** @var string The clock line of a call; `%d` = the index, `%s` = the zone, `%s` = the local date, weekday and time */
    public const string CLOCK_LINE = '[%d] CLOCK (the "now" the agent was given for this call, in the space\'s zone %s — count today, '
        . 'tomorrow and weekdays from this local date): %s';

    /** @var string The local date, weekday and time of a clock line */
    public const string CLOCK_LOCAL_FORMAT = 'Y-m-d l H:i';

    /** @var string A line where the situation changes; `%d` = the index, `%s` = the transitions */
    public const string VARIANTS_LINE = '[%d] VARIANTS (the SITUATION the platform resolved from the stored record and the owner\'s statements — '
        . 'never set by the agent; "proposed" = a proposal from the record the owner has not confirmed yet; "owner@N" = decided by the owner in line N): %s';

    /** @var string A line of an append-only call; `%d` = the index, then the appended skills and tools */
    public const string SKILLS_LOADED_LINE = '[%d] SKILLS LOADED (appended to the history before this call): skills=%s tools=%s';

    /** @var string A line of a call that started a state epoch; `%d` = the index, `%s` = the cause, `%s` = the space's subject */
    public const string STATE_EPOCH_START_LINE = '[%d] STATE EPOCH START (%s): from this call on the agent\'s instructions carry the %s record, '
        . 'the addressing, the zone and the plan blocks as of this moment; later changes arrive as STATE NOTES';

    /** @var string A line of the state notes a call delivered; `%d` = the index, `%s` = the space's subject, `%s` = the notes */
    public const string STATE_NOTES_LINE = '[%d] STATE NOTES (delivered to the agent as background before this call — a reply that announces or answers '
        . 'a note is a defect; the agent must use the new value from here on: the addressing, the zone for dates, the %s name and data; '
        . 'after a task-plan note it follows its ← next item): %s';

    /** @var int The longest text of one state note */
    public const int STATE_NOTE_MAX_LENGTH = 240;

    /** @var string A tool-call line; index, tool, arguments, status, skills, read marker, result */
    public const string TOOL_LINE = "[%d] TOOL %s(%s) [%s]%s%s\n    result: %s";

    /** @var string The marker of a tool call that persists nothing */
    public const string READ_MARKER = ' (READ — persists nothing)';

    /** @var string The line of a repeat the loop suppressed; `%d` = the index */
    public const string SYSTEM_NOTE_LINE = '[%d] SYSTEM NOTE (loop bookkeeping, NOT an agent message, invisible to the owner): the agent '
        . 'wanted to send a text that repeats a message already DELIVERED in THIS turn — the system dropped the duplicate. The '
        . 'owner HAS the content; this is a suppressed repeat, NOT a missing delivery, and counts neither against goalMet nor '
        . 'against the agent.';

    /** @var string The line of the turn's terminal report; `%d` = the index, `%s` = the text */
    public const string OWNER_REPORT_LINE = '[%d] OWNER_REPORT (the turn\'s terminal report: the platform\'s pause report, a send_owner_report '
        . 'delivery, or the agent\'s own closing message under the step budget with the platform\'s pause footer; NOT a repetition '
        . 'by the agent): %s';

    /** @var string The line of a widget row; `%d` = the index, `%s` = its writer, `%s` = the text */
    public const string TOOL_MESSAGE_LINE = '[%d] TOOL_MESSAGE (a widget row written into the chat by %s — owner-visible, model-invisible; '
        . 'NOT composed by the agent and not one of its messages): %s';

    /** @var string The writer of a widget row without a widget block */
    public const string WIDGET_ROW_WITHOUT_BLOCK_WRITER = 'a TOOL or the PLATFORM (a row without a widget block)';

    /** @var string The line of an owner action signal; index, source, text */
    public const string OWNER_ACTION_SIGNAL_LINE = '[%d] SIGNAL(%s — owner action, counts as owner evidence): %s';

    /** @var string The line of a loop corrective signal, its text left out; index, source */
    public const string LOOP_CORRECTIVE_SIGNAL_LINE = '[%d] SIGNAL(%s — the platform\'s loop corrective, neither an agent message nor an owner answer; text left out)';

    /** @var string The line of any other signal; index, source, text */
    public const string PLATFORM_SIGNAL_LINE = '[%d] SIGNAL(%s — platform machinery, neither an agent message nor an owner answer): %s';

    /** @var string The line of an owner message; index, type, role, owner reply number, text */
    public const string OWNER_REPLY_LINE = '[%d] %s %s (owner reply %d): %s';

    /** @var string The line of any other message; index, type, role, text */
    public const string MESSAGE_LINE = '[%d] %s %s: %s';

    /** @var string Headline of a sub-agent's tool calls under the `delegate` call that started it */
    public const string SUB_AGENT_HEADLINE = '%s[%d] SUB-AGENT %s of the delegate call above — its own tool calls; what their results show counts as GROUNDED for the agent\'s answer:';

    /** @var string One tool call of a sub-agent, its result shortened */
    public const string SUB_AGENT_TOOL_LINE = "%s- TOOL %s(%s) [%s]\n%s  result: %s";

    /** @var int The characters of a sub-agent's tool result the judge reads */
    public const int SUB_AGENT_RESULT_MAX_CHARS = 1500;

    /** @var int The nesting depth of sub-agents whose tool calls are shown */
    public const int SUB_AGENT_MAX_DEPTH = 3;

    /** @var string[] The lines, in message order */
    protected array $lines = [];

    /** @var int The owner replies numbered so far */
    protected int $ownerReplyCount = 0;

    /** @var array<string, string>|null The situation of the last call with one, by dimension; null before the first */
    protected ?array $currentVariantTokens = null;

    /** @var string[]|null The skill sections of the last call with a situation; null before the first */
    protected ?array $currentSkillSections = null;

    /**
     * The lines of `$messages`, in their order.
     *
     * @param AIConversationMessages $messages the loaded rows of the judged conversation
     * @return static
     */
    public static function fromMessages(AIConversationMessages $messages): static
    {
        $agentEvalJudgeTraceMessageLines = static::newInstance();
        foreach ($messages->getElements() as $position => $message) {
            $agentEvalJudgeTraceMessageLines->addMessage((int)($message->indexInConversation ?? $position), $message);
        }
        return $agentEvalJudgeTraceMessageLines;
    }

    /**
     * The system prompt as the trace shows it: its headline, the prompt, a blank line.
     *
     * @param string $systemPrompt
     * @return string
     */
    public static function buildSystemPromptSection(string $systemPrompt): string
    {
        return sprintf(static::SYSTEM_PROMPT_HEADLINE, AgentApplicationWording::current()->agentSpaceContextLabel) . "\n" . $systemPrompt . "\n";
    }

    /**
     * The lines, in message order.
     *
     * @return string[]
     */
    public function getLines(): array
    {
        return $this->lines;
    }

    /**
     * Adds the lines of one message: those of its request snapshot, then its own.
     *
     * @param int $messageIndex the stored index
     * @param AIConversationMessage $message
     * @return void
     */
    protected function addMessage(int $messageIndex, AIConversationMessage $message): void
    {
        $requestSnapshot = isset($message->requestSnapshot) ? $message->requestSnapshot : null;
        if ($requestSnapshot !== null) {
            $this->addRequestSnapshotLines($messageIndex, $requestSnapshot);
        }
        if (($message->messageType ?? null) === AIConversationMessage::MESSAGE_TOOL_CALL) {
            $this->lines[] = $this->buildToolCallLine($messageIndex, $message);
            $this->addSubAgentToolCallLines($messageIndex, $message, 1);
            return;
        }
        if ($this->isOwnerReply($message)) {
            $this->ownerReplyCount++;
        }
        $messageLine = $this->buildMessageLine($messageIndex, $message);
        if ($messageLine !== null) {
            $this->lines[] = $messageLine;
        }
    }

    /**
     * Adds the lines of a request snapshot: the clock line, the VARIANTS line where the situation changed (and the
     * call's skill sections remembered), what an append-only call appended, a started state epoch and the delivered
     * state notes.
     *
     * @param int $messageIndex
     * @param RequestSnapshot $requestSnapshot
     * @return void
     */
    protected function addRequestSnapshotLines(int $messageIndex, RequestSnapshot $requestSnapshot): void
    {
        $clockLine = $this->buildClockLine($messageIndex, $requestSnapshot);
        if ($clockLine !== null) {
            $this->lines[] = $clockLine;
        }
        if (is_array($requestSnapshot->variants)) {
            $snapshotVariantTokens = $this->buildVariantTokensOfRequestSnapshot($requestSnapshot);
            if ($snapshotVariantTokens !== $this->currentVariantTokens) {
                $this->lines[] = $this->buildVariantsLine($messageIndex, $snapshotVariantTokens);
                $this->currentVariantTokens = $snapshotVariantTokens;
            }
            $this->currentSkillSections = is_array($requestSnapshot->skillSections) ? $requestSnapshot->skillSections : [];
        }
        if ($requestSnapshot->appendedSkillNames !== null || $requestSnapshot->appendedToolNames !== null) {
            $this->lines[] = sprintf(
                static::SKILLS_LOADED_LINE,
                $messageIndex,
                implode(',', $requestSnapshot->appendedSkillNames ?? []),
                implode(',', $requestSnapshot->appendedToolNames ?? [])
            );
        }
        $spaceSubjectName = AgentApplicationWording::current()->agentSpaceContextSubjectName;
        if ($requestSnapshot->stateEpochCause !== null) {
            $this->lines[] = sprintf(static::STATE_EPOCH_START_LINE, $messageIndex, $requestSnapshot->stateEpochCause, $spaceSubjectName);
        }
        if (is_array($requestSnapshot->stateDeltas) && $requestSnapshot->stateDeltas !== []) {
            $stateNoteTexts = array_map(
                static fn (array $stateDelta): string => (string)($stateDelta['kind'] ?? '?') . ': '
                    . mb_substr(preg_replace('/\s+/', ' ', (string)($stateDelta['text'] ?? '')) ?? '', 0, static::STATE_NOTE_MAX_LENGTH),
                $requestSnapshot->stateDeltas
            );
            $this->lines[] = sprintf(static::STATE_NOTES_LINE, $messageIndex, $spaceSubjectName, implode(' | ', $stateNoteTexts));
        }
    }

    /**
     * The clock line of a call: the instant the model was told is "now", in the zone the call thought in, as local
     * date, weekday and time. Null where the call had no clock signal (a snapshot without a clock or without a zone:
     * the model then got no clock either).
     *
     * @param int $messageIndex
     * @param RequestSnapshot $requestSnapshot
     * @return string|null
     */
    protected function buildClockLine(int $messageIndex, RequestSnapshot $requestSnapshot): ?string
    {
        $clockAt = $requestSnapshot->clockAt ?? null;
        $timezoneIdentifier = $requestSnapshot->timezoneIdentifier ?? null;
        if ($clockAt === null || $timezoneIdentifier === null || $timezoneIdentifier === '') {
            return null;
        }
        $timezone = new \DateTimeZone($timezoneIdentifier);
        $localClock = \DateTimeImmutable::createFromInterface($clockAt)->setTimezone($timezone);
        return sprintf(static::CLOCK_LINE, $messageIndex, $timezone->getName(), $localClock->format(static::CLOCK_LOCAL_FORMAT));
    }

    /**
     * The situation a request snapshot was rendered with, by dimension, without its evidence row (a re-read with
     * newer evidence of the same value is no transition).
     *
     * @param RequestSnapshot $requestSnapshot
     * @return array<string, string>
     */
    protected function buildVariantTokensOfRequestSnapshot(RequestSnapshot $requestSnapshot): array
    {
        $variantTokens = [];
        foreach ((array)$requestSnapshot->variants as $dimension => $token) {
            $variantTokens[(string)$dimension] = (string)preg_replace('/@\d+/', '', (string)$token);
        }
        return $variantTokens;
    }

    /**
     * The VARIANTS line: each dimension that changed since the last call with a situation, before and after.
     *
     * @param int $messageIndex
     * @param array<string, string> $variantTokens
     * @return string
     */
    protected function buildVariantsLine(int $messageIndex, array $variantTokens): string
    {
        $transitions = [];
        foreach ($variantTokens as $dimension => $token) {
            $previousToken = $this->currentVariantTokens[$dimension] ?? null;
            if ($previousToken !== $token) {
                $transitions[] = $dimension . ': ' . ($previousToken ?? 'none') . ' → ' . $token;
            }
        }
        return sprintf(static::VARIANTS_LINE, $messageIndex, implode(' ', $transitions));
    }

    /**
     * The line of a tool call.
     *
     * @param int $messageIndex
     * @param AIConversationMessage $message
     * @return string
     */
    protected function buildToolCallLine(int $messageIndex, AIConversationMessage $message): string
    {
        $skillsText = $message->hasLoadedCapabilities() ? (' skills=' . implode(',', $message->getLoadedSkillNames())) : '';
        if ($this->currentSkillSections !== null) {
            $skillsText .= ' sections=' . ($this->currentSkillSections === [] ? 'none' : implode(',', $this->currentSkillSections));
        }
        $toolName = (string)($message->toolCall->name ?? '?');
        $aIConversationAuditRenderService = $this->getAuditRenderService();
        return sprintf(
            static::TOOL_LINE,
            $messageIndex,
            $toolName,
            $message->toolCall->argumentsAsJson(),
            $message->toolCall->status ?? '?',
            $skillsText,
            $aIConversationAuditRenderService::isReadOnlyToolName($toolName) ? static::READ_MARKER : '',
            $this->getToolResultTextAsTheModelReceivedIt($message)
        );
    }

    /**
     * Adds, under a `delegate` call, the tool calls of every sub-agent it started, results shortened to
     * {@see SUB_AGENT_RESULT_MAX_CHARS}: the parent's result carries only the children's verdicts, so without these
     * lines a source or date a child found reads as invented. Only children already in memory (the evaluation loads
     * them before judging); nothing is loaded here.
     *
     * @param int $messageIndex the stored index of the `delegate` call
     * @param AIConversationMessage $message
     * @param int $depth 1 for the children of the judged conversation
     * @return void
     */
    protected function addSubAgentToolCallLines(int $messageIndex, AIConversationMessage $message, int $depth): void
    {
        if ($depth > static::SUB_AGENT_MAX_DEPTH || !$message->isDelegationCall() || !isset($message->delegatedConversations)) {
            return;
        }
        $indent = str_repeat('    ', $depth);
        foreach ($message->delegatedConversations->getElements() as $childConversation) {
            if (!isset($childConversation->messages)) {
                continue;
            }
            $this->lines[] = sprintf(static::SUB_AGENT_HEADLINE, $indent, $messageIndex, '#' . ($childConversation->id ?? '?'));
            foreach ($childConversation->messages->getElements() as $childMessage) {
                if (($childMessage->messageType ?? null) !== AIConversationMessage::MESSAGE_TOOL_CALL || !isset($childMessage->toolCall)) {
                    continue;
                }
                $resultText = $this->getToolResultTextAsTheModelReceivedIt($childMessage);
                if (mb_strlen($resultText) > static::SUB_AGENT_RESULT_MAX_CHARS) {
                    $resultText = mb_substr($resultText, 0, static::SUB_AGENT_RESULT_MAX_CHARS) . ' …(shortened)';
                }
                $this->lines[] = sprintf(
                    static::SUB_AGENT_TOOL_LINE,
                    $indent,
                    (string)($childMessage->toolCall->name ?? '?'),
                    $childMessage->toolCall->argumentsAsJson(),
                    $childMessage->toolCall->status ?? '?',
                    $indent,
                    str_replace("\n", "\n" . $indent . '    ', $resultText)
                );
                $this->addSubAgentToolCallLines($messageIndex, $childMessage, $depth + 1);
            }
        }
    }

    /**
     * The result of a tool call as the model received it in the call that followed: without the fields the platform
     * keeps for itself, with the platform note the model read; a delegation call gives its children's verdicts. A fact
     * only the stored row carries is no evidence the agent could read.
     *
     * @param AIConversationMessage $message
     * @return string
     */
    protected function getToolResultTextAsTheModelReceivedIt(AIConversationMessage $message): string
    {
        $toolResultContent = $message->toOpenAiToolResultArray()['content'] ?? null;
        return is_string($toolResultContent) ? $toolResultContent : $message->getToolResultText();
    }

    /**
     * Whether the row is numbered as an owner reply: a row with text whose role (or, for a row without a role, whose
     * author type) is the user's, written by the owner (or a legacy row without an author type), that is no signal, no
     * widget row, no suppressed repeat and no delivered report.
     *
     * @param AIConversationMessage $message
     * @return bool
     */
    protected function isOwnerReply(AIConversationMessage $message): bool
    {
        $messageType = $message->messageType ?? null;
        $signalSource = $message->signalSource ?? null;
        $authorType = $message->authorType ?? null;
        return $messageType !== AIConversationMessage::MESSAGE_SIGNAL
            && $messageType !== AIConversationMessage::MESSAGE_TOOL_MESSAGE
            && $signalSource !== AIConversationMessage::SIGNAL_SOURCE_REPEATED_TEXT_SUPPRESSED
            && $signalSource !== AIConversationMessage::SIGNAL_SOURCE_OWNER_REPORT
            && ($message->role ?? $authorType) === AIConversationMessage::ROLE_USER
            && ($authorType === AIConversationMessage::AUTHOR_USER || $authorType === null)
            && trim((string)($message->message ?? '')) !== '';
    }

    /**
     * The line of a message that is no tool call; null for a message without text.
     *
     * @param int $messageIndex
     * @param AIConversationMessage $message
     * @return string|null
     */
    protected function buildMessageLine(int $messageIndex, AIConversationMessage $message): ?string
    {
        $messageText = trim((string)($message->message ?? ''));
        if ($messageText === '') {
            return null;
        }
        $signalSource = $message->signalSource ?? null;
        if ($signalSource === AIConversationMessage::SIGNAL_SOURCE_REPEATED_TEXT_SUPPRESSED) {
            return sprintf(static::SYSTEM_NOTE_LINE, $messageIndex);
        }
        if ($signalSource === AIConversationMessage::SIGNAL_SOURCE_OWNER_REPORT) {
            return sprintf(static::OWNER_REPORT_LINE, $messageIndex, $messageText);
        }
        $messageType = $message->messageType ?? '?';
        if ($messageType === AIConversationMessage::MESSAGE_TOOL_MESSAGE) {
            return sprintf(static::TOOL_MESSAGE_LINE, $messageIndex, $this->describeWidgetRowWriter($message), $messageText);
        }
        if ($messageType === AIConversationMessage::MESSAGE_SIGNAL) {
            return $this->buildSignalLine($messageIndex, (string)($signalSource ?? '?'), $messageText);
        }
        $role = $message->role ?? $message->authorType ?? '';
        if ($this->isOwnerReply($message)) {
            return sprintf(static::OWNER_REPLY_LINE, $messageIndex, $messageType, $role, $this->ownerReplyCount, $messageText);
        }
        return sprintf(static::MESSAGE_LINE, $messageIndex, $messageType, $role, $messageText);
    }

    /**
     * The line of a signal, by the class of its source.
     *
     * @param int $messageIndex
     * @param string $signalSource
     * @param string $messageText
     * @return string
     */
    protected function buildSignalLine(int $messageIndex, string $signalSource, string $messageText): string
    {
        $conversationSignalSource = ConversationSignalSources::tryFrom($signalSource);
        if ($conversationSignalSource?->carriesAnOwnerStatement() === true) {
            return sprintf(static::OWNER_ACTION_SIGNAL_LINE, $messageIndex, $signalSource, $messageText);
        }
        if ($conversationSignalSource?->isLoopCorrectiveOrWorkContinuation() === true) {
            return sprintf(static::LOOP_CORRECTIVE_SIGNAL_LINE, $messageIndex, $signalSource);
        }
        return sprintf(static::PLATFORM_SIGNAL_LINE, $messageIndex, $signalSource, $messageText);
    }

    /**
     * Who wrote a widget row: the writer of each of its blocks, in block order, repeats dropped; a row without a block
     * is named as one.
     *
     * @param AIConversationMessage $message
     * @return string
     */
    protected function describeWidgetRowWriter(AIConversationMessage $message): string
    {
        $widgetWriters = [];
        $aIConversationAuditRenderService = $this->getAuditRenderService();
        foreach ($message->buildWidgetBlockOrigins() as $conversationWidgetOrigin) {
            $widgetWriters[] = $aIConversationAuditRenderService::describeWidgetWriter($conversationWidgetOrigin, $message->isToolMessage());
        }
        return $widgetWriters !== [] ? implode(', ', array_values(array_unique($widgetWriters))) : static::WIDGET_ROW_WITHOUT_BLOCK_WRITER;
    }

    /** The audit render service, resolved through the container (an application's same-path subclass included). */
    protected function getAuditRenderService(): AIConversationAuditRenderService
    {
        /** @var AIConversationAuditRenderService $aIConversationAuditRenderService */
        $aIConversationAuditRenderService = DDDService::instance()->getService(AIConversationAuditRenderService::class);
        return $aIConversationAuditRenderService;
    }
}
