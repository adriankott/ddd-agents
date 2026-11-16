<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunIdNormalization;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunPlayedAnswerIdTranslation;
use DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunRecording;
use DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunScope;
use DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunScopeColumns;
use DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunSession;
use DDD\Domain\AI\Repo\DB\AgentSpaces\DBAgentSpace;
use DDD\Domain\AI\Repo\DB\Conversations\Agent\DBAIConversationDelegationBatchModel;
use DDD\Domain\AI\Repo\DB\Conversations\Compaction\DBAIConversationCompactionNodeModel;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\AI\Repo\DB\Conversations\Schedules\DBAIConversationScheduleModel;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use RuntimeException;

/**
 * Record and replay of an agent run: the factory of the per-run session ({@see startSession()}), the recording file
 * (read and checked, written) and the parts of a run an application declares — the scope columns its tables carry and
 * the id keys of its tools a played answer translates by table.
 *
 * Override points of an application (a subclass at the same path):
 * - {@see getScopeColumnsByName()}: the scope names of its runs and the columns its tables carry for each;
 * - {@see buildPlayedAnswerIdTranslation()}: a translation class that declares the id keys of its tools;
 * - its own session class at the same path as {@see AgentRecordedRunSession} (built by {@see newSession()}).
 *
 * The recording format is opaque to the package: scope names and run fields are the application's strings; the
 * version is {@see AgentRecordedRunRecording::RECORDING_VERSION}.
 */
class AgentRecordedRunsService extends Service
{
    /** The package's scope of a run: the space it works in, then the account. */
    public const string SCOPE_NAME_AGENT_SPACE = 'agentSpace';

    /**
     * A new session, not started: a replay of `$agentRecordedRunRecording`, or a recording (null).
     *
     * @param AgentRecordedRunRecording|null $agentRecordedRunRecording the recording to replay; null records
     * @param bool $toolsRunLive for a recording: the tools run live (a replay takes it from the recording)
     * @return AgentRecordedRunSession
     */
    public function startSession(?AgentRecordedRunRecording $agentRecordedRunRecording, bool $toolsRunLive = false): AgentRecordedRunSession
    {
        $agentRecordedRunSession = $this->newSession();
        $agentRecordedRunSession->prepareRun($this->getScopeColumnsByName(), $agentRecordedRunRecording, $toolsRunLive);
        return $agentRecordedRunSession;
    }

    /**
     * The session object of one run: the application's session class when it declares one at the same path.
     *
     * @return AgentRecordedRunSession
     */
    protected function newSession(): AgentRecordedRunSession
    {
        $sessionClassName = DDDService::instance()->getContainerServiceClassNameForClass(AgentRecordedRunSession::class) ?? AgentRecordedRunSession::class;
        /** @var AgentRecordedRunSession $agentRecordedRunSession */
        $agentRecordedRunSession = new $sessionClassName();
        return $agentRecordedRunSession;
    }

    /**
     * The recording of a file, checked ({@see AgentRecordedRunRecording::fromJson()}).
     *
     * @param string $recordingPath
     * @return AgentRecordedRunRecording
     * @throws RuntimeException when the file is missing or holds no replayable recording
     */
    public function findRecording(string $recordingPath): AgentRecordedRunRecording
    {
        $recordingJson = @file_get_contents($recordingPath);
        if ($recordingJson === false) {
            throw new RuntimeException("{$recordingPath} cannot be read");
        }
        return AgentRecordedRunRecording::fromJson($recordingJson, $recordingPath);
    }

    /**
     * Writes the recording to its file (pretty-printed JSON), creating the folder when it is missing.
     *
     * @param string $recordingPath
     * @param AgentRecordedRunRecording $agentRecordedRunRecording
     * @return void
     */
    public function writeRecording(string $recordingPath, AgentRecordedRunRecording $agentRecordedRunRecording): void
    {
        if (!is_dir(dirname($recordingPath))) {
            @mkdir(dirname($recordingPath), 0775, true);
        }
        file_put_contents($recordingPath, $agentRecordedRunRecording->buildRecordingFileContent());
    }

    /**
     * The scope names of a run and the columns a table may carry for each, in priority order. The package's tables
     * carry the space and the account; an application lists the columns of its own tables (and its own scopes).
     *
     * @return AgentRecordedRunScopeColumns
     */
    public function getScopeColumnsByName(): AgentRecordedRunScopeColumns
    {
        return AgentRecordedRunScopeColumns::newInstance()
            ->withScope(self::SCOPE_NAME_AGENT_SPACE, 'agentSpaceId')
            ->withScope(AgentRecordedRunScope::SCOPE_NAME_ACCOUNT, 'accountId');
    }

    /**
     * The translation of the recording's created ids inside the played answers of a replay whose tools run live. An
     * application whose tools name their rows under own id keys returns its class that declares them.
     *
     * @param array<string, int[]> $recordedCreatedIdsByTable per table: the ids the recording created
     * @return AgentRecordedRunPlayedAnswerIdTranslation
     */
    public function buildPlayedAnswerIdTranslation(array $recordedCreatedIdsByTable): AgentRecordedRunPlayedAnswerIdTranslation
    {
        return AgentRecordedRunPlayedAnswerIdTranslation::forRecordedCreatedIds($recordedCreatedIdsByTable);
    }

    /**
     * The tables of the id families of a conversation tree, as the run's id normalization reads them.
     *
     * @return array<string, string> family → table
     */
    public function buildTableByFamily(): array
    {
        return [
            AgentRecordedRunIdNormalization::FAMILY_CONVERSATION => DBAIConversationModel::getTableName(),
            AgentRecordedRunIdNormalization::FAMILY_MESSAGE => DBAIConversationMessageModel::getTableName(),
            AgentRecordedRunIdNormalization::FAMILY_DELEGATION_BATCH => DBAIConversationDelegationBatchModel::getTableName(),
            AgentRecordedRunIdNormalization::FAMILY_COMPACTION_NODE => DBAIConversationCompactionNodeModel::getTableName(),
            AgentRecordedRunIdNormalization::FAMILY_SCHEDULE => DBAIConversationScheduleModel::getTableName(),
            AgentRecordedRunIdNormalization::FAMILY_AGENT_SPACE => (DBAgentSpace::BASE_ORM_MODEL)::getTableName(),
        ];
    }
}
