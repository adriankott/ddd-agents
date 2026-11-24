# Agent.UI and the System tools

**Reader:** host developer

Contents: Agent.UI (awareness, channel, tools, what the host provides) · System.* (packages, rights, health sections).

## Agent.UI: the user's UI, navigation and guidance

Three things, one skill `Agent.UI` ↔ `agent.ui` (`AgentUIMCPPackage`, right `AgentAccountRights::USE_UI`):

| Part | What it is | Where |
|---|---|---|
| Awareness | the `[SIGNAL:ui]` note: area, record with the server's description, tab, view, count of unsaved fields and validation messages, an open dialog; appended once when it changed (state delivery kind `ui`), a tail entry on a conversation without state delivery; no clock, no client text | `AgentUIStateNotesService`, `AgentStateDelta::KIND_UI` |
| Reported state | what the client sends with every message (`clientId`, `uiState`) and every run-state read (`clientId`, `uiStateJson`): tokens of one pattern, normalized on arrival (`AgentUIState::normalize()`), stored on the client's row | `AgentUIState`, `AgentClientAttachment` (table `AgentClientAttachments`) |
| Channel | a tool stores a request and waits (≤ 20 s, heartbeat every second); the run-state read delivers it to one client (`clientToolRequest`); the client posts `POST /{aiConversationId}/clientToolRequests/{requestId}/answer`; the text is blanked after the tool read it | `AgentClientToolRequestsService`, `AgentClientToolRequest` (table `AgentClientToolRequests`) |
| Tools | `getCurrentScreen`, `listScreens`, `openScreen`, `highlightOnScreen`: effect READ, never reused within a turn, never parallel, the client's text wrapped as untrusted | `AgentUIMCPPackage`, skill `Agent/UI.md` |
| Housekeeping | `app:agent:purge-client-tool-requests` hourly (expires overdue requests, deletes requests after 30 days and clients after a day) | `AgentClientToolsPurgeCommand` |
| Evaluations | `AgentScriptedUIClientService` answers the requests of an evaluation account from fixtures in the folder `AI/Evals/Agent/Screens` below a config root (`setScreen()`, `readLog()` from the host's harness tools); never in `APP_ENV=prod` | |

The host provides:

1. The tables `AgentClientToolRequests` and `AgentClientAttachments` (schema diff). Without them the chat keeps
   working: stamping and delivery are skipped with a log line, the note is unknown, the tools answer "no client".
2. The screen catalog on the server: a same-path subclass of `AgentUIScreenCatalogService::describeScreen()` that maps
   a route to an area (key, label) and describes the record under the caller's rights. The default names the route
   path as the area and no record.
3. The client side: `@mgamadeus/ddd-agents` runs the requests (`ClientToolRequestRunner`) with four adapters
   (`ScreenTargetSource`, `ScreenNavigator`, `ScreenReader`, `ScreenHighlighter`); DOM defaults exist for reader and
   highlighter; `@mgamadeus/ddd-admin-ui` brings the adapters of screens built from its blocks.
4. The right: `useUI` (default every account, `AI.accountRights`). A host that keeps the tools to staff adds its gate
   in a same-path subclass of the package and repeats `#[MCPRequiresAccountRights(AgentAccountRights::USE_UI)]`
   (class attributes are not inherited).
5. The realtime accelerator (optional): `AIConversationRealtimeSignalService::publishClientToolRequestSignal()`.

## System.*: Core's features for the platform operators

| Skill ↔ id | Package | Over Core's |
|---|---|---|
| `System.Health` ↔ `ddd.system.health` (parent of the three) | `SystemHealthMCPPackage` | `SystemHealthService`: workers, crons, issues sections + `SystemHealthSectionProvider` services of the host |
| `System.Issues` ↔ `ddd.system.issues` | `SystemIssuesMCPPackage` | `IssueGroupsService`, `IssuesOverviewService` (status change asks the owner first) |
| `System.Workers` ↔ `ddd.system.workers` | `SystemWorkersMCPPackage` | `WorkersOverviewService` |
| `System.Crons` ↔ `ddd.system.crons` | `SystemCronsMCPPackage` | `CronsManagementService` |

Every package carries `#[MCPRequiresAccountRights(AgentAccountRights::OPERATE_PLATFORM)]` and
`#[MCPRequiresAdminWithAccessToAllResources]`; the skills name `operatePlatform`. A host that maps nothing grants
nothing (the default maps it to `Role::SUPERADMIN`). A host section (its database mode, a failed-request log, a store
of its own) implements `SystemHealthSectionProvider` (tag `ai.system_health_section_provider`); a host tool of its own
(a failed-request list) is a package of the host added to the binding of `System.Health`. The result lines go through
`LlmTextRendering` and `LlmSecretRedaction` (`Entities/MCPPackages/LlmRendering/`): no host, process id, environment
value or connection string reaches the model.
