<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunIdNormalization;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunPlayedAnswerIdTranslation;
use DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunRecording;
use DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunSession;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Services\Eval\AgentRecordedRunsService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The service of recorded runs: the session it starts (a recording or a replay, never a container service), the
 * recording file read back byte for byte and checked, and the parts an application declares (scope columns, the
 * played-answer translation, the tables of the id families).
 */
class AgentRecordedRunsServiceTest extends UnitTestCase
{
    /** @var string[] files a test wrote */
    protected array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $temporaryFile) {
            if (is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
            if (is_dir(dirname($temporaryFile)) && str_contains(dirname($temporaryFile), 'recorded-run-files-')) {
                rmdir(dirname($temporaryFile));
            }
        }
        parent::tearDown();
    }

    protected function getAgentRecordedRunsService(): AgentRecordedRunsService
    {
        /** @var AgentRecordedRunsService $agentRecordedRunsService */
        $agentRecordedRunsService = DDDService::instance()->getService(AgentRecordedRunsService::class);
        return $agentRecordedRunsService;
    }

    public function testASessionRecordsOrReplaysAndIsNoContainerService(): void
    {
        $agentRecordedRunsService = $this->getAgentRecordedRunsService();

        $recordingSession = $agentRecordedRunsService->startSession(null, toolsRunLive: true);
        self::assertFalse($recordingSession->isReplay());
        self::assertTrue($recordingSession->toolsRunLive);
        self::assertSame(['agentSpace', 'account'], $recordingSession->scopeColumns->getScopeNames());

        $replaySession = $agentRecordedRunsService->startSession(AgentRecordedRunRecording::fromStoredRecording(['toolsRunLive' => false, 'calls' => []]), toolsRunLive: true);
        self::assertTrue($replaySession->isReplay());
        self::assertFalse($replaySession->toolsRunLive, 'a replay takes it from the recording');
        self::assertNotSame($recordingSession, $replaySession, 'one session per run');
        self::assertNotEmpty((new ReflectionClass(AgentRecordedRunSession::class))->getAttributes(Exclude::class));
    }

    public function testARecordingIsWrittenAndReadBackByteForByte(): void
    {
        $recordingPath = sys_get_temp_dir() . '/recorded-run-files-' . bin2hex(random_bytes(4)) . '/run.json';
        $this->temporaryFiles[] = $recordingPath;
        $storedRecording = [
            'version' => AgentRecordedRunRecording::RECORDING_VERSION,
            'toolsRunLive' => false,
            'recordedConversationId' => 501,
            'scopeIds' => ['garden' => 7, 'rootConversation' => 501],
            'idSeed' => 'abc',
            'harness' => 'app:example:chat',
            'gardenId' => 7,
            'turns' => ['Grüße / hello'],
            'idNormalization' => [],
            'createdIdReadReport' => [],
            'calls' => [],
            'toolResults' => [],
            'snapshot' => ['conversations' => []],
        ];
        $agentRecordedRunsService = $this->getAgentRecordedRunsService();

        $agentRecordedRunsService->writeRecording($recordingPath, AgentRecordedRunRecording::fromStoredRecording($storedRecording));
        $readRecording = $agentRecordedRunsService->findRecording($recordingPath);

        self::assertSame($storedRecording, $readRecording->toArray(), 'keys, order and values survive the file');
        self::assertSame((string)file_get_contents($recordingPath), $readRecording->buildRecordingFileContent());
        self::assertStringContainsString('"Grüße / hello"', (string)file_get_contents($recordingPath));
    }

    public function testAMissingFileAndAFileOfAnotherVersionAreRefused(): void
    {
        $agentRecordedRunsService = $this->getAgentRecordedRunsService();
        $missingPath = sys_get_temp_dir() . '/no-recording-' . bin2hex(random_bytes(4)) . '.json';
        try {
            $agentRecordedRunsService->findRecording($missingPath);
            self::fail('a missing file is refused');
        } catch (RuntimeException $refusal) {
            self::assertSame("{$missingPath} cannot be read", $refusal->getMessage());
        }
        $versionTwoPath = (string)tempnam(sys_get_temp_dir(), 'recorded-run-');
        $this->temporaryFiles[] = $versionTwoPath;
        file_put_contents($versionTwoPath, (string)json_encode(['version' => 2, 'calls' => []]));
        $this->expectExceptionMessage("{$versionTwoPath} is a version-2 recording");
        $agentRecordedRunsService->findRecording($versionTwoPath);
    }

    public function testTheTranslationAndTheTablesOfTheIdFamiliesAreThePackages(): void
    {
        $agentRecordedRunsService = $this->getAgentRecordedRunsService();

        $playedAnswerIdTranslation = $agentRecordedRunsService->buildPlayedAnswerIdTranslation(['notes' => [12, 11]]);
        self::assertSame(AgentRecordedRunPlayedAnswerIdTranslation::class, $playedAnswerIdTranslation::class);
        self::assertSame(['notes' => [11, 12]], $playedAnswerIdTranslation->recordedCreatedIdsByTable);

        $tableByFamily = $agentRecordedRunsService->buildTableByFamily();
        self::assertSame([
            AgentRecordedRunIdNormalization::FAMILY_CONVERSATION,
            AgentRecordedRunIdNormalization::FAMILY_MESSAGE,
            AgentRecordedRunIdNormalization::FAMILY_DELEGATION_BATCH,
            AgentRecordedRunIdNormalization::FAMILY_COMPACTION_NODE,
            AgentRecordedRunIdNormalization::FAMILY_SCHEDULE,
        ], array_keys($tableByFamily));
        self::assertSame(DBAIConversationModel::getTableName(), $tableByFamily[AgentRecordedRunIdNormalization::FAMILY_CONVERSATION]);
    }
}
