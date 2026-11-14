<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Diagnostics;

use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunComparison;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\MCPPackages\ToolResult;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A stored tool call carries `wasRefusedAsFinal` only when it is true: a call that was not refused finally is stored
 * as before the flag existed, so a recorded run compares identical to its replay, and a stored row without the key
 * reads back as not refused finally.
 */
class AgentRecordedRunFinalRefusalKeyTest extends UnitTestCase
{
    public function testACallThatWasNotRefusedFinallyIsStoredWithoutTheKey(): void
    {
        $toolCall = $this->toolCall();
        $toolCall->applyExecutionOutcome(new ToolResult('done'));
        $storedToolCall = (string)$toolCall->toJSON();

        self::assertArrayNotHasKey('wasRefusedAsFinal', json_decode($storedToolCall, true, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * A recording made before the key existed holds it on no call; the replay of a call the executor refuses finally
     * holds it. The comparison leaves the key out of the replayed call then, and compares it once the recording holds it.
     */
    public function testTheReplayOfARecordingMadeBeforeTheKeyComparesWithoutIt(): void
    {
        $recordedBeforeTheKey = '{"name":"publishOffer","status":"FAILED"}';
        $replayedWithTheKey = '{"name":"publishOffer","status":"FAILED","wasRefusedAsFinal":true}';
        $withoutKeysTheRecordingPredates = new \ReflectionMethod(AgentRecordedRunComparison::class, 'withoutToolCallKeysTheRecordingPredates');

        self::assertSame($recordedBeforeTheKey, $withoutKeysTheRecordingPredates->invoke(null, $recordedBeforeTheKey, $replayedWithTheKey));
        self::assertSame(
            $replayedWithTheKey,
            $withoutKeysTheRecordingPredates->invoke(null, $replayedWithTheKey, $replayedWithTheKey),
            'a recording that holds the key compares it'
        );
    }

    public function testAFinalRefusalIsStoredAndReadBack(): void
    {
        $refusedResult = new ToolResult('Not executed. Do not retry.', true);
        $refusedResult->refusalIsFinal = true;
        $toolCall = $this->toolCall();
        $toolCall->applyExecutionOutcome($refusedResult);
        $storedStructure = json_decode((string)$toolCall->toJSON(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($storedStructure['wasRefusedAsFinal']);

        $readBack = new ToolCall();
        $storedObject = json_decode((string)$toolCall->toJSON(), false, 512, JSON_THROW_ON_ERROR);
        $readBack->setPropertiesFromObject($storedObject);
        self::assertTrue($readBack->isRefusedAsFinal());

        $readBackWithoutTheKey = new ToolCall();
        $storedObjectWithoutTheKey = json_decode('{"name":"publishOffer","status":"' . AIConversationMessage::STATUS_FAILED . '"}', false, 512, JSON_THROW_ON_ERROR);
        $readBackWithoutTheKey->setPropertiesFromObject($storedObjectWithoutTheKey);
        self::assertFalse($readBackWithoutTheKey->isRefusedAsFinal(), 'a row stored before the flag');
    }

    protected function toolCall(): ToolCall
    {
        $toolCall = new ToolCall();
        $toolCall->toolCallId = 'call_1';
        $toolCall->name = 'publishOffer';
        $toolCall->arguments = ['offerId' => 4];
        return $toolCall;
    }
}
