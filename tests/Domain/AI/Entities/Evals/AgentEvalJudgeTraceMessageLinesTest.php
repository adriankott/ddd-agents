<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\DelegationResults;
use DDD\Domain\AI\Entities\Conversations\Messages\RequestSnapshot;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Evals\AgentEvalJudgeTraceMessageLines;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use ReflectionClass;

/**
 * The message lines of the judge's trace carry what the judge reads and the agent never sees: the stored index, the
 * owner-reply numbering, signal rows labelled by their class, `(READ — persists nothing)` on a call that stores
 * nothing, a widget row's writer read from its blocks, the result of a tool call as the model received it (a
 * delegation call's children's verdicts, no field the platform keeps for itself, the platform note the model read),
 * the bookkeeping of a suppressed repeat and of a delivered report, and the lines of the request snapshots.
 */
class AgentEvalJudgeTraceMessageLinesTest extends IntegrationTestCase
{
    protected function buildMessage(int $index, string $messageType, ?string $role, ?string $authorType, string $text = '', ?string $signalSource = null): AIConversationMessage
    {
        /** @var AIConversationMessage $message */
        $message = (new ReflectionClass(AIConversationMessage::class))->newInstanceWithoutConstructor();
        $message->indexInConversation = $index;
        $message->messageType = $messageType;
        $message->role = $role;
        $message->authorType = $authorType;
        $message->message = $text;
        $message->signalSource = $signalSource;
        $message->toolCall = null;
        return $message;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function buildToolCallMessage(int $index, string $toolName, array $arguments = [], string $status = AIConversationMessage::STATUS_COMPLETED, string $result = 'ok'): AIConversationMessage
    {
        $message = $this->buildMessage($index, AIConversationMessage::MESSAGE_TOOL_CALL, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_AGENT);
        $toolCall = new ToolCall();
        $toolCall->name = $toolName;
        $toolCall->arguments = $arguments;
        $toolCall->status = $status;
        $toolCall->result = $result;
        $message->toolCall = $toolCall;
        return $message;
    }

    protected function buildTraceText(AIConversationMessage ...$orderedMessages): string
    {
        $messages = new AIConversationMessages();
        foreach ($orderedMessages as $message) {
            $messages->add($message);
        }
        return implode("\n", AgentEvalJudgeTraceMessageLines::fromMessages($messages)->getLines());
    }

    public function testADelegationCallRendersTheChildVerdictsFromTheDelegationResults(): void
    {
        $delegationMessage = $this->buildToolCallMessage(1, 'delegate', [], AIConversationMessage::STATUS_COMPLETED, '');
        $delegationResults = new DelegationResults();
        $delegationResults->mapFromRepository([
            '67866' => [
                'task' => 'Read the current listing status',
                'verdict' => '55 entries checked — 13 listed, 8 missing, 34 with differing data',
            ],
        ]);
        $delegationMessage->delegationResults = $delegationResults;

        $traceText = $this->buildTraceText($delegationMessage);

        self::assertStringContainsString('34 with differing data', $traceText);
        self::assertStringContainsString('Read the current listing status', $traceText);
    }

    public function testAnOrdinaryToolCallRendersItsStoredResult(): void
    {
        self::assertStringContainsString('Entries — 55 entries · 13 listed', $this->buildTraceText($this->buildToolCallMessage(1, 'getEntries', [], AIConversationMessage::STATUS_COMPLETED, 'Entries — 55 entries · 13 listed')));
    }

    public function testAFactThatOnlyTheStoredRowCarriesIsNotShownToTheJudge(): void
    {
        $storedResult = (string)json_encode([
            'content' => [['type' => 'text', 'text' => 'Verification: on file']],
            'preservedFacts' => ['verificationIsComplete' => false, 'missingField' => 'taxNumber'],
        ]);

        $traceText = $this->buildTraceText($this->buildToolCallMessage(1, 'getRecord', [], AIConversationMessage::STATUS_COMPLETED, $storedResult));

        self::assertStringContainsString('Verification: on file', $traceText);
        self::assertStringNotContainsString('preservedFacts', $traceText);
        self::assertStringNotContainsString('taxNumber', $traceText);
    }

    public function testThePlatformNoteTheModelReadWithTheResultIsShownToTheJudge(): void
    {
        $toolCallMessage = $this->buildToolCallMessage(1, 'getEntries', [], AIConversationMessage::STATUS_COMPLETED, 'Entries — 55 entries · 13 listed');
        $toolCallMessage->toolCall->platformNoteForModel = 'The entry read is a day old: say so when you quote it.';

        self::assertStringContainsString(
            "Entries — 55 entries · 13 listed\n\nThe entry read is a day old: say so when you quote it.",
            $this->buildTraceText($toolCallMessage)
        );
    }

    public function testAWidgetRowNamesItsWriterFromTheBlocks(): void
    {
        $toolBlock = "```widget\n{\"type\":\"OwnerQuestion\",\"options\":[],\"emittedBy\":\"tool:resolveIdentity\"}\n```";
        $platformBlock = "```widget\n{\"type\":\"QuickReplies\",\"options\":[\"Yes\"],\"emittedBy\":\"platform:setsQuestion\"}\n```";
        $traceText = $this->buildTraceText(
            $this->buildMessage(1, AIConversationMessage::MESSAGE_TOOL_MESSAGE, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_TOOL, $toolBlock, 'borrowedName'),
            $this->buildMessage(2, AIConversationMessage::MESSAGE_TOOL_MESSAGE, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_TOOL, "Which one?\n\n" . $platformBlock, 'platform:setsQuestion'),
            $this->buildMessage(3, AIConversationMessage::MESSAGE_TOOL_MESSAGE, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_TOOL, "```widget\n{\"type\":\"OwnerQuestion\",\"options\":[]}\n```", 'resolveIdentity'),
            $this->buildMessage(4, AIConversationMessage::MESSAGE_TOOL_MESSAGE, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_TOOL, $toolBlock . "\n\n" . $platformBlock, 'x'),
            $this->buildMessage(5, AIConversationMessage::MESSAGE_TOOL_MESSAGE, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_TOOL, 'Plain text row.'),
        );

        self::assertStringContainsString('[1] TOOL_MESSAGE (a widget row written into the chat by the TOOL resolveIdentity (a widget row) — owner-visible, model-invisible; NOT composed by the agent and not one of its messages): ', $traceText);
        self::assertStringContainsString('[2] TOOL_MESSAGE (a widget row written into the chat by the PLATFORM (a widget row, reason: setsQuestion) — ', $traceText);
        self::assertStringContainsString('[3] TOOL_MESSAGE (a widget row written into the chat by a TOOL or the PLATFORM (a widget row stored without its origin) — ', $traceText);
        self::assertStringContainsString('[4] TOOL_MESSAGE (a widget row written into the chat by the TOOL resolveIdentity (a widget row), the PLATFORM (a widget row, reason: setsQuestion) — ', $traceText);
        self::assertStringContainsString('[5] TOOL_MESSAGE (a widget row written into the chat by a TOOL or the PLATFORM (a row without a widget block) — ', $traceText);
        self::assertStringNotContainsString('borrowedName', $traceText, 'the row label is no origin');
        self::assertStringNotContainsString('card', $traceText);
    }

    public function testRowsCarryTheStoredIndexOwnerReplyNumbersAndSignalClasses(): void
    {
        $traceText = $this->buildTraceText(
            // the loaded set starts at index 40 (a bounded tail): the printed numbers are the stored ones
            $this->buildMessage(40, AIConversationMessage::MESSAGE_TEXT, AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'Hello'),
            $this->buildMessage(41, AIConversationMessage::MESSAGE_TEXT, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, 'Hi — what do you need?'),
            $this->buildMessage(42, AIConversationMessage::MESSAGE_SIGNAL, AIConversationMessage::ROLE_SYSTEM, AIConversationMessage::AUTHOR_AGENT, 'You have the skills … loaded', AIConversationMessage::SIGNAL_SOURCE_LOOP_NUDGE),
            $this->buildMessage(43, AIConversationMessage::MESSAGE_SIGNAL, AIConversationMessage::ROLE_SYSTEM, AIConversationMessage::AUTHOR_AGENT, '{"widget":"ChannelConnections","action":"clicked"}', AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_CLICK),
            $this->buildMessage(44, AIConversationMessage::MESSAGE_SIGNAL, AIConversationMessage::ROLE_SYSTEM, AIConversationMessage::AUTHOR_AGENT, '[SUPERVISOR] Do this NOW: …', AIConversationMessage::SIGNAL_SOURCE_SUPERVISOR_STEERING),
            $this->buildMessage(45, AIConversationMessage::MESSAGE_TEXT, AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'The second entry'),
            $this->buildMessage(46, AIConversationMessage::MESSAGE_TEXT, AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, '   '),
            // a row without a role is read by its author type
            $this->buildMessage(47, AIConversationMessage::MESSAGE_TEXT, null, AIConversationMessage::AUTHOR_USER, 'Only the newest one.'),
        );

        self::assertStringContainsString('[40] TEXT USER (owner reply 1): Hello', $traceText);
        self::assertStringContainsString('[41] TEXT ASSISTANT: Hi — what do you need?', $traceText);
        self::assertStringContainsString('[45] TEXT USER (owner reply 2): The second entry', $traceText);
        self::assertStringContainsString('[47] TEXT USER (owner reply 3): Only the newest one.', $traceText);
        self::assertStringContainsString('[42] SIGNAL(LOOP_NUDGE — the platform\'s loop corrective, neither an agent message nor an owner answer; text left out)', $traceText);
        self::assertStringNotContainsString('You have the skills', $traceText, 'the corrective\'s text is glossed away');
        self::assertStringContainsString('[43] SIGNAL(FRONTEND_WIDGET_CLICK — owner action, counts as owner evidence): {"widget"', $traceText);
        self::assertStringContainsString('[44] SIGNAL(SUPERVISOR_STEERING — platform machinery, neither an agent message nor an owner answer): [SUPERVISOR] Do this NOW', $traceText);
        self::assertStringNotContainsString('[46]', $traceText, 'a row without text has no line');
        self::assertStringNotContainsString('[0] ', $traceText);
    }

    public function testReadRowsSayTheyPersistNothing(): void
    {
        $traceText = $this->buildTraceText(
            $this->buildToolCallMessage(2, 'proposeEntries', ['maxEntries' => 12]),
            $this->buildToolCallMessage(3, 'searchAreas', ['searchInput' => 'North']),
            $this->buildToolCallMessage(4, 'addEntries', ['entries' => ['north street']]),
        );

        self::assertStringContainsString("[2] TOOL proposeEntries({\"maxEntries\":12}) [COMPLETED] (READ — persists nothing)\n    result: ok", $traceText);
        self::assertStringContainsString('[3] TOOL searchAreas({"searchInput":"North"}) [COMPLETED] (READ — persists nothing)', $traceText);
        self::assertStringContainsString('[4] TOOL addEntries({"entries":["north street"]}) [COMPLETED]' . "\n", $traceText);
    }

    public function testASuppressedRepeatIsBookkeepingAndADeliveredReportIsLabelledAsSuch(): void
    {
        $traceText = $this->buildTraceText(
            $this->buildMessage(7, AIConversationMessage::MESSAGE_SIGNAL, AIConversationMessage::ROLE_SYSTEM, AIConversationMessage::AUTHOR_AGENT, 'the repeated text was NOT delivered', AIConversationMessage::SIGNAL_SOURCE_REPEATED_TEXT_SUPPRESSED),
            $this->buildMessage(8, AIConversationMessage::MESSAGE_TEXT, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, 'Weekly report: all good.', AIConversationMessage::SIGNAL_SOURCE_OWNER_REPORT),
        );

        self::assertStringContainsString('[7] SYSTEM NOTE (loop bookkeeping, NOT an agent message, invisible to the owner): ', $traceText);
        self::assertStringContainsString('NOT a missing delivery', $traceText);
        self::assertStringNotContainsString('was NOT delivered', $traceText, 'the raw wording would read as a missing delivery');
        self::assertStringContainsString("[8] OWNER_REPORT (the turn's terminal report: ", $traceText);
        self::assertStringContainsString('NOT a repetition by the agent): Weekly report: all good.', $traceText);
    }

    public function testTheRequestSnapshotsNameTheSituationTransitionsTheAppendedSkillsTheStateEpochAndTheStateNotes(): void
    {
        $firstCall = $this->buildToolCallMessage(3, 'getEntries');
        $firstSnapshot = new RequestSnapshot();
        $firstSnapshot->variants = ['reach' => 'proposed@2', 'serve' => 'owner@1'];
        $firstSnapshot->skillSections = ['reach.local'];
        $firstSnapshot->appendedSkillNames = ['Entries.Main'];
        $firstSnapshot->appendedToolNames = ['getEntries'];
        $firstSnapshot->stateEpochCause = 'firstCall';
        $firstCall->requestSnapshot = $firstSnapshot;
        $sameSituationCall = $this->buildToolCallMessage(5, 'getEntries');
        $sameSituationSnapshot = new RequestSnapshot();
        // newer evidence of the same value is no transition
        $sameSituationSnapshot->variants = ['reach' => 'proposed@4', 'serve' => 'owner@1'];
        $sameSituationSnapshot->skillSections = [];
        $sameSituationSnapshot->stateDeltas = [['kind' => 'addressing', 'text' => "Call   the owner\n Sam"]];
        $sameSituationCall->requestSnapshot = $sameSituationSnapshot;
        $changedSituationCall = $this->buildToolCallMessage(7, 'getEntries');
        $changedSituationSnapshot = new RequestSnapshot();
        $changedSituationSnapshot->variants = ['reach' => 'owner@6', 'serve' => 'owner@1'];
        $changedSituationCall->requestSnapshot = $changedSituationSnapshot;

        $traceLines = explode("\n", $this->buildTraceText($firstCall, $sameSituationCall, $changedSituationCall));

        self::assertStringStartsWith('[3] VARIANTS (the SITUATION the platform resolved from the stored record', $traceLines[0]);
        self::assertStringEndsWith('): reach: none → proposed serve: none → owner', $traceLines[0]);
        self::assertSame('[3] SKILLS LOADED (appended to the history before this call): skills=Entries.Main tools=getEntries', $traceLines[1]);
        self::assertStringStartsWith('[3] STATE EPOCH START (firstCall): from this call on the agent\'s instructions carry the workspace record, ', $traceLines[2]);
        self::assertStringStartsWith('[3] TOOL getEntries({}) [COMPLETED] sections=reach.local (READ — persists nothing)', $traceLines[3]);
        self::assertStringStartsWith('[5] STATE NOTES (delivered to the agent as background before this call', $traceLines[5]);
        self::assertStringContainsString('the zone for dates, the workspace name and data;', $traceLines[5]);
        self::assertStringEndsWith('): addressing: Call the owner Sam', $traceLines[5]);
        self::assertStringStartsWith('[5] TOOL getEntries({}) [COMPLETED] sections=none (READ — persists nothing)', $traceLines[6]);
        self::assertStringEndsWith('): reach: proposed → owner', $traceLines[8]);
        self::assertStringStartsWith('[7] VARIANTS', $traceLines[8]);
    }

    /**
     * The clock of each call rides on the wire tail the judge never sees: the trace names it per call in the space's
     * zone (local date, weekday, time), so "tomorrow" is counted from the local date, not from the UTC one. A call
     * without a zone got no clock signal and gets no clock line.
     */
    public function testEachCallWithAClockGetsAClockLineInTheSpacesZone(): void
    {
        $lateEveningCall = $this->buildToolCallMessage(3, 'getEntries');
        $lateEveningSnapshot = new RequestSnapshot();
        // 23:30 UTC on Monday is already Tuesday 01:30 in Berlin (summer time)
        $lateEveningSnapshot->clockAt = new DateTime('2026-06-15 23:30:00', new \DateTimeZone('UTC'));
        $lateEveningSnapshot->timezoneIdentifier = 'Europe/Berlin';
        $lateEveningCall->requestSnapshot = $lateEveningSnapshot;
        $callWithoutZone = $this->buildToolCallMessage(5, 'getEntries');
        $snapshotWithoutZone = new RequestSnapshot();
        $snapshotWithoutZone->clockAt = new DateTime('2026-06-15 23:31:00', new \DateTimeZone('UTC'));
        $callWithoutZone->requestSnapshot = $snapshotWithoutZone;

        $traceLines = explode("\n", $this->buildTraceText($lateEveningCall, $callWithoutZone));

        self::assertSame(
            '[3] CLOCK (the "now" the agent was given for this call, in the space\'s zone Europe/Berlin — count today, tomorrow and weekdays from this local date): 2026-06-16 Tuesday 01:30',
            $traceLines[0]
        );
        self::assertStringStartsWith('[3] TOOL getEntries', $traceLines[1]);
        self::assertStringStartsWith('[5] TOOL getEntries', $traceLines[3], 'a call without a zone has no clock line');
        self::assertStringNotContainsString('[5] CLOCK', implode("\n", $traceLines));
    }

    public function testTheSystemPromptSectionNamesTheContextBlockInTheApplicationsWords(): void
    {
        self::assertSame(
            "[SYSTEM PROMPT — the base data it carries (in particular in its 'Work context' block) counts as GROUNDED, also without a tool call]\nthe prompt\n",
            AgentEvalJudgeTraceMessageLines::buildSystemPromptSection('the prompt')
        );
    }
}
