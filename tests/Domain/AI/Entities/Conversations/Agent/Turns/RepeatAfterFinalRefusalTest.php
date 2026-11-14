<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Turns;

use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentRepeatedToolCallCheck;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationToolCallHistory;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\MCPPackages\ToolResult;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultRecordingEntry;
use DDD\Presentation\Mcp\Dtos\MCPToolResponse;
use DDD\Tests\Agents\Presentation\Services\Probes\StubbedResponseToolExecutor;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The identical write right after its FINAL refusal in the same turn: how a final refusal travels from the executor's
 * response onto the call, which earlier call of the turn makes the repeat one, and what the model reads instead of a
 * second run.
 */
class RepeatAfterFinalRefusalTest extends UnitTestCase
{
    protected const string REFUSAL_TEXT = "Tool 'publishOffer' is not available here — it requires the product features OFFERS, which this "
        . 'plan does not include. Do not retry.';

    public function testAFinalRefusalIsAnErrorThatKeepsItsGateKind(): void
    {
        $finalRefusal = MCPToolResponse::finalRefusal('Not executed.');
        self::assertTrue($finalRefusal->isError);
        self::assertTrue($finalRefusal->isFinalRefusal());
        self::assertNull($finalRefusal->refusalKind);

        $finalGateRefusal = MCPToolResponse::finalRefusal('Not executed.', MCPToolResponse::REFUSAL_MANDATE_REQUIRED);
        self::assertTrue($finalGateRefusal->isFinalRefusal());
        self::assertSame(MCPToolResponse::REFUSAL_MANDATE_REQUIRED, $finalGateRefusal->refusalKind, 'the repeat guards keep not counting it');

        self::assertFalse(MCPToolResponse::gateRefusal('Not executed.', MCPToolResponse::REFUSAL_MANDATE_REQUIRED)->isFinalRefusal(), 'a gate that may open later');
        self::assertFalse(MCPToolResponse::error('Tool error.')->isFinalRefusal());
        self::assertArrayNotHasKey('refusalIsFinal', $finalRefusal->jsonSerialize(), 'never part of the MCP response');
    }

    public function testTheCallRecordsAFinalRefusalOnlyOnAnError(): void
    {
        $refusedResult = new ToolResult(self::REFUSAL_TEXT, true);
        $refusedResult->refusalIsFinal = true;
        $refusedCall = $this->writeCall(['offerId' => 4]);
        $refusedCall->applyExecutionOutcome($refusedResult);
        self::assertTrue($refusedCall->isRefusedAsFinal());
        self::assertSame(AIConversationMessage::STATUS_FAILED, $refusedCall->status);

        $succeededResult = new ToolResult('done', false);
        $succeededResult->refusalIsFinal = true;
        $succeededCall = $this->writeCall(['offerId' => 4]);
        $succeededCall->applyExecutionOutcome($succeededResult);
        self::assertFalse($succeededCall->isRefusedAsFinal());
        self::assertNull($succeededCall->wasRefusedAsFinal, 'never false: a null key stays off the stored row');
    }

    public function testARecordedRunKeepsTheFinalRefusal(): void
    {
        $refusedResult = new ToolResult(self::REFUSAL_TEXT, true);
        $refusedResult->refusalIsFinal = true;
        $storedEntry = ToolResultRecordingEntry::forToolResult('publishOffer', '{"offerId":4}', $refusedResult)->toStoredEntry();
        self::assertTrue($storedEntry['refusalIsFinal'] ?? false);
        self::assertTrue(ToolResultRecordingEntry::fromStoredEntry($storedEntry)->buildToolResult()->refusalIsFinal);

        $storedEntryWithoutFinalRefusal = ToolResultRecordingEntry::forToolResult('publishOffer', '{"offerId":4}', new ToolResult('done'))->toStoredEntry();
        self::assertSame(
            ['toolName', 'arguments', 'content', 'isError', 'refusalKind', 'platformNoteForModel', 'dictatedWidgetType', 'bindableResult'],
            array_keys($storedEntryWithoutFinalRefusal),
            'an entry without a final refusal is stored as before'
        );
    }

    public function testTheIdenticalWriteAfterItsFinalRefusalIsFound(): void
    {
        $refusedCall = $this->finallyRefusedRow(['offerId' => 4], 1);
        $repeat = $this->writeCallRow(['offerId' => 4], 2);
        $check = AgentRepeatedToolCallCheck::forToolCall($repeat->toolCall, $this->conversation($this->ownerText(0), $refusedCall, $repeat));

        self::assertSame($refusedCall->toolCall, $check->findFinalRefusalOfTheSameWriteInTurn());

        // the fork admission asks before the call's row is appended
        $notYetAppended = $this->writeCall(['offerId' => 4]);
        self::assertTrue(
            AgentRepeatedToolCallCheck::forToolCall($notYetAppended, $this->conversation($this->ownerText(0), $refusedCall))->wouldInterceptBeforeAppend(['publishOffer']),
            'the fork admission does not run it either'
        );
        $otherArguments = $this->writeCall(['offerId' => 5]);
        self::assertFalse(AgentRepeatedToolCallCheck::forToolCall($otherArguments, $this->conversation($this->ownerText(0), $refusedCall))->wouldInterceptBeforeAppend(['publishOffer']));
    }

    public function testOtherArgumentsAnotherRunInBetweenAReadOrAnEarlierTurnDoNotCount(): void
    {
        $refusedCall = $this->finallyRefusedRow(['offerId' => 4], 1);

        $otherArguments = $this->writeCallRow(['offerId' => 5], 2);
        self::assertNull(AgentRepeatedToolCallCheck::forToolCall($otherArguments->toolCall, $this->conversation($this->ownerText(0), $refusedCall, $otherArguments))->findFinalRefusalOfTheSameWriteInTurn());

        $ranAfterTheRefusal = $this->writeCallRow(['offerId' => 4], 2);
        $ranAfterTheRefusal->toolCall->markCompleted();
        $repeat = $this->writeCallRow(['offerId' => 4], 3);
        self::assertNull(
            AgentRepeatedToolCallCheck::forToolCall($repeat->toolCall, $this->conversation($this->ownerText(0), $refusedCall, $ranAfterTheRefusal, $repeat))->findFinalRefusalOfTheSameWriteInTurn(),
            'the newest identical call decides'
        );

        $refusedRead = $this->finallyRefusedRow(['offerId' => 4], 1);
        $refusedRead->toolCall->stampEffect(new MCPToolEffect(MCPToolEffect::READ));
        $repeatedRead = $this->writeCallRow(['offerId' => 4], 2);
        $repeatedRead->toolCall->stampEffect(new MCPToolEffect(MCPToolEffect::READ));
        self::assertNull(
            AgentRepeatedToolCallCheck::forToolCall($repeatedRead->toolCall, $this->conversation($this->ownerText(0), $refusedRead, $repeatedRead))->findFinalRefusalOfTheSameWriteInTurn(),
            'a read keeps its own repeat rules'
        );

        $gateRefusalThatMayOpen = $this->finallyRefusedRow(['offerId' => 4], 1);
        $gateRefusalThatMayOpen->toolCall->wasRefusedAsFinal = null;
        $gateRefusalThatMayOpen->toolCall->wasRefusedByGate = true;
        $repeatAfterTheGate = $this->writeCallRow(['offerId' => 4], 2);
        self::assertNull(
            AgentRepeatedToolCallCheck::forToolCall($repeatAfterTheGate->toolCall, $this->conversation($this->ownerText(0), $gateRefusalThatMayOpen, $repeatAfterTheGate))->findFinalRefusalOfTheSameWriteInTurn(),
            'a gate refusal that is not final is the sanctioned retry'
        );

        $repeatInTheNextTurn = $this->writeCallRow(['offerId' => 4], 3);
        self::assertNull(
            AgentRepeatedToolCallCheck::forToolCall($repeatInTheNextTurn->toolCall, $this->conversation($this->ownerText(0), $refusedCall, $this->ownerText(2), $repeatInTheNextTurn))->findFinalRefusalOfTheSameWriteInTurn(),
            'a new owner turn may have changed what the refusal depends on'
        );
    }

    public function testTheRepeatIsAnsweredWithTheRefusalAndTheMoveLeft(): void
    {
        $refusedCall = $this->finallyRefusedRow(['offerId' => 4], 1);
        $repeat = $this->writeCallRow(['offerId' => 4], 2);
        $check = AgentRepeatedToolCallCheck::forToolCall($repeat->toolCall, $this->conversation($this->ownerText(0), $refusedCall, $repeat));

        self::assertSame(
            'NOT executed: `publishOffer` with these arguments was already refused in this turn, and that refusal is final — the same '
                . 'call is refused again, and nothing was done. The refusal: «' . self::REFUSAL_TEXT . '» Write your answer now from what '
                . 'it says; the next step has no tools.',
            $check->buildRepeatAfterFinalRefusalCorrective($refusedCall->toolCall)
        );
    }

    /**
     * The sub-agent case, the owner's confirmation, a widget click, a connected channel: a signal row after the final
     * refusal may bring what was missing (a mandate the parent granted, the owner's consent), so the identical repeat
     * runs. Asked of the history directly: a signal row of any source counts, also one that starts no turn.
     */
    public function testASignalRowAfterTheFinalRefusalLetsTheRepeatRun(): void
    {
        foreach ([
            AIConversationMessage::SIGNAL_SOURCE_ORCHESTRATOR_MESSAGE,
            AIConversationMessage::SIGNAL_SOURCE_OWNER_ACTION_CONFIRM,
            AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_CLICK,
            AIConversationMessage::SIGNAL_SOURCE_FRONTEND_CHANNEL_CONNECTION,
            AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_RENDER_REPORT,
        ] as $signalSource) {
            $refusedCall = $this->finallyRefusedRow(['offerId' => 4], 1);
            $repeat = $this->writeCallRow(['offerId' => 4], 3);
            self::assertNull(
                AIConversationToolCallHistory::fromRows([$refusedCall, $this->signalRow($signalSource, 2), $repeat])->getNewestEarlierIdenticalCallWhenRefusedAsFinal($repeat->toolCall),
                $signalSource
            );
            self::assertSame(
                $refusedCall->toolCall,
                AIConversationToolCallHistory::fromRows([$this->signalRow($signalSource, 0), $refusedCall, $repeat])->getNewestEarlierIdenticalCallWhenRefusedAsFinal($repeat->toolCall),
                $signalSource . ': a signal BEFORE the refusal changes nothing'
            );
        }
    }

    /**
     * A scheduled run whose write was refused for a missing mandate: a completed write after the refusal (the mandate
     * granted) lets the identical repeat run. A write the loop refused as a repeat, a read and a failed write do not.
     */
    public function testACompletedWriteAfterTheFinalRefusalLetsTheRepeatRun(): void
    {
        $refusedCall = $this->finallyRefusedRow(['offerId' => 4], 1);
        $grant = $this->otherCallRow('grantMandate', MCPToolEffect::WRITE, 2);
        $grant->toolCall->markCompleted();
        $repeat = $this->writeCallRow(['offerId' => 4], 3);
        self::assertNull(
            AgentRepeatedToolCallCheck::forToolCall($repeat->toolCall, $this->conversation($this->ownerText(0), $refusedCall, $grant, $repeat))->findFinalRefusalOfTheSameWriteInTurn(),
            'the granted mandate may be what the refusal asked for'
        );

        $refusedAsRepeat = $this->otherCallRow('grantMandate', MCPToolEffect::WRITE, 2);
        $refusedAsRepeat->toolCall->markCompleted();
        $refusedAsRepeat->toolCall->wasRefusedAsDuplicate = true;
        $read = $this->otherCallRow('listMandates', MCPToolEffect::READ, 3);
        $read->toolCall->markCompleted();
        $failedWrite = $this->otherCallRow('grantMandate', MCPToolEffect::WRITE, 4);
        $failedWrite->toolCall->markFailed();
        $repeatAfterNoChange = $this->writeCallRow(['offerId' => 4], 5);
        self::assertSame(
            $refusedCall->toolCall,
            AgentRepeatedToolCallCheck::forToolCall($repeatAfterNoChange->toolCall, $this->conversation($this->ownerText(0), $refusedCall, $refusedAsRepeat, $read, $failedWrite, $repeatAfterNoChange))->findFinalRefusalOfTheSameWriteInTurn(),
            'nothing changed: a refused repeat, a read and a failed write'
        );
    }

    /**
     * The quoted refusal is cut at a sentence end at or below the limit, else at a word end; the longest refusal the
     * package writes, the mandate refusal with long names, is quoted whole with its closing instruction.
     */
    public function testTheQuotedRefusalIsCutAtASentenceEndAndThePackagesRefusalsAreQuotedWhole(): void
    {
        self::assertSame('Short. Whole.', AgentRepeatedToolCallCheck::cutAtSentenceEnd('Short. Whole.', 13));
        self::assertSame('One sentence. Two sentences. …', AgentRepeatedToolCallCheck::cutAtSentenceEnd('One sentence. Two sentences. Three sentences.', 30));
        self::assertSame('One sentence. Two sentences. …', AgentRepeatedToolCallCheck::cutAtSentenceEnd('One sentence. Two sentences. Three sentences.', 35), 'a limit inside a word of the next sentence: the sentence end wins over the word end');
        self::assertSame('One sentence. Two sentences. …', AgentRepeatedToolCallCheck::cutAtSentenceEnd('One sentence. Two sentences. Three sentences.', 28), 'a sentence that ends at the limit is kept');
        self::assertSame('One long sentence without an …', AgentRepeatedToolCallCheck::cutAtSentenceEnd('One long sentence without an end before the limit', 30), 'no sentence end: the last whole word');
        self::assertSame('abcde …', AgentRepeatedToolCallCheck::cutAtSentenceEnd('abcdefghij', 5), 'no word end: at the limit');

        $mandateRefusalText = (string)(new StubbedResponseToolExecutor())->callBuildMandateRequiredRefusal(
            'publishSeasonalOfferToEveryConnectedChannel',
            'ONE_INSTRUCTION_ACTION_WITH_A_LONG_NAME',
            'publish an offer to every connected channel of the business, the website included'
        )->content[0]['text'];
        self::assertGreaterThan(400, mb_strlen($mandateRefusalText));
        self::assertSame($mandateRefusalText, AgentRepeatedToolCallCheck::cutAtSentenceEnd($mandateRefusalText, AgentRepeatedToolCallCheck::MAX_QUOTED_FINAL_REFUSAL_CHARS));

        $refusedCall = $this->finallyRefusedRow(['offerId' => 4], 1);
        $refusedCall->toolCall->result = json_encode(['content' => [['type' => 'text', 'text' => $mandateRefusalText]], 'isError' => true], JSON_THROW_ON_ERROR);
        $repeat = $this->writeCallRow(['offerId' => 4], 2);
        self::assertStringContainsString(
            '«' . $mandateRefusalText . '»',
            AgentRepeatedToolCallCheck::forToolCall($repeat->toolCall, $this->conversation($this->ownerText(0), $refusedCall, $repeat))->buildRepeatAfterFinalRefusalCorrective($refusedCall->toolCall),
            'the closing instruction "Do not retry here." is quoted'
        );
    }

    public function testAThirdIdenticalCallQuotesTheExecutorsRefusal(): void
    {
        $refusedCall = $this->finallyRefusedRow(['offerId' => 4], 1);
        $secondCall = $this->writeCallRow(['offerId' => 4], 2);
        $secondCheck = AgentRepeatedToolCallCheck::forToolCall($secondCall->toolCall, $this->conversation($this->ownerText(0), $refusedCall, $secondCall));
        // the loop's answer to the second call, as the dispatch writes it
        $secondCall->toolCall->result = $secondCheck->buildRepeatAfterFinalRefusalCorrective($refusedCall->toolCall);
        $secondCall->toolCall->markFailed();
        $secondCall->toolCall->wasRefusedAsDuplicate = true;
        $secondCall->toolCall->wasRefusedAsFinal = true;
        $thirdCall = $this->writeCallRow(['offerId' => 4], 3);
        $thirdCheck = AgentRepeatedToolCallCheck::forToolCall($thirdCall->toolCall, $this->conversation($this->ownerText(0), $refusedCall, $secondCall, $thirdCall));

        self::assertSame($refusedCall->toolCall, $thirdCheck->findFinalRefusalOfTheSameWriteInTurn());
        $thirdAnswer = $thirdCheck->buildRepeatAfterFinalRefusalCorrective($refusedCall->toolCall);
        self::assertStringContainsString('«' . self::REFUSAL_TEXT . '»', $thirdAnswer);
        self::assertSame(1, substr_count($thirdAnswer, 'NOT executed'), 'the earlier answer is not quoted inside the new one');
    }

    protected function signalRow(string $signalSource, int $index): AIConversationMessage
    {
        $message = $this->row(AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, 'the mandate is granted, publish it', $index);
        $message->messageType = AIConversationMessage::MESSAGE_SIGNAL;
        $message->signalSource = $signalSource;
        return $message;
    }

    protected function otherCallRow(string $toolName, string $effect, int $index): AIConversationMessage
    {
        $message = $this->row(AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, '', $index);
        $message->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        /** @var ToolCall $toolCall */
        $toolCall = self::instantiateWithoutConstructor(ToolCall::class);
        $toolCall->name = $toolName;
        $toolCall->arguments = ['actionClass' => 'PUBLISH'];
        $toolCall->stampEffect(new MCPToolEffect($effect));
        $message->toolCall = $toolCall;
        return $message;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function writeCall(array $arguments): ToolCall
    {
        /** @var ToolCall $toolCall */
        $toolCall = self::instantiateWithoutConstructor(ToolCall::class);
        $toolCall->name = 'publishOffer';
        $toolCall->arguments = $arguments;
        $toolCall->stampEffect(new MCPToolEffect(MCPToolEffect::WRITE));
        return $toolCall;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function writeCallRow(array $arguments, int $index): AIConversationMessage
    {
        $message = $this->row(AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, '', $index);
        $message->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        $message->toolCall = $this->writeCall($arguments);
        return $message;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function finallyRefusedRow(array $arguments, int $index): AIConversationMessage
    {
        $message = $this->writeCallRow($arguments, $index);
        $refusedResult = new ToolResult(json_encode(['content' => [['type' => 'text', 'text' => self::REFUSAL_TEXT]], 'isError' => true], JSON_THROW_ON_ERROR), true);
        $refusedResult->refusalIsFinal = true;
        $message->toolCall->applyExecutionOutcome($refusedResult);
        $message->toolCall->result = $refusedResult->content;
        return $message;
    }

    protected function ownerText(int $index): AIConversationMessage
    {
        return $this->row(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'publish the offer', $index);
    }

    protected function row(string $role, string $authorType, string $text, int $index): AIConversationMessage
    {
        /** @var AIConversationMessage $message */
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->role = $role;
        $message->authorType = $authorType;
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->message = $text;
        $message->indexInConversation = $index;
        return $message;
    }

    protected function conversation(AIConversationMessage ...$rows): AIConversation
    {
        $messages = new AIConversationMessages();
        foreach ($rows as $row) {
            $messages->add($row);
        }
        /** @var AIConversation $conversation */
        $conversation = self::instantiateWithoutConstructor(AIConversation::class);
        $conversation->messages = $messages;
        return $conversation;
    }
}
