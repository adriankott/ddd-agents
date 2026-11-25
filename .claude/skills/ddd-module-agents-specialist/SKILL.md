---
name: ddd-module-agents-specialist
description: "Installs, wires and runs the agent of mgamadeus/ddd-agents in a DDD host and routes to the nine agent topic skills. Covers the installation checklist, chat controller traits, the agent space (agentSpaceId, account level, its host entity), same-path overrides, entitlements, listeners, the config files, host wording, persona and egress, the six Messenger transports, the usage envelope middleware, workers and stop grace, the package's console commands (schedules, purges, diagnosis, chat replay, evaluation), the installation check app:agent:check-installation, host duties, the cron list, table names, persisted class names, the package suite, source ownership. Use when adding the agent to an application, checking an installation after an update, wiring agent transports or workers, schedules never fire, a crashed turn never answers, agent event tables grow, overriding an agent service, naming agent tables, or asking which agent skill explains a file."
metadata:
  author: mgamadeus
  version: "0.2.0"
  module: mgamadeus/ddd-agents
---

# Agents module: install, wire and run

The agent of `mgamadeus/ddd-agents`: conversations, turns, tools, skills, purposes, widgets, delegation and schedules
on top of `mgamadeus/ddd-ai`. This skill answers **how a host installs, wires and runs it, and what the host must
provide**, and routes every other question to one of nine topic skills.

> Base patterns: the core skills of `mgamadeus/ddd` (services, entities, endpoints, message handlers, console
> commands). Models, prompts, usage envelopes and the language-model trait: `mgamadeus/ddd-ai`. Argus repositories:
> `mgamadeus/ddd-argus`.

## Scope

This skill owns the module class, agent spaces, entitlements and their interfaces, the scope filter, the read rights
of the agent tables, environments, the seam the core asks about live calls (the voice channel itself is the voice
skill's), source-root discovery, the customer's model choice,
transports and handler routing, the worker state resetter, listeners, table names, the chat controller traits and
their DTOs, the naming, wording, persona and egress configs, and `examples/`. The full file list is the manifest
[references/source-ownership.tsv](references/source-ownership.tsv).

## Rules that always hold

1. **The package ships commands; the host runs them.** The commands that fire schedules, finalize conversations,
   purge the event tables, sweep schedule state, deliver owed reports and resume owed turns, reap dead workers and
   stale sub-agent groups, maintain old rows, chat and inspect a conversation are the package's (section "Console
   commands the package ships"); a scheduled one runs only from the cron line the host registers
   (`templates/crons.sh`). Without the cron lines unattended work never runs, a crashed turn never answers and the
   event tables grow without bound. A host adapts a command by a subclass with the same name and its override points;
   contracts: [references/host-duties.md](references/host-duties.md).
2. **The host provides the envelope middleware.** Every agent message that makes model calls declares its usage scope
   with `WithAIEnvelope` (all but `AIConversationReportEmailSendMessage`);
   no package ships the Messenger middleware that opens it. Without it the worker's model calls run outside any
   envelope: none is attributed to one, and the handlers' budget lifts (`grantBudgetBypassIfAnEnvelopeIsOpen()`) do
   nothing, so a host budget gate refuses compaction, finalize, summary and screening for an account over budget. The
   calls throw only when the host's own `AIModelCallGateService` requires an envelope.
3. **The framework registers no agent transport.** The host copies `AgentMessengerTransports::ALL` and
   `AgentMessengerTransports::ROUTING_BY_MESSAGE_CLASS` into its `messenger.yaml`; an unrouted message runs
   synchronously in the request that dispatched it.
4. **One turn per message.** The turn handler's PHP time limit is 1800 s of execution time, but model and tool waits
   do not count toward it, so a turn can run longer by the clock. The stop grace of the turn workers and
   `busySilentSeconds` must exceed 1800 s at least, or a deploy kills running turns.
5. **Overrides by same-path subclass, reached through the container.** `App\Domain\AI\…\X` replaces
   `DDD\Domain\AI\…\X`; build entities with `X::newInstance()`, services with `DDDService::getService()`, never `new`.
6. **A host config file replaces the package's whole.** Keys are never merged; copy the file, then change it.
7. **An empty `agentSpaceId` means account level**, never "every space". The package never creates a space.
8. **Envelopes are opened by the caller.** The chat traits open one when none is open, except the start route, which
   the host declares and wraps itself; the turn handler opens a fallback one. Whether a call without an envelope is
   refused is the host's policy (its `AIModelCallGateService`), not the package's.
9. **No application words in the package.** The host declares its words in `agentApplicationWording.php`, its names in
   `agentApplicationNames.php`, its persona in `agentPersona.php`.
10. **Code rules of the module:** no closures in regular methods, no `private`, no `final`, `declare(strict_types=1)`,
    one class per file, classes only in `AgentsModule::OWNED_NAMESPACES`.

## Traps

- **Core's `Cache::get()` answers a miss with `false`, not null** (package maintainers). A read that compares with
  null takes every miss for a hit; read with a type check (`is_array`, `is_numeric`, `instanceof`) or treat `false`
  and null as a miss (`AgentClientToolRequestsService::isCacheMiss()`). The package's cache double
  (`InMemoryCacheGroupDouble`) answers a miss with `false` as well.
- **Model regeneration is the host's script.** A host with `DATABASE_TABLE_PREFIX` or `tableNames` entries adds
  `app:generate-doctrine-models-for-entities` to its own Composer `post-install-cmd` and `post-update-cmd` (no package
  declares a script) and never runs Composer with `--no-scripts`: the vendor models keep the shipped names, and every
  raw statement of the module hits a missing table.
- **The space lazy-load lifts the rights for every repository.** A host's space repository reads no other entity
  while it maps a row.
- **An MCP route without a firewall** publishes the tool catalogue: `initialize` and `tools/list` check nothing.
- **A host root namespace other than `App\`** gets no automatic same-path override and no folder discovery: it maps
  every class it replaces in its `services.yaml` and binds tool packages by class
  ([references/override-points.md](references/override-points.md)).
- **`QueryOptionsTrait` is not inherited.** A host subclass of a filterable entity or set repeats it.
- **Class attributes are not inherited either.** A host subclass of the conversation repeats the module's
  `#[DatabaseIndex]` lines and `#[LazyLoadRepo]` verbatim, or its model has none of the module's indexes (the exact
  list: [references/override-points.md](references/override-points.md), "Rules for every override").
- **One more request parameter needs no redeclared route.** A host's same-path subclass of the request DTO is what the
  routed trait methods read (`getRequestDtoClassOfRoutedMethod()`); only the generated API document keeps the module's
  DTO.
- **The finalize and schedule scans are not environment-scoped.** Several environments on one database run those
  commands in one environment only.
- **`AuthService::setAccount()` takes its argument by reference:** pass a variable, never a literal `null`.
- **The framework's admin role reads every agent row**, of any account (`Account::isAdminWithAccessToAllResources()`).
  A host whose scoped users hold that role narrows the module's own predicate
  `AgentAccountRightsService::isAccountReaderOfAllAgentRows()` (or one table's,
  [references/agent-space.md](references/agent-space.md), "Read rights"); every "reads all" rule of the module asks it.

## Minimal template: the host's chat controller

```php
namespace App\Presentation\Api\Agents\Controller;

use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Presentation\Api\Agents\Conversations\Controller\AgentConversationReadsTrait;
use DDD\Presentation\Api\Agents\Conversations\Controller\AgentConversationStartTrait;
use DDD\Presentation\Api\Agents\Conversations\Controller\AgentConversationWritesTrait;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationStartWithMessagePostRequestDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationStartWithMessagePostResponseDto;
use DDD\Presentation\Base\Controller\HttpController;
use DDD\Presentation\Base\OpenApi\Attributes\Summary;
use DDD\Presentation\Base\Router\Routes\Post;
use DDD\Presentation\Base\Router\Routes\Route;

#[Route('/conversations')]
class AgentConversationsController extends HttpController
{
    use AgentConversationReadsTrait, AgentConversationWritesTrait, AgentConversationStartTrait;

    #[Post('/start')]
    #[Summary('AIConversation Start')]
    public function startConversation(
        AIConversationStartWithMessagePostRequestDto $requestDto,
        AIConversationAgentService $aiConversationAgentService
    ): AIConversationStartWithMessagePostResponseDto {
        $this->authorizeConversationAccess();
        $aIUsageEnvelopeContext = $this->openConversationEnvelopeIfNoneIsOpen(AgentAIUsageScope::ENVELOPE_AI_CONVERSATION_TURN);
        try {
            // null = account level; a host with spaces passes the space the request names
            return $this->buildConversationStartedResponse($requestDto, $this->findAgentSpaceOfStartRequest($requestDto), $aiConversationAgentService);
        } finally {
            $this->closeConversationEnvelope($aIUsageEnvelopeContext);
        }
    }
}
```

Besides this controller: an authentication subscriber that calls `AuthService::setAccount()`, the transport block,
the two middlewares, the host commands the package does not ship and the cron list. The reference kernel (`examples/reference-kernel`) is a
complete host without spaces that runs only inside the package (its bootstrap loads the package's test autoloader);
an application copies its `src/` and `config/` as a model and writes its own bootstrap.
[references/installation.md](references/installation.md) is the checklist.

## Which topic skill answers which question

| Question | Skill |
|---|---|
| How does a turn run from the owner's message to the answer? Run claims, release, failed turns, message writes, the task plan, cost summaries | `ddd-module-agents-loop-specialist` |
| What does the model see on each call? System prompt, prompt cache, context reduction, skill eviction, recall and search, memories, owner presence and time | `ddd-module-agents-context-specialist` |
| How do I write an agent skill or a purpose, bind it to tools, gate it, override a package skill? Done criteria, variants | `ddd-module-agents-skills-and-purposes-specialist` |
| How do I write an MCP tool the agent and an outside client can call? Attributes, DTOs, results, time limits, parallel calls, closed owner questions | `ddd-module-agents-tools-specialist` |
| How does the agent show something in the conversation and read the owner's answer? Widgets, the composed view, signals, realtime push, external agents | `ddd-module-agents-widgets-specialist` |
| How does the agent work without the owner? Delegation, schedules and their fire contract, eligibility, mandates, the supervisor, owner reports | `ddd-module-agents-autonomy-specialist` |
| How do the decision layer and the guards work? Decision sites, injection screening, owner consent, circuit breaker | `ddd-module-agents-decisions-and-guards-specialist` |
| How do I watch, debug, record and replay, and evaluate a run? A turn that never answers, diagnostics, trace and turn events | `ddd-module-agents-observability-specialist` |
| How does the owner talk to the agent, and how does the agent ring the owner? The optional voice channel: its switch and suggested library, calls and their store, admission, the session worker and its transports, the routes, the ring, the admin list of calls | `ddd-module-agents-voice-specialist` |

A topic two skills touch has one owner: firing schedules and eligibility are explained in the autonomy skill (here:
the command and its cron line only); parallel tool calls in child processes in the tools skill (here: the package's
child-process command and the host's console script); a turn that never answers in the observability skill (here:
the recovery commands); a voice call a dead worker left running in the voice skill (here: the reap command that
closes it).

## Task or symptom → reference

### Host developer

| Task or symptom | Reference |
|---|---|
| Add the agent to an application; the first request | [installation.md](references/installation.md) |
| Check an installation after an update; a finding of `app:agent:check-installation`; add a check from another package | [installation-check.md](references/installation-check.md) |
| Replace an agent service, gate by flags or rights, add a listener, join a host table into a statement | [override-points.md](references/override-points.md) |
| Which config file does what, which skill explains it; the host's words, persona, egress; environment variables | [config-map.md](references/config-map.md) |
| Configure transports, routing, middlewares, worker pools, stop grace, retries | [transports-and-workers.md](references/transports-and-workers.md) |
| A worker's model calls are attributed to no envelope, or refused over budget although the handler lifts the budget | [transports-and-workers.md](references/transports-and-workers.md) |
| Schedules never fire; owed reports never arrive; a turn whose worker died never answers; conversations never finalize; event tables grow without bound | [host-duties.md](references/host-duties.md) |
| Which console commands the package ships and which need a cron line | section "Console commands the package ships" below |
| Adapt a package command by its override points; register the cron list | [host-duties.md](references/host-duties.md), `templates/crons.sh` |
| Model "what the agent works for": spaces, account level, starting a conversation for a space, tools that name a space | [agent-space.md](references/agent-space.md) |
| Missing agent tables, wrong table names, a table prefix, a renamed persisted class | [tables-and-persisted-names.md](references/tables-and-persisted-names.md) |
| Build the first tool, skill, purpose and widget | [first-capability-end-to-end.md](references/first-capability-end-to-end.md) |
| Start a purposed conversation, with or without an agent space | [first-capability-end-to-end.md](references/first-capability-end-to-end.md) |

### Package maintainer

| Task or symptom | Reference |
|---|---|
| Run the package suite; a contract or kernel test fails | [package-suite.md](references/package-suite.md) |
| Which skill must change when a file changes; a file without an owner; a dead rule | [source-ownership.md](references/source-ownership.md) |
| Rename a persisted class or a message class | [tables-and-persisted-names.md](references/tables-and-persisted-names.md) |

## Console commands the package ships

All on the base `AgentConsoleCommand`, in `src/Symfony/Commands/Agents/`. Shipping a command does not run it: a
command with an interval below runs only from the cron line the host registers (`templates/crons.sh`). An application
replaces a command by a subclass outside the namespace `DDD\Symfony\Commands` that repeats the `AsCommand` attribute
with the same name.

| Command | What it does | On a schedule | Writes |
|---|---|---|---|
| `app:agent:fire-due-schedules` | expires, claims and spawns the due schedules; counts each skip reason | every minute to every five minutes | yes |
| `app:agent:mark-finalized-conversations` | sets `finalizedAt` on conversations idle past the window (`--idle-minutes`, default 60), one UPDATE | every five minutes | yes |
| `app:agent:compact-finalized-conversations` | dispatches one finalize job per finalized conversation that owes its completion compaction (`--batch`, default 50); `--inline` runs the chain of one conversation in the console | every five minutes | yes (`--inline` makes model calls) |
| `app:agent:trace-events-purge` | deletes trace events older than `--days` (default 180) in batches | daily | yes |
| `app:agent:turn-events-purge` | deletes turn events older than `--days` (default 180), then the snapshots no event references | daily | yes |
| `app:agent:close-schedules-for-inactive-agent-spaces` | the schedule state sweep: closes, pauses or re-arms schedules whose space or account changed its automation state | hourly | yes |
| `app:agent:deliver-owed-reports-and-resume-owed-turns` | delivers owed reports late, resumes owed turns and inbound messages left idle; override points for the host's own passes | every five minutes | yes |
| `app:agent:reap` | wakes or expires runs whose worker died, times out sub-agent groups, closes voice calls a dead worker left running (only while voice is switched on), wakes parents of settled groups, settles purposed conversations closed without an owner turn, re-sends lost run-end notifications | every five minutes | yes |
| `app:agent:chat` | the console chat with the agent (ephemeral or stored, `--conversationId` resumes, turns file, slash commands); `--record-run-for-comparison` / `--replay-run-and-compare` record a run and compare a replay with it (observability skill, console-commands reference) | no | yes (makes model calls) |
| `app:agent:chat-tui` | the full-screen chat (suggested `php-tui/php-tui`; without it one line names the library) | no | yes (makes model calls) |
| `app:agent:inspect` | prints one conversation: header, summary, transcript, request snapshots | no | no |
| `app:agent:jev-probe` | runs the decision probe over a fixture, one evaluation envelope per case | no | no (makes model calls) |
| `app:agent:conversation-scorecards` | selects conversations and dispatches (or runs `--inline`) their quality scorecards; `--print` the metrics | no | yes |
| `app:mcp:documentation` | prints the MCP documentation of a bundle or package; `--call` runs one tool for a space | no | only with `--call` |
| `app:mcp:clear-cache` | clears the cached MCP documentation of the bundles and skill packages, then the container cache in a child process (a failed child ends it non-zero); `--onlyPackageCaches` leaves the container to the application's own cache reset | no | no |
| `app:agent:export-composed-view-node-schema` | writes or checks the composed-view node schema file (`--check`) | no | yes, unless checking |
| `app:agent:request-snapshot-debug-payloads-strip` | removes the full request and raw response from request snapshots older than `--olderThanDays` (default 2); resumes from a cursor | optional, nightly, `--maxSeconds` below 360 | yes |
| `app:agent:loaded-capabilities-compact` | rewrites loaded-capability rows that carry their complete set into checkpoints and change records; resumes from a cursor | optional, nightly, `--maxSeconds` below 360; only once every reader reads the chain format | yes |
| `app:agent:repair-zero-embeddings` | re-embeds (or deletes) embeddings that hold the zero vector: messages, compaction nodes, the host's memory tables | optional; no interval in the code | dry run unless `--apply` |
| `app:agent:backfill-message-embeddings` | embeds the messages that have no embedding row; `--async` dispatches them to the embedding worker | no (on demand) | yes |
| `app:agent:backfill-conversation-environment` | stamps conversations without an environment with one (`--environment`) | no (once) | yes (`--dryRun` counts) |
| `app:agent:execute-tool-call` | hidden: executes one persisted tool call in a child process and writes its outcome into the call row | no; the turn worker starts it when the host declares `consoleScript` | yes |
| `app:agent:diagnose` | reconstructs the resume decision of one conversation; `--drive` drives one turn in the console, `--retry` retries a FAILED run | no | no (`--drive`, `--retry` write) |
| `app:agent:check-installation` | checks the installation: bindings, unbound package skills, rights held by nobody, skill rights, skill-only rights, rights declared outside packages, tool overlays, missing tables, renamed config keys, package command names and the class each runs, the skill lint, pending renames, added checks; `AgentInstallationCheckTestCase` runs it in the host's suite; `--format=json`; exit 1 on a BLOCKING finding ([references/installation-check.md](references/installation-check.md)) | no; after every update and in CI | no |
| `app:agent:validate-conversation` | checks the integrity of stored conversations; exit 1 on a violation | no | no |
| `app:agent:cache-diff` | per model call: where the request first differs from the previous one, the cache credit, the cause | no | no |
| `app:agent:activity-lines` | replays the tool calls of a conversation through the activity narrator and prints the lines per model | no | no (makes model calls) |
| `app:agent:tool-response-stats` | ranks tools by stored result size; the largest results of one tool; one call's payload | no | no |
| `app:agent:skill-token-map` | the per-turn token size of every agent skill, by package and tool, on the wire schema | no | no (no database) |
| `app:agent:context-eviction-sim` | simulates the per-tool eviction pass (`unloadGranularity = tool`) over scripted scenarios; not the skill-granular default | no | no (no database, no model call) |
| `app:agent:run-report` | the per-run report of stored conversations: judge verdict and findings from the evaluation logs (`--log-dir`), wall and agent seconds, model and tool calls, the time split with the parallel gain, the slowest tools, the variants timeline, the plan size, the suite total | no | no |
| `app:agent:compact` | runs one compaction round of a conversation in the console and prints the tree and the cover; `--show-only` renders only | no | yes, unless `--show-only` (makes model calls) |
| `app:agent:conversation-search-inspect` | runs the conversation search stage by stage, rights off and on, inside the search envelope | no | no (makes model calls; `--embed` writes embeddings) |
| `app:agent:eval-tools` | runs the tools cases of the host's fixtures, deterministic or `--judge`; in this process or in child processes; result files and a stored run (observability skill, console-commands reference) | no | yes (makes model calls) |
| `app:agent:eval-suite` | every configured suite on every model and flavor, one child process per case, lanes per space and resource; correctness and performance against a baseline; stops on a `STOP` file (observability skill, console-commands reference) | no | yes (its children make model calls) |
| `app:agent:eval-selection` | whether the expected skill was loaded; accuracy (observability skill, console-commands reference) | no | yes (makes model calls) |
| `app:agent:eval-recall` | seeds past conversations, asks on a fresh one, checks the recall tool and the facts; deletes the seed (observability skill, console-commands reference) | no | yes (makes model calls) |
| `app:agent:eval-tiers` | the tools cases under the model of each tier (observability skill, console-commands reference) | no | yes (makes model calls) |
| `app:agent:eval-analyze` | classifies the failed cases of a stored run offline and prints reproduce lines (observability skill, console-commands reference) | no | no |
| `app:agent:eval-eviction` | multi-skill trajectories on one transient conversation; the load curve (observability skill, console-commands reference) | no | no (makes model calls) |
| `app:agent:eval-guard` | the injection guard probe: variants × inputs × runs; false-block and bypass rate (observability skill, console-commands reference) | no | a run file (makes model calls) |
| `app:agent:eval-schedule-runs` | fires one evaluation schedule several times and compares its reports; needs the workers (observability skill, console-commands reference) | no | yes |
| `app:agent:eval-compaction-tree` | a judged long run with frequent compaction from a case file (`--case-file`); judges the compaction tree per turn and at the end (observability skill, console-commands reference) | no | yes (makes model calls; stores the conversation only with `--persist`) |
| `app:agent:eval-conversations-purge` | deletes the conversation trees of the configured evaluation accounts older than `--older-than-hours` (default 48) with every dependent row, in bounded chunks under a time budget below 360 s; any other account only with `--evalOnly` and the host's allowance (observability skill, console-commands reference) | optional, e.g. hourly | only with `--apply` (dry run counts) |
| `app:agent:decision-fixtures` | sends the cases of a JSON decision fixture to the decision model and scores the picks (observability skill, console-commands reference) | no | no (makes model calls) |
| `app:agent:decision-stats` | decision statistics over the turn events: per site, mode, arm, day; shadow agreement (observability skill, console-commands reference) | no | no |
| `AgentDecisionReplayCommand` (abstract, no name) | the base of a host's replay of stored tool calls against one decision (observability skill, console-commands reference) | no | a result file (makes model calls) |
| `app:mcp:compare-tool-calls` | runs an MCP package's comparison script in-process: `--record` writes the recording, `--replay-and-compare` lists every difference against it (exit 1) (observability skill, console-commands reference) | no | the recording files; the tools write as in a conversation |

The package ships the chat with its record and replay, the evaluation commands and the tool-call comparison; the host
writes its evaluation fixtures, its comparison scripts and, when it needs one, a decision replay command on the abstract
`AgentDecisionReplayCommand`. For the commands above it writes subclasses only where it needs an override point
([references/host-duties.md](references/host-duties.md)); every option, default, exit code and override point:
the console-commands reference of `ddd-module-agents-observability-specialist`.

## Templates

The folder [templates/](templates/) holds the files a host copies:

| File | For |
|---|---|
| `templates/AgentUsageEnvelopeMiddleware.php`, `templates/AgentConversationOverheadCostsMiddleware.php` | the worker middlewares |
| `templates/messenger.yaml` | the transport block; the two voice transports as a commented block |
| `templates/crons.sh` | the cron list: the package's scheduled commands and the three host commands above |

Each PHP template names its placeholders in its first lines (`// placeholder {{NAME}} sample: Value`); with the
samples filled in it passes `php -l` and calls only services of the package and its dependencies.

## Cross-references

- `ddd-module-ai-specialist` (ddd-ai): models, prompts, usage envelopes, budget grants.
- `ddd-message-handler-specialist`, `ddd-cli-command-specialist`, `ddd-endpoint-specialist`,
  `ddd-entity-specialist`, `ddd-rights-specialist` (ddd): the framework patterns the host code follows.
- `ddd-module-argus-specialist` (ddd-argus): the Argus load behind the agent's model call.
