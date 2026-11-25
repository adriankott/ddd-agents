---
name: ddd-module-agents-observability-specialist
description: "Watches, debugs, records, replays and evaluates ddd-agents runs; documents every package console command. Covers the command reference (chat record/replay, inspect, diagnose, cache-diff, run-report, app:agent:eval-*, eval purge, decision commands, app:mcp:compare-tool-calls; options, defaults, exit codes, envelopes, --ignore-budget, --agentSpaceId, STOP file, override points), the eval fixture format (case keys, expect, restore, stateAfterRun, AI.evalSuite, result files), tool-call comparison scripts, seed accounts, surface snapshot (AgentSurfaceSnapshotTestCase), request snapshots, turn and trace events, AGENT_TRACE, printer, eval safety, costs. Use when a turn never answers, a run stays RUNNING, FAILED or AWAITING_DELEGATES, what the model saw is needed, a console command or option is looked up, an eval case or fixture is written, an eval suite runs, a refactor or MCP package change is proved by replay, a command is replaced, a host pins its surface, a test run must not send mail, or cost is measured."
metadata:
  author: mgamadeus
  version: "0.3.0"
  module: mgamadeus/ddd-agents
---

# Agents Observability Specialist

How to see what a run of the agent did and what the model saw, how to reproduce it, and how to measure and evaluate
the agent — with the console commands the package ships and the services and value objects behind them. The package
ships 47 commands (46 listed, plus the hidden tool-call child) on the base `AgentConsoleCommand` (the account of a run,
lifted rights, the budget option, the usage envelope, the progress reporter `AgentConsoleProgressReporter`, the space
options) and, for evaluation, `AgentEvalConsoleCommand` (scope fallback, child processes, stop signals), with the
option traits `ContextRetentionSettingsOptionsTrait` and `AgentEvalArmOptionsTrait` and the arms object
`AgentEvalArms`: the console chat (`app:agent:chat`, `app:agent:chat-tui`) with the record and replay of a run
(`--record-run-for-comparison` / `--replay-run-and-compare` on `AgentRecordedRunsService` and the per-run
`AgentRecordedRunSession` behind the contract `AgentRecordedRunSessionInterface`), inspection and diagnosis
(`app:agent:inspect`, `app:agent:diagnose`, `app:agent:validate-conversation`, `app:agent:cache-diff`,
`app:agent:run-report`, …), the ten evaluation commands (`app:agent:eval-tools`, `app:agent:eval-suite` and
siblings), the tool-call comparison of one MCP package (`app:mcp:compare-tool-calls`), the evaluation purge
(`app:agent:eval-conversations-purge` on `AIConversationEvalPurgeService`), the seed-account lease and clean start
(`AgentEvalSeedAccountLeasesService`, `AgentEvalSeedAccountResetService`), the decision commands
(`app:agent:decision-fixtures`, `app:agent:decision-stats`, the abstract replay base `AgentDecisionReplayCommand`),
the live reasoning-replay probe (`app:agent:probe-reasoning-replay` on `AgentReasoningReplayProbeService`),
the scheduled maintenance and the line editor a console chat reads its input with (`src/Symfony/Commands/Agents/Chat/`).
Every command, option, default and exit code: [console-commands](references/console-commands.md); the fixtures the
evaluation commands read: [evaluation-fixtures](references/evaluation-fixtures.md). The fixtures and the comparison
scripts are the application's.

> Installation, transports, workers and the full host-duty table: `ddd-module-agents-specialist`. How a turn runs,
> is claimed, released and recovered: `ddd-module-agents-loop-specialist`.

## Scope

| This skill | Another skill |
|---|---|
| the console command reference: options, defaults, exit codes, envelopes, override points, replacing a command | the cron list and the host duties around the scheduled commands (`ddd-module-agents-specialist`) |
| the evaluation fixture format, the `AI.evalSuite` configuration, the result files | how a tool declares itself read-only for the eval write block (`ddd-module-agents-tools-specialist`) |
| the symptom table "a turn that never answers" and the read-only diagnosis | run claims, release, recovery mechanics (`ddd-module-agents-loop-specialist`) |
| request snapshots, the reconstructed payload, cache diff, integrity report | what goes into the model input, context reduction (`ddd-module-agents-context-specialist`) |
| turn events, trace events, `AITraceLogTrait` | the task plan and its trace while working on a plan (`ddd-module-agents-loop-specialist`) |
| the console printer, console stream, HTML transcript, TUI view model | the chat HTTP routes and controller traits (`ddd-module-agents-specialist`) |
| record and replay, the recorded-run comparison, the tool-call comparison | delegation and sub-agent runs themselves (`ddd-module-agents-autonomy-specialist`) |
| eval safety: `evalRun`, eval tool blocks, the outbound recipient guard | owner consent and mandates for real runs (`ddd-module-agents-decisions-and-guards-specialist`) |
| characterization tests: the surface snapshot test case, the package's shipped surface | the skill files and tools the surface lists (`ddd-module-agents-skills-and-purposes-specialist`, `ddd-module-agents-tools-specialist`) |
| conversation metrics, cost reads, scorecard worker | usage envelopes and cost summaries of a turn (`ddd-module-agents-loop-specialist`) |
| the control panel statistics, the admin drive and truncate | widgets and the realtime push (`ddd-module-agents-widgets-specialist`) |
| the decision commands (statistics, fixtures, replay base) | decision sites themselves (`ddd-module-agents-decisions-and-guards-specialist`) |

## Rules that always hold

1. **Diagnose read-only first.** `app:agent:diagnose <id>` (without options) replays the resume's two loads and says
   whether a resume would drive a turn — without driving one (`AIConversationResumeDiagnosis::buildFor()`). Run it (and
   read the stored state columns) before you dispatch, retry or edit anything.
2. **A repair is a recovery pass, never a hand-edited row.** The package repairs every stuck state through a service
   method (`findStuckConversationIds()`, `findConversationIdsWithInboundLeftIdle()`, `retryUnansweredTurn()`, …);
   `app:agent:reap` and `app:agent:deliver-owed-reports-and-resume-owed-turns` call them. Writing
   `runState` or `resumeAttempts` by SQL skips the claim fence.
3. **The slim request snapshot is not the payload.** A request snapshot stores model, effort, times, hashes and
   timings. The full sent payload and raw response exist only when `storeRequestSnapshot` was on for that
   conversation before the call; otherwise `reconstructSentPayloadForDisplay()` rebuilds it, and the rebuild shows
   today's system prompt, skills and space state, not necessarily what was sent.
4. **Every console turn opens a usage envelope.** `generateAgentReply()` makes model calls; outside a worker nobody
   opens the envelope for you. The package's commands open one per turn (`ENVELOPE.AI.CONVERSATION.TURN`); an
   application's own command opens it with `openUsageEnvelope()` and closes it in a `finally`. Without one the calls are
   not grouped under the turn and a budget lift granted on the envelope does nothing; the envelope middleware of the
   workers is a host duty of `ddd-module-agents-specialist`.
5. **The synchronous console path never drives a conversation a worker may hold.** `generateAgentReply()` takes no run
   claim. Drive only an IDLE conversation from the console (`app:agent:chat` inline, `app:agent:diagnose --drive`);
   otherwise dispatch (`app:agent:chat --async`, `app:agent:diagnose --retry`) and inspect afterwards.
6. **`--ignore-budget` lifts the cap for this process only.** The spend is still booked; a child process gets the
   option handed on by the evaluation base; `app:agent:eval-suite` lifts by default and keeps the cap with
   `--enforce-budget`. A command without the option runs under the application's budget gate.
7. **An eval run is marked before its first drive.** `RunSettings::$evalRun` decides the tool block, the outbound
   guard's eval context, the report-mail skip and the statistics exclusion. A conversation stamped later has already
   run unguarded. The package's evaluation commands mark every conversation they build.
8. **The outbound guard guards only the send points that call it.** `EvalOutboundRecipientsService` checks a
   recipient when an application's mail, SMS, call, push or webhook sender asks; a sender that does not ask is
   unguarded.
9. **Record and replay in one console process.** The recorders are process statics
   (`ArgusApiOperations::$transportRecording`, `MCPToolExecutorService::$toolResultRecording`,
   `RecordedRunClockAndIdSources`). A job consumed by a worker resets the Argus and tool recorders, so a recording made
   in a worker loses them when the next job starts; the pinned clock and id sources are not reset there. The chat's
   recorded run keeps every dispatched message and runs it in the console process.
10. **The package's evaluation commands run nothing without the application's fixtures.** `AI.evalSuite.fixturePaths`
    names the default fixture of each single command and `AI.evalSuite.suites` the suites `eval-suite` plans; both are
    empty in the package. `app:mcp:compare-tool-calls` likewise reads the application's scripts.
11. **A package command is replaced, not edited.** A subclass outside `DDD\Symfony\Commands\` wins
    (`FrameworkCommandsYieldPass` of `mgamadeus/ddd` removes the package's service); without an `#[AsCommand]` of its
    own it keeps every name, alias and the description of the package command — in the container (Core 3.44.3) and on
    an object built with `new` (`AgentConsoleCommand::__construct()`). Override its documented protected methods. A
    child process runs the subclass because it runs the parent's own name.
12. **No closures in the application's commands** (the package's own rule): pass a value object or call a named
    method; a short callback for `array_map` / `usort` is fine.

## Traps

- `AIConversationResumeDiagnosis::buildFor()` replaces `$conversation->messages` and `compactionNodes` on the object
  you pass. Reload the messages (`findMessagesInOrder()`) before you render a transcript from the same object.
- The recovery selectors read only the rows of the process's own environment
  (`AIConversationsService::currentEnvironment()`); a conversation written under another environment name is never
  recovered by this process.
- `AGENT_TRACE` is read from the raw process environment first; an inline `AGENT_TRACE=1` before the command works,
  and the lines are logged at INFO — a logger configured for errors only drops them.
- `--agentSpaceId` with an empty value is refused, never read as account level; together with one of the application's
  own space options it is refused too.
- An evaluation command prints its progress on the error output; read the standard output for its `--json` document.
- `app:agent:eval-tools` runs a case once more after an infrastructure error (a model transport failure, a deadlock — `AgentEvalSuiteCommand::RETRIED_INFRASTRUCTURE_ERRORS`), as `eval-suite` does; the row keeps its first attempt.
- `app:agent:eval-tools --case=<id>` runs in this process even with `--parallel`; only `--repeat` > 1 forces child
  processes for one case.
- A judged verdict `ERROR` with `judge call failed: <model>: <reason>` is the provider refusing the judge's call, not
  the agent failing; a `--judge-model` / `--driver-model` the catalog does not know exits `2` before anything runs.
- A suite case without `access` that only reads runs with every write tool refused — a judged case whose rubric needs a
  write declares `"access": "write"`.
- A chat replay refuses a recording whose wire settings or passed `--agentSpaceId` differ from the recorded ones, and a
  tool-call comparison refuses a script whose text changed since the recording: record again after such a change.
- A recording made per owner turn needs a turn set on replay (the instance property `turnOrdinal` of the installed
  `ArgusTransportRecording`) and the other way round; the player refuses to match across turns.
- The comparison covers the conversation tree only: a supervisor conversation is outside it, so record without the
  supervisor.
- `AgentConversationScorecardsService` writes nothing by default; the scorecard worker runs and logs `scorecard.done`
  with no row behind it until the application overrides it.

## Minimal template: an application's subclass of a package command

The package's `app:agent:inspect` prints the space of a conversation by its id. An application that knows its space
by something more telling replaces the command under the same name and overrides one point; everything else (options,
rights lifted, exit codes) stays the package's.

```php
<?php

declare(strict_types=1);

namespace App\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Symfony\Commands\Agents\AgentInspectConversationCommand;
use DDD\Symfony\Commands\Agents\Inspect\ConversationHeaderRow;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'app:agent:inspect', description: 'Prints one stored agent conversation, read-only')]
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

The override points of every command and how a new command is added:
[console-commands](references/console-commands.md#how-an-application-adds-its-own-command-or-replaces-one).

## First commands for a question

| Question | Command |
|---|---|
| Talk to the agent, watch every tool call | `app:agent:chat --agentSpaceId=<id> -m "…"` (`--debug`, `--full`, `-i`) |
| Why did this conversation not answer? | `app:agent:diagnose <id>`, then `app:agent:validate-conversation <id>` |
| What is in this conversation? | `app:agent:inspect <id>` (`--snapshots --full` for the sent payloads) |
| Why did the prompt cache miss? | `app:agent:cache-diff <id>` |
| Which tool result is too large? Which skill costs most tokens? | `app:agent:tool-response-stats`, `app:agent:skill-token-map` |
| Did a refactor of the loop change a run? | `app:agent:chat --turns-file=<f> --record-run-for-comparison=<run.json>` before, `--replay-run-and-compare=<run.json>` after |
| Did a change of an MCP package change what the model receives? | `app:mcp:compare-tool-calls --script=<Package> --record=<file>` before, `--replay-and-compare=<file>` after |
| Run the evaluation | `app:agent:eval-suite --dry-run`, then without it; one area: `app:agent:eval-tools --skill=<skill>` |
| Analyse a failed run | `app:agent:eval-analyze [<run>]`, `app:agent:run-report <ids> --log-dir=<folder>` |
| Does a model accept its reasoning back, a forced tool choice, append-only loading on Responses or Messages? | `app:agent:probe-reasoning-replay --model=<name> --scenario=all [--wire=responses\|messages] [--switch-model=<name>]` (real calls, nothing persisted) |
| Did the provider drop thinking blocks of a Messages call; what did thinking cost per call? | `app:agent:cache-diff <id>` (column `thinking`), `app:agent:inspect <id> --snapshots` (the `messages wire:` line), `app:agent:run-report <ids> --usage` |
| Clean up evaluation conversations | `app:agent:eval-conversations-purge` (dry run), then `--apply` |

## Task or symptom → reference

**Application developer**

| Task or symptom | Reference |
|---|---|
| Which command does it, which options and defaults, what it writes, its exit codes, its envelope, `--ignore-budget` | [console-commands](references/console-commands.md) |
| Replace a package command or add one on the shared bases; scope options, arms, stop file, child processes | [console-commands](references/console-commands.md#the-shared-bases) |
| Write an evaluation case or fixture: case keys, `expect`, `restore`, `stateAfterRun`, judged keys, suite lanes; configure `AI.evalSuite`; read the result files | [evaluation-fixtures](references/evaluation-fixtures.md) |
| The owner wrote and the agent never answers; a run stays RUNNING, IDLE, FAILED, QUEUED or CANCELLED; a parent waits for its sub-agents forever | [a-turn-that-never-answers](references/a-turn-that-never-answers.md) |
| What did the model see on this call; which tools were offered; why did the cache miss | [debugging-a-turn](references/debugging-a-turn.md) |
| Adapt the console chat, build an HTML transcript or a TUI on the printer | [console-chat-on-the-printer](references/console-chat-on-the-printer.md) |
| Record a run and replay it against the recording to prove a refactor; drive the session from another harness | [record-and-replay](references/record-and-replay.md) |
| Prove an MCP package change with a comparison script; adapt the comparison session, its scope, its recorders or its stored keys | [record-and-replay](references/record-and-replay.md#the-tool-call-comparison) |
| Keep a test run from writing to a real profile, mailbox or phone | [eval-safety](references/eval-safety.md) |
| Agent time, owner time, tool failures, cost per conversation, scorecards | [metrics-and-cost](references/metrics-and-cost.md) |
| The runner, the judged run, the suite's parts and their override points | [building-evaluation-in-the-host](references/building-evaluation-in-the-host.md) |
| Run a judged case (a simulated owner and a judge verdict) or a compaction-tree run; adapt its prompt files or override points | [building-evaluation-in-the-host](references/building-evaluation-in-the-host.md) § The judged run |
| Run or adapt an evaluation command (`eval-tools`, `eval-suite`, `eval-selection`, `eval-recall`, `eval-tiers`, `eval-analyze`, `eval-eviction`, `eval-guard`, `eval-schedule-runs`, `eval-compaction-tree`); configure its fixtures and suites; stop a running suite | [building-evaluation-in-the-host](references/building-evaluation-in-the-host.md) § The evaluation commands |
| Score a decision site against a fixture, read decision statistics, replay stored tool calls against a decision (`decision-fixtures`, `decision-stats`, `AgentDecisionReplayCommand`) | [building-evaluation-in-the-host](references/building-evaluation-in-the-host.md) § The evaluation commands |
| Delete old evaluation conversations; give each parallel case its own seed account and a clean start; add the application's tables to the purge | [building-evaluation-in-the-host](references/building-evaluation-in-the-host.md) § Seed accounts, clean start and the purge |
| Build an admin control panel; drive, edit or branch a conversation as an admin | [control-panel-and-admin-drive](references/control-panel-and-admin-drive.md) |
| Read the turn events and the trace events of a conversation | [trace-and-turn-events](references/trace-and-turn-events.md) |
| Characterization tests: `AgentSurfaceSnapshotTestCase` — pin what the application's agent is offered (skills, packages, tools, parameters, wire schemas, purposes, bundles); subclass it, the accepted-differences file, the regenerate switch `REGENERATE_AGENT_SURFACE_SNAPSHOT`, reading a failure | [characterization-tests](references/characterization-tests.md) |

**Package maintainer**

| Task | Reference |
|---|---|
| Add or change a command, an option or a default; keep the command reference in step | [console-commands](references/console-commands.md) |
| Add a case key or an `expect` key; keep the fixture reference in step | [evaluation-fixtures](references/evaluation-fixtures.md) |
| Add a symptom or a diagnosis field; keep the diagnosis in step with the resume | [a-turn-that-never-answers](references/a-turn-that-never-answers.md) |
| Add a turn-event kind or a trace-event type; keep the writers fail-open | [trace-and-turn-events](references/trace-and-turn-events.md) |
| Add a column the comparison must ignore, a timing key or an id family | [record-and-replay](references/record-and-replay.md) |
| A new outbound channel or a new eval marker on `RunSettings` | [eval-safety](references/eval-safety.md) |
| Change a metrics definition (bump `AIConversationMetrics::METRICS_VERSION`) | [metrics-and-cost](references/metrics-and-cost.md) |
| Change the printer, the transcript or the view model | [console-chat-on-the-printer](references/console-chat-on-the-printer.md) |
| A package tool, skill or purpose changed and `AgentPackageSurfaceTest` fails: regenerate the shipped `agent-package-surface.json` | [characterization-tests](references/characterization-tests.md#for-the-package-maintainer) |

## Cross-references

- `ddd-module-agents-specialist` — installation, override points (`AgentConversationScorecardsService`,
  `AgentEvalOutboundGuardHooksService`, `AgentEvalAccountsService`, the printer alias), the host-duty table and the
  cron list of the scheduled commands.
- `ddd-module-agents-loop-specialist` — run claims, release, failed turns that stay owed, the recovery passes, the
  task plan and its trace, usage envelopes and cost summaries.
- `ddd-module-agents-context-specialist` — what the model sees on each call, the prompt cache and the stable prefix,
  context reduction.
- `ddd-module-agents-autonomy-specialist` — delegation, sub-agent deadlines, owner reports, schedules.
- `ddd-module-agents-decisions-and-guards-specialist` — decision sites and their statistics, injection screening.
- `ddd-module-agents-tools-specialist` — tool attributes such as `MCPToolEffect`, which the eval read-only block reads.
- `ddd-module-agents-widgets-specialist` — the realtime signal a frontend waits for.
- `ddd-cli-command-specialist` (ddd) — the console command pattern and the framework's own commands.
