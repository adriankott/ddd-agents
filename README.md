# mgamadeus/ddd-agents

Status: pre-release (0.1.0-alpha). Nothing here is stable API yet.

The agentic loop for `mgamadeus/ddd`, on top of `mgamadeus/ddd-ai`: conversations, messages, compaction, the task
plan, skills and MCP metadata, purposes and done criteria, widgets and signal sources, delegation and sub-runs, the
supervisor, run claims, the decision layer, the message handlers of the loop and the MCP tool executor. Optional and
switched off by default: the voice channel (the owner talks to the agent in the application's window).

## Layout

| Path | Content |
|---|---|
| `src/Modules/Agents/AgentsModule.php` | the `DDDModule` (source and config path, public service namespaces, exclude patterns) |
| `src/Domain/AI/…` | the agent core, in the namespace `DDD\Domain\AI\…` shared with `ddd-ai` (no class name collides) |
| `src/Presentation/…` | the agent console printer, the JSON-RPC base controller `MCPController`, `MCPToolExecutorService` |
| `src/Domain/AI/…/Voice/`, `src/Presentation/…/Voice/` | the optional voice channel: a folder `Voice/` in every layer and nothing outside it (see "Voice") |
| `config/app/AI/` | the module's defaults: application names and wording, egress, persona, the tool-call child process, context retention (an application overrides a file under its own `config/app/AI/`) |
| `src/Testing/Agents/` | test hooks an application's suite extends (`AgentSurfaceSnapshotTestCase`, `AgentInstallationCheckTestCase`); they need PHPUnit and are never services |
| `tests/` | the package's own suite: unit, contract, integration (a local MariaDB) and the reference-kernel smoke test |
| `examples/` | `reference-kernel/` (a minimal host without an application) and `workers.yaml` (one consumer per transport) |
| `.claude/skills/` | the developer skills: the entry skill `ddd-module-agents-specialist` and nine topic skills |

## Words per layer

| Layer | Name |
|---|---|
| Composer, npm, repository | `mgamadeus/ddd-agents`, `@mgamadeus/ddd-agents`, `ddd-framework/ddd-agents` |
| Module class | `DDD\Modules\Agents\AgentsModule` (owned namespaces: `AgentsModule::OWNED_NAMESPACES`) |
| PHP | `DDD\Domain\AI\…` (feature folders), `DDD\Presentation\Api\Agents\`, `DDD\Presentation\Mcp\Client\Agents\`, `DDD\Presentation\Mcp\Admin\System\`, `DDD\Symfony\Commands\Agents\`; the voice channel in `…\Voice\` below `DDD\Domain\AI\{Entities,Services,Repo\DB,MessageHandlers,Exceptions}\`, `DDD\Presentation\Api\{Agents,Admin}\` and `DDD\Presentation\Mcp\Client\` |
| Config | `config/app/AI/…` (`AI.skillPackages`, `AI.accountRights`, …), skills in `config/app/AI/Skills/Agent/` and `…/System/` |
| Skills ↔ package ids | `Agent.Mandates` ↔ `agent.mandates`, `Agent.Tasks` ↔ `agent.tasks`, `Agent.TaskRunReports` ↔ `agent.taskRunReports`, `Agent.Memory` ↔ `agent.memory`, `Agent.Web.Search` ↔ `agent.web.search`, `Agent.UI` ↔ `agent.ui`, `Agent.Voice` ↔ `agent.voice`, `System.Health` / `.Issues` / `.Workers` / `.Crons` ↔ `ddd.system.*` |
| Rights | `AgentAccountRights`: `operatePlatform`, `scheduleAgentTasks`, `useAgentMemory`, `useWeb`, `useUI`; `AgentVoiceAccountRights`: `useVoice` (composed with the host's own rights object, see below) |
| Console | `app:agent:…` (the former `app:ai-agent:`, `app:ai-conversation:`, `app:ai-schedule:`, `app:ai:` names stay as aliases), `app:mcp:…` |
| npm entries | `.`, `./renderer`, `./kit`, `./widgets`, `./contract`, `./client`, `./client/testing`, `./chat`, `./preview`, `./image-render`, `./provider`, `./ui`, `./voice`, `./voice/admin` |

## Developer skills

Start with `.claude/skills/ddd-module-agents-specialist/SKILL.md`: install, wiring, the duties a host takes over
(console commands, crons, middlewares, transports) and a table that names the topic skill of every source folder —
loop, context, skills and purposes, tools, widgets, autonomy, decisions and guards, observability, voice.
`tests/Contract/DeveloperSkill*Test.php` keep the skills in step with the code (every named class exists, every file
under `src/` and `config/` has exactly one owning skill).

## Characterization test: the agent surface snapshot

An application pins what its agent is offered — skills, tool packages, tools, parameters, wire schemas, purposes,
HTTP MCP bundles — with one subclass of `DDD\Testing\Agents\Surface\AgentSurfaceSnapshotTestCase` in its own suite
(`getPinnedSurfaceFile()`, optionally `getAcceptedDifferencesFile()`, `findPurposeNames()`, `findBundleClasses()`). A
failure names each changed value by its path. The package ships its own surface
(`src/Testing/Agents/Surface/agent-package-surface.json`); with an accepted-differences file the application keeps
only where it differs from the package, so a package release rewrites neither file unless the application's own change
meets it. Per tool the document also holds its `declarations` (every attribute of the tool method but `#[MCPTool]`)
and what the application's overlay sets (`overlay`). `REGENERATE_AGENT_SURFACE_SNAPSHOT=1` writes both files. Details:
the observability skill, reference "characterization-tests".

The installation check runs as a test of the application's suite with one subclass of
`DDD\Testing\Agents\Installation\AgentInstallationCheckTestCase` (`createConsoleApplication()` for the command-name
check; fails on BLOCKING findings by default, `getFailingSeverities()` and `getCheckNamesNotFailingTheTest()` adapt
it).

## Application overrides

`DDD` resolves an overridable class through the application's same-path subclass first (`App\Domain\AI\…\X` before
`DDD\Domain\AI\…\X`), so an application customizes a service, an entity set or a repository by a subclass at the same
path under its own root namespace.

The hooks a host usually needs first: `AgentAccountRightsService::isAccountReaderOfAllAgentRows()` (who reads every
row of the agent's tables; by default the framework's admin role of any account), `AgentOwnerFacingTextTranslationService`
(the translation of the texts the module writes for the owner; by default the framework's translation keys) and
`MCPToolExecutorService::isEnvironmentWideWriteLockActive()` (refuses every tool that is no declared read; off by
default). The entry skill's override-points reference lists every hook.

**The client context.** The web client sends what the owner sees (route path, route parameters, record kind and id,
tab; each capped, an over-cap value refused with HTTP 400) with a start, a posted message and an injected signal. The
server stores it on that owner message (`AgentClientContext`) and the model reads the newest owner input's context as
one volatile line `[SIGNAL:client-context]` right before the clock — never in the cached prefix, never a transcript row.
A host turns ids into names and labels the keys in a same-path subclass of `AgentClientContextService`
(`describeRecordOfClientContext()`, `getClientContextKeyLabel()`); the default renders the keys plainly. Details: the
context skill, "What the model sees", section "The client context".

**Tokens per model call.** `config/app/AI/agentModelInputOptions.php` (and a purpose's `modelInputOptions:` map) leaves
parts of the model input out: the delegation, task-plan, `askOwner` and `findWidgets` meta tools, the widget part of
the base prompt, base prompt sections by heading, the memory rules when no memory tool is reachable, and the reachable
tool schemas before their skill is loaded. Every switch defaults to the package's behaviour, so the input stays
byte-identical until a host sets one. The default character (`config/app/AI/Prompts/AI/Agent/Character.md`) is an
example; a host replaces it with its own file at the same path. Details: the context skill, "What the model sees",
section 5.

## Rights, tool texts and names a host sets by config

- **Rights.** The package asks the host's own rights object (`AIConversationEntitlementsService`) composed with the
  host's own mapping `AI.accountRights` (`AgentComposedAccountRights`): a package right the host object does not hold is
  still held through that mapping (roles, `'*'`, or `['right' => '<host right>']`). Without a mapping of the host's own,
  the package's default file grants nothing to a host with its own rights object (fail closed). A host object that implements
  `AgentAccountRightsAnsweringInterface` decides the names it answers alone. `AgentDeclaredAccountRightsService`
  lists every right the installed packages declare.
- **Per tool, by name** (`config/app/AI/toolOverlays.php`): `accountRights` (replaces the attributes, `[]` = none),
  `description`, `summary`, `parameters` (a parameter set to `false` leaves the schema and its sent value is ignored —
  for the space argument the conversation's space applies), `examples` (replaces the `@example` lines the model
  sees), `ownerFacingSubject`.
- **Agent.UI** (`config/app/AI/agentUI.php`, `config/app/AI/agentRealtimeChannel.php`): no tables; requests travel
  through the realtime port (`AgentRealtimeChannelService`: a mailbox in the shared cache read with the run state, a
  host transport optional); `openScreen` at most once per user turn by default (`maxOpenScreenPerUserTurn`), the window
  tool `setAgentWindowMode` off by default (`windowModeRequests`).
- **Account-level realtime read** (`GET …/realtimeMessages` of the chat controller traits): the open realtime messages
  addressed to the signed-in account (`AgentRealtimeAudience::forAccount()`, e.g. a call's ring) and the listener
  credential without a conversation — for a client that shows no conversation; same gate, expiry and target-client
  rules as the run-state read. A read that carries the query parameter `clientId` states that client's presence for
  its account (hook `stampUIClientOfAccount()`: one entry per account in the channel's shared cache, no table, written
  at most every 10 s, fresh for 25 s), so `isClientListening()` of the account audience is true while only a polling
  client is open. With a bound transport the transport answers `isClientListening()`, for the account audience too
  (`askTransportWhetherClientIsListening()`). Web: `createAccountRealtimePoll()` of `@mgamadeus/ddd-agents/ui` (its
  subscribers are told an arrived and a cleared message); the HTTP conversation client states a client id on every
  account read.
- **Voice channel provider** (`AgentVoiceChannelProvider`, tagged, autoconfigured): answers the voice channel seam of
  `AgentVoiceChannelService` without a same-path subclass. The package's own voice channel is one
  (`AgentVoiceChannelProviderService`, available only while voice can run, see "Voice"); an application with a
  channel of its own registers another. Exactly one provider is asked — the first available in container order;
  more than one registered is a `voiceChannel` warning of `app:agent:check-installation`. Without an available one:
  no voice channel.
- **The space argument** (`config/app/AI/agentSpaceToolArgument.php`): its name and description on the wire.
- **The task list widget** (`config/app/AI/agentTasks.php`): `taskListWidgetType`.
- **`app:agent:check-installation`** (read-only, `--format=json`, exit 1 on a blocking finding): unbound package
  skills, bindings to missing classes, package rights held by nobody, missing tables, features without their store or
  binaries, leftover config keys, unresolved command names, names that cannot be built or run another class, skill
  rights no tool requires, rights attributes outside packages, the naming lint, pending renames.
- **Renames** ship as data: `renames.json` at the package root. After an update run `vendor/bin/ddd-renames` (dry run),
  then `vendor/bin/ddd-renames --apply` (needs `mgamadeus/ddd` 3.45.0 or newer for the bin).

## Voice: the owner talks to the agent

Optional and off by default (`AI.agentVoice.enabled`, `config/app/AI/agentVoice.php`). The owner talks to the agent in
the application's window, and the agent can ring the owner. The browser speaks to the voice provider over WebRTC; a
worker of the application stays with the call from its start to its end, writes what was said into the conversation,
hands every request of the voice model to the real agent and speaks the agent's answer.

Switched off, an application gets no tool, no skill and no required table, and the routes it mounted answer
`403 feature_not_available`. Not part of the channel: a second medium beside the application's window, a ring on a
device without an open client, written messages to the owner. An application that has them keeps them and binds them
to the override points below.

**Switching it on:**

1. `composer require ratchet/pawl react/event-loop`: the WebSocket library of the session worker, which the package
   only suggests. Switched on without it nothing fails: the channel reads as switched off, and
   `app:agent:check-installation` names the two packages (check `voiceChannel`).
2. Copy `config/app/AI/agentVoice.php` (replaced as a whole) and set `enabled`, the provider's host, model and default
   voice, and the limits. The provider key is one environment variable the config names (default
   `API_OPENAI_REALTIME_API_KEY`), never a value of a config file.
3. The call table through the schema diff; required only while `enabled` is true and `callStore` is `entity`.
4. A controller that mounts `AgentVoiceSessionsTrait` and declares `open` (and `ring`, for a "call me" button) over
   the trait's builder methods; optionally one that mounts `AdminAgentVoiceCallsTrait` (the admin list of calls).
5. The two transports and the routing of `AgentVoiceMessengerTransports` in the application's `messenger.yaml`: a
   pool for `voice_live_session` (one process per running call, a stop grace above `limits.maximumCallSeconds`,
   outbound WebSocket access to the provider) and a consumer for `voice_ring_deadline` (delayed messages).
6. The realtime binding carries an account audience: the ring goes to every open client of the account, and the
   transport answers whether a client of the account listens (`askTransportWhetherClientIsListening()`). Without a
   bound transport the ring arrives through the account-level realtime read, within its poll interval; that read
   states the polling client's presence, so a client that only polls is rung.
7. The right `useVoice` in `AI.accountRights`; nobody holds it by default. The skill `Agent.Voice` is bound to
   `AgentVoiceCallsMCPPackage` by the shipped `skillPackages.php` (an application that replaced the file adds the
   line).
8. A price per session second for the provider's model in the model catalog of `mgamadeus/ddd-ai`
   (`costsPerSessionSecondInUSD`); without one the seconds land on the call and no cost is booked.
9. Web: `AgentVoiceCallProvider` (once, with the `api` and the `ringSource`), the start button, the band, the
   captions and the incoming call of `@mgamadeus/ddd-agents/voice`; the admin screens of
   `@mgamadeus/ddd-agents/voice/admin`. The provider has no `translate` prop: the texts are keys of `i18n-keys.json`
   and go through `AgentsEnvironment.texts`. The styles are part of the package's one stylesheet
   (`@mgamadeus/ddd-agents/styles.css`); colours and sizes are custom properties `--agents-voice-*`. Three peers are
   optional and loaded by `/voice/admin` alone (`@mgamadeus/ddd-admin-ui`, `@mgamadeus/ddd-web`, `lucide-react`): an
   application without the admin screens installs none of them.

Calls a dead worker left running are closed by `app:agent:reap`, with no cron line of their own; an application
command that overrides `closeStaleChannelSessions()` calls the parent.

**Routes** (`AgentVoiceSessionsTrait`; the two that name what the agent works for are declared by the application):

| Route | Declared by | Answer |
|---|---|---|
| `POST …/open` | application → `buildVoiceSessionOpenedResponse()` | `agentVoiceCall`, `sdpAnswer`, `conversationId` |
| `POST …/ring` (optional) | application → `buildVoiceCallRingingResponse()` | `callId`, `conversationId`, `ringExpiresAt`, `anyDeviceReached` |
| `POST /{callId}/answer`, `/decline` | trait | `won` and `conversationId`; `acknowledged` |
| `POST /{callId}/hangUp`, `/deviceStatus` | trait | `acknowledged` |
| `GET /{callId}` | trait | `callId`, `endedAt`, `endReason` |

| HTTP | Code | When |
|---|---|---|
| 400 | `invalid_sdp`, `call_not_answered`, `invalid_answer_token`, `no_registered_devices` | a malformed offer; `open` of a ringing call nobody answered; a token that is not this call's; `ring` while no client of the account listens |
| 403 | `feature_not_available`, `no_ai_budget`, `daily_limit_reached` | voice cannot run here, or the admission refused |
| 404 | `call_not_found`, `conversation_not_found` | not there, or not the account's |
| 409 | `call_already_running`, `already_answered` | one call per account; another client won the ring |
| 410 | `ring_expired` | the ring deadline passed |
| 502 | `provider_unavailable` | the provider did not create the session; the call is ended `FAILED` |

**The ring** is one realtime message of the type `agent.voice.ring`, keyed by the call id, addressed to the account
and expiring at the ring deadline; a client answers with `answer` and, when it won, opens the session with `open` and
the call id. A ring nobody answers ends the call `EXPIRED`, a declined one `DECLINED`, and the agent is told in the
conversation that started it. A ring starts only while a client of the account listens (`isClientListening()` of the
account audience): a bound transport answers that; without its answer a client's presence statement counts while it
is fresh (25 s), and a client states it with its run-state read or, when it shows no conversation, with the
account-level read. A ring that was answered or declined in another client, or withdrawn, stops in a polling client
with the read that finds it gone (`createAgentVoiceRingSource()` over the account realtime poll).

**What an application can replace** (each a service with a default, replaced by a same-path subclass):

| Port | Default | An application overrides it to |
|---|---|---|
| `AgentVoiceCallAdmissionService::canVoiceRunHere()` with the `protected` `isSessionWorkerLibraryInstalled()` | voice can run = `enabled` AND the session worker's WebSocket library installed. THE one place: the admission of a call start, the channel provider, the tools and their skill, the routes and `app:agent:check-installation` ask it | answer for a worker of its own that carries the calls without the package's session worker (it then also overrides `openProviderSessionForCall()` of the routes trait, which attaches the package's worker) |
| `AgentVoiceCallAdmissionService` | the right `useVoice`, the daily cap, the model-call gate of `mgamadeus/ddd-ai`; for a running call also the longest a call may last | add its own conditions for a call start and for a running call; say who pays for a running call (`buildSessionModelCallOfRunningCall()`, asked by the check and by the booking) |
| `AgentVoiceCallStoreService` | the store `entity`: the package's call table | map a store of its own by name (`getCallStoreClassNames()`, contract `AgentVoiceCallStore`) |
| `AgentVoiceCallStartsService` | a call the agent starts rings the owner's open clients | route a call start to another medium, add a ring target |
| `AgentVoiceRingService` | one ring message per call to the account's open clients; its caller context is empty | say who calls: `buildCallerContext()` answers the application's same-path subclass of the value object `AgentVoiceRingCallerContext`, which names its fields in `toPayloadArray()`; name the caller label key |
| `AgentVoiceSessionContextsService` | the briefing of the voice model: instructions, the conversation so far, the delegation rules, the memories | add what it knows of the agent space |
| the provider adapter | `OpenAILiveVoiceProviderAdapter` | register another adapter (a subclass of `RealtimeVoiceProviderAdapter`) under `provider.adapters`; one class answers to one name |
| `AgentVoiceCallsMCPPackage` | three tools on the signed-in account's calls (`startVoiceCall`, `endVoiceCall`, `getVoiceCalls`) | add arguments of its own |
| `AgentVoiceSessionsTrait`, `AdminAgentVoiceCallsTrait` | the routes; the admin list of the signed-in account's own calls | a stricter access rule for voice (`authorizeVoiceSessionAccess()`); whose calls an admin lists (`findAccountIdsOfListableVoiceCalls()`); the costs of a call (`findBookedCostsOfVoiceCall()`) |
| `stampUIClientOfAccount()`, `getAgentClientPresenceService()` (hooks of the chat controller traits) | the account-level read states the presence of a client that sends `clientId`, voice switched on or not | state none (an empty `stampUIClientOfAccount()`); keep the presences in a service of its own |

A refused call start or a refused running call carries a code (`feature_not_available`, `no_ai_budget`,
`daily_limit_reached`; a running call also `maximum_call_seconds_reached`, at `limits.maximumCallSeconds`). A running
call that is refused is told first: the model says one short goodbye (a used-up budget or daily limit is named, any
other refusal ends the call without a reason), then the worker ends the call with the refusal's end reason. Seconds
are booked by the worker, in slices of at least `limits.usageBookingMinimumSeconds`, through the session usage service
of `mgamadeus/ddd-ai`, and the same seconds onto the call.

Details: the developer skill `ddd-module-agents-voice-specialist` (adopting voice with the configuration table;
routes, ring, tools and admin; call life and the session worker; override points).

## Memory: the store is the host port

`AI.agentMemory.enabled` (`config/app/AI/agentMemory.php`, off by default) switches the agent's memory on. Every read
and write of a memory goes through `AgentMemoryStoreService` (`DDD\Domain\AI\Services\Conversations\Memories`): the
core's directives and search, the memory tools, the curation, the embedding repair and the evaluation purge. The
default store keeps its rows in the package's table. A host with memories in rows of its own puts a same-path subclass
of the store under its root namespace, fills `AgentMemory` as the carrier (`id` = its row's id, scope = `accountId` and
an optional `agentSpaceId`) and switches memory on; it needs no package table. The methods a host store answers:

| Method | Answers |
|---|---|
| `findCurrentMemoriesForScope(int $accountId, ?int $agentSpaceId, ?string $memoryType, ?int $limit)` | the current memories of a scope, newest first |
| `findCurrentDirectivesForScope(int $accountId, ?int $agentSpaceId)` | the current directives of a scope |
| `findSimilarMemories(string $searchText, int $accountId, ?int $agentSpaceId, int $limit, ?string $memoryType)` | the nearest current memories |
| `findCurrentDuplicateOfContent(int $accountId, ?int $agentSpaceId, string $content)` | the current memory with the same content, or null |
| `persistMemory(AgentMemory)`, `embedMemory(AgentMemory)` | the write of a memory |
| `closeValidityWindow(int $targetId, int $successorId, DateTime $validTo, ?DateTime $invalidatedAt)`, `invalidateMemory(int $memoryId)` | close a row for its successor; forget a memory |
| `find(int $memoryId)` | one memory by id, under the caller's rights |
| `getEmbeddingRepairTargets()` | the tables the embedding repair covers |
| `getColumnsNotPurgedByEvalPurge()` | `table.column` => reason for the evaluation purge |

Details: the context skill, reference "Memories and standing directives", section 8.

## Environment of the package test kernel (`tests/Support/kernel`)

No env file is committed. `tests/Support/PackageTestEnvironment.php` sets every variable where the kernel is built
(`tests/bootstrap.php`, `tests/Support/bin/console.php`); key-shaped values there are the fake `test-not-a-secret`.
Order: process environment, then a git-ignored `tests/Support/kernel/.env.test` (copy `.env.dist`), then those
defaults. Nothing connects to a database unless a test reads or writes a DB entity.

| Variable | Purpose | Example format |
|---|---|---|
| `APP_ENV`, `APP_DEBUG` | Symfony environment and debug flag | `test`, `0` |
| `APP_SECRET` | Symfony's secret | any random string |
| `DB_DEFAULT_CONNECTION_DRIVER` | Doctrine driver | `pdo_mysql` |
| `DB_DEFAULT_CONNECTION_HOST`, `…_HOST_PORT` | a local throwaway database (integration tests only) | `127.0.0.1`, `3307` |
| `DB_DEFAULT_CONNECTION_DB_NAME` | its schema | `ddd_agents_test` |
| `DB_DEFAULT_CONNECTION_USER`, `…_PASSWORD` | its credentials | `<your local user>`, `<your local password>` |
| `DB_DEFAULT_CONNECTION_DB_SERVER_VERSION`, `…_DB_SERVER_CHARSET` | server version and charset | `mariadb-11.8.5`, `utf8mb4` |
| `LAZYLOAD_DEFAULT_REPO_TYPE` | the repository type of a `LazyLoad` without `repoType` | `DB` |

## Environment of the reference host (`examples/reference-kernel`)

No env file is committed. Copy `examples/reference-kernel/.env.dist` to `.env` (git-ignored) and fill the empty values;
a git-ignored `.env.local` overrides `.env`, and the process environment wins over both. Without a `.env` the bootstrap
loads `.env.dist`. The smoke test (`tests/Kernel/ReferenceKernelSmokeTest.php`) passes `test-not-a-secret` for every
key-shaped variable. No model provider is called: the model answers come from a recording.

| Variable | Purpose | Example format |
|---|---|---|
| `APP_ENV`, `APP_DEBUG` | Symfony environment and debug flag (never `false`: use `0`) | `prod`, `0` |
| `APP_SECRET` | Symfony's secret | any random string |
| `DB_DEFAULT_CONNECTION_DRIVER` | Doctrine driver | `pdo_mysql` |
| `DB_DEFAULT_CONNECTION_HOST`, `…_HOST_PORT` | the host's database (MariaDB 11.8+ for VECTOR columns) | `127.0.0.1`, `3307` |
| `DB_DEFAULT_CONNECTION_DB_NAME` | its schema | `ddd_agents_reference` |
| `DB_DEFAULT_CONNECTION_USER`, `…_PASSWORD` | its credentials | `<your local user>`, `<your local password>` |
| `DB_DEFAULT_CONNECTION_DB_SERVER_VERSION`, `…_DB_SERVER_CHARSET` | server version and charset | `mariadb-11.8.5`, `utf8mb4` |
| `LAZYLOAD_DEFAULT_REPO_TYPE` | the repository type of a `LazyLoad` without `repoType` | `DB` |
| `REFERENCE_DEMO_OWNER_ACCOUNT_ID` | the fixed demo account every request acts as | `1` |
| `CACHE_APC_TTL` | cache TTL in seconds (every cache group falls back to APCu) | `3600` |
| `OPENROUTER_KEY` | the provider key the model catalog names; the egress refuses to build a request while it is empty, even when the recorded transport answers | `<your provider key>` (any non-empty string for the recording) |
| `JWT_HASH_KEY` | key that signs every outgoing Argus call and login token | a random string of 32+ characters |
| `JWT_HASH_METHOD` | its algorithm | `HS256` |
