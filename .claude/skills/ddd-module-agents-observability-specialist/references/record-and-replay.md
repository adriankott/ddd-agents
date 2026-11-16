# Record and replay

**Reader:** host developer and package maintainer

Contents: what it proves · the three recording points · what a recording holds · the package's session and service · the chat's options · a harness of the host · reading a comparison · the tool-call comparison · limits · for the package maintainer · full class names

Record a run of the agent on one build, replay it on the next, and compare: the rows written, the messages
dispatched and the requests sent to the model must be the same after normalization. It is the identity proof of a
refactor of the loop. The package ships the recording points, the per-run session that installs them
(`AgentRecordedRunSession`, created by `AgentRecordedRunsService::startSession()`), the comparison and the chat's
`--record-run-for-comparison` / `--replay-run-and-compare` (options, refusals and exit codes:
[console-commands](console-commands.md#appai-agentchat--agentchatcommand)); a host adds its scope columns and, for
another harness (an evaluation run), drives the same session from its own command. The same proof for ONE MCP package,
without the loop, is the tool-call comparison ([below](#the-tool-call-comparison)).

## What it proves

Equal means: the same conversation tree (root, sub-agents, their messages, the delegation batches, the compaction
nodes), the same Messenger messages, and every request the loop sent to Argus (model calls, decision calls) the same
body as the recorded call that answered it. A replay never calls a model, never runs a tool, never reads the wall
clock or the random generator at the switched sites. A difference is a change of behaviour — or a non-determinism
the normalization does not cover yet.

## The three recording points

| Recording point | Where | Record | Replay |
|---|---|---|---|
| Argus calls | `DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperations::$transportRecording`, holding a `DDD\Domain\Base\Repo\Argus\Utils\ArgusTransportRecording` (ddd-argus) | `ArgusTransportRecording::forRecording()`: every Argus call goes to the network and is kept (endpoint, body, response, its kind, its turn, whether it ran inside a tool) | `ArgusTransportRecording::forPlaying($entries)`: every call is answered from the recording — same turn and kind, the identical body first, else the oldest unused one; a call with no answer left fails |
| Tool results | `DDD\Presentation\Mcp\Services\MCPToolExecutorService::$toolResultRecording`, holding a `DDD\Domain\AI\Entities\MCPPackages\ToolResultRecording` | `ToolResultRecording::forRecording()`: each tool result with its arguments and the AI costs the tool booked | `ToolResultRecording::forPlaying($entries)`: the tool does not run; the same tool with the same arguments first, else the oldest unused result of that tool; its recorded costs are booked again |
| Clock and ids | `DDD\Domain\AI\Libs\RecordedRuns\RecordedRunClockAndIdSources` (a static library, not a service), with the sources in `DDD\Domain\AI\Entities\RecordedRuns` | `installPinnedSources($nowSource, $idSource)` with `RecordedRunNowSource::pinnedAt()` and `RecordedRunIdSource::seeded()`; it returns the sources installed before, which `restoreInstalledSources()` puts back in a `finally` | the same moment and seed, so the n-th id and every "now" read through it are the same bytes |

While a tool runs, the executor marks the Argus recording `insideToolExecution`: a recorded in-tool call is not
expected from a replay that plays the tool's result. `ArgusTransportRecording::forPlayingWithLiveTools()` answers
every call, in-tool calls from the in-tool recordings, for a comparison whose tools run live;
`forPlayingToolCalls()` answers only in-tool calls. Which sites read "now" or mint ids through
`RecordedRunClockAndIdSources` is the host's choice (the package's delegation ids do).

**Record in one console process.** A job a worker consumes resets the recorders before it runs:
`ArgusApiOperations::resetForNewJob()` drops `$transportRecording`, `AgentWorkerStateResetter` drops
`MCPToolExecutorService::$toolResultRecording` and the two harness switches below. A recording made in a worker
process therefore loses its recorders when the next job starts. The pinned clock and id sources are not reset there;
the caller of `installPinnedSources()` puts them back with `restoreInstalledSources()`, on return and on a throw. The
session keeps every dispatched message in the framework's `RecordedRunMessageQueue` and runs it in the console process
(`runQueuedMessages()`); after the worker-state reset of each such job the queue asks the session to set its switches
again. A host registers the framework's `MessageDispatchCaptureMiddleware` in its bus and ends its worker-state reset
with `RecordedRunMessageQueue::resetDispatchCaptureForNewJob()`.

Two switches belong to the harness: `AgentDecisionModelCircuitBreakerService::$harnessMode` (true: the decision
model's circuit breaker never opens and never reads or writes its shared key, so a breaker opened by another process
does not change the replay) and `AgentToolCallForkJoinService::$forkingDisabled` (true: no tool call runs in a child
process, whose Argus calls would bypass the recording and reach the network). Set both for a record and a replay.

## What a recording holds

`AgentRecordedRunRecording` is the stored document (version `RECORDING_VERSION`, 3). Its top-level keys, in order:
`version`, `toolsRunLive`, `recordedConversationId`, `scopeIds`, `idSeed`, the harness's own fields
(`AgentRecordedRunFields`: `harness`, the settings a replay must match, `async`, `turns`, the wire settings …), then
`idNormalization`, `createdIdReadReport` (`AgentRecordedRunCreatedIdRead`), `calls`
(`ArgusTransportRecording::toStorageArray()`), `toolResults` (`ToolResultRecording::toStoredEntries()`) and `snapshot`
(`AgentRecordedRunSnapshot`: the tree rows and the dispatched messages, normalized). A harness may append its fields
after the snapshot (`withAppendedRunFields()`). Scope names and field names are the host's strings; the package never
renames a stored key, so a recording made on one build replays on the next.

`AgentRecordedRunRecording::fromJson()` refuses another version and a document without `calls`, `snapshot` or
`idNormalization`; `findReplayRefusal($harness, $settingsPassedByTheCaller)` refuses the recording of another harness
and a passed setting that differs from the recorded one (a setting passed as null is not checked).

## The package's session and service

| Piece | What it does |
|---|---|
| `AgentRecordedRunsService::startSession($recording, $toolsRunLive)` | a new session, not started: a replay of `$recording`, or a recording (`null`) |
| `findRecording($path)` / `writeRecording($path, $recording)` | the recording file, read and checked / written pretty-printed |
| `getScopeColumnsByName()` | `AgentRecordedRunScopeColumns`: per scope name the columns a table carries for it, in priority order. Package default: `agentSpace` → `agentSpaceId`, `account` → `accountId`; a host lists the columns of its own tables |
| `buildPlayedAnswerIdTranslation($recordedCreatedIdsByTable)` | the translation of played answers of a live-tools replay; a host returns its subclass of `AgentRecordedRunPlayedAnswerIdTranslation` that declares the id keys of its tools (`TABLE_BY_ID_KEY`) |
| `newSession()` | the session class a host derives |
| `AgentRecordedRunSession` | the per-run object (`#[Exclude]`, never a container service): `setScope(AgentRecordedRunScope)` / `setScopeIds()`, `setRootConversation()`, `start()`, `beginTurn()`, `runQueuedMessages()`, `stop()` in a `finally`, then `buildRecording($root, $scopeIds, $runFields)` or `compareWithRecording($root, $scopeIds)` (or `concludeRun()`); it is the queue's host and the translator of played answers |
| `AgentRecordedRunScope` | the run's scope ids, root conversation and the scope names it shares with other runs; a table read through a shared scope's column is listed by `describeSharedScopeTables()` |

The comparison itself is `AgentRecordedRunComparison::buildComparison()` with seven arguments:

| # | Argument | What |
|---|---|---|
| 1 | `$expectedSnapshot` | the recording's snapshot |
| 2 | `$actualSnapshot` | the same for the replay |
| 3 | `$expectedRequests` | per recorded position that a replay asks for (in-tool calls left out when tool results are played), `AgentRecordedRunArgusRequest::forEndpointAndRequestBody($entry['endpoint'], $entry['requestBody'])` |
| 4 | `$actualRequests` | `AgentRecordedRunComparison::buildPlayedArgusRequests($argusPlayer->playedRequests)` |
| 5 | `$expectedIdNormalization` | `AgentRecordedRunIdNormalization::fromArray()` of the stored state, plus the replay's scope ids |
| 6 | `$actualIdNormalization` | the replay's own normalization |
| 7 | `$unusedRecordedCalls` | `$argusPlayer->unusedEntries()` as it is (int positions), plus each `ToolResultRecording::unusedEntries()` entry under a string key (`'tool result #3'`), so neither list overwrites the other |

Afterwards `isIdentical()`, `$differences` (at most `MAX_REPORTED_DIFFERENCES`) and `$notes`; the session adds the
created-id read reports of both runs and, for a live-tools replay, the played-answer translations.

## The chat's options

`app:ai-agent:chat --turns-file=<file> --record-run-for-comparison=<run.json>` drives the lines on a new conversation
and writes the recording (`harness` = `app:ai-agent:chat`, the settings of `buildRecordedRunSettingsPassedByCaller()`,
`async`, `turns`, the wire settings of `buildWireRecordingKeys()`); `--replay-run-and-compare=<run.json>` drives the
recorded turns and prints `recorded-run comparison IDENTICAL: …` (exit 0) or `… DIFFERS …` with one line per
difference (exit 1); a refused replay exits with `Command::INVALID`. A host adapts the chat through three hooks:
`buildRecordedRunSettingsPassedByCaller()` (the option that names its scope), `buildRecordedRunScope()` and
`buildRecordedRunConcludingScopeIds()` (its scope names).

## A harness of the host

Another harness (an evaluation run on a fresh scope) drives the same session:

```php
/** @var AgentRecordedRunsService $agentRecordedRunsService */
$agentRecordedRunsService = DDDService::instance()->getService(AgentRecordedRunsService::class);
$agentRecordedRunSession = $agentRecordedRunsService->startSession(null, toolsRunLive: true);
$agentRecordedRunSession->readHighWaterMarks();               // before the run creates its own scope
$agentRecordedRunSession->setScopeIds(['agentSpace' => $agentSpaceId, 'account' => $accountId]);
$agentRecordedRunSession->start();
try {
    // per owner turn: beginTurn($turnOrdinal), drive the turn, runQueuedMessages()
} finally {
    $agentRecordedRunSession->stop(runRemainingMessages: false);
}
$agentRecordedRunRecording = $agentRecordedRunSession->buildRecording($rootConversation, ['agentSpace' => $agentSpaceId], AgentRecordedRunFields::fromValuesByField(['harness' => 'my-harness']));
$agentRecordedRunsService->writeRecording($recordingPath, $agentRecordedRunRecording);
```

## Reading a comparison

| Difference reads | Means |
|---|---|
| `messages: 12 recorded, 13 replayed` | the replay wrote a row more or less |
| `messages #4 <conversation#1>@4 message: recorded «…» replayed «…»` | one column of one row differs; each excerpt starts 60 bytes before the first different byte and shows 160 bytes |
| `call #3 (recorded #3) POST:…: …` | a request body to Argus changed — the model saw something else |
| `recorded call #7 … was never asked for` | the replay made fewer calls (or a tool result was not used) |
| `no recorded call answered it` | the replay made a call the recording does not have |
| a note `… read unscoped …` | the created ids of a table were read without a scope column; rows of other runs may count |

Ids above the high-water mark become ordinals of their creation (`<message#3>`), declared scope ids become
`<agentSpace>`-style names, timestamps, UUIDs, long hex tokens and sub-agent durations become placeholders, the
timing keys (`AgentRecordedRunIdNormalization::TIMING_KEYS`) and the screening verdict keys written later by a worker
(`ASYNC_VERDICT_KEYS`) are dropped, and the volatile columns (`AgentRecordedRunComparison::VOLATILE_ROW_COLUMNS`) are
never compared.

A row's `agentSpaceId` is a declared id key of the family `agentSpace`: a space the harness names as a scope reads as
`<agentSpace>`, a space the run created without naming it as `<agentSpace#1>`, a space that existed before the run
stays raw (a different existing space is a difference).

## The tool-call comparison

`app:mcp:compare-tool-calls` (options, the script format, the files and the exit codes:
[console-commands](console-commands.md#appmcpcompare-tool-calls--mcpcomparetoolcallscommand)) runs the scripted tool
calls of one MCP package in the console process through the tool executor — the real gates, hydration and rendering,
no model — once on the unchanged build (record) and once on the build under test (replay), and compares per case what
the model receives and every effect of the call. On the replay the tools run live; only their Argus calls are answered
from the recording (the in-tool player, never a played tool result).

| Piece | What it does |
|---|---|
| `ToolCallComparisonsService::buildSession($script, $packageClass, $additionalPackageClasses, $recording)` | the session of one run, not started: a record run (`$recording` null) or a replay; a replay refuses a script whose text differs from the recorded one (its hash) |
| `ToolCallComparisonsService::newSession()` | the session object; the application's class at the session's mirrored path when it has one |
| `findPackageClassesByShortName()`, `findBundleClasses()`, `resolvePackageClass()` | the packages and bundles below `Presentation/Mcp` of the source roots, the application's root first, so its class wins over a module class of the same short name |
| `ToolCallComparisonSession` | the per-run object (`#[Exclude]`, never a container service): `start($bundleClasses)`, `runFixtureSteps()`, `runCase()` per case, `buildCaseDifferences()` on a replay, `stop()` in a `finally` |
| `ToolCallComparisonRecording` | the stored document (version `RECORDING_VERSION`, 3): `harness`, `version`, `scriptName`, `scriptHash`, `packageClass`, `comparisonAgentSpaceId`, `harnessIdsByPlaceholder`, `pinnedMoment`, `idSeed`, `recordedConversationIds`, `definitionsDumpFileName`, `caseOutcomes`, `argusCalls`, `hostRecordedCalls`; `fromJson()` refuses another harness and another version |

**What makes two runs byte-comparable.** The moment is pinned 2 s before the run starts
(`PINNED_MOMENT_LEAD_SECONDS`, so a stamp the run could not pin never equals it) and the id source is re-seeded per
call; both are stored in the recording. Every call — case, fixture or `before` call — starts in the process state of a
fresh worker job (entity registries and Doctrine caches emptied, the connection renewed), so it reads the database,
never what an earlier call of the run left in the process. No Argus or application cache answers, the decision model's
breaker runs in harness mode, and every Messenger dispatch stays in the process: a case's dispatches are compared, the
harness's dropped. The harness conversations and the run's agent space are created or prepared anew in each run and
read as placeholders (`<comparison:agentSpace>`, `getComparisonAgentSpacePlaceholder()`); on a replay they are paired
with the recorded ids, so a played Argus answer comes back in the replay's ids. The wall clock the run could not pin is
normalized by one rule for statements, dispatches and the JSON of tool results: a moment under a time-named key or
column written between the start of the run and the end of the call reads `<clock>`, the script's derived-moment keys
and columns `<derived-clock>`, its duration keys `<duration>`, the time after a `wallClockTextPrefixes` text `<clock>`.

**Which ids become placeholders.** After each call the session reads, per table, the ids above the high-water marks
taken before the call. A table is read through the first scope column it carries: the columns of the harness
conversations (`aiConversationId`, `parentConversationId`, `rootConversationId`), then the columns
`AgentRecordedRunsService::getScopeColumnsByName()` lists for each scope of the run (the run's agent space, then its
account). The ids of a table read through a scope column become placeholders (`<table#n>`). A table without a scope
column holds every id above its mark, whoever wrote it on a shared database: its ids become placeholders only when the
captured statements of the same call inserted into that table (an INSERT or a REPLACE); otherwise they stay raw. A row a
call creates and deletes again in one call is gone when the ids are read: the application names such id columns in
`buildDeletedRowIdFamilies()` and their marks in `buildHighWaterMarksOfDeletedRowFamilies()`, and the statements
normalize them against the case's mark.

**Opaque tokens.** A token derived from a harness id or minted at random in every run (a lock token in a queued message)
is compared by which values are equal, never by its bytes: each distinct value reads `<key#n>`. The keys are the
script's `opaqueTokenKeys` plus `findApplicationOpaqueTokenKeys()` of the session, declared for every script.

**Override points of the session** (the application's class at the session's mirrored path):

| Method | Decides |
|---|---|
| `prepareComparisonAgentSpace()` / `releaseComparisonAgentSpace()` | where the run works: by default the space the script names, read as the run's account (none: account level); an application that creates a fresh space per run does it here (before any hook is installed, so its own Argus calls are real in both runs), leases the account with `acquireSeedAccountLease()` and gives it back in the release (`releaseSeedAccountLease()`); progress lines go through `reportProgress()` and print with the command's `getProgressLinePrefix()` |
| `refuseAnAccountOtherThanTheComparisonAccount()` | refuses an authenticated account that may not run a comparison, before anything is prepared (default: every account may) |
| `refuseUnsafeScriptReferences()` | refuses a script that names something a call must never reach (an id of a real record), after the space is prepared (default: nothing) |
| `assignComparisonAgentSpace()`, `getComparisonAgentSpacePlaceholder()` | how a harness conversation is put into the run's space; the placeholder of the space |
| `buildScopeIdsByName()` | the scopes of the run in priority order (default: the agent space, then the account); an application adds its own |
| `buildHostRecording()` | the application's own recorder of calls its tools make to its own back ends (default: none): the session builds and installs it, on a replay as a player that answers from `ToolCallComparisonRecording::getStoredHostRecordedCallsJson()`. The returned object implements `ToolCallComparisonHostRecordingInterface`, through which the session labels its calls with the call that runs (`labelCalls()`), copies the kept calls into the recording at the end of a record run (`buildStoredCallsJson()`), compares the requests of one case (`buildStoredRequestsOfCallJson()`), lists the recorded calls a replay never asked for outside harness calls (`findUnusedCallLines()`) and removes it in a `finally` (`uninstall()`). The calls travel as the JSON text of their stored document |
| `buildDeletedRowIdFamilies()`, `buildHighWaterMarksOfDeletedRowFamilies()` | ids of rows created and deleted within one call (above) |
| `findApplicationOpaqueTokenKeys()` | the application's opaque token keys (above) |

**The script** (`ToolCallComparisonScript`) reads the application's own keys in `readHostScriptFields()` and adds its
rules in `findHostValidationErrors()` (the whole script) and `findHostToolCallErrors()` (one call).

**The stored keys.** `ToolCallComparisonRecording`, `ToolCallComparisonCaseOutcome` (one case: the tool result as the
model receives it, `isError`, the refusal kind or the execution error, the appended rows, the write statements, the
dispatches, the application's recorded requests, the Argus requests and their count) and
`ToolCallComparisonDefinitionsDump` read and write their stored document through `getStoredKeyByField()`
(field → key; the default stores every field under its own name). An application whose recordings carry other key
names maps them there, so a recording it made before stays readable and is written byte-identically.

## Limits

- The tree is the root and the conversations whose `rootConversationId` is the root; a supervisor conversation lies
  outside it — record with the supervisor off.
- Image parts of a tool result are not recorded.
- Live third-party data: a recording carries what the tools answered on that day; store it where the host keeps
  test data, not in the repository when it holds customer data.
- Runtime code must not change while a recording or replay runs: one run, one build.

The reference kernel's `ReferenceScriptedModelTransport` is a hand-written player of the same Argus recording point: it extends
`ArgusTransportRecording` and picks an answer by what the request says instead of its position — a way to run a host
without a model provider, not a comparison.

## For the package maintainer

- A new column that differs between two runs of one build (a stamp, a token, a vector) goes into
  `VOLATILE_ROW_COLUMNS`; a new id-bearing column or JSON key into `AgentRecordedRunIdNormalization::TABLE_FAMILY_BY_ID_KEY`;
  a new timing key into `TIMING_KEYS`. Otherwise every recording reports it.
- A new key on the stored tool call that old recordings lack is compared only when the recording holds it
  (`TOOL_CALL_KEYS_ADDED_AFTER_RECORDINGS`).
- A new site that reads the clock or mints an id on a path a replay compares reads through
  `RecordedRunClockAndIdSources::readNow()`, `mintHexToken()` or `mintUuid()`.

## Full class names

The classes this reference names, with their namespace (for the `use` lines of a host's code):
`DDD\Domain\AI\Services\Decisions\AgentDecisionModelCircuitBreakerService` ·
`DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunArgusRequest` ·
`DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunComparison` ·
`DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunCreatedIdReadReport` ·
`DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunDispatchedMessage` ·
`DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunIdNormalization` ·
`DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunPlayedAnswerIdTranslation` ·
`DDD\Domain\AI\Services\Conversations\AgentToolCallForkJoinService` ·
`DDD\Domain\AI\Messenger\Conversations\AgentWorkerStateResetter` ·
`DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperations` · `DDD\Domain\Base\Repo\Argus\Utils\ArgusTransportRecording` ·
`DDD\Domain\AI\Repo\DB\Conversations\Compaction\DBAIConversationCompactionNodeModel` ·
`DDD\Domain\AI\Repo\DB\Conversations\Agent\DBAIConversationDelegationBatchModel` ·
`DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel` ·
`DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel` ·
`DDD\Domain\AI\Repo\DB\Conversations\Schedules\DBAIConversationScheduleModel` ·
`DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory` · `DDD\Presentation\Mcp\Services\MCPToolExecutorService` ·
`DDD\Domain\AI\Libs\RecordedRuns\RecordedRunClockAndIdSources` ·
`DDD\Domain\AI\Entities\RecordedRuns\RecordedRunIdSource` · `DDD\Domain\AI\Entities\RecordedRuns\RecordedRunNowSource`
· `DDD\Domain\AI\Entities\MCPPackages\ToolResultRecording` ·
`DDD\Domain\AI\Services\Eval\AgentRecordedRunsService` · `DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunSession` ·
`DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunRecording` · `DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunFields` ·
`DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunScope` · `DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunScopeColumns` ·
`DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunSnapshot` · `DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunCreatedIdRead` ·
`DDD\Domain\Base\Entities\MessageHandlers\RecordedRunMessageQueue` ·
`DDD\Symfony\Messenger\Middleware\MessageDispatchCaptureMiddleware` ·
`DDD\Symfony\Commands\Agents\MCPCompareToolCallsCommand` · `DDD\Domain\AI\Services\Eval\ToolCallComparisonsService` ·
`DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons\{ToolCallComparisonSession, ToolCallComparisonScript, ToolCallComparisonRecording, ToolCallComparisonCaseOutcome, ToolCallComparisonDefinitionsDump, ToolCallComparisonHostRecordingInterface}`
