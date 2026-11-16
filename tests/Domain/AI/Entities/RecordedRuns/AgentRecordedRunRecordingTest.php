<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\RecordedRuns;

use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunIdNormalization;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultRecording;
use DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunCreatedIdRead;
use DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunFields;
use DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunRecording;
use DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunSnapshot;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusTransportRecording;
use DDD\Tests\Agents\Support\UnitTestCase;
use RuntimeException;

/**
 * The stored document of a recorded run: what a replay refuses before it runs (another version, another harness, a
 * setting passed on the command line that differs from the recorded one — the recording wins), the stored key order,
 * and a recording whose scope names and run fields are the harness's own strings reads back unchanged.
 */
class AgentRecordedRunRecordingTest extends UnitTestCase
{
    public const string SETUP_HARNESS = 'app:example:judged-run';

    public const string CHAT_HARNESS = 'app:example:chat';

    /** @return array<string, mixed> a judged-run recording with the harness's own fields */
    protected static function judgedRunRecording(): array
    {
        return [
            'version' => AgentRecordedRunRecording::RECORDING_VERSION,
            'harness' => self::SETUP_HARNESS,
            'case' => 'intake.walk-in',
            'seedGardenId' => 21404374,
            'agentModel' => 'VENDOR.SOME_MODEL',
            'evalArms' => [],
            'calls' => [],
            'snapshot' => [],
            'idNormalization' => [],
        ];
    }

    public function testAVersionOneRecordingAndAnUnknownVersionAreRefusedWithTheReason(): void
    {
        try {
            AgentRecordedRunRecording::fromJson((string)json_encode(['version' => 1, 'calls' => [], 'rows' => []]), 'g1.json');
            self::fail('a version-1 recording must be refused');
        } catch (RuntimeException $refusal) {
            self::assertSame('g1.json is a version-1 recording; this build replays version 3 only — record it again', $refusal->getMessage());
        }
        try {
            AgentRecordedRunRecording::fromJson((string)json_encode(['version' => 2, 'calls' => [], 'snapshot' => [], 'idNormalization' => []]), 'g2.json');
            self::fail('a version-2 recording (cross-turn matching, status-0 failures) must be refused');
        } catch (RuntimeException $refusal) {
            self::assertStringStartsWith('g2.json is a version-2 recording', $refusal->getMessage());
        }
        try {
            AgentRecordedRunRecording::fromJson((string)json_encode(['version' => 3, 'calls' => []]), 'g4.json');
            self::fail('a recording without snapshot must be refused');
        } catch (RuntimeException $refusal) {
            self::assertSame('g4.json has no snapshot or id normalization — record it again', $refusal->getMessage());
        }
        $this->expectExceptionMessage('g9.json is no run recording');
        AgentRecordedRunRecording::fromJson('{"turns":[]}', 'g9.json');
    }

    public function testACurrentRecordingIsAcceptedAndItsFieldsReadBack(): void
    {
        $agentRecordedRunRecording = AgentRecordedRunRecording::fromJson((string)json_encode(self::judgedRunRecording()), 'g3.json');

        self::assertSame('intake.walk-in', $agentRecordedRunRecording->getRunFieldValue('case'));
        self::assertSame(self::judgedRunRecording(), $agentRecordedRunRecording->toArray(), 'the document reads back unchanged');
    }

    public function testTheRecordingWinsOverEveryDifferingSettingAndOverTheHarness(): void
    {
        $agentRecordedRunRecording = AgentRecordedRunRecording::fromStoredRecording(self::judgedRunRecording());

        self::assertNull($agentRecordedRunRecording->findReplayRefusal(self::SETUP_HARNESS, ['case' => null, 'seedGardenId' => null, 'agentModel' => null, 'evalArms' => []]), 'nothing passed: the recording decides');
        self::assertNull($agentRecordedRunRecording->findReplayRefusal(self::SETUP_HARNESS, ['seedGardenId' => 21404374, 'case' => 'intake.walk-in']), 'the recorded values may be passed again');
        self::assertStringStartsWith('seedGardenId differs from the recording (recorded 21404374, passed 412553)', (string)$agentRecordedRunRecording->findReplayRefusal(self::SETUP_HARNESS, ['seedGardenId' => 412553]));
        self::assertStringStartsWith('case differs', (string)$agentRecordedRunRecording->findReplayRefusal(self::SETUP_HARNESS, ['case' => 'intake.online-shop']));
        self::assertStringStartsWith('evalArms differs', (string)$agentRecordedRunRecording->findReplayRefusal(self::SETUP_HARNESS, ['evalArms' => ['decision-model' => 'off']]));
        self::assertSame(
            'the recording comes from app:example:judged-run, not from app:example:chat',
            $agentRecordedRunRecording->findReplayRefusal(self::CHAT_HARNESS, [])
        );
        self::assertSame(
            'the recording comes from an unknown harness, not from app:example:chat',
            AgentRecordedRunRecording::fromStoredRecording(['calls' => []])->findReplayRefusal(self::CHAT_HARNESS, [])
        );
    }

    public function testARecordingOfARunKeepsTheStoredKeyOrderAndTheHarnessFieldsInTheMiddle(): void
    {
        $agentRecordedRunRecording = AgentRecordedRunRecording::forRun(
            true,
            501,
            ['garden' => 7, 'rootConversation' => 501],
            'abc123',
            AgentRecordedRunFields::fromValuesByField(['harness' => self::CHAT_HARNESS, 'gardenId' => 7, 'async' => false, 'turns' => ['Hello'], 'version' => 99]),
            AgentRecordedRunIdNormalization::withHighWaterMarks(['notes' => 10], []),
            AgentRecordedRunCreatedIdRead::fromStoredRead(['idsByTable' => ['notes' => [11]], 'sharedScopeTables' => []]),
            ArgusTransportRecording::forRecording(),
            ToolResultRecording::forRecording(),
            AgentRecordedRunSnapshot::fromRowsBySection(['conversations' => [['_label' => 'root']], 'dispatches' => []])
        );
        $storedRecording = $agentRecordedRunRecording->toArray();

        self::assertSame(
            ['version', 'toolsRunLive', 'recordedConversationId', 'scopeIds', 'idSeed', 'harness', 'gardenId', 'async', 'turns', 'idNormalization', 'createdIdReadReport', 'calls', 'toolResults', 'snapshot'],
            array_keys($storedRecording)
        );
        self::assertSame(3, $storedRecording['version'], 'a run field never replaces a key of the recording');
        self::assertSame(['garden' => 7, 'rootConversation' => 501], $agentRecordedRunRecording->getScopeIds(), 'scope names are the harness\'s strings');
        self::assertSame(['Hello'], $agentRecordedRunRecording->getTurns());
        self::assertTrue($agentRecordedRunRecording->toolsRunLive());
        self::assertSame('abc123', $agentRecordedRunRecording->getIdSeed());
        self::assertSame(['conversations' => 1, 'dispatches' => 0], $agentRecordedRunRecording->getSnapshot()->countRowsBySection());
        self::assertSame(['notes' => [11]], $agentRecordedRunRecording->getCreatedIdRead()->getIdsByTable());

        $withAppendedFields = $agentRecordedRunRecording->withAppendedRunFields(AgentRecordedRunFields::fromValuesByField(['case' => 'intake.walk-in', 'harness' => self::SETUP_HARNESS]));
        self::assertSame('case', array_key_last($withAppendedFields->toArray()), 'a new field goes after the snapshot');
        self::assertSame(self::SETUP_HARNESS, $withAppendedFields->getRunFieldValue('harness'), 'a present field keeps its place and takes the new value');
        self::assertSame(array_search('harness', array_keys($storedRecording), true), array_search('harness', array_keys($withAppendedFields->toArray()), true));
    }

    public function testTheFileContentIsPrettyPrintedWithUnicodeAndSlashesUnescaped(): void
    {
        $agentRecordedRunRecording = AgentRecordedRunRecording::fromStoredRecording(['turns' => ['Grüße / 1']]);

        self::assertSame("{\n    \"turns\": [\n        \"Grüße / 1\"\n    ]\n}", $agentRecordedRunRecording->buildRecordingFileContent());
    }
}
