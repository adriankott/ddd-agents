# The console commands of the package

**Reader:** host developer and package maintainer

Contents: how to read this reference · run and drive a conversation · inspect and diagnose · cost, cache and catalog ·
evaluation · evaluation housekeeping · decisions · scheduled maintenance and repair · the shared bases · how an
application adds its own command or replaces one · full class names

Every command below is registered by the package kernel under the name shown (46 names, 45 of them listed and the
hidden tool-call child; the list the package tests is the command map of
`tests/Kernel/PackageAgentCommandsRegistrationTest.php`). All classes live in
`src/Symfony/Commands/Agents/` and extend `AgentConsoleCommand`; the evaluation commands extend `AgentEvalConsoleCommand`
on top of it. Run them through the application's `bin/console`, as the same user as the workers.

## How to read this reference

- **Default** is the default exactly as `configure()` declares it; a constant is named with its value. "flag" = an option
  without a value (`InputOption::VALUE_NONE`); "none" = declared without a default.
- **Writes** names the files (with the folder the configuration gives) and the database rows a run changes.
- **Envelope** is the usage envelope the command's model calls are booked under (the `ENVELOPE.` scopes of
  `AgentAIUsageScope`, opened through `AIUsageEnvelopesService` of `mgamadeus/ddd-ai`). "No model call" means the
  command makes none itself.
- **Exit codes:** `0` = `Command::SUCCESS`, `1` = `Command::FAILURE`, `2` = `Command::INVALID`. An exception that leaves
  `executeConsoleWork()` ends the process with Symfony's own exit code.
- **Budget:** whether the command declares `--ignore-budget` (see [the budget grant](#the-budget-grant)). A command that
  makes model calls without the option runs under whatever budget gate the application has.
- **Override points:** the protected methods a subclass is meant to override. The shared ones of the bases are in
  [the shared bases](#the-shared-bases).

## Run and drive a conversation

### `app:ai-agent:chat` — `AgentChatCommand`

Talks to the agent from the console: one owner turn per call (`-m`), several turns from a file, or an interactive loop;
prints every message of the turn as it is produced.

| Option | Default | Meaning |
|---|---|---|
| `--accountId` | none | the account the run works as; omitted = the owner of the space the space options name |
| `--agentSpaceId` and the application's space options | none | [scope options](#scope-options) |
| `--conversationId` | none | resume a stored conversation (ignored with `--ephemeral`) |
| `--purpose` | none | get or start the purposed conversation of the space and print its id; the kickoff turn runs inline; needs a space and no `--ephemeral` |
| `--ephemeral` | flag | store nothing (single process, no resume) |
| `-m`, `--message` | none | the owner message of this turn |
| `-i`, `--interactive` | flag | the interactive loop (`/help`, `/exit`; an empty line or `exit` ends it) |
| `--turns-file` | none | one turn per non-empty line of the file, inline, one after the other; needs a stored conversation |
| `--async` | flag | dispatch the turn to the worker path (`resumeConversationIfReady()` with `async: true`) instead of driving it inline; needs a stored conversation |
| `--model` | none | catalog name of the agent model; wins over `--tier` |
| `--tier`, `--vendor` | none | [model and tier options](#model-and-tier-options) |
| `--provider` | none | pin the gateway provider order (lowercase slugs, comma-separated) without fallback; console only |
| `--no-compact` | flag | no inline compaction after a turn |
| `--no-side-work` | flag | skip the owner-message embedding, the memory curation and the inline compaction (implies `--no-compact`) |
| `--debug` | flag | print payloads (tool calls and arguments) untruncated |
| `--full` | flag | do not truncate tool results |
| `--collapse-threads` | flag | show only the delegate call and verdict of a sub-agent |
| `--html` | none | also write a self-contained HTML transcript to this path, rewritten after every turn |
| `--timings` | flag | print the wall-clock time of each operation |
| `--memory` | flag | print what the memory curation decided (the curation runs anyway on a stored conversation) |
| `--context-composition` | flag | print the token-sized segments of the model call behind the reply, rebuilt from its request snapshot |
| `--capabilities` | flag | print the eviction snapshot of the loaded capabilities after each turn |
| `--max-steps` | none | `runSettings.maxStepsPerTurn`, stored |
| `--max-turn-cost` | none | `runSettings.maxTurnCostUsd`, stored |
| `--store-snapshot` | flag | `contextRetentionSettings.storeRequestSnapshot`, stored |
| `--hint-recent-tool-calls` | flag | `contextRetentionSettings.hintRecentToolCallsToDiscourageRepeats`, stored |
| `--agent-wire-api` | none | `chatCompletions` or `responses` (`inherit` clears it), stored as a run-settings row |
| `--previous-reasoning-carry-over` | none | `all`, `currentTurn`, `off` or `capped` (`inherit` = the default), stored as a run-settings row |
| `--previous-reasoning-cap-tokens` | none | the cap of `capped` in reasoning tokens (`0` = the configured default) |
| the context-retention options | none | `--eager` (flag), `--evict-soft`, `--evict-hard`, `--evict-ref`, `--evict-tool-tokens`, `--evict-tool-turns`, `--evict-skill-tokens`, `--evict-skill-turns`, `--evict-min-residency`, `--unload-granularity`, `--skill-loading-strategy` (a new conversation only), `--rebuild-epoch-on-situation-change`, `--state-change-delivery` (`ContextRetentionSettingsOptionsTrait`) |
| the compaction options | none | `--compact-trigger-tokens`, `--compact-keep-messages`, `--compact-segment-messages`, `--compact-fan-in`, `--compact-retention` |
| `--record-run-for-comparison` | none | drive `--turns-file` on a new stored conversation and write the recording of the run to this JSON file (with `--async` every turn takes the worker path; its messages run in this process) |
| `--replay-run-and-compare` | none | drive the turns of this recording on a new stored conversation (worker path or inline, as recorded), answer every Argus call and tool result from it and compare; exit 1 on a difference |
| `--ignore-budget` | flag | [the budget grant](#the-budget-grant) |

**The recorded-run comparison** (both options need a stored conversation; `--interactive` wins over them):

- **Record:** reads the lines of `--turns-file`, drives them under a recording session and writes the recording
  pretty-printed to the path as given (its folder is created): harness `app:ai-agent:chat`, the settings the caller
  passed (`agentSpaceId`), `async`, the turns, the wire settings of the conversation, then the id normalization, the
  Argus calls, the tool results and the normalized tree and dispatches. Prints
  `run recording for comparison: <n> turns, … → <file>`; exit `0`.
- **Replay:** reads and checks the recording, drives its turns on a new conversation and compares the tree rows, the
  dispatched messages and the sent requests. Prints `recorded-run comparison IDENTICAL: …` (exit `0`) or
  `recorded-run comparison DIFFERS (conversation <id>): <n> difference(s)` with one line per difference (exit `1`).
  Tables read through a scope shared with other runs are named in one comment line.
- **Refused with exit `2`:** `--ephemeral`; a recording that cannot be read, has another version or lacks its calls,
  snapshot or id normalization; a recording of another harness; a passed `--agentSpaceId` or a wire setting (wire API,
  skill-loading strategy, carry-over and its cap, state-change delivery) that differs from the recorded one (a setting
  not passed is not checked); a record run without a readable, non-empty `--turns-file`.
- Mechanism and the session: [record-and-replay](record-and-replay.md).

- **Writes:** a stored conversation and its messages (unless `--ephemeral`); the settings options are stored on the
  conversation; with `--ignore-budget` and `--purpose`, `budgetBypass` on the purposed conversation (the workers that
  drive its later turns inherit the lift); the `--html` file; the recording file of `--record-run-for-comparison`.
- **Envelope:** every inline turn `ENVELOPE.AI.CONVERSATION.TURN` for the conversation's account; the memory curation
  after a stored turn `ENVELOPE.AI.MEMORY.CURATE`.
- **Exit codes:** `0`; `2` without `--message` (and without `--interactive` / `--turns-file`), for `--async` with
  `--ephemeral`, for `--purpose` without a space or with `--ephemeral`, for several turns on an unstored conversation, for
  a missing or empty turns file, for a refused recorded run; the scope refusal's own code: `2` when neither
  `--accountId` nor a space is given, `1` when the account or the space's owner is not found. A failed turn is printed,
  not thrown.
- **Budget:** `--ignore-budget`.
- **Override points:** `resolveChatScope()`, `findAgentSpaceForNewConversation()`, `createConversationInAgentSpace()`,
  `describeAgentSpaceOptions()` (from `AgentConsoleChatScopeTrait`, shared with `app:ai-agent:chat-tui`);
  `curateMemoryAfterTurn()` (the memory curation after a turn); `startRecordedRunSession()` /
  `finishRecordedRunSession()` (the recorded-run comparison), `buildRecordedRunSettingsPassedByCaller()` (the settings
  a replay must match; the application's own scope option), `buildRecordedRunScope()` and
  `buildRecordedRunConcludingScopeIds()` (its scope names), `buildWireRecordingKeys()` (the wire settings a recording
  carries); `buildSlashCommandRegistry()` (the interactive slash commands); `getInteractiveChatTitle()` (the headline).

### `app:ai-agent:chat-tui` — `AgentChatTuiCommand`

The same chat on a full screen: a scrollable transcript, tool results that open on a click, a pinned status line. Needs
the library `php-tui/php-tui` (suggested, not required) and a real terminal.

| Option | Default | Meaning |
|---|---|---|
| `--accountId` | none | as in `app:ai-agent:chat` |
| `-m`, `--message` | none | an opening message sent as the first turn |
| `--conversationId` | none | resume a stored conversation (ignored with `--ephemeral`) |
| `--ephemeral` | flag | store nothing |
| `--full` | flag | start with tool results expanded |
| `--collapse-threads` | flag | accepted for parity, no effect |
| `--debug` | flag | accepted for parity, no effect |
| `--html` | none | also write the HTML transcript, refreshed after every turn |
| `--model`, `--tier`, `--vendor` | none | [model and tier options](#model-and-tier-options) |
| `--agentSpaceId` and the application's space options | none | [scope options](#scope-options) |
| `--ignore-budget` | flag | [the budget grant](#the-budget-grant) |

- **Writes:** as `app:ai-agent:chat` (conversation, messages, the `--html` file).
- **Envelope:** each turn `ENVELOPE.AI.CONVERSATION.TURN`.
- **Exit codes:** `0`; `1` when the library is missing and for every scope refusal.
- **Budget:** `--ignore-budget`.
- **Override points:** the scope methods of `AgentConsoleChatScopeTrait` (see `app:ai-agent:chat`).

### `app:ai-agent:compact` — `AgentCompactConversationCommand`

Runs one compaction round of a stored conversation in the console and prints the node tree and the cover the model
would receive; rights lifted, the model calls made as the conversation's account.

| Option / argument | Default | Meaning |
|---|---|---|
| `conversationId` (argument, required) | — | the stored conversation |
| `--show-only` | flag | render the tree and the cover, no round |
| `--as-of-message` | none | render the cover as it was when this message index was produced |
| `--validate` | flag | compare every bounded loader with the full-scan reference and print the efficiency counts |
| `--trigger-tokens`, `--keep-messages`, `--segment-messages`, `--fan-in`, `--retention` | none | the compaction knobs for this run, merged onto the stored settings (no prefix on this command) |
| `--ignore-budget` | flag | [the budget grant](#the-budget-grant) |

- **Writes:** the compaction nodes of the round (nothing with `--show-only`).
- **Envelope:** `ENVELOPE.AI.CONVERSATION.COMPACTION` for the conversation's account.
- **Exit codes:** `0`; `1` when the conversation does not exist.
- **Budget:** `--ignore-budget`.
- **Override points:** none beyond the base.

### `app:ai-agent:execute-tool-call` — `AgentExecuteToolCallCommand` (hidden)

The child process of a parallel tool call: executes ONE persisted tool-call row the turn worker created and writes the
outcome into that row under the worker's lease. Started by the turn worker when the application names a
`consoleScript` in `AI.agentToolCallChildProcess`; never by a person; not in the command list.

| Option / argument | Default | Meaning |
|---|---|---|
| `messageId` (argument, required) | — | the tool-call row |
| `--lease` | none | the ownership token the worker assigned |
| `--timeout` | `'60'` | the tool's own execution ceiling in seconds (a SIGALRM ends the call) |
| `--blocked-tools` | none | comma-separated tools the worker process blocks (an evaluation run) |
| `--every-write-blocked` | flag | the write block of the worker process |
| `--run-token` | none | the worker's claim on the conversation; the child's writes are fenced on it |

- **Writes:** the `toolCall` column of the row (and whatever the tool writes).
- **Envelope:** `ENVELOPE.MCP.TOOL_CALL`, carrying the conversation's budget-bypass grant.
- **Exit codes:** `0` outcome written (a failed tool is an outcome); `1` row or conversation not loaded;
  `3` (`EXIT_LEASE_NOT_CLAIMED`) lease not claimed; `4` (`EXIT_OUTCOME_WRITE_REFUSED`) outcome write refused;
  `5` (`EXIT_RUN_CLAIM_LOST`) the worker's claim on the conversation is lost.
- **Budget:** no option; the lift comes from the conversation.
- **Override points:** none beyond the base. An application that replaces it under another name sets `commandName` in
  `AI.agentToolCallChildProcess`.

## Inspect and diagnose

### `app:ai-agent:inspect` — `AgentInspectConversationCommand`

Read-only view of one stored conversation, rights lifted: a header, one table row per message, the transcript of the
live printer, the request snapshots (`--snapshots`), the delegation children and sub-agents.

| Option / argument | Default | Meaning |
|---|---|---|
| `conversationId` (argument, required) | — | the conversation |
| `--include-truncated` | flag | also show soft-deleted (`truncatedAt`) rows, marked PRUNED |
| `--full` | flag | do not truncate tool results |
| `--debug` | flag | payloads untruncated |
| `--collapse-threads` | flag | delegations as call and verdict only |
| `--no-transcript` | flag | skip the transcript |
| `--snapshots` | flag | dump the stored debug payloads (only present with `storeRequestSnapshot` on) |
| `--html` | none | also write the HTML transcript to this path |

- **Writes:** only the `--html` file.
- **Envelope:** no model call.
- **Exit codes:** `0`; `1` when the conversation does not exist.
- **Budget:** no option.
- **Override points:** `describeAgentSpaceRow()` (the header row of the space; the default prints its id),
  `describeAccountRow()`, `buildHeaderRows()`.

### `app:ai-agent:diagnose` — `AgentDiagnoseResumeCommand`

Reconstructs, without driving a turn, the decision a resume would take for one conversation (the resume diagnosis);
rights lifted.

| Option / argument | Default | Meaning |
|---|---|---|
| `conversationId` (argument, required) | — | the conversation |
| `--include-truncated` | flag | count soft-deleted rows in the full tail |
| `--drive` | flag | after the diagnosis, drive one turn synchronously with full output and stack trace; takes no run claim |
| `--retry` | flag | after the diagnosis, `retryUnansweredTurn()`: reset a FAILED or IDLE run with an unanswered owner message and dispatch the resume to the workers |

- **Writes:** nothing without options; `--drive` appends and stores the reply; `--retry` changes the run state and
  dispatches a resume.
- **Envelope:** `--drive` runs inside `ENVELOPE.AI.CONVERSATION.TURN` for the conversation's account.
- **Exit codes:** `0`; `1` when the conversation does not exist.
- **Budget:** no option; `--drive` lifts the budget for the process only when the conversation carries `budgetBypass`.
- **Override points:** none beyond the base.

### `app:ai-agent:validate-conversation` — `AgentValidateConversationCommand`

Checks the integrity of stored conversations (preloads on their anchor, no reload of an admitted skill in a turn, final
tool calls, unique tool-call ids, contiguous indexes); read-only, rights lifted.

| Argument | Default | Meaning |
|---|---|---|
| `conversationIds` (argument, required, list) | — | one or more ids |

- **Writes:** nothing. **Envelope:** no model call.
- **Exit codes:** `0`; `1` when a conversation is missing or has a violation.
- **Budget:** no option. **Override points:** none beyond the base.

### `app:ai-agent:activity-lines` — `AgentActivityLinesCommand`

Replays the tool calls of a stored conversation through the activity narrator and prints the lines an owner would have
read, per model; or runs the endpoint's read of the newest turn (`--endpoint`). Works as the default account for
console work.

| Option | Default | Meaning |
|---|---|---|
| `--conversation` | none | the conversation id (required by the work) |
| `--models` | `''` | comma-separated catalog models to compare; empty = the narrator's model scope |
| `--turn` | `null` | only the turn that starts at this index |
| `--effort` | `''` | reasoning effort override (none, minimal, low, medium); empty = the attribute decides |
| `--batch` | `''` | elements per narrator call; empty = the service constant |
| `--endpoint` | flag | the endpoint's read instead of the replay |
| `--json` | flag | print JSON |
| `--show-payload` | flag | print the prompt and user content of every narrator call |

- **Writes:** nothing on the conversation.
- **Envelope:** `ENVELOPE.AI.CONVERSATION.ACTIVITY` for the conversation's account.
- **Exit codes:** `0`; `1` without `--conversation`, for an unknown conversation, when the replay throws.
- **Budget:** no option. **Override points:** none beyond the base.

### `app:ai-conversation:search-inspect` — `AISearchInspectCommand`

Runs the conversation recall search stage by stage (embedding coverage, the vector query with the rights lifted and
with them, the pipeline without and with an envelope, the rerank probe) and prints the hit count at each.

| Option / argument | Default | Meaning |
|---|---|---|
| `conversationId` (argument, required) | — | names the account and enables the within-conversation stage |
| `query` (argument, required) | — | the search query |
| `--account` | none | the account of the stage with rights; default the conversation's |
| `--embed` | flag | embed the conversation's messages without an embedding synchronously first |

- **Writes:** embedding rows with `--embed`; nothing else.
- **Envelope:** `ENVELOPE.AI.CONVERSATION.SEARCH` for the account, initiator `ACCOUNT` (the grouped call and the rerank
  probe).
- **Exit codes:** `0`; `1` for an unknown conversation or account, or when the query embedding is not generated.
- **Budget:** no option. **Override points:** none beyond the base.

### `app:ai-agent:run-report` — `AgentRunReportCommand`

The per-run report of stored conversations: judge verdict and findings from evaluation logs, wall and agent seconds,
model and tool calls, the time split, the slowest tools, the variants timeline, the plan size, the totals. Works as the
default account for console work.

| Option / argument | Default | Meaning |
|---|---|---|
| `conversationIds` (argument, required, list) | — | the runs |
| `--log-dir` | none | folder of `*.log` evaluation logs; the log naming a conversation id carries its verdict |
| `--markdown` | flag | tables as Markdown |
| `--findings` | flag | print the judge findings per run |
| `--no-tools` | flag | skip the slowest-tools table |
| `--variants` | flag | print the per-run variants timeline |

- **Writes:** nothing. **Envelope:** no model call. **Exit codes:** `0`.
- **Budget:** no option.
- **Override points:** `buildRunReportTotals()` (the totals object); the figures come from
  `AIConversationRunReportService`, which an application replaces at its mirrored path.

### `app:ai-agent:context-eviction-sim` — `AgentModelInputEvictionSimCommand`

Simulates the per-tool eviction pass (`unloadGranularity: tool`) over scripted scenarios; no database, no account, no
model call. The skill-granular default pass is not simulated.

| Option | Default | Meaning |
|---|---|---|
| `--scenario` | `'all'` (`ALL_SCENARIOS`) | one scenario name or all |
| `--soft` | `'24000'` (`LoadedCapability::DEFAULT_PRESSURE_BUDGET_TOKENS`) | the soft target |
| `--hard` | `'40000'` (`DEFAULT_HARD_CAP_TOKENS`) | the hard ceiling |
| `--ref` | `'500'` (`DEFAULT_REFERENCE_RESIDENT_TOKENS`) | the size-factor denominator |
| `--tool-tokens` | `'8000'` (`DEFAULT_UNLOAD_TOOL_AFTER_UNUSED_TOKENS`) | tool unload horizon in tokens |
| `--tool-turns` | `'10'` (`DEFAULT_UNLOAD_TOOL_AFTER_UNUSED_TURNS`) | tool unload horizon in turns |
| `--skill-tokens` | `'5000'` (`DEFAULT_UNLOAD_SKILL_AFTER_UNUSED_TOKENS`) | skill unload horizon in tokens |
| `--skill-turns` | `'8'` (`DEFAULT_UNLOAD_SKILL_AFTER_UNUSED_TURNS`) | skill unload horizon in turns |
| `--min-residency` | `'2'` (`DEFAULT_MIN_RESIDENCY_TURNS`) | residency grace in turns |
| `--eager` | flag | load all tools of a skill with the skill |

- **Writes:** nothing. **Exit codes:** `0`. **Budget:** no option.
- **Override points:** `buildScenarios()` and `buildCatalog()` (simulate the application's own skills).

## Cost, cache and catalog

### `app:ai-agent:cache-diff` — `AgentCacheDiffCommand`

The prompt-cache diff of a conversation: per model call where the request first differs from the previous one, the
cache credit, the gap and the cause class; rights lifted.

| Option / argument | Default | Meaning |
|---|---|---|
| `conversationId` (argument, required) | — | the conversation (a stored debug payload adds the wire-message column) |
| `--json` | flag | the report as JSON |

- **Writes:** nothing. **Envelope:** no model call.
- **Exit codes:** `0`; `1` for an unknown conversation or when no model call carries segment hashes or a debug payload.
- **Budget:** no option. **Override points:** none beyond the base.

### `app:ai-agent:tool-response-stats` — `AgentToolResponseStatsCommand`

Ranks tools by stored result size, lists the largest results of one tool, or dumps one call's payload; reads the
generated `virtual*` size columns of the messages table; rights lifted.

| Option | Default | Meaning |
|---|---|---|
| `--days` | `'30'` | range = the last N days (ignored with `--from` / `--to`) |
| `--from`, `--to` | none | range bounds (Y-m-d, inclusive; `--to` defaults to today) |
| `--order-by` | `'avg'` (`ORDER_BY_AVG`) | `avg`, `max`, `sum` or `calls` |
| `--limit` | `'30'` | ranking rows |
| `--tool` | none | the largest single responses of one tool |
| `--samples` | `'20'` | how many with `--tool` |
| `--message` | none | dump one tool-call message's arguments and result |
| `--head` | none | with `--message`: only the first N characters of the result |

- **Writes:** nothing. **Envelope:** no model call.
- **Exit codes:** `0`; `1` for a bad range or `--order-by`, a failed aggregation (missing size columns), an unknown
  message or a message without a tool call.
- **Budget:** no option. **Override points:** none beyond the base.

### `app:ai-agent:skill-token-map` — `SkillTokenMapCommand`

The per-turn token size of every agent skill, by package and tool, measured on the wire schema the loop sends; reads
reflection and the skill files, no database.

| Option | Default | Meaning |
|---|---|---|
| `--json` | flag | the map as JSON |
| `--top` | `'12'` | rows in the ranking sections |
| `--detail` | flag | split each tool into description, parameters and shared definitions |
| `--skill` | none | only this skill |
| `--tool` | none | only this tool (implies `--detail`) |
| `--doc` | flag | with `--tool`: dump that tool's full wire schema |
| `--shared-blocks` | flag | dump the two once-per-turn shared system blocks |

- **Writes:** nothing. **Envelope:** no model call.
- **Exit codes:** `0`; `1` for `--doc` without `--tool`, or a tool the catalog does not hold.
- **Budget:** no option. **Override points:** none beyond the base.

### `app:mcp:documentation` — `MCPDocumentationCommand`

Prints the tool list and generated JSON schemas of an MCP bundle or package with a token estimate; `--call` executes one
tool in-process through the agent's executor and measures its response.

| Option | Default | Meaning |
|---|---|---|
| `--bundle` | none | bundle class (FQCN or short name) |
| `--package` | none | package class, documented on its own |
| `--list` | flag | list every bundle and package found |
| `--cache` | flag | read the documentation cache instead of rebuilding |
| `--call` | none | execute this tool and report its response size |
| `--asAdminAccountId` | none | for an admin-audience tool: run it as this admin account, no space needed |
| `--arguments` | none | JSON tool arguments for `--call` |
| `--dump-images` | `false` | for `--call`: write the image parts of the result into this folder as `<tool>-<n>.<ext>` |
| `--agentSpaceId` and the application's space options | none | the space a `--call` runs for |
| `--ignore-budget` | flag | [the budget grant](#the-budget-grant) |

- **Writes:** `--dump-images` files; whatever the called tool writes.
- **Envelope:** the command opens none.
- **Exit codes:** `0`; `2` without `--bundle`, `--package`, `--call` or `--list`, for a non-numeric admin id, for
  `--arguments` that are no JSON object, for an invalid scope option or a `--call` without a space; `1` when the
  documentation build, the admin account, the space's owner or the tool call fails.
- **Budget:** `--ignore-budget`.
- **Override points:** `getMcpClassSourceRoots()` (where bundles and packages are searched), `resolveToolCallAgentSpace()`,
  `describeToolCallScopeRequirement()`, `describeToolCallAgentSpace()` (the words of the refusals),
  `startToolCallMeasurement()` / `reportToolCallMeasurement()` (around a `--call` for a space).

### `app:mcp:clear-cache` — `MCPClearCacheCommand`

Clears the MCP documentation cache of every bundle and every package bound to a skill, then runs the container cache
clear in the same process. No options.

- **Writes:** the cache entries (`mcp.doc.bundle.*`, `mcp.doc.package.*`) and the compiled container.
- **Envelope:** no model call. **Exit codes:** `0`. **Budget:** no option.
- **Override points:** `getBundleClasses()` (the bundles cleared), `getContainerCacheClearCommandName()` (default
  `cache:clear`), `getWorkerRestartNotice()` (the last line; a running worker still needs a restart).

### `app:ai:composed-view:export-node-schema` — `ComposedViewNodeSchemaExportCommand`

Writes the application's part of the composed-view node schema from the registered classes and compares the installed
package's `composed-node-schema.json`; the package's file is never written.

| Option | Default | Meaning |
|---|---|---|
| `--check` | flag | compare only; fail when a file differs |

- **Writes:** the application's schema part file (not with `--check`).
- **Exit codes:** `0`; `1` when a file differs or the folder cannot be created.
- **Budget:** no option.
- **Override points:** `getSchemaFilePaths()` (where both files lie; the default names no application part).

### `app:ai-conversation:scorecards` — `AIConversationScorecardsCommand`

Selects conversations and dispatches one scorecard message per conversation to the scorecard worker, writes inline
(`--inline`) or prints the metrics (`--print`). Works as the default account for console work. Without an application's
quality component the package writes no scorecard.

| Option | Default | Meaning |
|---|---|---|
| `--since` | `date('Y-m-d', strtotime('-30 days'))` | root conversations created on or after this date |
| `--conversation` | none | one id or a comma list instead of the date window |
| `--purpose` | none | comma-separated purposes; `none` (`PURPOSE_NONE`) = regular chats |
| `--missing` | flag | the cron run: the open conversations the quality component names, up to `--limit` minus the queue |
| `--print` | flag | compute and print, write nothing |
| `--force` | flag | analyze again (declared by `configureAnalysisOptions()`) |
| `--eval-runs` | flag | include evaluation runs |
| `--internal-accounts` | flag | include internal accounts the application names |
| `--inline` | flag | write in this process instead of dispatching |
| `--limit` | `'500'` (`DEFAULT_LIMIT`) | at most this many conversations |
| `--shard` | none | `N/M`: only shard N of M (crc32 of the id) |

- **Writes:** dispatched scorecard messages; with `--inline` what `analyzeConversation()` writes.
- **Envelope:** `--inline` runs under `ENVELOPE.AI.CONVERSATION.COMPACTION` as the conversation's account.
- **Exit codes:** `0`. **Budget:** no option.
- **Override points:** `configureAnalysisOptions()`, `describeOption()` (help texts), `findOpenConversationIds()` (the
  `--missing` selection), `analyzeConversation()` (the `--inline` work), `buildPrintedRow()` and
  `getPrintedTableHeadings()` (the `--print` table), `isConversationScored()`.

## Evaluation

The fixture format these commands read: [evaluation-fixtures](evaluation-fixtures.md). Every evaluation command extends
`AgentEvalConsoleCommand`: its progress lines go to the error output (indented two spaces), so a `--json` document on
the standard output stays pure.

### `app:ai-agent:eval-tools` — `AgentEvalToolsCommand`

Runs the cases of the tools fixtures: deterministic (the case's `expect` block) or judged (`--judge`: a simulated owner
and a judge model). Reports per case and overall, writes the run into the results folder and stores its record.

| Option | Default | Meaning |
|---|---|---|
| `--agentSpaceId` and the application's space options | none | the space of the cases that name none |
| `--accountId` | none | the account of the cases that name no space (and no `--agentSpaceId`): they run at account level for it instead of being skipped; also the account of a space without an owner |
| `--fixture` | none | one fixture file; default every fixture of `AI.evalSuite.fixturePaths.tools` |
| `--skill` | none | only the fixture whose `skill` matches |
| `--case` | none | only this case id (runs in this process) |
| `--full` | flag | tool arguments and results also for passing cases |
| `--json` | flag | the result document instead of the report |
| `--model` | none | the agent model; `--tier` / `--vendor` too ([model and tier options](#model-and-tier-options)) |
| `--repeat` | `'1'` | run every case N times, each in its own child process (`<caseId>.rep<k>.json`); the report prints per case its passed runs of all runs and, under it, why each failed run failed (its error, else its first failure; at most 5 per case, 200 characters each, `AgentEvalCaseRepetition::$failureDetails`, not stored) |
| `--parallel` | `'10'` | cases in child processes, N at a time; `1` = one after the other in this process |
| `--no-save` | flag | write no run files and store no record (a child) |
| `--no-prune` | flag | skip the retention sweep of the results folder |
| `--judge` | flag | the judged flavor |
| `--judge-model` | none | the judge model (default: `AI.evalSuite.judgeModelName`, else the package's) |
| `--driver-model` | none | the simulated owner model (default: `AI.evalSuite.driverModelName`, else the package's) |
| `--owner-device` | none | the simulated owner's device stamped on every owner message (`OwnerDevice::SURFACES`) |
| `--include-async` | flag | also run `executionMode: async` cases inline |
| `--transient` | flag | store no conversations (no decision site runs); ignored when an arm option is passed |
| `--persist-conversations` | flag | store the conversations (the default) |
| `--result-file` | none | also write the result document to this file |
| the arm options | none | [evaluation arm options](#evaluation-arm-options) |
| `--ignore-budget` | flag | [the budget grant](#the-budget-grant) |

- **Writes:** in the results folder `<date>_<time>-<model>-<scope>.json` (a run in this process) or a folder of that
  name with one `<caseId>.json` per case run and `_summary.json` ([evaluation-fixtures](evaluation-fixtures.md#result-files));
  the stored run and its case rows (`AgentEvalSuiteRunsService`) unless `--no-save` or `--result-file`; the
  evaluation conversations (stored unless transient); what a case writes and restores. The retention sweep deletes
  dated run entries older than 28 days (`AgentEvalResultFilesService::DEFAULT_RETENTION_DAYS`) at every start unless
  `--no-save` or `--no-prune`.
- **Envelope:** every agent turn `ENVELOPE.AI.CONVERSATION.TURN` for the space's owner; the driver and the judge
  `ENVELOPE.AI.AGENT.EVALUATION` (booked as `AI.AGENT.SIMULATE.OWNER` / `AI.AGENT.JUDGE.EVALUATION`).
- **Cost lines:** `Model cost:` is the agent's alone; a judged run adds `Judge and driver cost:` (total, judge,
  driver) from the rows' `judgeCostUsd` / `driverCostUsd`, stored as `metrics.harnessCosts`
  ([evaluation-fixtures](evaluation-fixtures.md#the-judged-flavors-keys)).
- **Exit codes:** `0` when every case that counts passed; `1` otherwise or when no fixture is found; `2` for a
  `--judge-model` / `--driver-model` the model catalog does not know ([the harness models](#the-harness-models)).
- **Budget:** `--ignore-budget`, handed to every child.
- **Child processes:** when `--repeat` > 1, or `--parallel` > 1 without `--case`; each child is this command with
  `--case`, `--json`, `--no-save`, `--fixture`; ceiling = the case's `timeoutSeconds` (default 600, at least 60).
- **Override points:** `runCaseOfOwnShape()` (a case of the application's own shape), `buildPlanOptionsOfRun()` (the
  plan options), the `*_LABEL` and `*_SKIP_REASON` constants (the words of the output).

### `app:ai-agent:eval-suite` — `AgentEvalSuiteCommand`

The whole suite in one run: every configured suite on every model in every flavor, each case in a child process of its
kind's command; reads in a pool, writes in lanes per space and resource. Two verdicts: correctness and performance
against a baseline. LIVE.

| Option | Default | Meaning |
|---|---|---|
| `--suites` | the configured suites with `default` true, comma-joined; none when no suite is configured | which suites |
| `--models` | `getDefaultModelName()` (null in the package = the conversation's own) | comma list of agent models |
| `--flavors` | `'call,judge'` | `call` (deterministic) and `judge` |
| `--skill` | none | only tools-style fixtures whose skill or file name matches |
| `--case` | none | one case id or a comma list |
| `--max-processes` | `'24'` (`DEFAULT_MAX_PROCESSES`) | process slots |
| `--head-start-seconds` | `'45'` (`DEFAULT_HEAD_START_SECONDS`) | the first case runs alone this long or until it ends |
| `--stagger-ms` | `'500'` (`DEFAULT_STAGGER_MILLISECONDS`) | between two starts after the head start |
| `--isolate-scopes` | flag | a write excludes every read of its space |
| `--include-async` | flag | also run `executionMode: async` cases inline |
| `--transient` | flag | store no conversations of the tools-style and selection cases |
| `--judge-model`, `--driver-model` | none | the judged flavor's models |
| `--baseline` | none | a former suite run folder, or `latest` |
| `--performance-tolerance` | `AgentEvalBaselineComparison::DEFAULT_TOLERANCE_PERCENT` | percent a figure may grow before it is a regression |
| `--label` | none | suffix of the run folder |
| `--dry-run` | flag | print the plan, start nothing |
| `--json` | flag | the suite record as JSON at the end |
| `--enforce-budget` | flag | keep the budget cap; without it every child gets `--ignore-budget` |
| `--agentSpaceId` and the application's space options | none | the fallback space |
| `--accountId` | none | cases without a space are planned at account level for it (scope id = the negated account id); forwarded to every child |
| the arm options | none | [evaluation arm options](#evaluation-arm-options) |

- **Writes:** a run folder `<date>_<time>-suite[-<label>]` in the results folder (see
  [evaluation-fixtures](evaluation-fixtures.md#result-files)); the stored suite run; what the children write.
- **Envelope:** none of its own; the children book as above.
- **Exit codes:** `0` when both verdicts pass; `1` otherwise; `2` when no case matched or for an unknown
  `--judge-model` / `--driver-model` (refused before the plan); a stop signal before the first case started ends the
  process with `128 + signal`.
- **Budget:** inverted: lifted unless `--enforce-budget`.
- **Stop:** a `STOP` file in the run folder or SIGINT / SIGTERM ([stop signals](#stop-signals-and-the-stop-file)).
- **Child processes:** `app:ai-agent:eval-tools` (kind `toolsCase`), `app:ai-agent:eval-selection` (`selectionCase`),
  `app:ai-agent:eval-recall` (`recallCase`); ceiling = the case's `timeoutSeconds` (default 600, at least 60); a case
  that ended on an infrastructure error (`RETRIED_INFRASTRUCTURE_ERRORS`: deadlock, lock wait timeout, cURL 28 / 35 /
  56, server gone away, a missing compiled-container file) runs once more.
- **Override points:** `getDefaultModelName()`, `getRetriedInfrastructureErrors()`, `getChildCommandNameOfKind()` (the
  child of an application's own kind), `buildApplicationArgumentsOfTask()`, `buildPlanOptions()`,
  `buildFixturesOfSuite()`, `configureApplicationOptions()`, `isIsolateWholeScopeRequested()`, `getDecisionLayerLabel()`,
  `describeStopRequest()`.

### `app:mcp:compare-tool-calls` — `MCPCompareToolCallsCommand`

Proves that a change of an MCP package changed nothing the model receives: `--record` runs the package's comparison
script in this process on the unchanged build and writes the recording; `--replay-and-compare` runs the same script on
the build under test with the tools' Argus calls answered from the recording and lists every difference. No model is
called. Base `AgentConsoleCommand`.

| Option | Default | Meaning |
|---|---|---|
| `--script` | none | the comparison script: a path, or a package name resolved to `config/app/AI/ToolCallComparisons/<PackageName>.json` (`SCRIPTS_DIRECTORY`) |
| `--record` | none | run the script and write the recording to this path |
| `--replay-and-compare` | none | run the script against this recording and compare |
| `--ignore-budget` | flag | [the budget grant](#the-budget-grant) |

A script path is tried as given, then below `getRelativePathBaseDirectory()`, then below the application's root
directory, then as `<name>.json` in `getScriptsDirectory()`; a relative recording path resolves against
`getRelativePathBaseDirectory()` (default: the application's root directory). Packages and bundles are found below
`Presentation/Mcp` of the source roots, the application's root first: an application class wins over a module class of
the same short name.

**The script** (`ToolCallComparisonScript`; JSON object):

| Key | Type | Meaning |
|---|---|---|
| `package` | string (required) | short class name or class name of the package under test |
| `additionalPackages` | list | packages offered beside it (a case may call their tools) |
| `agentSpaceId` | int | the agent space the run works in; absent = account level |
| `description` | string | free text |
| `derivedMomentColumns` | list of `table.column` | columns holding a moment derived from the wall clock (statements read `<derived-clock>`) |
| `derivedMomentKeys` | list | keys of message bodies and JSON values holding such a moment (`<derived-clock>`) |
| `durationKeys` | list | keys holding a wall-clock duration (`<duration>`) |
| `wallClockTextPrefixes` | list | literal text right before which a site the run cannot pin writes a time of day (`H:i`) into a text; that time reads `<clock>` when the run's wall clock wrote it |
| `opaqueTokenKeys` | list | keys (JSON values, message bodies, JSON written into text) whose value is a token derived from a harness id; compared by which values are equal, each distinct value reads `<key#n>` |
| `conversations` | object name → `{ownerRows?, purposeName?, agentWorkingState?, childOf?, scheduleId?}` | harness conversations, created anew in each run; `childOf` names a root conversation |
| `fixture` | list of `{tool, arguments, conversation, expectsError?}` | harness calls before the first case, not compared; `expectsError: true` = a call that must answer an error |
| `cases` | list of `{id, tool, arguments, conversation, provenance?, before?, note?}` | the compared calls; `provenance` `agent`, `evalHarness` or `none`; `before` = harness calls of the case, not compared |

`{{agentSpaceId}}` in an argument is the id of the run's agent space. Every call names a declared conversation. The
script is refused (exit `2`, one line each) without `package` or `cases`, for a duplicate or missing case id, a tool
that is no tool of the script's packages, an undeclared conversation, a `childOf` that names no root conversation, or
an unknown provenance. A replay refuses a script whose text differs from the recorded one.

**What a run does:** each call (case, fixture, `before`) starts in the process state of a fresh worker job, under the
pinned clock and an id source seeded per call (moment and seed stored in the recording), with no Argus or application
cache, the decision model's breaker in harness mode and every Messenger dispatch kept instead of sent. Compared per
case: the tool result as the loop hands it to the model (text, structured loop-control fields, error frame, `isError`,
refusal kind), the rows appended to the harness conversations, every INSERT / UPDATE / DELETE the call issued, every
dispatched message, the requests of the application's own recorders and the Argus request bodies. Database ids, the
run's agent space and the harness conversations read as placeholders; the wall clock the run could not pin is
normalized by the script's keys above. Recorded calls a replay never asked for are a difference, except those of
harness calls.

- **Writes:** `--record`: the recording at the path and `<recording>.definitions.json` beside it (the wire schemas,
  the HTTP documentation, the summaries and the skill tool lists); `--replay-and-compare`:
  `<recording>.replay-definitions.json`, compared byte for byte with the recorded dump. Folders are created. The tool
  calls themselves write to the database like the agent's calls.
- **Envelope:** no model call; the executor runs the tools as in a conversation.
- **Exit codes:** `0` recorded / identical (`tool-call comparison IDENTICAL: …`); `1` differences (one line each,
  `tool-call comparison DIFFERS: …`), a failed run, or a recording that could not be written; `2` without `--script`
  or without exactly one of `--record` / `--replay-and-compare`, a script or package that cannot be found, a refused
  script, an unreadable recording, one of another harness or version, or a script that differs from the recorded one.
- **Budget:** `--ignore-budget`.
- **Account:** the owner of the script's agent space, else the default account for console work.
- **Override points:** `getScriptsDirectory()` (where the committed scripts live), `getRelativePathBaseDirectory()`
  (what a relative script or recording path resolves against), `findComparisonAccount()` (the account a run works
  as), `describeCaseOutcome()` (the record line of a case), `describeRecording()` (the closing line of a record run),
  `getProgressLinePrefix()` (the prefix of a progress line of the run's agent space), `describeScriptSearchPlaces()`
  (where a script is looked for, as the refusal names it), `getMcpClassSourceRoots()` (the source roots of packages
  and bundles). The session, the service and the stored formats are adapted at their own override points:
  [record-and-replay](record-and-replay.md#the-tool-call-comparison).

### `app:ai-agent:eval-selection` — `AgentEvalSelectionCommand`

The skill-selection evaluation: per case the agent answers the prompt, and the case passes when one of the expected
skills (`expectedSkill`, `A|B` for either) was loaded — or, with `expectedSkill: "none"`, when no skill was loaded — and
none of its `forbiddenSkills`. Record references in the prompt are resolved in the case's scope first (an unresolved one
makes the case not applicable). Every write tool is refused.

| Option | Default | Meaning |
|---|---|---|
| `--fixture` | none | the fixture file; default `AI.evalSuite.fixturePaths.selection` |
| `--case` | none | only this case id |
| `--json` | flag | the run summary instead of the report |
| `--result-file` | none | also write the result document here |
| `--transient`, `--persist-conversations` | flag | as in `app:ai-agent:eval-tools` |
| `--model`, `--tier`, `--vendor` | none | [model and tier options](#model-and-tier-options) |
| the arm options | none | [evaluation arm options](#evaluation-arm-options) |
| `--ignore-budget` | flag | [the budget grant](#the-budget-grant) |
| `--agentSpaceId` and the application's space options | none | the space of the cases that name none |

- **Writes:** the stored run when no `--result-file` is given; the result file; the evaluation conversations.
- **Envelope:** `ENVELOPE.AI.CONVERSATION.TURN`.
- **Exit codes:** `0`; `1` when the fixture holds no case; `2` when no case matched.
- **Budget:** `--ignore-budget`.
- **Override points:** `getDefaultFixturePath()`, `findFallbackScopeValueOfRow()`, `describeMissingScope()`.

### `app:ai-agent:eval-recall` — `AgentEvalRecallCommand`

The cross-conversation recall evaluation: seeds past conversations, drives the agent on a fresh one and checks the
recall tool and the seeded facts in the answer; every write tool refused; the seeds are deleted afterwards.

| Option | Default | Meaning |
|---|---|---|
| `--fixture` | none | one fixture; default the files of `AI.evalSuite.fixturePaths.recall` |
| `--case` | none | only this case id |
| `--model` | none | the agent model |
| `--keep-seed` | flag | keep the seeded conversations |
| `--full` | flag | print every final answer |
| `--json` | flag | JSON output |
| `--result-file` | none | also write the result document here |
| `--ignore-budget` | flag | [the budget grant](#the-budget-grant) |

- **Writes:** seeded conversations and their embeddings (deleted in a `finally` unless `--keep-seed`); the result file.
- **Envelope:** `ENVELOPE.AI.CONVERSATION.TURN`.
- **Exit codes:** `0` when every case that counts passed; `1` otherwise or when no case is found.
- **Budget:** `--ignore-budget`. No scope options: a recall case names its own space.
- **Override points:** `getDefaultFixturePaths()`, `getScopeWordOfOverallLine()`.

### `app:ai-agent:eval-tiers` — `AgentEvalTiersCommand`

Runs every tools case under the model each tier selects and reports pass rate and efficiency per tier. A case runs only
with its scope keys, `prompt`, `restore`, `blockedTools` and `allowExternalWrites` (`RUN_CASE_FIELD_NAMES`).

| Option | Default | Meaning |
|---|---|---|
| `--tiers` | every tier of `ModelTier::getAll()`, comma-joined | the tiers |
| `--vendor` | none | soft vendor preference |
| `--fixture` | none | one fixture; default `AI.evalSuite.fixturePaths.tools` |
| `--full` | flag | list each failing case per tier |
| `--agentSpaceId` and the application's space options | none | the space of the cases that name none |

- **Writes:** what the cases write and restore.
- **Envelope:** `ENVELOPE.AI.CONVERSATION.TURN`.
- **Exit codes:** `0`; `1` when no case is found.
- **Budget:** no option. **Override points:** `getDefaultFixturePaths()`.

### `app:ai-agent:eval-eviction` — `AgentEvalEvictionCommand`

The live eviction evaluation: each trajectory's prompts run on one continuing transient conversation and the load curve
of the loaded capabilities is printed.

| Option | Default | Meaning |
|---|---|---|
| `--fixture` | none | the trajectory fixture; default `AI.evalSuite.fixturePaths.eviction` |
| `--trajectory` | none | only this trajectory |
| `--json` | flag | also print the per-turn capability snapshot |
| `--model`, `--tier`, `--vendor` | none | [model and tier options](#model-and-tier-options) |
| `--defaults` | flag | ignore the fixture's settings, run on the platform defaults |
| the context-retention options | none | as in `app:ai-agent:chat`; they override the fixture or the defaults |
| `--agentSpaceId` and the application's space options | none | the space of the trajectories that name none |
| `--ignore-budget` | flag | [the budget grant](#the-budget-grant) |

- **Writes:** nothing stored (the conversation is transient).
- **Envelope:** `ENVELOPE.AI.CONVERSATION.TURN`.
- **Exit codes:** `0`; `1` when the fixture lists no trajectory or none ran.
- **Budget:** `--ignore-budget`.
- **Override points:** `trajectoryNamesItsScope()`, `getScopeWordOfSkipLine()`.

### `app:ai-agent:eval-guard` — `AgentEvalGuardCommand`

The injection-guard probe: guard-prompt variants × inputs × runs, each probe one guard model call; reports the
false-block rate and the bypass rate per model and variant.

| Option | Default | Meaning |
|---|---|---|
| `--agentSpaceId` and the application's space options | none | the space whose owner the probes are billed to |
| `--model`, `--tier`, `--vendor` | none | the guard model |
| `--fixture` | none | one fixture; default every `*.json` of `AI.evalSuite.fixturePaths.guard` |
| `--variant` | none | only this variant id |
| `--input` | none | only this input id |
| `--runs` | `'1'` | runs per cell |
| `--parallel` | `'8'` | chunks run by child processes |
| `--full` | flag | print every raw output |
| `--json` | flag | JSON instead of the report |
| `--no-save` | flag | write no run file (a child) |
| `--cells` | none | internal: base64 JSON list of the cells of a child |
| `--guard-file` | none | test this guard system prompt file (variant id `candidate`) |
| `--pass-phrase` | none | override the fixture's pass-phrase |
| `--input-text` | none | inspect this one input (input id `adhoc`) |
| `--input-class` | `AgentEvalGuardRunKnobs::DEFAULT_INPUT_CLASS` (attack) | class of `--input-text`: benign or attack |
| `--ignore-budget` | flag | [the budget grant](#the-budget-grant) |

- **Writes:** `<date>_<time>-<model>-guard-sentinel.json` in the results folder, unless `--no-save`.
- **Envelope:** each probe `ENVELOPE.AI.AGENT.EVALUATION` for the space's owner.
- **Exit codes:** `0`; `1` when no fixture is found or no cell remains after the filters.
- **Budget:** `--ignore-budget`, handed to the children.
- **Child processes:** chunks of cells; ceiling `CHILD_PROCESS_TIMEOUT_SECONDS` = 1200 s.
- **Override points:** none beyond the base (the guard is built with `newInstance()`, so an application's same-path
  guard class runs).

### `app:ai-agent:eval-schedule-runs` — `AgentEvalScheduleRunsCommand`

Runs one recurring task several times in a row in a space of an evaluation account: creates an evaluation schedule,
fires it `--runs` times through the cron's own arm, claim and spawn, waits for each run on the workers and prints
whether each kept the structure of the run before it. Needs running workers.

| Option | Default | Meaning |
|---|---|---|
| `--agentSpaceId` and the application's space options | none | the space; its owner must be an evaluation account |
| `--task` | none | the task text (required unless the application runs without firing) |
| `--title` | `'Eval schedule runs'` | the schedule title |
| `--runs` | `'3'` | runs in a row |
| `--reportChannel` | `AIConversationSchedule::REPORT_CHANNEL_EMAIL` | `EMAIL` or `CHAT` |
| `--timeoutPerRun` | `'900'` | seconds to wait for one run |
| `--keep` | flag | keep the schedule active at the end |
| `--ignore-budget` | flag | [the budget grant](#the-budget-grant) |

- **Writes:** the evaluation schedule (cancelled at the end unless `--keep`) and its run conversations.
- **Envelope:** the runs are driven on the workers.
- **Exit codes:** `0`; `1` without a scope or a task, for refused application options, when the owner is no evaluation
  account, or on an error.
- **Budget:** `--ignore-budget`.
- **Override points:** `configureReportOptions()`, `validateReportOptions()`, `isRunWithoutFiring()`,
  `runInsteadOfFiring()`, `afterRunEnded()`, `describeScope()`, `describeEvalAccountsSource()`,
  `describeRequiredOptions()`.

### `app:ai-agent:eval-compaction-tree` — `AgentEvalCompactionTreeCommand`

The judged evaluation of the compaction tree: a driver keeps a long conversation going with frequent compaction, a judge
rates the tree from the per-turn snapshots.

| Option | Default | Meaning |
|---|---|---|
| `--agentSpaceId` and the application's space options | none | the space of a case file without `agentSpaceId`; the case file's own space wins |
| `--case-file` | none | the JSON case file; required unless `getDefaultCaseFile()` returns one |
| `--agent-model` | none | the agent model |
| `--judge-model`, `--driver-model` | none | default: `AI.evalSuite.judgeModelName` / `driverModelName`, else the package's |
| `--max-turns` | `'14'` | owner turns |
| `--segment-messages` | `'3'` | messages per level-0 leaf |
| `--keep-messages` | `'3'` | recent messages kept raw |
| `--fan-in` | `'2'` | roll-up fan-in |
| `--retention` | `'1'` | per-level retention |
| `--trigger-tokens` | `'200'` | compaction trigger |
| `--persist` | flag | store the conversation and validate the stored build afterwards |
| `--json` | flag | the verdict and snapshots as JSON |
| `--ignore-budget` | flag | [the budget grant](#the-budget-grant) |

- **Writes:** the conversation with `--persist`; nothing stored otherwise.
- **Envelope:** agent turns `ENVELOPE.AI.CONVERSATION.TURN`; driver and judge `ENVELOPE.AI.AGENT.EVALUATION`.
- **Exit codes:** `0` only when the verdict is PASS and, with `--persist`, the stored build validates; `1` otherwise
  (a failed driver call: `eval failed: driver call failed: <model>: <reason>`); `2` without a readable case file or a
  space, or for an unknown `--judge-model` / `--driver-model`.
- **Budget:** `--ignore-budget`.
- **Override points:** `getDefaultCaseFile()`, `getDefaultCompactionParamsTemplate()`,
  `buildCompactionTreeParameters()`, `resolveTreeEvalScope()`, `buildJudgedRunOptions()`, `buildTurnSnapshotHeading()`,
  `getFinalTreeHeading()`. An application's own case-file keys are read through
  `AgentEvalCompactionTreeCaseFile::getInteger()` / `getScalarText()` (the raw `getValue()` is protected).

### `app:ai-agent:eval-analyze` — `AgentEvalAnalyzeCommand`

Offline failure analysis of a stored run in the results folder: classifies each failed case, prints its trace and a
reproduce command, then the count per class. No live call.

| Option / argument | Default | Meaning |
|---|---|---|
| `path` (argument, optional) | — | run file, run folder or run name; default the most recent run |
| `--all` | flag | also list passing cases |

- **Writes:** nothing. **Exit codes:** `0`; `1` when no run is found or the file holds no `cases`.
- **Budget:** no option. **Override points:** `buildReproduceArguments()`.

## Evaluation housekeeping

### `app:ai-agent:eval-conversations-purge` — `AgentEvalConversationsPurgeCommand`

Deletes the conversation trees of the evaluation accounts (`AgentEvalAccountsService`) whose last activity is older than
`--older-than-hours`, with every dependent row, under a time budget and resumable. Dry run unless `--apply`. Works as the
default account for console work.

| Option | Default (from `AIConversationEvalPurgeSettings`) | Meaning |
|---|---|---|
| `--older-than-hours` | `48` | age of the newest activity |
| `--fresh-heartbeat-minutes` | `30` | a younger run heartbeat keeps the tree |
| `--trees-per-batch` | `25` | root conversations per batch |
| `--heavy-rows-per-statement` | `50` | row limit of one write on the large tables |
| `--light-rows-per-statement` | `500` | row limit of one write on the others |
| `--pause-ms` | `100` | base pause after every write |
| `--slow-write-ms` | `2000` | a slower write doubles the pause |
| `--max-seconds` | `240` (`MAXIMUM_SECONDS`, also the cap) | time budget |
| `--max-threads-running` | `40` | MariaDB only: wait while more statements run on the server |
| `--max-history-list-length` | `200000` | MariaDB only: wait while the storage engine's purge backlog is longer |
| `--after-root-conversation-id` | none | start after this root |
| `--only-root-conversation-id` | none | only this root |
| `--accounts` | none | account ids instead of the configured evaluation accounts; any other account is refused unless `--evalOnly` is passed and the purge service allows it (`findAccountIdsAllowedForEvalOnlyPurge()`, none by default) |
| `--evalOnly` | flag | only evaluation-marked trees |
| `--apply` | flag | write |

- **Writes:** deletes with `--apply`; counts otherwise. Every read and every write chunk of the purge carries a 20 s
  statement limit (`AIConversationEvalPurgeService::STATEMENT_TIMEOUT_SECONDS`), every write chunk runs in its own
  transaction with a 2 s lock wait (`LOCK_WAIT_TIMEOUT_SECONDS`); nothing stays set on the connection.
  The time budget is checked before every tree check and every write, never inside a statement.
- **Exit codes:** `0`; `1` when the purge fails. **Budget:** no option.
- **Override points:** `getEvalOnlyOptionDescription()`.

Seed accounts have no command: a case that needs a clean account leases one through `AgentEvalSeedAccountLeasesService`
(a per-host `flock()` on `var/eval-seed-account-leases/<prefix><accountId>.lock`) and resets it through
`AgentEvalSeedAccountResetService`; an application's own evaluation command calls them.

## Decisions

### `app:ai-agent:decision-stats` — `AgentDecisionStatsCommand`

The decision statistics over the turn events for one site and window, evaluation traffic excluded twice; read-only.
Works as the default account for console work.

| Option | Default | Meaning |
|---|---|---|
| `--site` | `'skillPreload'` (`AgentDecisionModelSettings::SITE_SKILL_PRELOAD`) | the decision site |
| `--from` | now − 7 days at `00:00:00` | window start |
| `--to` | now | window end, exclusive |
| `--cutoff` | now − 30 minutes | observation cutoff |
| `--mode` | none | only `shadow` or `on` |
| `--power` | flag | print the accrual arithmetic |
| `--includeEval` | flag | do not exclude evaluation traffic |

- **Writes:** nothing. **Exit codes:** `0`. **Budget:** no option.
- **Override points:** `buildStatisticsRequest()` (the request the options describe), `renderSection()` (how one
  section prints).

### `app:ai-agent:decision-fixtures` — `AgentDecisionFixturesCommand`

Sends synthetic decision calls from a JSON fixture to the decision model and scores each answer (the sub-skill pass, or
`"site": "replyLanguage"`).

| Option / argument | Default | Meaning |
|---|---|---|
| `fixture` (argument, required) | — | absolute, or relative to the application directory |
| `--only` | none | only this case id |
| `--repeat` | `'1'` | calls per case |
| `--show-state` | flag | print the state sent per case |
| `--without-context` | flag | reply-language fixtures: the context-free question |
| `--ignore-budget` | flag | [the budget grant](#the-budget-grant) |

- **Writes:** nothing stored.
- **Envelope:** each call `ENVELOPE.AI.AGENT.EVALUATION` for the owner of the fixture's space.
- **Exit codes:** `0`; `1` for a missing fixture, no case, or an unresolved scope.
- **Budget:** `--ignore-budget`.
- **Override points:** `getFixtureScopeKey()`, `resolveBillingScope()`, `getAgentSpaceFactsKey()`,
  `getDefaultSupportedLanguageCodes()`, `getDefaultReplyLanguageCode()`, `getReplyLanguageArmLabel()`. An override reads
  the application's own fixture keys through `AgentDecisionFixture::getInteger()` / `getScalarText()` (the raw
  `getValue()` is protected).

### `app:ai-agent:jev-probe` — `AgentJevProbeCommand`

Probes a decision-model question on labelled cases before a decision site is built; prints each answer beside its label
and the hit rate per question.

| Option / argument | Default | Meaning |
|---|---|---|
| `fixture` (argument, required) | — | absolute, or relative to the application directory |
| `--floor` | `'0.5'` | a probability answer counts as true from here |
| `--resultFile` | none | write every answer as JSON here |
| `--ignore-budget` | flag | [the budget grant](#the-budget-grant) |

- **Writes:** the `--resultFile`.
- **Envelope:** each case `ENVELOPE.AI.AGENT.EVALUATION` for the space's owner.
- **Exit codes:** `0`; `1` for a missing fixture or an unresolved space.
- **Budget:** `--ignore-budget`.
- **Override points:** `getFixtureAgentSpaceKey()`, `findAgentSpaceOfFixture()`.

### `app:ai-agent:probe-reasoning-replay` — `AgentProbeReasoningReplayCommand`

Live probe of reasoning replay on OpenRouter: a small synthetic tool conversation built in memory (system, two tools,
one owner question), sent with the agent's own composers (`AgentChatCompletionsRequestComposer`,
`AgentResponsesRequestComposer`), the model's catalog provider filters and the agent's OpenRouter key
(`AI.modelCalls.openRouterApiKeyEnvName`). It changes nothing in the agent and persists nothing; it costs real tokens.
Built for the decision whether Anthropic models can get their own reasoning back (Chat Completions, Responses, or a new
Messages wire).

| Option / argument | Default | Meaning |
|---|---|---|
| `--model` | none (required) | catalog name, e.g. `ANTHROPIC.CLAUDE_OPUS_5_5`, `MOONSHOT.KIMI_K3_1_PRO` |
| `--scenario` | `clean` | comma-separated, or `all` (every scenario the wire allows; `model-switch` only with `--switch-model`) |
| `--wire` | `chatCompletions` | or `responses`; a non-OpenAI model on `responses` bypasses the agent's runtime refusal (`REJECTION_RESPONSES_NEEDS_OPENAI_MODEL`) for this probe only, and the output says so |
| `--switch-model` | none | the model of call 2 in `model-switch` |
| `--drop-block` | flag | replaying calls send `thinking.block_binding.prefix_mismatch_behavior: drop_block` |
| `--effort` | `medium` | reasoning effort of every call |
| `--timeout` | `120` | total timeout of one call in seconds (at least 10) |
| `--format` | `table` | or `json` (the `ReasoningReplayProbeResults` object) |

| Scenario | Calls | Verdict |
|---|---|---|
| `clean` | 1: tools → 2: replays call 1's reasoning over the unchanged prefix | PASS accepted; FAIL refused |
| `edit-history` | as clean; before call 2 the owner's earlier message is re-rendered (as the image-marker swap does) | PASS accepted (see `input_transformations`); FINDING refused, with the upstream text |
| `edit-tools` | as clean; call 2 adds a third top-level tool | same |
| `edit-system` | as clean; call 2's system text gains a skill-catalog line | same |
| `model-switch` | as clean; call 2 goes to `--switch-model` with call 1's reasoning | PASS accepted; FINDING refused |
| `forced-tool-choice` | 1: the delivery step's named pin; 2: `auto` plus the `[SIGNAL:tool-expected]` line | PASS pin accepted; FINDING pin refused (the 400 text); FAIL the `auto` form refused |
| `append-only` (`responses` only) | 1: tools → 2: a skill appended (`additional_tools` item + developer message, top-level tools unchanged) with call 1's reasoning → 3: the reasoning of both carried | PASS all accepted; FINDING call 2 or 3 refused |

Every scenario FAILs when call 1 is refused on the agent's own route (on a bypassed Responses route that is a FINDING),
calls no tool, or — for a replay — returns no reasoning items. Per call the table shows HTTP status, provider, the tool
choice sent, tools called, reasoning items back (types, signed or encrypted), items replayed, input / cached / cache-write
tokens, the cache share, the cost OpenRouter reports and the wall time; refused calls print the provider's text with the
upstream's raw message.

- **Writes:** nothing.
- **Envelope:** none — the calls go to OpenRouter directly (`AgentReasoningReplayProbeTransportService`) and are booked on
  no account; the output sums the reported cost.
- **Exit codes:** `0` every scenario PASS or FINDING; `1` a scenario FAILed or the probe stopped; `2` invalid options
  (unknown model or scenario, `append-only` without `--wire=responses`, `model-switch` without `--switch-model`).
- **Budget:** none (no account budget applies).
- **Override points:** `AgentReasoningReplayProbeService::getTransportService()`, `findModel()`; the command's
  `resolveScenarios()`, `renderTables()`.

### `AgentDecisionReplayCommand` (abstract base, no name)

The base of an application's command that replays one decision on stored conversations: for every tool call the
subclass picks, the decision is made again on the messages before the call. The subclass names the command with
`#[AsCommand]`.

| Option | Default | Meaning |
|---|---|---|
| `--conversations` | none | comma-separated conversation ids |
| `--agentSpaceId` and the application's space options | none | the scope of work beyond stored conversations |
| `--resultFile` | none | write every row as JSON here |
| `--showState` | flag | print the input sent per call |
| `--ignore-budget` | flag | [the budget grant](#the-budget-grant) |

- **Writes:** the result file; nothing on a conversation. The decision model's circuit breaker runs in its harness mode
  for the run.
- **Envelope:** each replayed decision `ENVELOPE.AI.AGENT.EVALUATION` for the conversation's account.
- **Exit codes:** `0`; `1` without conversations (default work).
- **Must implement:** `isToolCallReplayed()` (which calls), `replayToolCall()` (one row per call).
- **Override points:** `executeReplayWork()` (work beyond stored conversations), `getMissingConversationsMessage()`,
  `getRowTableHeaders()`, `printRowSummary()`, `resolveBillingScope()`, `openEvaluationEnvelope()`.

## Scheduled maintenance and repair

The cron intervals and the cron list are in `ddd-module-agents-specialist` (its cron template). None of these makes
model calls unless stated.

| Command — class | Options (default) | Writes | Exit codes | Override points |
|---|---|---|---|---|
| `app:ai-conversation:fire-due-schedules` — `AgentFireDueSchedulesCommand` | `--batch` (`AIConversationSchedulesService::DUE_SCHEDULES_BATCH_LIMIT` = 50), `--dry-run` flag, `--ignore-budget` flag (stores `budgetBypass` on every run conversation it spawns) | expires, claims and spawns due schedules | `0` | none beyond the base |
| `app:ai-conversation:mark-finalized` — `AgentMarkFinalizedConversationsCommand` | `--idle-minutes` (`DEFAULT_IDLE_MINUTES` = 60), `--dry-run` flag | `finalizedAt` in one UPDATE | `0` | none |
| `app:ai-conversation:compact-finalized` — `AgentCompactFinalizedConversationsCommand` | `--batch` (`DEFAULT_BATCH_SIZE` = 50), `--dry-run` flag, `--inline` (none; one conversation id), `--rebuild-summary` flag, `--ignore-budget` flag | dispatches finalize jobs; `--inline` runs the chain (model calls under `ENVELOPE.AI.CONVERSATION.COMPACTION`) | `0`; `1` when `--inline` fails | none |
| `app:ai-agent:trace-events-purge` — `AgentTraceEventsPurgeCommand` | `--days` (`RETENTION_DAYS` = 180), `--batch` (`'5000'`), `--dryRun` flag | deletes trace events | `0`; `1` on an error | none |
| `app:ai-agent:turn-events-purge` — `AgentTurnEventsPurgeCommand` | `--days` (`RETENTION_DAYS` = 180), `--batch` (`'5000'`), `--dryRun` flag | deletes turn events and unreferenced snapshots | `0`; `1` on an error | none |
| `app:ai-schedule:close-for-inactive-agent-spaces` — `AgentCloseSchedulesForInactiveAgentSpacesCommand` | `--batch` (`STATE_SWEEP_BATCH_LIMIT` = 200), `--dry-run` flag | closes, pauses or re-arms schedules | `0` | `describeScheduleScope()` |
| `app:ai-conversation:deliver-owed-reports-and-resume-owed-turns` — `AgentDeliverOwedReportsAndResumeOwedTurnsCommand` | `--quiet-seconds` (`AGENT_TURN_OWED_QUIET_SECONDS` = 300), `--batch` (`OWED_CONVERSATIONS_BATCH_LIMIT` = 50), `--dry-run` flag | delivers owed reports, resumes owed turns and lost resumes | `0` | `runApplicationRecoveryPasses()`, `describeApplicationRecoveryPassCounts()` |
| `app:ai-agent:reap` — `AgentReapStuckConversationsCommand` | `--hung-after` (`RUN_HUNG_AFTER_SECONDS` = 300), `--revive-max-age` (`RUN_REVIVE_MAX_AGE_SECONDS` = 1800), `--settle-only` (none; one id) | resumes, expires, times out, settles, notifies | `0` | `closeStaleChannelSessions()`, `getPurposeNamesClosedWithoutAnOwnerTurn()` |
| `app:ai-agent:request-snapshot-debug-payloads-strip` — `AgentRequestSnapshotDebugPayloadsStripCommand` | `--olderThanDays` (`'2'`), `--windowSize` (`'1000'`), `--chunkSize` (`'50'`), `--pauseMs` (`'200'`), `--slowWriteMs` (`'2000'`), `--maxSeconds` (`'1500'`), `--maxThreadsRunning` (`'40'`), `--maxHistoryListLength` (`'200000'`), `--fromMessageId` (none), `--dryRun` flag | removes `sentPayloadJson` / `rawResponseJson`; moves its cursor | `0`; `1` on an error | none |
| `app:ai-agent:loaded-capabilities-compact` — `AgentLoadedCapabilitiesCompactCommand` | `--conversationId` (none), `--fromConversationId` (none), `--batchSize` (`'200'`), `--pauseMs` (`'200'`), `--slowWriteMs` (`'2000'`), `--maxSeconds` (`'1500'`), `--maxThreadsRunning` (`'40'`), `--quietMinutes` (`'30'`), `--dryRun` flag, `--verify` flag | rewrites loaded-capability rows into the chain format | `0`; `1` on an error | none |
| `app:ai-conversation:repair-zero-embeddings` — `AIConversationEmbeddingsRepairCommand` | from `AIConversationEmbeddingsRepairSettings`: `--target` (`all`; `messages`, `nodes`, `memories`), `--batchSize` (25), `--sleepMs` (1000), `--limit` (0 = all), `--scanWindow` (50000), `--maxThreadsRunning` (40), `--maxHistoryListLength` (200000), `--afterMessageEmbeddingId` (`'0'`), `--afterCompactionNodeId` (`'0'`), `--afterMemoryId` (`'0'`), `--newestRowsOnly` (0), `--maxSeconds` (0 = none; a positive value capped at 200), `--dryRun` flag, `--apply` flag | re-embeds zero vectors with `--apply` (embedding calls) | `0`; `1` when the repair failed or stopped on a failure | none |
| `app:ai-conversation:backfill-message-embeddings` — `AIConversationBackfillEmbeddingsCommand` | `--batch` (`'100'`), `--max` (`'0'` = all), `--async` flag | embedding rows, or dispatched embedding jobs | `0` | none |
| `app:ai-conversation:backfill-environment` — `AIConversationBackfillEnvironmentCommand` | `--environment` (`AIConversationsService::ENVIRONMENT_PRODUCTION` = `prod`), `--dryRun` flag | the environment stamp | `0`; `1` for an empty environment or a failed write | none |

## The shared bases

### What `AgentConsoleCommand` sets up

`execute()` builds the progress reporter, grants the budget bypass when asked, then — each only when the subclass asks
for it — authenticates the default account for console work (`usesDefaultAccountForConsoleWork()`, default no), lifts the
entity rights (`liftsEntityRightsRestrictions()`, default no) and opens the run's envelope (`getUsageEnvelopeScope()`,
default null; the account from `findUsageEnvelopeAccountId()`). It runs `executeConsoleWork()` and, whatever throws,
closes the envelope, restores the rights once and authenticates the account of before the run again. A command that
knows the account only inside its work opens one envelope per step: `openUsageEnvelope()` / `closeUsageEnvelope()` in a
`finally`. `actAsAccount($account)` authenticates the account for the next step and answers false for null (the caller
skips the step).

### Scope options

`configureAgentSpaceOptions()` declares `--agentSpaceId` and every option the application's `AgentSpacesService`
returns from `getConsoleScopeOptions()` (an application's own name for the thing a space stands for; the package
declares none). `resolveAgentSpaceFromInput()` resolves them under the rights of the run:

- `--agentSpaceId` must be a positive whole number; an empty value is refused (it never means account level);
- `--agentSpaceId` together with one of the application's options is refused (an option that carries only a default the
  command declared does not count as passed);
- the application's values go to `AgentSpacesService::findAgentSpaceForConsoleScopeOptionValues()`; null refuses them;
- neither given = null = account level.

The refusals are `InvalidOptionException`. The evaluation base calls the same pair `configureEvalScopeOptions()`;
`resolveFallbackAgentSpaceId()` resolves the space of the cases that name none with the rights lifted, and
`buildScopeArgumentsForChild()` hands the options to a child as given.

`configureEvalScopeOptions()` also declares `--accountId` (`AgentEvalConsoleCommand::OPTION_EVAL_ACCOUNT_ID`, a
positive whole number; `resolveFallbackAccountId()`, kept for the run by `rememberFallbackAccountIdOfRun()`): every
evaluation command on the base runs a case without a space at account level for that account, and a case in a space
without an owner for it (a space with an owner keeps its owner). The scope comes from
`AgentEvalRunnerService::resolveEvalScopeWithFallbackAccount()` →
`AgentEvalRunHooksService::resolveEvalScopeWithFallbackAccount()` (the account read by `findAccountOfEvalScope()`);
without the option it is `resolveEvalScope()` unchanged, so a host's override of that method keeps deciding. The
planning (`AgentEvalSuitePlanService::assignScopeOfCaseToTask()`) gives such a case the task scope
`AgentEvalSuiteTask::$accountId` (`getScopeId()` = the negated account id, never a space's id), so a host without
spaces plans its cases; without the option such a case is skipped as before.

### The budget grant

`configureIgnoreBudgetOption()` declares `--ignore-budget`. When it is passed, `execute()` calls
`AIUsageEnvelopesService::grantBudgetBypassForTheProcess()` with `AIBudgetBypassGrant::REASON_CONSOLE_OPTION` before the
work: the cap is lifted for this process, the spend is still booked, the grant ends with the process (the worker reset of
`mgamadeus/ddd-ai` clears it). A child process is a process of its own: the evaluation base hands the option on
(`buildBudgetArgumentsForChild()`). `isBudgetBypassRequested()` is the override point of a command whose option works
the other way round (`app:ai-agent:eval-suite`: `--enforce-budget`). Commands with model calls and no option:
`app:ai-agent:activity-lines`, `app:ai-conversation:search-inspect`, `app:ai-agent:eval-tiers`; `app:ai-agent:diagnose`
lifts for `--drive` only from the conversation's own `budgetBypass`.

### The harness models

`AgentEvalConsoleCommand::execute()` checks every option of `HARNESS_MODEL_OPTION_NAMES` (`--judge-model`,
`--driver-model`) the command declares and the caller passed, before anything of the run happens
(`findUnknownHarnessModelNameRefusal()`, through `AgentEvalJudgedRunService::refuseUnknownModelName()`): a name that is
neither a key of the configuration `AI.models` nor a retired name of `AIModel::RETIRED_MODEL_SUCCESSORS` prints
`--judge-model: The model catalog knows no model "<name>" (…)` and exits `2` (`Command::INVALID`); no case, child
process or plan runs. Covers `eval-tools` (an application's subclass included), `eval-suite` and `eval-compaction-tree`.
A command of the application that is no evaluation command (a plain `Command`) calls `refuseUnknownModelName()` itself
after reading its options.

### Model and tier options

`ModelTierOptionTrait` (from `mgamadeus/ddd-ai`): `addModelTierOptions()` declares `--tier` and `--vendor`, and `--model`
unless the command declared its own. `resolveEffectiveModelName()`: `--model` wins; else `--tier` selects through
`AIModelsService::selectModel()` with `--vendor` as a soft preference; else null (the conversation's default model). An
unknown tier or vendor is an `InvalidOptionException`. `writeModelSelection()` prints which model runs and why.

### Evaluation arm options

`AgentEvalArmOptionsTrait::addEvalArmOptions()` declares, each with a value and no default:
`--decision-model` (`off` = no decision site runs), `--decision-model-reply-language` (`on`/`off`),
`--decision-model-skill-preload` (`on`/`off`), `--decision-model-turn-closing` (`off` or a turn-closing mode),
`--decision-model-plan-dynamics` (`AgentEvalArms::PLAN_DYNAMICS_ARMS`), `--decision-model-reasoning-effort` (`off` or a
floor `low`/`medium`/`high`), `--decision-model-stall-recovery` (`on`/`off`), `--reasoning-effort` (a fixed effort for
every call), `--agent-wire-api`, `--previous-reasoning-carry-over`, `--previous-reasoning-cap-tokens` (a positive count),
`--skill-loading-strategy`, `--rebuild-epoch-on-situation-change` (`on`/`off`), `--state-change-delivery`; and the flag
`--store-snapshot`. Not passed, `default` or `inherit` = the platform default. `resolveEvalArmOptions()` refuses any other
value; `buildEvalArms()` builds the `AgentEvalArms` every conversation of the run is created with;
`buildEvalArmArguments()` hands them to a child. The decision sites need a stored conversation: a transient run never
reaches them. The three context arms share their names with `ContextRetentionSettingsOptionsTrait`; a command uses one
of the two traits for them.

### Stop signals and the STOP file

An evaluation command stops gracefully only when `handlesStopSignals()` answers true (default no; the package's only such
command is `app:ai-agent:eval-suite`). Then SIGINT and SIGTERM are counted by `handleSignal()`: the first asks for a stop
(`isStopRequested()`), the second for the end of everything still running (`isKillRequested()`); `onStopSignal()` is the
hook. `requestStop()` asks for a stop without a signal. The suite reads a file named `STOP` in its run folder as the first
signal (`AgentEvalResultFilesService::isStopRequestedForSuiteRun()`): no new case starts; while cases are queued the queue
is dropped and every running case that changes no live state is ended (a stopped process gets 3 s,
`STOP_TIMEOUT_SECONDS`); running writes finish and restore. Before the first case started, a signal ends the suite with
`128 + signal`.

### Child processes and their ceilings

`buildChildConsoleCommandLine($commandName)` = `php -d memory_limit=2G <root>/bin/console <commandName>`
(`CHILD_PROCESS_PHP_BINARY`, `CHILD_PROCESS_MEMORY_LIMIT`); the name comes from `getChildCommandName()` (default: the
command's own name, so an application's subclass runs its own class in its children).

| Parent | Child | Ceiling |
|---|---|---|
| `app:ai-agent:eval-tools` (repeat or parallel) | itself per case | the case's `timeoutSeconds`, default 600 s, at least 60 s (`AgentEvalSuitePlanService`) |
| `app:ai-agent:eval-suite` | the command of the task's kind | same |
| `app:ai-agent:eval-guard` | itself per chunk of cells | 1200 s |
| a turn worker (with `AI.agentToolCallChildProcess.consoleScript`) | `app:ai-agent:execute-tool-call` | the tool's own `--timeout` (SIGALRM) |

A child that runs past its ceiling is ended and its case counts as failed.

## How an application adds its own command or replaces one

**Add:** a class of the application that extends `AgentConsoleCommand` (or `AgentEvalConsoleCommand`), carries
`#[AsCommand]` with a name of its own, declares its options in `configure()` with the `configure…` methods it needs,
states its needs through the switch methods, and works in `executeConsoleWork()`.

**Replace:** a subclass of the package's command, outside the namespace `DDD\Symfony\Commands\`, that repeats
`#[AsCommand]` with the SAME name (an attribute is not inherited by the console's discovery) and overrides the override
points listed above. What then happens is the compiler pass `FrameworkCommandsYieldPass` of `mgamadeus/ddd`
(`src/Symfony/CompilerPasses/`): it collects every service tagged `console.command` by its names (the tag's `command`
attribute, else the `#[AsCommand]` name, both split at `|` for aliases); for a name registered by more than one service,
it removes every service whose class lies below `DDD\Symfony\Commands\` — but only when at least one of the services lies
outside that namespace. The package's commands live in `DDD\Symfony\Commands\Agents\`, so the application's class wins
regardless of the registration order. Two classes outside the namespace with one name are not resolved by the pass.

A subclass that must also answer to an older name declares both: `name: 'app:ai-schedule:close-for-inactive-agent-spaces|<its old name>'`.
An application that replaces the tool-call child under another name sets `commandName` in its
`config/app/AI/agentToolCallChildProcess.php`; the suite's children follow `getChildCommandNameOfKind()`.

```php
<?php

declare(strict_types=1);

namespace App\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Symfony\Commands\Agents\AgentInspectConversationCommand;
use DDD\Symfony\Commands\Agents\Inspect\ConversationHeaderRow;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'app:ai-agent:inspect', description: 'Prints one stored agent conversation, read-only')]
class ApplicationInspectConversationCommand extends AgentInspectConversationCommand
{
    protected function describeAgentSpaceRow(AIConversation $conversation): ConversationHeaderRow
    {
        // the relations of the space are attached before the header is built
        $agentSpace = isset($conversation->agentSpace) ? $conversation->agentSpace : null;
        if (!$agentSpace instanceof AgentSpace) {
            return parent::describeAgentSpaceRow($conversation);
        }
        return new ConversationHeaderRow(
            'agent space',
            $agentSpace->id . ' (owner account ' . ($agentSpace->accountId ?? '?') . ', time zone ' . ($agentSpace->timezone ?? ConversationHeaderRow::NO_VALUE) . ')'
        );
    }
}
```

## Full class names

`DDD\Symfony\Commands\Agents\{AgentConsoleCommand, AgentEvalConsoleCommand, AgentEvalArmOptionsTrait, ContextRetentionSettingsOptionsTrait, AgentConsoleProgressReporter, AgentDecisionReplayCommand}` ·
`DDD\Symfony\Commands\Agents\Chat\AgentConsoleChatScopeTrait` · `DDD\Symfony\Commands\Agents\Inspect\ConversationHeaderRow` ·
`DDD\Symfony\Commands\AI\ModelTierOptionTrait` (ddd-ai) · `DDD\Symfony\CompilerPasses\FrameworkCommandsYieldPass` (ddd) ·
`DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope` · `DDD\Domain\AI\Entities\UsageLogs\AIBudgetBypassGrant` ·
`DDD\Domain\AI\Services\AIUsageEnvelopesService` · `DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService` ·
`DDD\Domain\AI\Services\Eval\{AgentEvalResultFilesService, AgentEvalSuitePlanService, AgentEvalSeedAccountLeasesService, AgentEvalSeedAccountResetService}` ·
`DDD\Domain\AI\Entities\Evals\AgentEvalArms` ·
`DDD\Symfony\Commands\Agents\MCPCompareToolCallsCommand` ·
`DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons\{ToolCallComparisonScript, ToolCallComparisonSession, ToolCallComparisonRecording}` ·
`DDD\Domain\AI\Services\Eval\{AgentRecordedRunsService, ToolCallComparisonsService}` ·
`DDD\Symfony\Commands\Agents\AgentProbeReasoningReplayCommand` ·
`DDD\Domain\AI\Services\Eval\Probes\{AgentReasoningReplayProbeService, AgentReasoningReplayProbeTransportService}` ·
`DDD\Domain\AI\Entities\Evals\Probes\{ReasoningReplayProbeResult(s), ReasoningReplayProbeCall(s), ReasoningReplayProbeStep, ReasoningReplayProbeRun, ReasoningReplayProbeHttpAnswer}`
