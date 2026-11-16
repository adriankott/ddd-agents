<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\RecordedRuns;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunComparison;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunCreatedIdsReaderInterface;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunDispatchedMessage;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunIdNormalization;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunPlayedAnswerIdTranslation;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultRecording;
use DDD\Domain\AI\Libs\RecordedRuns\RecordedRunClockAndIdSources;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Domain\AI\Services\Conversations\AgentToolCallForkJoinService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Decisions\AgentDecisionModelCircuitBreakerService;
use DDD\Domain\AI\Services\Eval\AgentRecordedRunsService;
use DDD\Domain\Base\Entities\MessageHandlers\RecordedRunMessageQueue;
use DDD\Domain\Base\Entities\MessageHandlers\RecordedRunMessageQueueHostInterface;
use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperations;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusTransportRecording;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusTransportRecordingPlayedAnswerTranslatorInterface;
use DDD\Domain\Base\Repo\DB\Doctrine\Audit\DatabaseRowScopeColumns;
use DDD\Domain\Base\Repo\DB\Doctrine\Audit\DatabaseWriteStatementAudit;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;
use Doctrine\DBAL\Exception as DBALException;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * One recording or replay of a recorded run, as a console harness drives it over several owner turns (a chat, a judged
 * evaluation run). Between {@see start()} and {@see stop()} the process runs under the recording:
 *  - every Argus call is recorded / answered from the recording ({@see ArgusTransportRecording});
 *  - every tool result is recorded / played ({@see ToolResultRecording}) — or, for a run whose tools must write for
 *    real ({@see $toolsRunLive}: a run on a fresh scope whose later turns read back what the tools wrote), the tools run
 *    live and the calls they make are answered from the recorded in-tool calls;
 *  - every dispatched Messenger message is kept and run in this process, in dispatch order
 *    ({@see RecordedRunMessageQueue}, {@see runQueuedMessages()}) — the worker path of a turn, its delegation
 *    children, promotions and side work, recorded and replayed without a broker;
 *  - tool forks are off and the Argus and application caches are off.
 * {@see start()} reads the high-water mark of every table, so the ids the run mints read as ordinals
 * ({@see AgentRecordedRunIdNormalization}); {@see concludeRun()} snapshots the conversation tree and the dispatched
 * messages and builds the recording or compares with it ({@see AgentRecordedRunComparison}).
 *
 * The session is the host of its message queue ({@see RecordedRunMessageQueueHostInterface}: it owns the run's process
 * switches and sets them again after a queued job's worker-state reset, and passes the queue's phase on as the owner
 * turn of the Argus transport; while {@see runQueuedMessages()} runs, {@see AgentRecordedRunQueuedMessagesHost} stands in
 * and restores the account and grant after each message) and, for a replay whose tools run live, the translator of the
 * played answers ({@see ArgusTransportRecordingPlayedAnswerTranslatorInterface}) and the reader of the replay's created
 * ids the translation asks for ({@see AgentRecordedRunCreatedIdsReaderInterface}).
 *
 * A plain object of one run with state; no container service. Created only by
 * {@see AgentRecordedRunsService::startSession()}; an application that changes it derives it and builds its class in
 * {@see AgentRecordedRunsService::newSession()}.
 *
 * @phpstan-consistent-constructor
 */
#[Exclude]
class AgentRecordedRunSession implements
    AgentRecordedRunSessionInterface,
    RecordedRunMessageQueueHostInterface,
    ArgusTransportRecordingPlayedAnswerTranslatorInterface,
    AgentRecordedRunCreatedIdsReaderInterface
{
    public const string MODE_RECORD = 'record';
    public const string MODE_REPLAY = 'replay';

    /** The scope name the root conversation of a run reads as when a harness names it among the run's scope ids. */
    public const string SCOPE_NAME_ROOT_CONVERSATION = 'rootConversation';

    public string $mode = self::MODE_RECORD;

    /** The tools run live (their in-tool Argus calls answered from the recording) instead of their results being played. */
    public bool $toolsRunLive = false;

    /** The recording a replay answers from; null when recording. */
    public ?AgentRecordedRunRecording $recording = null;

    public ?ArgusTransportRecording $transportRecording = null;

    public ?ToolResultRecording $toolResultRecording = null;

    public RecordedRunMessageQueue $messageQueue;

    /** This run's id normalization: the high-water marks read at {@see start()}, the minted ids read at the end. */
    public ?AgentRecordedRunIdNormalization $idNormalization = null;

    /** The translation of the recording's created ids inside played answers (a replay whose tools run live), else null. */
    public ?AgentRecordedRunPlayedAnswerIdTranslation $playedAnswerIdTranslation = null;

    /** Where the run works: its scope ids, its root conversation, the scopes it shares with other runs. */
    public AgentRecordedRunScope $scope;

    /** The columns each scope of {@see $scope} is read through. */
    public AgentRecordedRunScopeColumns $scopeColumns;

    /** This run's read of its created ids, once the snapshot was built. */
    public ?AgentRecordedRunCreatedIdRead $createdIdRead = null;

    /** The recording {@see concludeRun()} built (record mode), without the harness's run fields. */
    public ?AgentRecordedRunRecording $concludedRecording = null;

    /** The comparison {@see concludeRun()} built (replay mode). */
    public ?AgentRecordedRunComparison $comparison = null;

    /**
     * @var string The seed of the run's pinned id source ({@see RecordedRunIdSource}): drawn when recording, read from
     * the recording when replaying; '' for a recording made before the id source (its replay mints random ids).
     */
    public string $idSeed = '';

    /** The pinned id source this run installs (null without a seed). */
    protected ?RecordedRunIdSource $idSource = null;

    /** The id source installed before {@see start()}, restored by {@see stop()}. */
    protected ?RecordedRunIdSource $previousIdSource = null;

    protected bool $started = false;

    protected bool $argusCacheWasDeactivated = false;

    protected bool $applicationCacheWasOff = false;

    protected bool $breakerHarnessModeWasOn = false;

    /** @var array<string, int> The replay's high-water marks of the tables the recording created rows in (played-answer translation) */
    protected array $replayHighWaterMarksOfRecordedTables = [];

    public function __construct()
    {
        $this->messageQueue = new RecordedRunMessageQueue();
        $this->scope = AgentRecordedRunScope::newInstance();
        $this->scopeColumns = AgentRecordedRunScopeColumns::newInstance();
    }

    /**
     * Sets up a new session; {@see AgentRecordedRunsService::startSession()} is the one caller.
     *
     * @param AgentRecordedRunScopeColumns $agentRecordedRunScopeColumns
     * @param AgentRecordedRunRecording|null $agentRecordedRunRecording the recording to replay; null records
     * @param bool $toolsRunLive for a recording: the tools run live; a replay takes it from the recording
     * @return void
     */
    public function prepareRun(AgentRecordedRunScopeColumns $agentRecordedRunScopeColumns, ?AgentRecordedRunRecording $agentRecordedRunRecording, bool $toolsRunLive): void
    {
        $this->scopeColumns = $agentRecordedRunScopeColumns;
        $this->recording = $agentRecordedRunRecording;
        $this->mode = $agentRecordedRunRecording !== null ? self::MODE_REPLAY : self::MODE_RECORD;
        $this->toolsRunLive = $agentRecordedRunRecording !== null ? $agentRecordedRunRecording->toolsRunLive() : $toolsRunLive;
    }

    /** @return bool */
    public function isReplay(): bool
    {
        return $this->mode === self::MODE_REPLAY;
    }

    /**
     * Reads the high-water mark of every table — once; a harness that creates the run's own scope first calls it
     * BEFORE, so the rows created with the scope count as created by the run.
     *
     * @return void
     * @throws DBALException
     */
    public function readHighWaterMarks(): void
    {
        $this->idNormalization ??= AgentRecordedRunIdNormalization::withHighWaterMarks(
            DatabaseWriteStatementAudit::findIdHighWaterMarksOfAllTables(EntityManagerFactory::getInstance()->getConnection()),
            $this->getAgentRecordedRunsService()->buildTableByFamily()
        );
    }

    /**
     * @param array<string, int|string|null> $scopeIdsByName scope name → id; an id of a name already set is replaced
     * @return void
     */
    public function setScopeIds(array $scopeIdsByName): void
    {
        $this->scope->addScopeIds($scopeIdsByName);
    }

    /**
     * Replaces the scope of the run: its ids, its root conversation and the scopes it shares with other runs.
     *
     * @param AgentRecordedRunScope $agentRecordedRunScope
     * @return void
     */
    public function setScope(AgentRecordedRunScope $agentRecordedRunScope): void
    {
        $this->scope = $agentRecordedRunScope;
    }

    /**
     * @param int $turnOrdinal
     * @return void
     */
    public function beginTurn(int $turnOrdinal): void
    {
        $this->messageQueue->switchPhaseOrdinal($turnOrdinal);
    }

    /**
     * @param AIConversation $rootConversation
     * @return void
     */
    public function setRootConversation(AIConversation $rootConversation): void
    {
        $this->scope->rootConversationId = isset($rootConversation->id) ? (int)$rootConversation->id : null;
    }

    /**
     * The tables this run's created-id read went through a SHARED scope column ({@see AgentRecordedRunScope::$sharedScopeNames})
     * — the line every harness prints once: such tables are exact only while nothing else runs on that scope.
     *
     * @return string|null
     */
    public function describeSharedScopeTables(): ?string
    {
        $sharedScopeTables = $this->createdIdRead?->getSharedScopeTables() ?? [];
        if ($sharedScopeTables === []) {
            return null;
        }
        $tableLines = [];
        foreach ($sharedScopeTables as $table => $scopeColumn) {
            $tableLines[] = "{$table} ({$scopeColumn})";
        }
        return 'record/replay alone on the account — account-scoped tables: ' . implode(', ', $tableLines);
    }

    /**
     * Reads the high-water marks (unless read already) and installs the recording's hooks in this process.
     *
     * @return void
     * @throws DBALException
     */
    public function start(): void
    {
        if ($this->started) {
            return;
        }
        $this->readHighWaterMarks();
        $this->transportRecording = $this->buildTransportRecording();
        // a live tool still runs through the recording executor branch: it marks the tool boundary for the transport
        $this->toolResultRecording = $this->recording !== null && !$this->toolsRunLive
            ? $this->recording->buildToolResultPlayer()
            : ToolResultRecording::forRecording();
        if ($this->isReplay() && $this->toolsRunLive) {
            $this->installPlayedAnswerIdTranslation();
        }
        ArgusApiOperations::$transportRecording = $this->transportRecording;
        MCPToolExecutorService::$toolResultRecording = $this->toolResultRecording;
        AgentToolCallForkJoinService::$forkingDisabled = true;
        // the ids the switched sites mint come from the run's seed, so the n-th id of the recording and of its replay
        // are the same bytes
        $this->idSeed = $this->isReplay() ? (string)$this->recording?->getIdSeed() : bin2hex(random_bytes(8));
        $this->idSource = $this->idSeed !== '' ? RecordedRunIdSource::seeded($this->idSeed) : null;
        $this->previousIdSource = RecordedRunClockAndIdSources::$installedIdSource;
        if ($this->idSource !== null) {
            RecordedRunClockAndIdSources::$installedIdSource = $this->idSource;
        }
        // No cross-run cache: a call the recording run cached would never reach the replay's transport (the Argus
        // cache), and a value an earlier run left in the shared application cache would take the replay another path.
        $this->argusCacheWasDeactivated = ArgusLoad::$deactivateArgusCache;
        $this->applicationCacheWasOff = DDDService::$noCache;
        $this->breakerHarnessModeWasOn = AgentDecisionModelCircuitBreakerService::$harnessMode;
        static::switchHarnessSwitchesOn();
        $this->messageQueue->startCapturing();
        // a queued job's worker-state reset clears every hook of the run; while the queue runs, they come back
        // (reinstallSwitchesAfterWorkerStateReset()), and the queue's phase is the owner turn (applyPhaseOrdinal())
        $this->messageQueue->host = $this;
        $this->started = true;
    }

    /**
     * The transport of the run: a recorder, or a player of the recording's calls (with live tools: one that also
     * answers the tools' own calls).
     *
     * @return ArgusTransportRecording
     */
    protected function buildTransportRecording(): ArgusTransportRecording
    {
        return $this->recording !== null ? $this->recording->buildTransportPlayer($this->toolsRunLive) : ArgusTransportRecording::forRecording();
    }

    /**
     * The queue's worker-state reset cleared every hook of the run: sets them again (the same id source object — its
     * counter runs on across the queued jobs).
     *
     * @return void
     */
    public function reinstallSwitchesAfterWorkerStateReset(): void
    {
        static::switchHarnessSwitchesOn();
        ArgusApiOperations::$transportRecording = $this->transportRecording;
        MCPToolExecutorService::$toolResultRecording = $this->toolResultRecording;
        AgentToolCallForkJoinService::$forkingDisabled = true;
        if ($this->idSource !== null) {
            RecordedRunClockAndIdSources::$installedIdSource = $this->idSource;
        }
    }

    /**
     * The queue's phase is the owner turn the Argus transport records and matches calls under.
     *
     * @param int|null $phaseOrdinal
     * @return void
     */
    public function applyPhaseOrdinal(?int $phaseOrdinal): void
    {
        if ($this->transportRecording !== null) {
            $this->transportRecording->turnOrdinal = $phaseOrdinal;
        }
    }

    /**
     * Nothing to restore here: queued messages run only inside {@see runQueuedMessages()}, where
     * {@see AgentRecordedRunQueuedMessagesHost} is the queue's host and restores the account and grant read right before.
     *
     * @return void
     */
    public function restoreStateAfterQueuedMessage(): void
    {
    }

    /**
     * Runs every message the run dispatched so far in this process (see {@see RecordedRunMessageQueue}); restores the
     * harness's authenticated account and its process budget grant after each one (a consumed job starts on fresh state).
     *
     * @return int how many messages ran
     */
    public function runQueuedMessages(): int
    {
        if (!$this->started || !$this->messageQueue->hasPendingMessages()) {
            return 0;
        }
        /** @var AIUsageEnvelopesService $aIUsageEnvelopesService */
        $aIUsageEnvelopesService = DDDService::instance()->getService(AIUsageEnvelopesService::class);
        $this->messageQueue->host = AgentRecordedRunQueuedMessagesHost::forSession(
            $this,
            AuthService::instance()->getAccount(),
            $aIUsageEnvelopesService->getBudgetBypassGrantForTheProcess()
        );
        try {
            return $this->messageQueue->runPendingMessages();
        } finally {
            $this->messageQueue->host = $this;
        }
    }

    /**
     * Removes the recording's hooks from the process — after running what is still queued, unless the run is being
     * abandoned ($runRemainingMessages false: an exception unwinds, and a queued message must neither run nor mask it).
     *
     * @param bool $runRemainingMessages
     * @return void
     */
    public function stop(bool $runRemainingMessages = true): void
    {
        if (!$this->started) {
            return;
        }
        try {
            if ($runRemainingMessages) {
                $this->runQueuedMessages();
            }
        } finally {
            ArgusApiOperations::$transportRecording = null;
            MCPToolExecutorService::$toolResultRecording = null;
            AgentToolCallForkJoinService::$forkingDisabled = false;
            ArgusLoad::$deactivateArgusCache = $this->argusCacheWasDeactivated;
            DDDService::$noCache = $this->applicationCacheWasOff;
            AgentDecisionModelCircuitBreakerService::$harnessMode = $this->breakerHarnessModeWasOn;
            RecordedRunClockAndIdSources::$installedIdSource = $this->previousIdSource;
            // the capture that was active before the run is active again
            $this->messageQueue->stopCapturing();
            $this->started = false;
        }
    }

    /**
     * Ends the run where the harness still holds its scope: runs what is still queued, removes the hooks, then builds
     * the recording (record mode, {@see $concludedRecording}, without run fields) or the comparison (replay mode,
     * {@see $comparison}).
     *
     * @param AIConversation $rootConversation
     * @param array<string, int|string|null> $scopeIdsByName the scope ids the run created for itself
     * @return void
     * @throws DBALException
     */
    public function concludeRun(AIConversation $rootConversation, array $scopeIdsByName): void
    {
        $this->setScopeIds($scopeIdsByName);
        $scopeIdsByName = $this->scope->scopeIdsByName;
        $this->stop();
        if ($this->isReplay()) {
            $this->comparison = $this->compareWithRecording($rootConversation, $scopeIdsByName);
            return;
        }
        $this->concludedRecording = $this->buildRecording($rootConversation, $scopeIdsByName, AgentRecordedRunFields::newInstance());
    }

    /**
     * The recording of this run: the harness's own fields, the calls, tool results, the id normalization (marks,
     * minted ids, the ordinals the snapshot assigned) and the normalized snapshot of the conversation tree and the
     * dispatched messages.
     *
     * @param AIConversation $rootConversation
     * @param array<string, int|string|null> $scopeIdsByName the scope ids the run names as its own
     * @param AgentRecordedRunFields $runFields
     * @return AgentRecordedRunRecording
     * @throws DBALException
     */
    public function buildRecording(AIConversation $rootConversation, array $scopeIdsByName, AgentRecordedRunFields $runFields): AgentRecordedRunRecording
    {
        $agentRecordedRunSnapshot = $this->buildSnapshotOfRun($rootConversation, $scopeIdsByName, []);
        return AgentRecordedRunRecording::forRun(
            $this->toolsRunLive,
            (int)($rootConversation->id ?? 0),
            $scopeIdsByName,
            $this->idSeed,
            $runFields,
            $this->requireIdNormalization(),
            $this->createdIdRead ?? AgentRecordedRunCreatedIdRead::newInstance(),
            $this->transportRecording,
            $this->toolResultRecording,
            $agentRecordedRunSnapshot
        );
    }

    /**
     * The replay against its recording: snapshot sections, then the requests sent to Argus, then the recorded calls
     * and tool results the replay never asked for.
     *
     * @param AIConversation $rootConversation
     * @param array<string, int|string|null> $scopeIdsByName the scope ids this replay created for itself
     * @return AgentRecordedRunComparison
     * @throws DBALException
     */
    public function compareWithRecording(AIConversation $rootConversation, array $scopeIdsByName): AgentRecordedRunComparison
    {
        $recording = $this->recording ?? AgentRecordedRunRecording::newInstance();
        $actualSnapshot = $this->buildSnapshotOfRun($rootConversation, $scopeIdsByName, $recording->getScopeIds());
        $comparison = $recording->compareWithReplay(
            $actualSnapshot,
            $scopeIdsByName,
            $this->requireIdNormalization(),
            $this->transportRecording,
            $this->toolResultRecording,
            $this->toolsRunLive
        );
        if ($this->playedAnswerIdTranslation !== null) {
            $comparison->addPlayedAnswerIdTranslationReport($this->playedAnswerIdTranslation->translations, $this->playedAnswerIdTranslation->missingCounterparts);
        }
        $comparison->addCreatedIdReadReport($recording->getCreatedIdRead()->buildReadReport(), 'recording');
        $comparison->addCreatedIdReadReport(($this->createdIdRead ?? AgentRecordedRunCreatedIdRead::newInstance())->buildReadReport(), 'replay');
        return $comparison;
    }

    /**
     * A replay whose tools run live creates its rows under other ids than the recording: every played answer to a call
     * outside a tool gets the recording's created ids in its tool-call arguments translated to the replay's ids of the
     * same table and ordinal, read from the database when the answer is handed over (the rows the replay created so far).
     *
     * @return void
     */
    protected function installPlayedAnswerIdTranslation(): void
    {
        $recordedCreatedIdsByTable = $this->recording?->getRecordedCreatedIdsByTable() ?? [];
        if ($recordedCreatedIdsByTable === [] || $this->transportRecording === null) {
            return;
        }
        $this->playedAnswerIdTranslation = $this->getAgentRecordedRunsService()->buildPlayedAnswerIdTranslation($recordedCreatedIdsByTable);
        // only the tables the recording created rows in can translate anything
        $this->replayHighWaterMarksOfRecordedTables = array_intersect_key($this->requireIdNormalization()->highWaterMarksByTable, $recordedCreatedIdsByTable);
        $this->transportRecording->playedAnswerTranslator = $this;
    }

    /**
     * A played answer to a call outside a tool, with the recording's created ids translated to the replay's (read when
     * the answer is handed over, {@see findReplayCreatedIdsByTable()}).
     *
     * @param string $responseBody
     * @param int $recordedPosition
     * @return string
     */
    public function translatePlayedAnswerBody(string $responseBody, int $recordedPosition): string
    {
        if ($this->playedAnswerIdTranslation === null) {
            return $responseBody;
        }
        // the translation asks for the created ids only when the answer carries a recorded one
        return $this->playedAnswerIdTranslation->translateResponseBody($responseBody, $this, "recorded call #{$recordedPosition}");
    }

    /**
     * The ids the replay created so far in the tables the recording created rows in, read in the same scope the
     * recording's created ids were read in: the run's tree so far (root set by the harness), then its scopes.
     *
     * @return array<string, int[]> table → ids
     * @throws DBALException
     */
    public function findReplayCreatedIdsByTable(): array
    {
        return DatabaseWriteStatementAudit::findIdsAboveHighWaterMarks(
            EntityManagerFactory::getInstance()->getConnection(),
            $this->replayHighWaterMarksOfRecordedTables,
            $this->buildRowScopeColumns($this->findTreeConversationIdsSoFar())
        )->idsByTable;
    }

    /**
     * @return int[] the ids of the run's conversation tree as stored now (none before the root exists)
     * @throws DBALException
     */
    protected function findTreeConversationIdsSoFar(): array
    {
        $rootConversationId = $this->scope->rootConversationId;
        if ($rootConversationId === null) {
            return [];
        }
        $sqlDialect = EntityManagerFactory::getDialect();
        return array_map('intval', EntityManagerFactory::getInstance()->getConnection()->fetchFirstColumn(
            'SELECT ' . $sqlDialect->quote('id') . ' FROM ' . $sqlDialect->quote(DBAIConversationModel::getTableName())
            . ' WHERE ' . $sqlDialect->quote('id') . ' = ? OR ' . $sqlDialect->quote('rootConversationId') . ' = ?',
            [$rootConversationId, $rootConversationId]
        ));
    }

    /**
     * The one-line summary a harness prints: Argus calls, rows per section, dispatched messages.
     *
     * @param AgentRecordedRunSnapshot $agentRecordedRunSnapshot
     * @return string
     */
    public function describeRun(AgentRecordedRunSnapshot $agentRecordedRunSnapshot): string
    {
        $sectionCounts = [];
        foreach ($agentRecordedRunSnapshot->countRowsBySection() as $section => $rowCount) {
            $sectionCounts[] = $rowCount . ' ' . $section;
        }
        $argusCallCount = match (true) {
            $this->transportRecording === null => 0,
            $this->isReplay() => $this->transportRecording->playedRequests->count(),
            default => $this->transportRecording->entries->count(),
        };
        return $argusCallCount . ' Argus calls, ' . implode(', ', $sectionCounts);
    }

    /**
     * The normalized snapshot of this run: its minted ids read now (every table that grew since {@see start()}), then
     * the tree sections and the dispatched messages with what running each did, in dispatch order.
     *
     * @param AIConversation $rootConversation
     * @param array<string, int|string|null> $scopeIdsByName
     * @param array<string, int|string|null> $otherRunScopeIdsByName the other run's scope ids, read as the same placeholders
     * @return AgentRecordedRunSnapshot
     * @throws DBALException
     */
    protected function buildSnapshotOfRun(AIConversation $rootConversation, array $scopeIdsByName, array $otherRunScopeIdsByName): AgentRecordedRunSnapshot
    {
        $idNormalization = $this->requireIdNormalization();
        $idNormalization->addScopeIds($scopeIdsByName);
        $idNormalization->addScopeIds($otherRunScopeIdsByName);
        $this->setRootConversation($rootConversation);
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
        $treeRows = $aIConversationsService->findTreeRowsForRecordedRunComparison($rootConversation);
        $treeConversationIds = array_map(static fn (array $conversationRow): int => (int)$conversationRow['id'], $treeRows[AgentRecordedRunComparison::SECTION_CONVERSATIONS] ?? []);
        $idsAboveHighWaterMarks = DatabaseWriteStatementAudit::findIdsAboveHighWaterMarks(
            EntityManagerFactory::getInstance()->getConnection(),
            $idNormalization->highWaterMarksByTable,
            $this->buildRowScopeColumns($treeConversationIds)
        );
        $this->createdIdRead = AgentRecordedRunCreatedIdRead::fromIdsAboveHighWaterMarks(
            $idsAboveHighWaterMarks,
            $this->findSharedScopeTables($idsAboveHighWaterMarks->scopeColumnByTable)
        );
        $idNormalization->addMintedIds($this->createdIdRead->getIdsByTable());
        $rowsBySection = AgentRecordedRunComparison::buildTreeSnapshot($treeRows, $idNormalization);
        $dispatchedMessages = [];
        foreach ($this->messageQueue->buildDispatchedMessagesWithOutcomes()->getElements() as $dispatchedMessageWithOutcome) {
            $dispatchedMessages[] = AgentRecordedRunDispatchedMessage::forMessageClassAndBody(
                $dispatchedMessageWithOutcome->messageClass,
                $dispatchedMessageWithOutcome->body,
                (string)$dispatchedMessageWithOutcome->outcome
            );
        }
        $rowsBySection[AgentRecordedRunComparison::SECTION_DISPATCHES] = AgentRecordedRunComparison::buildDispatchSnapshot($dispatchedMessages, $idNormalization);
        return AgentRecordedRunSnapshot::fromRowsBySection($rowsBySection);
    }

    /**
     * The columns a created-id read is scoped by, in priority order: the run's own conversations (the tree), then the
     * columns of its scopes ({@see AgentRecordedRunScope::buildScopeValuesByColumn()}).
     *
     * @param int[] $treeConversationIds
     * @return array<string, int[]> column → the run's values
     */
    public function buildScopeValuesByColumn(array $treeConversationIds): array
    {
        return $this->scope->buildScopeValuesByColumn($this->scopeColumns, $treeConversationIds);
    }

    /**
     * @param int[] $treeConversationIds
     * @return DatabaseRowScopeColumns the scope columns of {@see buildScopeValuesByColumn()} for the framework's read
     */
    public function buildRowScopeColumns(array $treeConversationIds): DatabaseRowScopeColumns
    {
        return $this->scope->buildRowScopeColumns($this->scopeColumns, $treeConversationIds);
    }

    /**
     * @param array<string, string> $scopeColumnByTable table → the scope column it was read through
     * @return array<string, string> the tables read through a column of a shared scope, with their column
     */
    public function findSharedScopeTables(array $scopeColumnByTable): array
    {
        return $this->scope->findSharedScopeTables($scopeColumnByTable, $this->scopeColumns);
    }

    /**
     * @return AgentRecordedRunIdNormalization
     * @throws RuntimeException when the run never started
     */
    protected function requireIdNormalization(): AgentRecordedRunIdNormalization
    {
        if ($this->idNormalization === null) {
            throw new RuntimeException('recorded run: the run was never started — no high-water marks');
        }
        return $this->idNormalization;
    }

    /** @return AgentRecordedRunsService */
    protected function getAgentRecordedRunsService(): AgentRecordedRunsService
    {
        /** @var AgentRecordedRunsService $agentRecordedRunsService */
        $agentRecordedRunsService = DDDService::instance()->getService(AgentRecordedRunsService::class);
        return $agentRecordedRunsService;
    }

    /**
     * The process switches of a recorded run: no Argus cache, no application cache, the decision-model breaker in
     * harness mode (never open, the shared key untouched). Re-applied after every queued job's worker-state reset.
     *
     * @return void
     */
    protected static function switchHarnessSwitchesOn(): void
    {
        ArgusLoad::$deactivateArgusCache = true;
        DDDService::$noCache = true;
        AgentDecisionModelCircuitBreakerService::$harnessMode = true;
    }
}
