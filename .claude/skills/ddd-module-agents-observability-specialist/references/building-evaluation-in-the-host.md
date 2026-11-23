# Evaluation: what ships and what the application adds

**Reader:** host developer

Contents: what ships, what does not · the parts of a runner · driving one case · reading the outcome · the judge · the judged run · the suite · the evaluation commands · seed accounts, clean start and the purge · throwaway spaces and cleanup · checklist · full class names

The package ships the safety layers, the recorded runs, the read models an evaluation needs, the run of ONE case
(`AgentEvalRunnerService` with the application's `AgentEvalRunHooksService`) and the judged run of one case
(`AgentEvalJudgedRunService`: a simulated owner and a judge), the parts a suite is built from: its objects, its
stored runs, its result files and the run report ([the suite](#the-suite)), the evaluation and decision commands on
them ([the evaluation commands](#the-evaluation-commands)), and the seed-account lease, the clean start and the purge
of old evaluation conversations ([seed accounts](#seed-accounts-clean-start-and-the-purge)). The fixtures are the host's: without them the commands find
no case. This reference names what each part calls in the package; every command's options, defaults, exit codes and
override points are in [console-commands](console-commands.md#evaluation), the fixture format in
[evaluation-fixtures](evaluation-fixtures.md).

## What ships, what does not

| Ships | Does not ship |
|---|---|
| the eval marker and the eval switches on `RunSettings`; the fixture loading (`AgentEvalSuitePlanService::findFixtureAt()`, `findFixturesInFolder()`; the case object `AgentEvalCase` reads a decoded case) | |
| the eval tool block and the outbound recipient guard ([eval-safety](eval-safety.md)) | the fixtures (case files, suites) and the configuration that names them (`AI.evalSuite.fixturePaths`, `AI.evalSuite.suites`) |
| the evaluation commands with child processes, retries and stop handling, the decision commands and the abstract decision replay ([the evaluation commands](#the-evaluation-commands)) | the host's subclasses where it names its own scope, case shape or texts; its decision replay commands |
| the seed-account lease, the clean start of a seed account and the evaluation purge ([seed accounts](#seed-accounts-clean-start-and-the-purge)) | the host's seed pool keys, its own account state and the purge steps of its own tables |
| `AgentEvalResult` (a verdict value object) and the assertions over a run (`AgentEvalExpectation`: expected tool, skill, status, arguments, plan, state after the run) | |
| the run of one case: `AgentEvalRunnerService::runCase()` / `run()` / `runWithRestore()` — the tool block applied and lifted, follow-up turns, purpose, reload, restore (`AgentEvalRestorePlan`, `AgentEvalHarnessToolCalls`), the state check, the teardown, applicability (`AgentEvalApplicabilityFacts`), the eviction trajectory, the recall seed, the figures (`AgentEvalRunFigures`) | the application's part of a run: the hooks of `AgentEvalRunHooksService` (scope, ineligible scope, withheld skills, harness arguments, record references by name (`resolveRecordReference()`), the agent space a fixture names by reference (`resolveAgentSpaceReference()`), the residue teardown of every stored conversation — account-level ones included (`tearDownHostResidueOfConversation()`), …), overridden in a same-path subclass |
| `AIConversationAuditRenderService` (the deterministic audit blocks a judge reads); the judged run with its driver and verdict prompts and its model calls ([the judged run](#the-judged-run)) | the application's override points of the judged run |
| request snapshots, turn events, metrics, the integrity report | |
| record and replay with the comparison ([record-and-replay](record-and-replay.md)) | |
| the suite's objects, its planning service, its stored runs, its result files and the run report ([the suite](#the-suite)) | the application's task kinds and their planning, the application's own dated folders in the results folder |
| `AgentEvalOutboundGuardHooksService`, `AgentEvalAccountsService` as override points | test accounts, test spaces and their cleanup |

## The parts of a runner

1. **Cases.** One file per area: the owner's message (or several turns), the expected skill and tool, the forbidden
   tools, the blocked tools and their simulated errors, a read-only switch, set-up and restore steps.
2. **A scope per case.** A test agent space whose data the case may change; a throwaway space for a case that writes
   (`AgentEvalOutboundGuardHooksService::isThrowawayScope()` must answer true for it).
3. **A driver** that starts a marked conversation and runs the owner turns (below).
4. **Assertions** over the stored rows; the outcome as `AgentEvalResult`.
5. **A judge** for what assertions cannot see (optional).
6. **A suite** that runs cases in lanes, stores each outcome and sums them up.
7. **Cleanup** of throwaway spaces and their rows.

## Driving one case

| Step | Package call |
|---|---|
| mark the run | a `RunSettings` with `evalRun = true`, the block from `setEvalToolBlock()`, `storeRequestSnapshot = true` to keep every payload, `fixedReasoningEffort` to measure at one effort, `evalStopAfterToolName` for staged cases. A decision-site field set on that `RunSettings` (an arm such as `decisionModelSkillPreload`) is stored with it as given, because `driveConversation()` writes the whole run-settings column of a new conversation — without the value check and the set-by and set-at stamps of `AIConversationsService::applyAdminRunSettingsOverride()`, which runs right after and is the writer for an existing conversation |
| start and run turn 1 | `AIConversationAgentService::driveConversation()` with the run settings and the case's space, scope `AIConversationDriveScope::ADMIN` for a synchronous turn (a `CUSTOMER` drive is always async); or create the conversation with those run settings and call `generateAgentReply()` inside a turn envelope |
| next owner turns | the same, by conversation id; the run settings are stamped only when the conversation is created — run settings passed on a continue are ignored. A simulated owner is a model call of the host between turns |
| the worker path | drive with `async: true` and run the turn transport in this process (`sync://`), or wait for the run state to come back to `IDLE`. **Mandatory for a run with the supervisor on:** an inline (synchronous) owner turn holds no run claim, so when the supervisor resumes the supervised conversation (`AIConversationAgentService::resumeConversationIfReady()` with `async: true`) that resume claims it and runs a second agent run beside the inline one. On the worker path the turn holds the claim and a concurrent resume is dropped. The supervisor's side: `ddd-module-agents-autonomy-specialist` |
| budget | a runner process may lift the budget for itself (`grantBudgetBypassForTheProcess()` with `AIBudgetBypassGrant::REASON_CONSOLE_OPTION`); the spend is still booked |
| seeded history | a conversation the case seeds as the owner's past gets `evalSeededHistory = true` beside `evalRun` |

## Reading the outcome

Load the conversation with every row (`findMessagesInOrder()`), then:

- called tools, their status and arguments: the tool-call rows (`AIConversationMessages::buildToolCallHistory()`);
- loaded skills: `AIConversationMessages::getLoadedSkillNames()` — an empty list on a case that expects a tool is a
  routing failure (the model never loaded the skill that offers it), not an execution failure;
- the final text: the newest assistant text row;
- time and failures: [metrics-and-cost](metrics-and-cost.md);
- what the model saw at a wrong step: the request snapshot of that row ([debugging-a-turn](debugging-a-turn.md)).

Wrap the verdict in `AgentEvalResult($passed, $failures, $matchedTool, $conversation)`; the conversation it carries is
the trace for the report.

## The judge

A judge model reads what the owner saw and what the agent did. `AIConversationAuditRenderService` renders the
deterministic part, so the judge does not have to infer it from prose: `renderWriteLedger()`,
`renderOutwardActions()`, `renderWidgetInventory()`, `renderTaskPlanWithProvenance()`, `renderAgentSpaceState()`. A
host's same-path subclass names removed outward tools of old conversations
(`getRemovedOutwardActionToolNames()`). The judged run below assembles these blocks into its trace. A verdict names
what went wrong, not why: read the conversation and the request snapshot of the step it blames.

## The judged run

`AgentEvalJudgedRunService` (extends `AgentEvalRunnerService`) runs one judged case: a driver model plays the owner,
then a judge model reads the trace and returns an `AgentEvalJudgeVerdict`.

| Call | What it does |
|---|---|
| `runSim(AgentEvalScope, AgentEvalSimCase, AgentEvalJudgedRunOptions): AgentEvalSimRunResult` | assembles the conversation like every evaluated one (arms, eval marker, budget lift), applies the case's settings blocks, tool block and purpose; the opening prompt, then the case's `followUpPrompts` (with `followUpIdleMinutes`), then the driver's messages until `[DONE]`, `[GIVE-UP]`, a closed conversation or `maxTurns` (default 6); a stored run is read back before the judge |
| `runSimWithRestore(…)` | the same inside the restore bracket of the case's `restore` block (open → run → close in a `finally`) |
| `runCompactionTreeEval(AgentEvalScope, AgentEvalSimCase, AgentEvalJudgedRunOptions): AgentEvalCompactionTreeRunResult` | a long conversation with the case's `compactionSettings`; the tree after every turn (a stored run: the whole stored tree), judged on growth, cover and fact survival; progress lines through the options' reporter |
| `callModel(scope, model, prompt, usageScope)` | one plain completion on a throwaway conversation of the scope, inside `ENVELOPE.AI.AGENT.EVALUATION` billed to the scope's owner, booked as `AI.AGENT.SIMULATE.OWNER` (driver) or `AI.AGENT.JUDGE.EVALUATION` (judge); refuses a model the catalog does not know before the envelope opens; a load the provider refused throws `AgentEvalHarnessModelCallFailedException` (`judge call failed: <model>: <reason>` / `driver call failed: …`) instead of answering an empty text |
| `refuseUnknownModelName(model)` / `refuseUnknownModelNamesOfRun(options)` | `AgentEvalUnknownModelNameException` for a name that is neither a key of `AI.models` nor of `AIModel::RETIRED_MODEL_SUCCESSORS` (a retired name passes: it resolves to its successor); `runSim()` and `runCompactionTreeEval()` check the run's judge and driver before the first turn |
| `judgeVerdict(scope, model, AIPrompt)` | an unparseable answer and a failed judge call are asked once more; a second failed call is `ERROR` with the provider's message (`AgentEvalJudgeVerdict::forFailedJudgeCall()`, `isJudgeCallFailed()`), never the unparseable `FAIL`; a verdict cut off by the output limit is judged once more, a second cut-off is `ERROR` |
| `getUserVisibleTranscript()` / `getFullTraceText()` | what the driver reads (`OWNER:` / `ASSISTANT:`, owner actions in widgets) / what the judge reads (system prompt, every row with its stored index — `AgentEvalJudgeTraceMessageLines`, under a `delegate` call the tool calls of each sub-agent with results shortened to 1,500 characters, with a CLOCK line per call: the "now" the call was given as local date, weekday and time in the call's zone, none for a call without a zone — task plan, write ledger, outward actions, widgets the owner saw, `AgentEvalRunCounts`, the application's end state) |
| `getDefaultJudgeModelName()` / `getDefaultDriverModelName()` | the models when the options name none |
| `applyDeterministicGatesToRunResult(result, scope, case)` | after the judge: the case's `expect` gates that hold across owner turns (`AgentEvalExpectation::evaluateConversationForJudgedRun()`: skills, forbidden skills, `tool` with status / arguments / result, `requiredTools`, `forbiddenTools`; not the last turn's widgets, phrases, progress text, task plan, signal sources or call counts) plus `buildHostGateFailures()`. A PASS with a missed gate becomes FAIL with the gates before the judge's reasons; the list is the verdict's `deterministicGateFailures` (`AgentEvalJudgeVerdict::getDeterministicGateFailures()`, `AgentEvalSimRunResult::getDeterministicGateFailures()`); an ERROR stays an ERROR. A judge never overrules a gate |

The judge and the driver run on the model they are named, never on the agent's default: without the refusal an unknown
name would fall back to the agent's default model (`ArgusAIAgentConversation::getAIModel()`) and that model would give
the verdict. A failed harness call is an evaluation error, never a verdict on the agent: the judge's is the `ERROR`
verdict (the case row gets `error: judge evaluation error: judge call failed: …`, counted as an error, not as a failed
case); the driver's ends the case (`runSim()` throws, the evaluation command writes the case's ERROR row with the
provider's message and runs the remaining cases) instead of sending the "please go on" owner turn.

Override points of the application's same-path subclass (each default is neutral):
`applyCaseFixtures()` (its own case keys on the new conversation, read with `AgentEvalSimCase::getApplicationValue()`),
`buildHostEndStateTextForJudge()` (the end-state section of the trace; null = none),
`buildTextOfHostWidgetShownToOwner()` (a widget block as the owner reads it in the driver's transcript),
`applyHostSectionsToVerdictPrompt()` (`HOST_RULES` / `HOST_SECTIONS`), `buildHostGateFailures(conversation, scope, case):
string[]` (the application's own deterministic gates of a judged run, e.g. a document check; one sentence per missed
gate, counted like the case's), `isHostOwnerReplyRow()` (a signal row counted
as an owner reply), and the texts `getEmptyDriverReplyText()`, `getEmptyCompactionTreeDriverReplyText()`,
`getDefaultCompactionTreeRubricText()`, `getTruncatedTwiceVerdictReasons()`.

The prompts are files the application replaces whole by a file of the same path; placeholders, filled in this order
(the conversation's text last, so a placeholder quoted in it is never replaced — a placeholder token inside a value set
earlier is):

| Prompt (`AgentAIPromptNames`) | File | Placeholders |
|---|---|---|
| `APP_AI_AGENT_EVALUATION_DRIVER` | `config/app/AI/Prompts/AI/Agent/Evaluation/Driver.md` | GOAL, FACTS, TRANSCRIPT |
| `APP_AI_AGENT_EVALUATION_VERDICT` | `…/Verdict.md` | GOAL, OPENING, RUBRIC, HOST_RULES, HOST_SECTIONS, TRACE |
| `APP_AI_AGENT_EVALUATION_COMPACTION_TREE_DRIVER` | `…/CompactionTreeDriver.md` | GOAL, FACTS, COMPACTION_PARAMETERS, TRANSCRIPT |
| `APP_AI_AGENT_EVALUATION_COMPACTION_TREE_VERDICT` | `…/CompactionTreeVerdict.md` | COMPACTION_PARAMETERS, GOAL, RUBRIC, TREE_EVOLUTION, FINAL_TREE, TRACE |

An application that keeps its own verdict text keeps its legend in step with the trace's labels (the markers are
the renderer's: `(owner reply k)`, `SIGNAL(SOURCE — owner action …)`, `TOOL_MESSAGE`, `(READ — persists nothing)`,
`SYSTEM NOTE`, `[TASK PLAN —`, `[COUNTS —`).

## The suite

Lanes in parallel processes, one case per process at a time (the recorders, the eval holds and the budget lift are
process state). Cases that write to the same space run in one lane. Keep every outcome (case, model, verdict, loaded
skills, called tools, metrics, cost) so a run can be compared with the last one. Exclude the eval accounts from the
host's own statistics through `AgentEvalAccountsService`.

What the package ships for it:

- **Suite objects** (`Entities/Evals/`): the task (`AgentEvalSuiteTask`, an open `kind`, scoped to an agent space) and
  its set, the schedule (`AgentEvalSuiteSchedule`: which tasks may run at the same time), the plan summary, the case
  rows (`AgentEvalCaseRow(s)`), the run summary (`AgentEvalRunSummary`), the performance roll-up, the baseline choice
  and comparison, and the suite record (`AgentEvalSuiteRecord`). `toArray()` of each gives the document a suite
  writes. `AgentEvalSuitePlanService` plans the module's task kinds; an application plans its own kinds in a
  same-path subclass. `AI.evalSuite.resourceGroupByFixture` and `AI.evalSuite.exclusiveFixtures` say which write
  cases share a resource or run alone.
- **Stored runs** (`AgentEvalSuiteRun(s)`, `AgentEvalSuiteCase(s)` with their repositories and
  `AgentEvalSuiteRunsService` / `AgentEvalSuiteCasesService`): one row per suite run and one per case. An admin reads
  every row, every other account none; an application's same-path repository decides who reads every row in
  `isAuthenticatedAccountReaderOfEveryRow()`.
- **Result files** (`AgentEvalResultFilesService`): the results folder (`AI.evalSuite.resultsDirectory`, default
  `var/agent-evals`), the files of a run, the baseline read and the retention sweep; an application adds the name
  patterns of its own dated folders through `getRetainedFolderNamePatterns()`.
- **Run report** (`AIConversationRunReportService`, the base command `app:ai-agent:run-report`): the per-run figures
  of stored conversations. An application's same-path subclass of the service names the id its usage log files a
  conversation's cost under (`findUsageLogScopeIdOfConversation()`), reads the decision model's cost from its usage
  log (`findModelCostInUsageLog()`) and drops its prefix of skill section ids (`getSkillSectionIdPrefixToDrop()`).
  A failed usage-log read is named below the run table (`<run>: decision cost not read: <exception class>`), and so
  are unreadable evaluation logs.
- **Configuration** (`evalSuite.php`): `suites` (suite name => folder, task kind, `default`, `flavors`; read by
  `AgentEvalSuitePlanService::getSuiteDefinitions()`) and `fixturePaths` (command name => fixture file or folder; read
  by `findFixturePath()`; `findFixtureAt()` / `findFixtureFilesAt()` read one file or every `*.json` of a folder). A
  relative path is taken relative to the kernel project directory. Both are empty in the package.
- **Stored run options** (`AgentEvalStoredRunOptions`): what `AgentEvalSuiteRunsService::recordSuiteRun()` and
  `AgentEvalSuiteRun::forFinishedRun()` store as the run's options; `AgentEvalSuiteRun::getRunOptions()` reads them
  back (`toArray()` is the stored document).

## The evaluation commands

All on the base `AgentEvalConsoleCommand` (`src/Symfony/Commands/Agents/`), live unless said otherwise; their options,
defaults and exit codes: [console-commands](console-commands.md#evaluation). The tool-call comparison of one MCP
package (`app:mcp:compare-tool-calls`, on the base `AgentConsoleCommand`) is in
[record-and-replay](record-and-replay.md#the-tool-call-comparison).

| Command | What it does | Fixture key | Override points |
|---|---|---|---|
| `app:ai-agent:eval-tools` | runs the tools cases (expectation, performance budget, state after the run), `--judge` in the judged flavor, `--repeat`; in this process or in child processes on the suite schedule | `tools` | `runCaseOfOwnShape()`, `buildPlanOptionsOfRun()`, the `*_LABEL` / `*_SKIP_REASON` constants |
| `app:ai-agent:eval-suite` | every configured suite on every model and flavor, one child process per case, reads in a pool and writes in lanes per space and resource; pass and performance verdict against a baseline | `AI.evalSuite.suites` | `getDefaultModelName()`, `getRetriedInfrastructureErrors()`, `getChildCommandNameOfKind()`, `buildApplicationArgumentsOfTask()`, `buildPlanOptions()`, `buildFixturesOfSuite()`, `configureApplicationOptions()`, `isIsolateWholeScopeRequested()`, `getDecisionLayerLabel()`, `describeStopRequest()` |
| `app:ai-agent:eval-selection` | whether the expected skill was loaded; accuracy; `--json`, `--result-file` for a suite parent | `selection` (one file) | `getDefaultFixturePath()`, `findFallbackScopeValueOfRow()`, `describeMissingScope()` |
| `app:ai-agent:eval-recall` | seeds past conversations, asks on a fresh one, checks the recall tool and the mentioned facts; deletes the seed unless `--keep-seed` | `recall` | `getDefaultFixturePaths()` |
| `app:ai-agent:eval-tiers` | the tools cases per model tier; pass rate and efficiency per tier | `tools` | `getDefaultFixturePaths()` |
| `app:ai-agent:eval-analyze` | offline: classifies the failed cases of a stored run (a run ended on its step budget is `STALL`) and prints reproduce lines | — | `buildReproduceArguments()` |
| `app:ai-agent:eval-eviction` | multi-skill trajectories on one conversation; the load curve and whether eviction fired | `eviction` (one file) | `trajectoryNamesItsScope()`, `getScopeWordOfSkipLine()` |
| `app:ai-agent:eval-guard` | the injection guard probe: variants × inputs × `--runs`, false-block and bypass rate; `--parallel` children, each ended at its 1,200 s ceiling; each probe under its own evaluation envelope | `guard` | the guard class by same-path subclass (`ArgusAgentSentinelCanaryGuard::newInstance()`) |
| `app:ai-agent:eval-schedule-runs` | fires one evaluation schedule `--runs` times and compares the structure of its reports; needs the workers | — | `configureReportOptions()`, `validateReportOptions()`, `runInsteadOfFiring()`, `afterRunEnded()`, `describeScope()`, `describeEvalAccountsSource()`, `describeRequiredOptions()` |
| `app:ai-agent:eval-compaction-tree` | drives a long judged run with frequent compaction and judges the compaction tree after every turn and at the end; `--json`, `--persist` (stores the conversation and validates the stored build) | the case file (`--case-file`); the space comes from the case file first, the scope options are the fallback | `getDefaultCaseFile()`, `getDefaultCompactionParamsTemplate()`, `buildCompactionTreeParameters()`, `resolveTreeEvalScope()`, `buildJudgedRunOptions()`, the headings |

The decision commands sit on the base `AgentConsoleCommand` (not on `AgentEvalConsoleCommand`):

| Command | What it does | Override points |
|---|---|---|
| `app:ai-agent:decision-fixtures <fixture>` | sends the cases of a JSON fixture (sub-skill preload or reply language) to the decision model, `--repeat` times, and scores the picks against the expectations; each call under an evaluation envelope of its own, billed to the fixture's space | `getFixtureScopeKey()`, `getAgentSpaceFactsKey()`, `resolveBillingScope()`, `getDefaultSupportedLanguageCodes()`, `getDefaultReplyLanguageCode()` |
| `app:ai-agent:decision-stats` | read-only statistics over the turn events of one decision site (`--site`, `--from`, `--to`, `--mode`): per mode, arm and day, shadow agreement; eval traffic excluded unless `--includeEval` | `buildStatisticsRequest()`, `renderSection()` |
| `AgentDecisionReplayCommand` (abstract) | the host's replay of stored tool calls against one decision: per conversation of `--conversations` it reads the messages with rights lifted, then replays each picked call as the conversation's account under an evaluation envelope; `--resultFile` writes the rows as JSON | the host names the command (`#[AsCommand]`) and implements `isToolCallReplayed()` and `replayToolCall()`; `configureReplayOptions()`, `executeReplayWork()`, `getRowTableHeaders()` |

What every command shares (the base):

- **Scope:** `--agentSpaceId` and the options the agent-spaces service declares in the application's words. A case
  names its own space; the options name the space of the cases that name none
  (`resolveFallbackAgentSpaceId()`); an option that resolves to no stored space is refused, and the two options
  together are refused. A parent hands the options to its children as given (`buildScopeArgumentsForChild()`).
- **Budget:** `--ignore-budget` lifts the AI budget cap for the process (the spend is still booked) and is handed to
  every child (`buildBudgetArgumentsForChild()`).
- **Output:** progress lines go to the console's error output, indented by two spaces, so `-q` silences them and a
  `--json` document on the standard output stays pure. Result files go into the results folder
  (`AI.evalSuite.resultsDirectory`); a missing folder is created.
- **Children:** a child runs the console with the parent's own command name (`getChildCommandName()`), so a host's subclass that
  repeats the `#[AsCommand]` name runs in its children too.
- **Stop:** a command that stops gracefully (`handlesStopSignals()`; `eval-suite`) counts SIGINT / SIGTERM: the first
  asks for a stop (as does a `STOP` file in the run folder, through `requestStop()`), the second ends everything still
  running (`isStopRequested()`, `isKillRequested()`, override point `onStopSignal()`).
- **Application hooks:** a guard probe is billed to `AgentEvalRunHooksService::findBillingEntityOfScope()` (default:
  the space's billing entity); a schedule the command creates is attached by `attachNewScheduleToScope()` (default:
  the stored space). Every task and options object of a plan comes from `AgentEvalSuitePlanService::createTask()` /
  `createPlanOptions()`, so a host's same-path class is used everywhere.

## Seed accounts, clean start and the purge

Cases that run in parallel must not share an account: every case resets its account at its start.

- **Lease:** `AgentEvalSeedAccountLeasesService::acquireLeaseFromPool(AgentEvalSeedPool)` hands one seed of the pool to
  this process: an exclusive, non-blocking `flock()` on a lock file per account in the project's var folder (`LEASE_FILE_FOLDER_NAME`),
  released by the operating system when the process ends. Per host only. `keepLeaseAlive()` once per owner turn (false
  = the lease is lost, abort the case), `releaseLease()` at the end. A seed is an agent space by default; a host maps
  its own kind in `findAccountIdOfSeed()` and builds the pool from its fixture keys (`AgentEvalSeedPool::fromSeedIds()`).
- **Clean start:** `AgentEvalSeedAccountResetService::resetAccountForCleanStart(Account, ?AgentSpace)` refuses an
  account that is no configured evaluation account, waits up to 60 s for the account's runs in flight, then runs
  `resetHostAccountStateBeforePurge()`, the purge of every tree of the account created before now, and
  `resetHostAccountStateAfterPurge()`; the outcome is an `AgentEvalSeedAccountResetReport` (a host's subclass from
  `newResetReport()` carries its own lines). `findKeptAgentSpaceIds()` names spaces whose conversations stay.
- **Purge:** `app:ai-agent:eval-conversations-purge` on `AIConversationEvalPurgeService` deletes the trees (root,
  delegation children, supervisor tandems) of the configured evaluation accounts whose newest activity is older than
  `--older-than-hours`, with every dependent row; a tree with a run in flight or a fresh heartbeat stays. A dry run is
  the default, `--apply` writes. `--accounts` names accounts; one outside the configured evaluation accounts is refused
  unless `--evalOnly` restricts the run to trees with an evaluation marker and the host allows the account
  (`findAccountIdsAllowedForEvalOnlyPurge()`). The statements run on the connection, not through repositories, so no
  read rights apply: the account check above is the scope. Every write chunk runs in its own transaction with a 2 s
  lock wait and a 20 s statement limit, every read with the 20 s limit; the budget is capped at 240 s for a cron line
  under a 360 s ticker.
- **The host's tables:** a same-path subclass of the purge service adds its steps (`buildHostDependantPurgeSteps()`,
  `AIConversationEvalPurgeStep::deleteRows()` / `nullifyColumn()`), the ids they match (`collectHostPurgeKeys()`), their
  order (`getPurgeStepKeysInOrder()`), its evaluation markers (`buildHostEvalMarkerConditionsByMarker()`) and its columns
  that are deliberately no step (`getColumnsNotPurged()`). Its own reads during a run go between
  `beginStatementLimitedRead()` and `endStatementLimitedRead()` (in a `finally`). `preparePoliteWriteSession()` is
  deprecated and does nothing.

## Throwaway spaces and cleanup

A throwaway space is the host's entity: create it per case or per lane, mark it for deletion, and delete it with its
rows after the run. The guard treats a send for it as eval context even outside an eval tool call.

## Checklist

- [ ] `RunSettings::$evalRun` set before the first drive, on every conversation the runner creates
- [ ] write tools to third parties blocked by default, an opt-in only with a restore
- [ ] `evalOutboundRecipients.php` lists the test sinks; every host sender calls `EvalOutboundRecipientsService`
- [ ] `AgentEvalOutboundGuardHooksService` and `AgentEvalAccountsService` overridden
- [ ] full request snapshots on, so a failure can be read
- [ ] each verdict checked against the stored rows before a fix

## Full class names

The classes this reference names, with their namespace (for the `use` lines of a host's code):
`DDD\Domain\AI\Entities\UsageLogs\AIBudgetBypassGrant` ·
`DDD\Domain\AI\Services\Conversations\AIConversationAgentService` ·
`DDD\Domain\AI\Services\Conversations\AIConversationAuditRenderService` ·
`DDD\Domain\AI\Entities\Conversations\AIConversationDriveScope` ·
`DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages` ·
`DDD\Domain\AI\Services\Conversations\Agent\AgentEvalAccountsService` ·
`DDD\Domain\AI\Services\Eval\AgentEvalOutboundGuardHooksService` ·
`DDD\Domain\AI\Entities\Conversations\Agent\AgentEvalResult` ·
`DDD\Domain\AI\Services\Eval\AgentEvalJudgedRunService` ·
`DDD\Domain\AI\Entities\Evals\{AgentEvalSimCase, AgentEvalJudgedRunOptions, AgentEvalSimRunResult, AgentEvalCompactionTreeRunResult, AgentEvalJudgeVerdict, AgentEvalRunCounts, AgentEvalJudgeTraceMessageLines}` ·
`DDD\Domain\AI\Services\Eval\EvalOutboundRecipientsService` ·
`DDD\Domain\AI\Entities\Evals\{AgentEvalSuiteTask, AgentEvalSuiteTasks, AgentEvalSuiteSchedule, AgentEvalSuitePlanSummary, AgentEvalCaseRow, AgentEvalCaseRows, AgentEvalRunSummary, AgentEvalPerformanceRollUp, AgentEvalBaselineChoice, AgentEvalBaselineComparison, AgentEvalSuiteRecord}` ·
`DDD\Domain\AI\Services\Eval\{AgentEvalSuitePlanService, AgentEvalResultFilesService, AgentEvalSuiteRunsService, AgentEvalSuiteCasesService}` ·
`DDD\Domain\AI\Entities\Evals\SuiteRuns\{AgentEvalSuiteRun, AgentEvalSuiteRuns}` ·
`DDD\Domain\AI\Entities\Evals\SuiteRuns\AgentEvalSuiteCases\{AgentEvalSuiteCase, AgentEvalSuiteCases}` ·
`DDD\Domain\AI\Services\Conversations\RunReports\AIConversationRunReportService` ·
`DDD\Symfony\Commands\Agents\AgentRunReportCommand` ·
`DDD\Symfony\Commands\Agents\{AgentEvalConsoleCommand, AgentEvalToolsCommand, AgentEvalSuiteCommand, AgentEvalSelectionCommand, AgentEvalRecallCommand, AgentEvalTiersCommand, AgentEvalAnalyzeCommand, AgentEvalEvictionCommand, AgentEvalGuardCommand, AgentEvalScheduleRunsCommand, AgentEvalCompactionTreeCommand, AgentEvalConversationsPurgeCommand, AgentDecisionFixturesCommand, AgentDecisionStatsCommand, AgentDecisionReplayCommand}` ·
`DDD\Domain\AI\Services\Eval\{AIConversationEvalPurgeService, AgentEvalSeedAccountLeasesService, AgentEvalSeedAccountResetService}` ·
`DDD\Domain\AI\Entities\Evals\ConversationPurge\{AIConversationEvalPurgeSettings, AIConversationEvalPurgeReport, AIConversationEvalPurgeStep, AIConversationEvalPurgeSteps, AIConversationEvalPurgeKeys}` ·
`DDD\Domain\AI\Entities\Evals\SeedAccounts\{AgentEvalSeedAccountLease, AgentEvalSeedAccountResetReport, AgentEvalSeedPool}` ·
`DDD\Domain\AI\Entities\Evals\{AgentEvalStoredRunOptions, AgentEvalSuiteDefinition, AgentEvalSuiteDefinitions, AgentEvalEvictionTurn, AgentEvalEvictionTurns}` ·
`DDD\Domain\AI\Entities\Conversations\Messages\RunSettings`
