<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunComparison;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Evals\SeedAccounts\AgentEvalSeedAccountLease;
use DDD\Domain\AI\Entities\Evals\SeedAccounts\AgentEvalSeedPool;
use DDD\Domain\AI\Entities\MCPPackages\ToolResult;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultRecording;
use DDD\Domain\AI\Entities\RecordedRuns\RecordedRunIdSource;
use DDD\Domain\AI\Entities\RecordedRuns\RecordedRunInstalledSources;
use DDD\Domain\AI\Entities\RecordedRuns\RecordedRunNowSource;
use DDD\Domain\AI\Interfaces\AIConversationProgressReporterInterface;
use DDD\Domain\AI\Libs\RecordedRuns\RecordedRunClockAndIdSources;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Decisions\AgentDecisionModelCircuitBreakerService;
use DDD\Domain\AI\Services\Eval\AgentEvalSeedAccountLeasesService;
use DDD\Domain\AI\Services\Eval\AgentRecordedRunsService;
use DDD\Domain\Base\Entities\MessageHandlers\AppMessageHandler;
use DDD\Domain\Base\Entities\MessageHandlers\MessageDispatchCapture;
use DDD\Domain\Base\Entities\RecordedRuns\RecordedRunClockWindow;
use DDD\Domain\Base\Entities\RecordedRuns\RecordedRunWallClockNormalization;
use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperations;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusTransportRecording;
use DDD\Domain\Base\Repo\Argus\Utils\RecordedRunIdCounterparts;
use DDD\Domain\Base\Repo\DB\Doctrine\Audit\DatabaseIdsAboveHighWaterMarks;
use DDD\Domain\Base\Repo\DB\Doctrine\Audit\DatabaseRowScopeColumns;
use DDD\Domain\Base\Repo\DB\Doctrine\Audit\DatabaseWriteStatement;
use DDD\Domain\Base\Repo\DB\Doctrine\Audit\DatabaseWriteStatementAudit;
use DDD\Domain\Base\Repo\DB\Doctrine\Audit\DatabaseWriteStatementShapes;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Throwable;

/**
 * One run of `app:mcp:compare-tool-calls`: it executes the scripted tool calls of one MCP package in this process
 * through {@see MCPToolExecutorService::execute()} — the real gates, hydration and rendering, no model — and keeps, per
 * case, everything the model receives and every effect the call has ({@see ToolCallComparisonCaseOutcome}). RECORD
 * stores it with the Argus calls the tools made; REPLAY runs the same script on the build under test with those calls
 * answered from the recording (the in-tool Argus player, never a played tool result) and builds the differences.
 *
 * Where it runs: in the agent space the run prepares ({@see prepareComparisonAgentSpace()}; by default the one the
 * script names, else account level), as the account the command authenticated. An application that runs every
 * comparison in a fresh agent space of its own creates it there (before any hook is installed: its Argus calls are
 * real in both runs), leases the account it belongs to for the whole run ({@see acquireSeedAccountLease()}) and removes
 * it again in {@see releaseComparisonAgentSpace()} (after the hooks are removed). The agent space is a harness row like
 * the conversations: normalised to its placeholder ({@see getComparisonAgentSpacePlaceholder()}) and, on a replay,
 * paired with the recorded id so the players match a request that names it and hand the recorded answer back in the
 * replay's ids ({@see RecordedRunIdCounterparts}).
 *
 * What makes the two runs byte-comparable: the pinned clock and the pinned id source ({@see RecordedRunClockAndIdSources};
 * the moment and the seed are stored in the recording, the id source is re-seeded per call), no Argus or application
 * cache, the decision-model breaker in harness mode, and the same fixture state: the harness creates the script's
 * conversations anew in both runs (one id each for the whole run) and runs the script's fixture calls and each case's
 * `before` calls first. Database ids are normalised ({@see ToolCallComparisonIdPlaceholders}); the wall clock the run
 * could not pin is normalised by ONE rule for statements, dispatches and the JSON of tool results
 * ({@see buildWallClockNormalization()}, {@see buildComparableResultContent()}).
 *
 * The same starting state for every call: each call — case, fixture or `before` call — starts in the process state of
 * a fresh worker job ({@see resetWorkerStateBeforeCall()}: the entity registries and the Doctrine caches emptied, the
 * connection renewed), so it reads the database, never what an earlier call of THIS run left in the process. A global
 * catalog row a case fills on first use is the script's concern: its fixture fills it in both runs, so every case meets
 * it filled — and a code change that adds or drops a write is still a difference, because the cases' statements are
 * compared in full. The Argus calls a harness call no longer makes on the replay are no difference: harness calls are
 * not compared.
 *
 * Every Argus call of the run — the harness's own included — counts as made inside a tool (labelled with the call it
 * belongs to), so the player answers each from the recorded calls of the same call. Messenger dispatches never leave
 * the process: a case's are kept for the comparison, the harness's are dropped.
 *
 * An application adds its own recorders (calls to its own back ends the tools make) through
 * {@see buildHostRecording()}: a {@see ToolCallComparisonHostRecordingInterface} that the run labels per call, whose
 * calls a record run keeps in its recording and whose requests each case outcome compares.
 *
 * A plain object of one run, created only by {@see \DDD\Domain\AI\Services\Eval\ToolCallComparisonsService}; no
 * container service.
 *
 * @phpstan-consistent-constructor
 */
#[Exclude]
class ToolCallComparisonSession implements AIConversationProgressReporterInterface
{
    public const string MODE_RECORD = 'record';
    public const string MODE_REPLAY = 'replay';

    /** The label of the Argus calls the harness makes while it creates the fixture conversations. */
    public const string CONVERSATION_SETUP_LABEL = 'harness#conversations';

    /**
     * How many seconds the pinned moment lies before the run starts. Every wall-clock moment of the run (the stamps of
     * the run's own agent space, the stamps of unswitched sites) then differs from the pinned moment, which the
     * wall-clock rule keeps raw: a stamp written in the same second as the pinned moment would otherwise stay raw in
     * the recording and read `<clock>` in the replay.
     */
    public const int PINNED_MOMENT_LEAD_SECONDS = 2;

    /** The placeholder the run's agent space reads as in the compared output. */
    public const string COMPARISON_AGENT_SPACE_PLACEHOLDER = '<comparison:agentSpace>';

    /** The scope name of the run's agent space in {@see buildScopeIdsByName()}. */
    public const string SCOPE_NAME_AGENT_SPACE = AgentRecordedRunsService::SCOPE_NAME_AGENT_SPACE;

    /** The scope name of the run's account in {@see buildScopeIdsByName()}. */
    public const string SCOPE_NAME_ACCOUNT = 'account';

    public string $mode = self::MODE_RECORD;

    public ToolCallComparisonScript $script;

    /** The class name of the package under test. */
    public string $packageClass = '';

    /** @var string[] the class names of the package under test and the additional packages, as offered to the executor */
    public array $offeredPackageClasses = [];

    /** The recording a replay answers from and compares with; the recording a record run builds. */
    public ToolCallComparisonRecording $recording;

    public ToolCallComparisonIdPlaceholders $idPlaceholders;

    public ?ToolCallComparisonDefinitionsDump $definitionsDump = null;

    /** The harness conversations of this run, by fixture name. */
    public ToolCallComparisonHarnessConversations $harnessConversations;

    /** @var string[] differences of the run that belong to no case (unused recorded calls) */
    public array $runDifferences = [];

    public ?ArgusTransportRecording $transportRecording = null;

    /** The application's own recorder (record) or player (replay) of the run; null when the application has none. */
    public ?ToolCallComparisonHostRecordingInterface $hostRecording = null;

    /** Swallows what the harness itself dispatches (conversation setup, fixture and before calls). */
    public MessageDispatchCapture $harnessDispatchCapture;

    /** The agent space the run works in; null = account level. */
    public ?AgentSpace $comparisonAgentSpace = null;

    /** The id the run's tool arguments name its agent space by ({@see ToolCallComparisonToolCall::AGENT_SPACE_ID_PLACEHOLDER}); 0 = none. */
    public int $comparisonAgentSpaceId = 0;

    /** The harness rows under their replay and recorded ids (replay only; the players read it). */
    public RecordedRunIdCounterparts $idCounterparts;

    /** @var string[] what the preparation and the release of the run's agent space reported, in order */
    public array $progressLines = [];

    /** The lease of the account the run works on, when the application takes one. */
    public ?AgentEvalSeedAccountLease $seedAccountLease = null;

    /** Unix seconds of {@see start()}: the start of the run's wall-clock window. */
    public int $runStartedAtTimestamp = 0;

    /** @var array<string, string[]> case id → the Argus requests of the replay no recorded call answered (a counter cache, never returned) */
    protected array $unansweredArgusRequestsByCase = [];

    protected bool $started = false;

    protected bool $argusCacheWasDeactivated = false;

    protected bool $applicationCacheWasOff = false;

    protected bool $breakerHarnessModeWasOn = false;

    public function __construct()
    {
        $this->idPlaceholders = ToolCallComparisonIdPlaceholders::newInstance();
        $this->harnessDispatchCapture = new MessageDispatchCapture();
        $this->recording = ToolCallComparisonRecording::newInstance();
        $this->idCounterparts = new RecordedRunIdCounterparts();
        $this->harnessConversations = ToolCallComparisonHarnessConversations::newInstance();
    }

    /**
     * Makes this session the recording run of the script: the pinned moment before now, a fresh id seed.
     *
     * @param string $packageClass the package's class name
     * @param string[] $additionalPackageClasses
     */
    public function prepareRecording(ToolCallComparisonScript $toolCallComparisonScript, string $packageClass, array $additionalPackageClasses): void
    {
        $this->mode = self::MODE_RECORD;
        $this->script = $toolCallComparisonScript;
        $this->packageClass = $packageClass;
        $this->offeredPackageClasses = array_values(array_unique(array_merge([$packageClass], $additionalPackageClasses)));
        $this->idPlaceholders->declareOpaqueTokenKeys(array_merge($toolCallComparisonScript->opaqueTokenKeys, $this->findApplicationOpaqueTokenKeys()));
        $this->recording->scriptName = $toolCallComparisonScript->scriptName;
        $this->recording->scriptHash = $toolCallComparisonScript->contentHash;
        $this->recording->packageClass = $packageClass;
        $this->recording->pinnedMoment = (new DateTime('-' . static::PINNED_MOMENT_LEAD_SECONDS . ' seconds'))->format('Y-m-d H:i:s');
        $this->recording->idSeed = bin2hex(random_bytes(8));
    }

    /**
     * Makes this session the replay of the script against `$toolCallComparisonRecording`.
     *
     * @param string $packageClass the package's class name
     * @param string[] $additionalPackageClasses
     * @throws RuntimeException when the recording refuses the script
     */
    public function prepareReplay(ToolCallComparisonScript $toolCallComparisonScript, string $packageClass, array $additionalPackageClasses, ToolCallComparisonRecording $toolCallComparisonRecording): void
    {
        $replayRefusal = $toolCallComparisonRecording->findReplayRefusal($toolCallComparisonScript);
        if ($replayRefusal !== null) {
            throw new RuntimeException($replayRefusal);
        }
        $this->prepareRecording($toolCallComparisonScript, $packageClass, $additionalPackageClasses);
        $this->mode = self::MODE_REPLAY;
        $this->recording = $toolCallComparisonRecording;
    }

    public function isReplay(): bool
    {
        return $this->mode === self::MODE_REPLAY;
    }

    /**
     * Builds the definitions dump (before any tool runs), prepares the run's agent space, installs the run's hooks and
     * creates the fixture conversations. A failure after the preparation leaves the release to {@see stop()}.
     *
     * @param string[] $bundleClasses the class names of every MCP bundle of the application
     * @throws Throwable when the run is refused, its agent space cannot be prepared or read, or a fixture conversation
     *         cannot be created
     */
    public function start(array $bundleClasses): void
    {
        $this->runStartedAtTimestamp = time();
        $this->definitionsDump = $this->buildDefinitionsDump($bundleClasses);
        $this->refuseAnAccountOtherThanTheComparisonAccount();
        $highWaterMarksBeforeThePreparation = $this->readIdHighWaterMarks();
        $preparationStatementAudit = $this->startStatementAudit();
        try {
            $this->prepareComparisonAgentSpace();
        } finally {
            if ($preparationStatementAudit->isStarted()) {
                $preparationStatementAudit->stop();
            }
        }
        $this->declareIdsMintedSince($highWaterMarksBeforeThePreparation, $preparationStatementAudit);
        $this->refuseUnsafeScriptReferences();
        if (!$this->isReplay()) {
            $this->recording->comparisonAgentSpaceId = $this->comparisonAgentSpaceId;
        }
        $this->installHarnessHooks();
        $this->labelRecordings(static::CONVERSATION_SETUP_LABEL);
        $this->createHarnessConversations();
    }

    /** Runs the script's fixture calls (not compared). @return string[] the fixture calls that did not complete, one line each */
    public function runFixtureSteps(): array
    {
        $failedFixtureSteps = [];
        foreach ($this->script->fixtureSteps->getElements() as $fixtureStep) {
            $failedFixtureStep = $this->runHarnessStep($fixtureStep);
            if ($failedFixtureStep !== null) {
                $failedFixtureSteps[] = $failedFixtureStep;
            }
        }
        return $failedFixtureSteps;
    }

    /**
     * Runs one case — its `before` calls, then the case itself under the pinned clock and ids with every capture on — and
     * returns its outcome (kept in the recording when recording).
     */
    public function runCase(ToolCallComparisonToolCall $case): ToolCallComparisonCaseOutcome
    {
        $caseExecution = new ToolCallComparisonCaseExecution($case, $this->readIdHighWaterMarks());
        foreach ($case->beforeSteps->getElements() as $beforeStep) {
            $failedBeforeStep = $this->runHarnessStep($beforeStep);
            if ($failedBeforeStep !== null) {
                $caseExecution->failedBeforeSteps[] = $failedBeforeStep;
            }
        }
        $this->resetWorkerStateBeforeCall();
        foreach ($this->harnessConversations->getElements() as $harnessConversation) {
            $caseExecution->newestRowIndexesByConversation[$harnessConversation->name] = $harnessConversation->conversation->messages->getNextIndex() - 1;
        }
        $caseExecution->argusCallCountBefore = $this->countArgusCalls();
        $this->executeCaseUnderCaptures($caseExecution);
        $this->declareIdsMintedSince($caseExecution->highWaterMarksByTable, $caseExecution->statementAudit);
        $caseOutcome = $this->buildCaseOutcome($caseExecution);
        if ($this->isReplay()) {
            $this->unansweredArgusRequestsByCase[$case->id] = $this->findUnansweredArgusRequestsSince($caseExecution->argusCallCountBefore);
        } else {
            // replace() takes its elements by reference: hand it its own variable, so the returned outcome keeps its type
            $recordedCaseOutcome = $caseOutcome;
            $this->recording->caseOutcomes->replace($recordedCaseOutcome);
        }
        return $caseOutcome;
    }

    /**
     * The differences of a replayed case against its recorded outcome, one line each; empty = identical.
     *
     * @return string[]
     */
    public function buildCaseDifferences(ToolCallComparisonCaseOutcome $replayedOutcome): array
    {
        $recordedOutcome = $this->recording->caseOutcomes->findOutcomeOfCase($replayedOutcome->caseId);
        if ($recordedOutcome === null) {
            return ['the recording holds no outcome of this case'];
        }
        // the replayed outcome goes through the same JSON round trip the recorded one went through (floats, invalid UTF-8)
        $replayedOutcomeAsRecorded = $replayedOutcome::fromArray((array)json_decode(
            (string)json_encode($replayedOutcome->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
            true
        ));
        return array_merge($recordedOutcome->buildDifferencesFrom($replayedOutcomeAsRecorded), $this->unansweredArgusRequestsByCase[$replayedOutcome->caseId] ?? []);
    }

    /**
     * Removes the run's hooks — a record run copies the recorded calls into its recording, a replay names the recorded
     * calls it never asked for ({@see $runDifferences}) — and releases the run's agent space, also after a failed start.
     */
    public function stop(): void
    {
        try {
            $this->removeHarnessHooks();
        } finally {
            $this->releaseComparisonAgentSpace();
        }
    }

    /** Keeps one line the preparation or the release of the run's agent space reported. */
    public function reportProgress(string $progressLine): void
    {
        $this->progressLines[] = $progressLine;
    }

    /**
     * Whether calls recorded under this label belong to a harness call (the conversation setup, a fixture or a
     * `before` call): those are not compared, so a recorded call of theirs the replay never asked for (a catalog row
     * the recording's fixture filled, which the replay's fixture finds filled) is no difference.
     */
    public function isHarnessCallLabel(?string $toolCallLabel): bool
    {
        return $toolCallLabel === static::CONVERSATION_SETUP_LABEL || in_array($toolCallLabel, $this->script->getHarnessStepIds(), true);
    }

    /**
     * The run's wall-clock rule for statements, dispatches and tool results: the window from the start of the run to
     * now (stamps of the run's agent space and of earlier calls included), the pinned moment kept raw, the script's
     * derived-moment and duration keys and its wall-clock text prefixes.
     */
    public function buildWallClockNormalization(): RecordedRunWallClockNormalization
    {
        $pinnedMoment = new DateTime($this->recording->pinnedMoment);
        return RecordedRunWallClockNormalization::declare(
            RecordedRunClockWindow::between($this->runStartedAtTimestamp - 1, time() + 1),
            [$pinnedMoment->format('Y-m-d H:i:s'), (string)$pinnedMoment->getTimestamp()],
            $this->script->derivedMomentKeys,
            $this->script->durationKeys,
            $this->script->wallClockTextPrefixes
        );
    }

    /**
     * The tool result as two runs compare it: the run's wall-clock rule on the JSON the result is or carries (the
     * executor's JSON of the MCP response, and a structured result serialised into its text — a row the run created
     * reads `"changeHistory":{"createdTime":"<clock>"}`), then the database ids. Only time-named keys of JSON are
     * touched, and in text only a time of day after one of the script's `wallClockTextPrefixes`: any other moment
     * written in free text (a markdown result) stays raw, and so does a moment outside the run's window — every other
     * value of the JSON is compared as it is.
     */
    public function buildComparableResultContent(string $resultContent, RecordedRunWallClockNormalization $wallClockNormalization): string
    {
        return $this->idPlaceholders->normalizeText($wallClockNormalization->normalizeClockTextPhrases($wallClockNormalization->normalizeJsonText($resultContent)));
    }

    /** The placeholder the run's agent space reads as in the compared output. */
    public function getComparisonAgentSpacePlaceholder(): string
    {
        return static::COMPARISON_AGENT_SPACE_PLACEHOLDER;
    }

    /**
     * Prepares the agent space the run works in: sets {@see $comparisonAgentSpace} and {@see $comparisonAgentSpaceId}
     * and declares the harness rows it consists of ({@see declareHarnessId()}). The default: the space the script
     * names, read as the run's account (none: account level). An application that creates a fresh space per run does it
     * here and reports its progress lines through {@see reportProgress()}.
     *
     * @throws RuntimeException when the space the script names cannot be read by the run's account
     */
    protected function prepareComparisonAgentSpace(): void
    {
        if ($this->script->agentSpaceId === null) {
            return;
        }
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        $agentSpace = $agentSpacesService->find($this->script->agentSpaceId, false);
        if (!$agentSpace instanceof AgentSpace) {
            throw new RuntimeException("the agent space {$this->script->agentSpaceId} cannot be read by the account of this run");
        }
        $this->comparisonAgentSpace = $agentSpace;
        $this->comparisonAgentSpaceId = (int)$agentSpace->id;
        $this->declareHarnessId($this->comparisonAgentSpaceId, $this->getComparisonAgentSpacePlaceholder());
    }

    /**
     * Releases what {@see prepareComparisonAgentSpace()} prepared, also after a failed start. The default: gives back
     * the lease of the account, when one was taken.
     */
    protected function releaseComparisonAgentSpace(): void
    {
        $this->releaseSeedAccountLease();
    }

    /**
     * Refuses a run whose authenticated account may not run a comparison, before anything is prepared. The default:
     * every account may.
     *
     * @throws RuntimeException
     */
    protected function refuseAnAccountOtherThanTheComparisonAccount(): void
    {
    }

    /**
     * Refuses a run whose script names something a call must never reach (an id of a real record), after the agent
     * space is prepared and before any hook is installed. The default: nothing is refused.
     *
     * @throws RuntimeException
     */
    protected function refuseUnsafeScriptReferences(): void
    {
    }

    /**
     * Leases the account of the seed `$seedId` for the run — waits while another evaluation process holds it — and
     * reports the lease line. Call it from {@see prepareComparisonAgentSpace()} before anything is created on the account.
     *
     * @throws RuntimeException when the account stays leased beyond the wait
     */
    protected function acquireSeedAccountLease(int $seedId): void
    {
        /** @var AgentEvalSeedAccountLeasesService $agentEvalSeedAccountLeasesService */
        $agentEvalSeedAccountLeasesService = DDDService::instance()->getService(AgentEvalSeedAccountLeasesService::class);
        $this->seedAccountLease = $agentEvalSeedAccountLeasesService->acquireLeaseFromPool(AgentEvalSeedPool::fromSeedIds([$seedId]));
        $this->reportProgress($this->seedAccountLease->describe());
    }

    /** Gives back the lease of {@see acquireSeedAccountLease()}; nothing when none is held. */
    protected function releaseSeedAccountLease(): void
    {
        if ($this->seedAccountLease === null) {
            return;
        }
        /** @var AgentEvalSeedAccountLeasesService $agentEvalSeedAccountLeasesService */
        $agentEvalSeedAccountLeasesService = DDDService::instance()->getService(AgentEvalSeedAccountLeasesService::class);
        $agentEvalSeedAccountLeasesService->releaseLease($this->seedAccountLease);
        $this->seedAccountLease = null;
    }

    /**
     * The definitions dump of the package, built from reflection before any tool runs.
     *
     * @param string[] $bundleClasses
     */
    protected function buildDefinitionsDump(array $bundleClasses): ToolCallComparisonDefinitionsDump
    {
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        return ToolCallComparisonDefinitionsDump::buildForPackage(
            $this->packageClass,
            $bundleClasses,
            $agentSpacesService->getDescriptionPrefixesOfToolArgumentsFilledFromAgentSpace()
        );
    }

    /** Creates the script's conversations: the root conversations first, then their delegation children. */
    protected function createHarnessConversations(): void
    {
        foreach ($this->script->conversationFixtures->getElements() as $conversationFixture) {
            if ($conversationFixture->childOf === null) {
                $this->addHarnessConversation($conversationFixture->name, $this->createConversation($conversationFixture, null));
            }
        }
        foreach ($this->script->conversationFixtures->getElements() as $conversationFixture) {
            if ($conversationFixture->childOf !== null) {
                $rootConversation = $this->harnessConversations->findConversationByName($conversationFixture->childOf);
                $this->addHarnessConversation($conversationFixture->name, $this->createConversation($conversationFixture, $rootConversation));
            }
        }
    }

    /** Keeps a created harness conversation; a record run also keeps its id in the recording. */
    protected function addHarnessConversation(string $conversationName, AIConversation $conversation): void
    {
        $harnessConversation = ToolCallComparisonHarnessConversation::forConversation($conversationName, $conversation);
        $this->harnessConversations->add($harnessConversation);
        if (!$this->isReplay()) {
            $this->recording->recordedConversationIds[$conversationName] = (int)$conversation->id;
        }
    }

    /** Removes the run's process hooks (no-op when they were never installed). */
    protected function removeHarnessHooks(): void
    {
        if (!$this->started) {
            return;
        }
        try {
            if ($this->isReplay()) {
                $this->runDifferences = array_merge(
                    $this->runDifferences,
                    $this->findUnusedRecordedArgusCalls(),
                    $this->hostRecording?->findUnusedCallLines($this->buildHarnessCallLabels()) ?? []
                );
            } else {
                $this->recording->keepArgusCallsOf($this->transportRecording);
                if ($this->hostRecording !== null) {
                    $this->recording->keepHostRecordedCallsOf($this->hostRecording);
                }
            }
        } finally {
            ArgusApiOperations::$transportRecording = null;
            MCPToolExecutorService::$toolResultRecording = null;
            $this->hostRecording?->uninstall();
            $this->harnessDispatchCapture->stopCapturing();
            ArgusLoad::$deactivateArgusCache = $this->argusCacheWasDeactivated;
            DDDService::$noCache = $this->applicationCacheWasOff;
            AgentDecisionModelCircuitBreakerService::$harnessMode = $this->breakerHarnessModeWasOn;
            $this->started = false;
        }
    }

    /**
     * The recorded Argus calls a replay never asked for, outside harness calls, one line each.
     *
     * @return string[]
     */
    protected function findUnusedRecordedArgusCalls(): array
    {
        $unusedRecordedArgusCalls = [];
        foreach ($this->transportRecording->unusedEntries() as $position => $endpoint) {
            $toolCallLabel = $this->transportRecording->entries->findEntryAtPosition($position)?->toolCallLabel;
            if (!$this->isHarnessCallLabel($toolCallLabel)) {
                $unusedRecordedArgusCalls[] = "unused recorded Argus call #{$position} {$endpoint} (" . ($toolCallLabel ?? '-') . ')';
            }
        }
        return $unusedRecordedArgusCalls;
    }

    /**
     * Installs the run's process hooks: the Argus transport (recorder or in-tool player, every call counted as made
     * inside a tool), the tool-result recorder (it only marks the tool boundary — results are never played), the
     * application's own recorders, the harness's dispatch capture, no Argus or application cache, the breaker in
     * harness mode.
     */
    protected function installHarnessHooks(): void
    {
        $this->transportRecording = $this->isReplay()
            ? $this->recording->buildArgusToolCallPlayer()
            : ArgusTransportRecording::forRecording();
        $this->transportRecording->insideToolExecution = true;
        if ($this->isReplay()) {
            $this->transportRecording->idCounterparts = $this->idCounterparts;
        }
        $this->hostRecording = $this->buildHostRecording();
        $this->argusCacheWasDeactivated = ArgusLoad::$deactivateArgusCache;
        $this->applicationCacheWasOff = DDDService::$noCache;
        $this->breakerHarnessModeWasOn = AgentDecisionModelCircuitBreakerService::$harnessMode;
        ArgusLoad::$deactivateArgusCache = true;
        DDDService::$noCache = true;
        AgentDecisionModelCircuitBreakerService::$harnessMode = true;
        ArgusApiOperations::$transportRecording = $this->transportRecording;
        MCPToolExecutorService::$toolResultRecording = ToolResultRecording::forRecording();
        $this->harnessDispatchCapture->startCapturing();
        $this->started = true;
    }

    /**
     * Builds and installs the application's own recorder of the run: on a record run a recorder, on a replay a player
     * that answers from {@see ToolCallComparisonRecording::getStoredHostRecordedCallsJson()} and pairs the harness ids
     * through {@see $idCounterparts}. The default: none.
     */
    protected function buildHostRecording(): ?ToolCallComparisonHostRecordingInterface
    {
        return null;
    }

    /**
     * The labels of the calls that are not compared: the conversation setup, the fixture calls and the `before` calls.
     *
     * @return string[]
     */
    protected function buildHarnessCallLabels(): array
    {
        return array_merge([static::CONVERSATION_SETUP_LABEL], $this->script->getHarnessStepIds());
    }

    /**
     * The id columns of the rows a call may create AND delete again in one call (a cost record that cost nothing), as
     * `table.column` → id family for {@see DatabaseWriteStatementAudit::buildComparableStatements()}: such an id is gone
     * when the minted ids are read after the call, so the statements normalise it against the case's high-water mark
     * ({@see buildHighWaterMarksOfDeletedRowFamilies()}). The default: none.
     *
     * @return array<string, string>
     */
    protected function buildDeletedRowIdFamilies(): array
    {
        return [];
    }

    /**
     * The high-water mark of each family of {@see buildDeletedRowIdFamilies()}, read from the marks taken before the case.
     *
     * @param int[] $highWaterMarksByTable table → its highest id before the case
     * @return array<string, int> id family → its high-water mark
     */
    protected function buildHighWaterMarksOfDeletedRowFamilies(array $highWaterMarksByTable): array
    {
        return [];
    }

    /**
     * Runs the call of one case with its dispatch capture and statement audit on, under the pinned clock and id source
     * seeded for the case; keeps what it answered or threw. Every capture is off again afterwards, also after a throw.
     */
    protected function executeCaseUnderCaptures(ToolCallComparisonCaseExecution $caseExecution): void
    {
        $sourcesInstalledBefore = null;
        try {
            $sourcesInstalledBefore = $this->installPinnedSourcesAndLabel($caseExecution->case->id);
            $caseExecution->dispatchCapture->startCapturing();
            $caseExecution->statementAudit->start();
            $caseExecution->toolResult = $this->executeToolCall($caseExecution->case);
        } catch (Throwable $executionThrowable) {
            $caseExecution->executionError = $executionThrowable::class . ': ' . $executionThrowable->getMessage();
        } finally {
            if ($caseExecution->statementAudit->isStarted()) {
                $caseExecution->statementAudit->stop();
            }
            $caseExecution->dispatchCapture->stopCapturing();
            if ($sourcesInstalledBefore !== null) {
                RecordedRunClockAndIdSources::restoreInstalledSources($sourcesInstalledBefore);
            }
            $this->transportRecording->insideToolExecution = true;
        }
    }

    /**
     * The outcome of a case from what it collected, normalised in one fixed order — the placeholders number ids by
     * first appearance: result, execution error, rows, statements, dispatches, the application's requests, Argus
     * requests.
     */
    protected function buildCaseOutcome(ToolCallComparisonCaseExecution $caseExecution): ToolCallComparisonCaseOutcome
    {
        $case = $caseExecution->case;
        $wallClockNormalization = $this->buildWallClockNormalization();
        $caseOutcome = ToolCallComparisonCaseOutcome::forCase($case->id, $case->toolName);
        if ($caseExecution->toolResult !== null) {
            $caseOutcome->resultContent = $this->buildComparableResultContent($caseExecution->toolResult->content, $wallClockNormalization);
            $caseOutcome->isError = $caseExecution->toolResult->isError;
            $caseOutcome->refusalKind = $caseExecution->toolResult->refusalKind;
        }
        if ($caseExecution->executionError !== null || $caseExecution->failedBeforeSteps !== []) {
            $caseOutcome->executionError = $this->idPlaceholders->normalizeText(trim(
                ($caseExecution->executionError ?? '')
                . ($caseExecution->failedBeforeSteps !== [] ? ' [before: ' . implode(' | ', $caseExecution->failedBeforeSteps) . ']' : '')
            ));
        }
        foreach ($this->harnessConversations->getElements() as $harnessConversation) {
            $caseOutcome->keepAppendedRowsOf(
                $harnessConversation->name,
                $harnessConversation->conversation->messages,
                $caseExecution->newestRowIndexesByConversation[$harnessConversation->name] ?? -1,
                $this->idPlaceholders
            );
        }
        $caseOutcome->keepWriteStatementsOf($caseExecution->statementAudit->buildComparableStatements(
            $this->buildDeletedRowIdFamilies(),
            $this->buildHighWaterMarksOfDeletedRowFamilies($caseExecution->highWaterMarksByTable),
            $wallClockNormalization->pinnedMoments,
            $this->script->derivedMomentColumns,
            $wallClockNormalization
        ), $this->idPlaceholders);
        $caseOutcome->keepDispatchesOf($caseExecution->dispatchCapture->buildComparableDispatches(
            wallClockNormalization: $wallClockNormalization
        ), $this->idPlaceholders);
        $caseOutcome->keepHostRequestsOf($this->hostRecording, $this->idPlaceholders);
        $caseOutcome->keepArgusRequestsOf($this->buildComparableArgusRequestsSince($caseExecution->argusCallCountBefore), $this->idPlaceholders);
        $caseOutcome->argusCallCount = $this->countArgusCalls() - $caseExecution->argusCallCountBefore;
        $statementAudit = $caseExecution->statementAudit;
        if ($statementAudit->wasInterrupted || $statementAudit->auditFailureCount > 0) {
            $caseOutcome->statementAuditGap = ($statementAudit->wasInterrupted ? 'the connection was re-created during the call; ' : '')
                . $statementAudit->auditFailureCount . ' statement(s) could not be kept';
        }
        return $caseOutcome;
    }

    /**
     * The Argus requests of a replay from a position on that no recorded call answered, one line each.
     *
     * @return string[]
     */
    protected function findUnansweredArgusRequestsSince(int $firstPosition): array
    {
        $unansweredArgusRequests = [];
        $playedArgusRequests = AgentRecordedRunComparison::buildPlayedArgusRequests($this->transportRecording->playedRequests);
        foreach (array_slice($playedArgusRequests, $firstPosition, null, true) as $playedPosition => $playedArgusRequest) {
            if ($playedArgusRequest->answeredByPosition === null) {
                $unansweredArgusRequests[] = "Argus call #{$playedPosition} {$playedArgusRequest->endpoint}: no recorded call answered it";
            }
        }
        return $unansweredArgusRequests;
    }

    /**
     * Puts the process into the state of a fresh worker job before a call: the framework's job reset (Doctrine caches,
     * connections renewed, the entity registries emptied) — without the application's worker reset (the account, the
     * budget grant and the tool guards stay).
     */
    protected function resetWorkerStateBeforeCall(): void
    {
        AppMessageHandler::resetWorkerStateForNewJob();
    }

    /** Puts the label of the call that runs now on the Argus recording and the application's recorders. */
    protected function labelRecordings(string $toolCallLabel): void
    {
        $this->transportRecording->toolCallLabel = $toolCallLabel;
        $this->hostRecording?->labelCalls($toolCallLabel);
    }

    /**
     * Puts the call's label on the recordings and installs the pinned clock and the id source seeded for this call;
     * the caller restores the returned sources in a `finally` ({@see RecordedRunClockAndIdSources::restoreInstalledSources()}).
     */
    protected function installPinnedSourcesAndLabel(string $toolCallLabel): RecordedRunInstalledSources
    {
        $this->labelRecordings($toolCallLabel);
        return RecordedRunClockAndIdSources::installPinnedSources(
            RecordedRunNowSource::pinnedAt(new DateTime($this->recording->pinnedMoment)),
            RecordedRunIdSource::seeded($this->recording->idSeed . ':' . $toolCallLabel)
        );
    }

    /**
     * Creates one fixture conversation in the run's agent space — its purpose, root, schedule and working state — with
     * its owner rows, and declares its ids as harness ids.
     *
     * @throws Throwable
     */
    protected function createConversation(ToolCallComparisonConversationFixture $conversationFixture, ?AIConversation $rootConversation): AIConversation
    {
        $conversation = AIConversation::newInstance();
        $conversation->accountId = (int)(AuthService::instance()->getAccount()->id ?? 0);
        $this->assignComparisonAgentSpace($conversation);
        $conversation->messages = AIConversationMessages::newInstance();
        $conversation->purposeName = $conversationFixture->purposeName;
        $conversation->scheduleId = $conversationFixture->scheduleId;
        $conversation->rootConversationId = $rootConversation !== null ? (int)$rootConversation->id : null;
        $agentWorkingState = $conversationFixture->buildAgentWorkingState();
        if ($agentWorkingState !== null) {
            $conversation->agentWorkingState = $agentWorkingState;
        }
        $conversation->update();
        $this->declareHarnessId((int)$conversation->id, '<conversation:' . $conversationFixture->name . '>');
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        foreach ($conversationFixture->ownerRows as $ownerRowIndex => $ownerRowText) {
            $ownerRow = AIConversationMessage::newInstance();
            $conversation->appendMessage(
                $ownerRow,
                AIConversationMessage::MESSAGE_TEXT,
                $ownerRowText,
                AIConversationMessage::ROLE_USER,
                AIConversationMessage::AUTHOR_USER
            );
            $aIConversationMessagesService->persistInboundRowIndexSafe($conversation, $ownerRow);
            if (isset($ownerRow->id)) {
                $this->declareHarnessId((int)$ownerRow->id, '<conversation:' . $conversationFixture->name . '/row#' . ($ownerRowIndex + 1) . '>');
            }
        }
        return $conversation;
    }

    /**
     * Puts a new harness conversation into the run's agent space, as the id and as the object. The default: the space
     * of {@see prepareComparisonAgentSpace()}; none = account level.
     */
    protected function assignComparisonAgentSpace(AIConversation $conversation): void
    {
        if ($this->comparisonAgentSpace === null) {
            return;
        }
        $conversation->agentSpaceId = (int)$this->comparisonAgentSpace->id;
        $conversation->agentSpace = $this->comparisonAgentSpace;
    }

    /**
     * Runs one harness call (fixture or `before`), pinned like a case, and declares the ids it minted (a later case that
     * reads such a row reads it as `<table#n>` in both runs); returns a line when it did not complete, else null.
     */
    protected function runHarnessStep(ToolCallComparisonToolCall $harnessStep): ?string
    {
        $this->resetWorkerStateBeforeCall();
        $highWaterMarksByTable = $this->readIdHighWaterMarks();
        $sourcesInstalledBefore = null;
        $harnessStepStatementAudit = null;
        try {
            $sourcesInstalledBefore = $this->installPinnedSourcesAndLabel($harnessStep->id);
            $harnessStepStatementAudit = $this->startStatementAudit();
            $toolResult = $this->executeToolCall($harnessStep);
            if ($harnessStep->expectsError) {
                return $toolResult->isError ? null : "{$harnessStep->id} ({$harnessStep->toolName}) was expected to answer an error and completed";
            }
            return $toolResult->isError ? "{$harnessStep->id} ({$harnessStep->toolName}) answered an error: " . mb_substr($toolResult->content, 0, 300) : null;
        } catch (Throwable $harnessStepError) {
            return "{$harnessStep->id} ({$harnessStep->toolName}) failed: " . $harnessStepError->getMessage();
        } finally {
            if ($harnessStepStatementAudit?->isStarted()) {
                $harnessStepStatementAudit->stop();
            }
            if ($sourcesInstalledBefore !== null) {
                RecordedRunClockAndIdSources::restoreInstalledSources($sourcesInstalledBefore);
            }
            $this->transportRecording->insideToolExecution = true;
            $this->declareIdsMintedSince($highWaterMarksByTable, $harnessStepStatementAudit ?? new DatabaseWriteStatementAudit());
        }
    }

    /**
     * One scripted call through the executor, with the provenance its kind asks for.
     *
     * @throws RuntimeException when the call names no harness conversation of this run
     */
    protected function executeToolCall(ToolCallComparisonToolCall $toolCall): ToolResult
    {
        $conversation = $this->harnessConversations->findConversationByName($toolCall->conversationName);
        if ($conversation === null) {
            throw new RuntimeException("{$toolCall->id}: no harness conversation {$toolCall->conversationName} in this run");
        }
        /** @var MCPToolExecutorService $mCPToolExecutorService */
        $mCPToolExecutorService = DDDService::instance()->getService(MCPToolExecutorService::class);
        return $mCPToolExecutorService->execute(
            $toolCall->toolName,
            $toolCall->buildArgumentsForAgentSpace($this->comparisonAgentSpaceId),
            $this->offeredPackageClasses,
            ['conversationId' => $conversation->id ?? null, 'toolCallComparison' => $toolCall->id],
            $this->buildProvenance($toolCall, $conversation)
        );
    }

    /**
     * The provenance of a scripted call: the agent loop's
     * ({@see \DDD\Domain\AI\Services\Conversations\Agent\AgentToolCallExecutionService::executeToolCall()} without an
     * origin message — the harness writes no assistant row), the eval harness's, or none.
     */
    protected function buildProvenance(ToolCallComparisonToolCall $toolCall, AIConversation $conversation): ?AgentToolCallProvenance
    {
        if ($toolCall->provenanceKind === ToolCallComparisonToolCall::PROVENANCE_NONE) {
            return null;
        }
        if ($toolCall->provenanceKind === ToolCallComparisonToolCall::PROVENANCE_EVAL_HARNESS) {
            $evalHarnessProvenance = AgentToolCallProvenance::forEvalHarness($conversation->agentSpace ?? null, $conversation);
            $evalHarnessProvenance->toolName = $toolCall->toolName;
            return $evalHarnessProvenance;
        }
        $conversation->refreshAgentSpaceTimezone();
        $conversationId = isset($conversation->id) ? (int)$conversation->id : null;
        $agentProvenance = new AgentToolCallProvenance(
            $conversation->getNewestUserMessage(),
            null,
            $conversationId,
            $conversation->agentSpace ?? null,
            isset($conversation->rootConversationId) ? (int)$conversation->rootConversationId : $conversationId,
            $conversation->messages ?? null,
            $conversation->scheduleId ?? null,
            $toolCall->toolName,
            $conversation,
        );
        $agentProvenance->toolCallId = 'call_cmp_' . substr(hash('sha256', $toolCall->id), 0, 16);
        return $agentProvenance;
    }

    /**
     * The columns the ids minted by a call are read through, in priority order: the harness conversations, then the
     * columns of each scope of {@see buildScopeIdsByName()} as the recorded-run service names them
     * ({@see AgentRecordedRunsService::getScopeColumnsByName()}).
     *
     * @return array<string, int[]> column → the run's values
     */
    protected function buildScopeValuesByColumn(): array
    {
        $conversationIds = [];
        $rootConversationIds = [];
        foreach ($this->harnessConversations->getElements() as $harnessConversation) {
            $conversationIds[] = (int)$harnessConversation->conversation->id;
            if (!isset($harnessConversation->conversation->rootConversationId)) {
                $rootConversationIds[] = (int)$harnessConversation->conversation->id;
            }
        }
        $scopeValuesByColumn = [
            'aiConversationId' => $conversationIds,
            'parentConversationId' => $conversationIds,
            'rootConversationId' => $rootConversationIds,
        ];
        /** @var AgentRecordedRunsService $agentRecordedRunsService */
        $agentRecordedRunsService = DDDService::instance()->getService(AgentRecordedRunsService::class);
        $agentRecordedRunScopeColumns = $agentRecordedRunsService->getScopeColumnsByName();
        foreach ($this->buildScopeIdsByName() as $scopeName => $scopeId) {
            foreach ($agentRecordedRunScopeColumns->getColumnsOfScope($scopeName) as $scopeColumn) {
                if ($scopeId > 0) {
                    $scopeValuesByColumn[$scopeColumn] = [$scopeId];
                }
            }
        }
        return array_filter($scopeValuesByColumn, static fn (array $scopeValues): bool => $scopeValues !== []);
    }

    /**
     * The scope ids of the run, by scope name, in priority order: the run's agent space, then its account. An
     * application whose space belongs to further scopes of its own names them here.
     *
     * @return array<string, int> scope name → id (0: none)
     */
    protected function buildScopeIdsByName(): array
    {
        return [
            static::SCOPE_NAME_AGENT_SPACE => (int)($this->comparisonAgentSpace->id ?? 0),
            static::SCOPE_NAME_ACCOUNT => (int)(AuthService::instance()->getAccount()->id ?? 0),
        ];
    }

    /**
     * The Argus requests of the run from a position on, as compared. RECORD reads its entries, REPLAY what it sent.
     */
    protected function buildComparableArgusRequestsSince(int $firstPosition): ToolCallComparisonArgusRequests
    {
        $toolCallComparisonArgusRequests = ToolCallComparisonArgusRequests::newInstance();
        if ($this->isReplay()) {
            $playedArgusRequests = AgentRecordedRunComparison::buildPlayedArgusRequests($this->transportRecording->playedRequests);
            foreach (array_slice($playedArgusRequests, $firstPosition) as $playedArgusRequest) {
                $toolCallComparisonArgusRequest = ToolCallComparisonArgusRequest::forRequest($playedArgusRequest->endpoint, $playedArgusRequest->requestBody);
                $toolCallComparisonArgusRequests->add($toolCallComparisonArgusRequest);
            }
            return $toolCallComparisonArgusRequests;
        }
        foreach (array_slice($this->transportRecording->toStorageArray(), $firstPosition) as $recordedArgusCall) {
            $toolCallComparisonArgusRequest = ToolCallComparisonArgusRequest::forRequest(
                (string)($recordedArgusCall['endpoint'] ?? ''),
                (string)($recordedArgusCall['requestBody'] ?? '')
            );
            $toolCallComparisonArgusRequests->add($toolCallComparisonArgusRequest);
        }
        return $toolCallComparisonArgusRequests;
    }

    /**
     * Declares a row the harness created itself: its placeholder for the comparison and, on a replay, its pair with the
     * recorded id of the same placeholder for the players; a recording keeps the id under its placeholder.
     */
    protected function declareHarnessId(int|string $id, string $placeholder): void
    {
        $this->idPlaceholders->declareHarnessId($id, $placeholder);
        if (!$this->isReplay()) {
            $this->recording->harnessIdsByPlaceholder[$placeholder] = (string)$id;
            return;
        }
        $recordedId = $this->recording->harnessIdsByPlaceholder[$placeholder] ?? null;
        if ($recordedId !== null) {
            $this->idCounterparts->addCounterpart($id, $recordedId);
        }
    }

    /**
     * The auto-increment high-water marks of every table, for {@see declareIdsMintedSince()}.
     *
     * @return int[] table → highest id
     */
    protected function readIdHighWaterMarks(): array
    {
        return DatabaseWriteStatementAudit::findIdHighWaterMarksOfAllTables(EntityManagerFactory::getInstance()->getConnection());
    }

    /**
     * Declares the ids minted since the marks were read to the placeholders: the ids of a table read through one of
     * the run's scope columns, and the ids of a table read without a scope only when the call (or harness step, or the
     * preparation) itself inserted into that table ({@see buildDeclaredMintedIdsByTable()}).
     *
     * @param int[] $highWaterMarksByTable from {@see readIdHighWaterMarks()}
     * @param DatabaseWriteStatementAudit $statementAuditOfTheCall the statements the same call issued
     */
    protected function declareIdsMintedSince(array $highWaterMarksByTable, DatabaseWriteStatementAudit $statementAuditOfTheCall): void
    {
        $this->idPlaceholders->declareMintedIds($this->buildDeclaredMintedIdsByTable(
            $this->findIdsAboveHighWaterMarks($highWaterMarksByTable),
            $this->findTablesInsertedInto($statementAuditOfTheCall->buildComparableStatements())
        ));
    }

    /**
     * The ids created since the marks were read, each table read through the first of the run's scope columns it has
     * ({@see buildScopeValuesByColumn()}); a table with none of them is read without a scope.
     *
     * @param int[] $highWaterMarksByTable from {@see readIdHighWaterMarks()}
     */
    protected function findIdsAboveHighWaterMarks(array $highWaterMarksByTable): DatabaseIdsAboveHighWaterMarks
    {
        return DatabaseWriteStatementAudit::findIdsAboveHighWaterMarks(
            EntityManagerFactory::getInstance()->getConnection(),
            $highWaterMarksByTable,
            DatabaseRowScopeColumns::fromValuesByColumn($this->buildScopeValuesByColumn(), ['rootConversationId'])
        );
    }

    /**
     * The ids that read as placeholders: every table read through a scope column keeps its ids; a table read without a
     * scope holds every id above its mark, whoever wrote it on a shared database, so it keeps its ids only when it is
     * one of `$tablesInsertedIntoByTheCall`.
     *
     * @param string[] $tablesInsertedIntoByTheCall from {@see findTablesInsertedInto()}
     * @return array<string, int[]> table → its declared ids
     */
    protected function buildDeclaredMintedIdsByTable(DatabaseIdsAboveHighWaterMarks $databaseIdsAboveHighWaterMarks, array $tablesInsertedIntoByTheCall): array
    {
        $declaredMintedIdsByTable = [];
        foreach ($databaseIdsAboveHighWaterMarks->idsByTable as $table => $mintedIds) {
            $isReadThroughScope = isset($databaseIdsAboveHighWaterMarks->scopeColumnByTable[$table]);
            if ($isReadThroughScope || in_array((string)$table, $tablesInsertedIntoByTheCall, true)) {
                $declaredMintedIdsByTable[(string)$table] = $mintedIds;
            }
        }
        return $declaredMintedIdsByTable;
    }

    /**
     * The tables the captured statements insert into (INSERT, and REPLACE, which inserts a row too), each once, without
     * a schema qualifier. A statement whose table the audit could not parse names no table.
     *
     * @return string[]
     */
    protected function findTablesInsertedInto(DatabaseWriteStatementShapes $databaseWriteStatementShapes): array
    {
        $insertedTables = [];
        foreach ($databaseWriteStatementShapes->getElements() as $databaseWriteStatementShape) {
            $isInsert = in_array($databaseWriteStatementShape->kind, [DatabaseWriteStatement::KIND_INSERT, DatabaseWriteStatement::KIND_REPLACE], true);
            if (!$isInsert || $databaseWriteStatementShape->table === null) {
                continue;
            }
            $tableName = substr((string)strrchr('.' . $databaseWriteStatementShape->table, '.'), 1);
            if (!in_array($tableName, $insertedTables, true)) {
                $insertedTables[] = $tableName;
            }
        }
        return $insertedTables;
    }

    /**
     * A new statement audit, started: it keeps the write statements of one harness step or of the preparation of the
     * run's agent space ({@see declareIdsMintedSince()} reads the tables they insert into).
     */
    protected function startStatementAudit(): DatabaseWriteStatementAudit
    {
        $databaseWriteStatementAudit = new DatabaseWriteStatementAudit();
        $databaseWriteStatementAudit->start();
        return $databaseWriteStatementAudit;
    }

    /**
     * Keys whose value is a token the application derives from a harness id or mints at random in every run (a lock
     * token it puts into a queued message), declared to the placeholders with the script's `opaqueTokenKeys` for every
     * script: each distinct value reads `<key#n>`. The default: none.
     *
     * @return string[]
     */
    protected function findApplicationOpaqueTokenKeys(): array
    {
        return [];
    }

    /** The Argus calls of the run so far: recorded entries, or played requests on replay. */
    protected function countArgusCalls(): int
    {
        return $this->isReplay() ? $this->transportRecording->playedRequests->count() : $this->transportRecording->entries->count();
    }
}
