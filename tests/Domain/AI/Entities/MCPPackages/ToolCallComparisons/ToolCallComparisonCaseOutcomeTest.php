<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\MCPPackages\ToolCallComparisons;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons\ToolCallComparisonArgusRequest;
use DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons\ToolCallComparisonArgusRequests;
use DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons\ToolCallComparisonCaseOutcome;
use DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons\ToolCallComparisonIdPlaceholders;
use DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons\ToolCallComparisonRecording;
use DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons\ToolCallComparisonScript;
use DDD\Tests\Agents\Support\UnitTestCase;
use RuntimeException;

/**
 * The per-case comparison: an unchanged outcome is identical, and a changed note text, an extra write statement, an
 * extra dispatch, a changed row or a changed Argus request body each surface as a readable difference. The recording
 * round-trips and refuses another script or version.
 */
class ToolCallComparisonCaseOutcomeTest extends UnitTestCase
{
    /** @var string The note a tool appends to a saved result */
    public const string NEW_STATE_NOTE = 'The saved values above are the new state — do not re-read them.';

    protected static function buildRecordedOutcome(): ToolCallComparisonCaseOutcome
    {
        return ToolCallComparisonCaseOutcome::fromArray([
            'caseId' => 'updateOpeningHours.example.week',
            'toolName' => 'updateOpeningHours',
            'resultContent' => (string)json_encode(['content' => [['type' => 'text', 'text' => "Saved: notes='x'.\n" . self::NEW_STATE_NOTE]]]),
            'appendedRowsByConversation' => ['chat' => [['messageType' => 'TOOL_MESSAGE', 'role' => 'assistant', 'authorType' => 'tool', 'signalSource' => 'updateOpeningHours', 'message' => 'widget']]],
            'writeStatements' => [['kind' => 'UPDATE', 'table' => 'space_settings', 'set' => ['data' => '{"mon":"09-17"}'], 'where' => 'id = ?', 'whereValues' => [['column' => 'id', 'value' => 42]]]],
            'dispatches' => [['messageClass' => 'SpaceDivergenciesRefreshMessage', 'body' => ['agentSpaceId' => 21436118]]],
            'argusRequests' => [['endpoint' => 'POST:https://gateway.test/presence/space', 'body' => ['agentSpaceId' => '<comparison:agentSpace>', 'name' => 'Studio']]],
            'argusCallCount' => 1,
        ]);
    }

    /**
     * The outcome with its stored document changed by `$storedFieldChanges` (stored key → value).
     *
     * @param array $storedFieldChanges stored key → the changed value
     */
    protected static function buildChangedOutcome(array $storedFieldChanges): ToolCallComparisonCaseOutcome
    {
        return ToolCallComparisonCaseOutcome::fromArray(array_replace(self::buildRecordedOutcome()->toArray(), $storedFieldChanges));
    }

    public function testAnUnchangedOutcomeIsIdenticalAfterTheRecordingRoundTrip(): void
    {
        $recordedOutcome = self::buildRecordedOutcome();
        $replayedOutcome = ToolCallComparisonCaseOutcome::fromArray(json_decode((string)json_encode($recordedOutcome->toArray()), true));

        self::assertSame([], $recordedOutcome->buildDifferencesFrom($replayedOutcome));
    }

    public function testADeliberatelyChangedNoteTextIsReported(): void
    {
        $recordedOutcome = self::buildRecordedOutcome();
        $replayedOutcome = self::buildRecordedOutcome();
        $replayedOutcome->resultContent = str_replace('do not re-read', 'do not read again', $replayedOutcome->resultContent);

        $differences = $recordedOutcome->buildDifferencesFrom($replayedOutcome);

        self::assertCount(1, $differences);
        self::assertStringStartsWith('result #0: recorded «', $differences[0]);
        self::assertStringContainsString('do not read again', $differences[0]);
    }

    public function testAnExtraStatementAnExtraDispatchAndAChangedRowAreReported(): void
    {
        $recordedFields = self::buildRecordedOutcome()->toArray();
        $replayedOutcome = self::buildChangedOutcome([
            'writeStatements' => [$recordedFields['writeStatements'][0], $recordedFields['writeStatements'][0]],
            'dispatches' => [$recordedFields['dispatches'][0], $recordedFields['dispatches'][0]],
            'appendedRowsByConversation' => ['chat' => [array_replace($recordedFields['appendedRowsByConversation']['chat'][0], ['message' => 'another widget'])]],
            'isError' => true,
        ]);

        $differences = implode("\n", self::buildRecordedOutcome()->buildDifferencesFrom($replayedOutcome));

        self::assertStringContainsString('isError #0', $differences);
        self::assertStringContainsString('statement count: 1 recorded, 2 replayed', $differences);
        self::assertStringContainsString('dispatch count: 1 recorded, 2 replayed', $differences);
        self::assertStringContainsString('row of chat #0', $differences);
        self::assertSame(2, $replayedOutcome->countWriteStatements());
        self::assertSame(2, $replayedOutcome->countDispatches());
        self::assertSame(1, $replayedOutcome->countAppendedRows());
    }

    /**
     * The rows after the index before the call, in index order, the ids normalised; the label of a platform-written
     * widget row is left out, a tool's label is kept; a conversation without a new row keeps nothing.
     */
    public function testTheAppendedRowsAreTheRowsAfterTheIndexAndThePlatformRowLabelIsLeftOut(): void
    {
        $idPlaceholders = ToolCallComparisonIdPlaceholders::newInstance();
        $idPlaceholders->declareHarnessId(7001234, '<conversation:chat>');
        $conversationMessages = AIConversationMessages::newInstance();
        foreach ([[0, 'updateOpeningHours', 'old row'], [2, 'platform:planQuestion', 'widget of conversation 7001234'], [1, 'updateOpeningHours', 'widget']] as [$index, $signalSource, $text]) {
            $message = AIConversationMessage::newInstance();
            $message->id = 9100 + $index;
            $message->indexInConversation = $index;
            $message->messageType = 'TOOL_MESSAGE';
            $message->role = 'assistant';
            $message->authorType = 'tool';
            $message->signalSource = $signalSource;
            $message->message = $text;
            $conversationMessages->add($message);
        }
        $caseOutcome = ToolCallComparisonCaseOutcome::forCase('c', 't');

        $caseOutcome->keepAppendedRowsOf('chat', $conversationMessages, 0, $idPlaceholders);
        $caseOutcome->keepAppendedRowsOf('other', $conversationMessages, 2, $idPlaceholders);

        self::assertSame(['chat' => [
            ['messageType' => 'TOOL_MESSAGE', 'role' => 'assistant', 'authorType' => 'tool', 'signalSource' => 'updateOpeningHours', 'message' => 'widget'],
            ['messageType' => 'TOOL_MESSAGE', 'role' => 'assistant', 'authorType' => 'tool', 'message' => 'widget of conversation <conversation:chat>'],
        ]], $caseOutcome->toArray()['appendedRowsByConversation']);
        self::assertSame(2, $caseOutcome->countAppendedRows());
    }

    public function testTheRequestsOfTheApplicationsRecorderAndTheArgusRequestsAreKeptNormalised(): void
    {
        $idPlaceholders = ToolCallComparisonIdPlaceholders::newInstance();
        $idPlaceholders->declareHarnessId(42, '<comparison:agentSpace>');
        $hostRecording = new ToolCallComparisonHostRecordingDouble((string)json_encode([
            ['label' => 'c', 'request' => ['method' => 'GET', 'endpoint' => '/space/42', 'agentSpaceId' => 42]],
            ['label' => 'other', 'request' => ['method' => 'GET', 'endpoint' => '/space/43']],
        ]));
        $argusRequests = ToolCallComparisonArgusRequests::newInstance();
        $argusRequest = ToolCallComparisonArgusRequest::forRequest('POST:https://gateway.test/x', '{"agentSpaceId":42}');
        $argusRequests->add($argusRequest);
        $caseOutcome = ToolCallComparisonCaseOutcome::forCase('c', 't');

        $caseOutcome->keepHostRequestsOf($hostRecording, $idPlaceholders);
        $caseOutcome->keepArgusRequestsOf($argusRequests, $idPlaceholders);

        self::assertSame([['method' => 'GET', 'endpoint' => '/space/42', 'agentSpaceId' => '<comparison:agentSpace>']], $caseOutcome->toArray()['hostRequests']);
        self::assertSame([['endpoint' => 'POST:https://gateway.test/x', 'body' => ['agentSpaceId' => '<comparison:agentSpace>']]], $caseOutcome->toArray()['argusRequests']);
        $caseOutcome->keepHostRequestsOf(null, $idPlaceholders);
        self::assertSame(0, $caseOutcome->countHostRequests(), 'no recorder, no request');
    }

    public function testAnIncompleteStatementAuditIsAlwaysADifference(): void
    {
        $recordedOutcome = self::buildRecordedOutcome();
        $replayedOutcome = self::buildRecordedOutcome();
        $replayedOutcome->statementAuditGap = '1 statement(s) could not be kept';

        self::assertSame(['statement audit of the replayed run is incomplete: 1 statement(s) could not be kept'], $recordedOutcome->buildDifferencesFrom($replayedOutcome));
    }

    public function testAChangedRequestOfTheApplicationsRecordersIsReportedUnderItsLabel(): void
    {
        $recordedOutcome = self::buildChangedOutcome(['hostRequests' => [['method' => 'GET', 'endpoint' => '/space/1']]]);
        $replayedOutcome = self::buildChangedOutcome(['hostRequests' => [['method' => 'GET', 'endpoint' => '/space/2']]]);

        $differences = $recordedOutcome->buildDifferencesFrom($replayedOutcome);

        self::assertCount(1, $differences);
        self::assertStringStartsWith('host request #0', $differences[0]);
        self::assertSame(1, $replayedOutcome->countHostRequests());
    }

    public function testTheRecordingRoundTripsAndRefusesAnotherScriptOrVersion(): void
    {
        $toolCallComparisonScript = ToolCallComparisonScript::fromJson('{"package":"P","cases":[]}', 'P.json');
        $toolCallComparisonRecording = ToolCallComparisonRecording::newInstance();
        $toolCallComparisonRecording->scriptName = 'P.json';
        $toolCallComparisonRecording->scriptHash = $toolCallComparisonScript->contentHash;
        $toolCallComparisonRecording->pinnedMoment = '2026-10-22 10:00:00';
        $recordedOutcome = self::buildRecordedOutcome();
        $toolCallComparisonRecording->caseOutcomes->add($recordedOutcome);
        $toolCallComparisonRecording->comparisonAgentSpaceId = 11111111;
        $toolCallComparisonRecording->harnessIdsByPlaceholder = ['<comparison:agentSpace>' => '11111111'];
        $storedFields = json_decode($toolCallComparisonRecording->buildStoredJson(), true);
        $storedFields['argusCalls'] = [['endpoint' => 'POST:https://gateway.test/x', 'requestBody' => '{}', 'duringTool' => true, 'toolCallLabel' => 'c']];
        $toolCallComparisonRecording = ToolCallComparisonRecording::fromJson((string)json_encode($storedFields), 'recording.json');

        $readRecording = ToolCallComparisonRecording::fromJson($toolCallComparisonRecording->buildStoredJson(), 'recording.json');

        self::assertSame($toolCallComparisonRecording->buildStoredJson(), $readRecording->buildStoredJson());
        self::assertNull($readRecording->findReplayRefusal($toolCallComparisonScript));
        self::assertNotNull($readRecording->findReplayRefusal(ToolCallComparisonScript::fromJson('{"package":"P","cases":[{}]}', 'P.json')));
        self::assertSame(1, $readRecording->countArgusCalls());

        $this->expectException(RuntimeException::class);
        ToolCallComparisonRecording::fromJson(str_replace('"version": ' . ToolCallComparisonRecording::RECORDING_VERSION, '"version": 99', $toolCallComparisonRecording->buildStoredJson()), 'old.json');
    }

    public function testAChangedArgusRequestBodyIsReported(): void
    {
        $recordedFields = self::buildRecordedOutcome()->toArray();
        $changedArgusRequest = $recordedFields['argusRequests'][0];
        $changedArgusRequest['body']['name'] = 'Other Studio';
        $replayedOutcome = self::buildChangedOutcome(['argusRequests' => [$changedArgusRequest]]);

        $differences = self::buildRecordedOutcome()->buildDifferencesFrom($replayedOutcome);

        self::assertCount(1, $differences);
        self::assertStringStartsWith('Argus request #0', $differences[0]);
        self::assertStringContainsString('Other Studio', $differences[0]);
    }

    public function testTheRecordingKeepsTheHarnessIdsByPlaceholder(): void
    {
        $toolCallComparisonRecording = ToolCallComparisonRecording::newInstance();
        $toolCallComparisonRecording->comparisonAgentSpaceId = 11111111;
        $toolCallComparisonRecording->harnessIdsByPlaceholder = ['<comparison:agentSpace>' => '11111111', '<conversation:chat>' => '900001'];

        $readRecording = ToolCallComparisonRecording::fromJson($toolCallComparisonRecording->buildStoredJson(), 'recording.json');

        self::assertSame(11111111, $readRecording->comparisonAgentSpaceId);
        self::assertSame(['<comparison:agentSpace>' => '11111111', '<conversation:chat>' => '900001'], $readRecording->harnessIdsByPlaceholder);
    }

    public function testAnOutcomeRecordedTwiceForOneCaseKeepsOneEntry(): void
    {
        $toolCallComparisonRecording = ToolCallComparisonRecording::newInstance();
        $firstOutcome = self::buildRecordedOutcome();
        $secondOutcome = self::buildRecordedOutcome();
        $secondOutcome->resultContent = 'again';
        $toolCallComparisonRecording->caseOutcomes->replace($firstOutcome);
        $toolCallComparisonRecording->caseOutcomes->replace($secondOutcome);

        self::assertSame(1, $toolCallComparisonRecording->caseOutcomes->count());
        self::assertSame('again', $toolCallComparisonRecording->caseOutcomes->findOutcomeOfCase('updateOpeningHours.example.week')?->resultContent);
    }
}
