# mgamadeus/ddd-agents

Status: pre-release (0.1.0-alpha). Nothing here is stable API yet.

The agentic loop for `mgamadeus/ddd`, on top of `mgamadeus/ddd-ai`: conversations, messages, compaction, the task
plan, skills and MCP metadata, purposes and done criteria, widgets and signal sources, delegation and sub-runs, the
supervisor, run claims, the decision layer, the message handlers of the loop and the MCP tool executor.

## Layout

| Path | Content |
|---|---|
| `src/Modules/Agents/AgentsModule.php` | the `DDDModule` (source and config path, public service namespaces, exclude patterns) |
| `src/Domain/AI/…` | the agent core, in the namespace `DDD\Domain\AI\…` shared with `ddd-ai` (no class name collides) |
| `src/Presentation/…` | the agent console printer, the JSON-RPC base controller `MCPController`, `MCPToolExecutorService` |
| `config/app/AI/` | the module's defaults: application names and wording, egress, persona, the tool-call child process, context retention (an application overrides a file under its own `config/app/AI/`) |
| `tests/` | the package's own suite: unit, contract, integration (a local MariaDB) and the reference-kernel smoke test |
| `examples/` | `reference-kernel/` (a minimal host without an application) and `workers.yaml` (one consumer per transport) |
| `.claude/skills/` | the developer skills: the entry skill `ddd-module-agents-specialist` and eight topic skills |

## Words per layer

| Layer | Name |
|---|---|
| Composer, npm, repository | `mgamadeus/ddd-agents`, `@mgamadeus/ddd-agents`, `ddd-framework/ddd-agents` |
| Module class | `DDD\Modules\Agents\AgentsModule` (owned namespaces: `AgentsModule::OWNED_NAMESPACES`) |
| PHP | `DDD\Domain\AI\…` (feature folders), `DDD\Presentation\Api\Agents\`, `DDD\Presentation\Mcp\Client\Agents\`, `DDD\Presentation\Mcp\Admin\System\`, `DDD\Symfony\Commands\Agents\` |
| Config | `config/app/AI/…` (`AI.skillPackages`, `AI.accountRights`, …), skills in `config/app/AI/Skills/Agent/` and `…/System/` |
| Skills ↔ package ids | `Agent.Mandates` ↔ `agent.mandates`, `Agent.Tasks` ↔ `agent.tasks`, `Agent.TaskRunReports` ↔ `agent.taskRunReports`, `Agent.Memory` ↔ `agent.memory`, `Agent.Web.Search` ↔ `agent.web.search`, `Agent.UI` ↔ `agent.ui`, `System.Health` / `.Issues` / `.Workers` / `.Crons` ↔ `ddd.system.*` |
| Rights | `AgentAccountRights`: `operatePlatform`, `scheduleAgentTasks`, `useAgentMemory`, `useWeb`, `useUI` (composed with the host's own rights object, see below) |
| Console | `app:agent:…` (the former `app:ai-agent:`, `app:ai-conversation:`, `app:ai-schedule:`, `app:ai:` names stay as aliases), `app:mcp:…` |
| npm entries | `.`, `./renderer`, `./kit`, `./widgets`, `./contract`, `./client`, `./client/testing`, `./chat`, `./preview`, `./image-render`, `./provider`, `./ui` |

## Developer skills

Start with `.claude/skills/ddd-module-agents-specialist/SKILL.md`: install, wiring, the duties a host takes over
(console commands, crons, middlewares, transports) and a table that names the topic skill of every source folder —
loop, context, skills and purposes, tools, widgets, autonomy, decisions and guards, observability.
`tests/Contract/DeveloperSkill*Test.php` keep the skills in step with the code (every named class exists, every file
under `src/` and `config/` has exactly one owning skill).

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
  `description`, `summary`, `parameters`, `ownerFacingSubject`.
- **The space argument** (`config/app/AI/agentSpaceToolArgument.php`): its name and description on the wire.
- **The task list widget** (`config/app/AI/agentTasks.php`): `taskListWidgetType`.
- **`app:agent:check-installation`** (read-only, `--format=json`, exit 1 on a blocking finding): unbound package
  skills, bindings to missing classes, package rights held by nobody, missing tables, features without their store or
  binaries, leftover config keys, unresolved command names, the naming lint, pending renames.
- **Renames** ship as data: `renames.json` at the package root. After an update run `vendor/bin/ddd-renames` (dry run),
  then `vendor/bin/ddd-renames --apply` (needs `mgamadeus/ddd` 3.45.0 or newer for the bin).

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
