# Agent.UI and the System tools

**Reader:** host developer

Contents: Agent.UI (awareness, channel, tools, what the host provides) · System.* (packages, rights, health sections).

## Agent.UI: the user's UI, navigation and guidance

Three things, one skill `Agent.UI` ↔ `agent.ui` (`AgentUIMCPPackage`, plus `AgentUIWindowMCPPackage` while the host
switches the view mode requests on; right `AgentAccountRights::USE_UI`). No table: everything short-lived lives in the
shared cache of the realtime channel (`AI.agentRealtimeChannel.sharedCacheGroup`, Redis or Valkey).

| Part | What it is | Where |
|---|---|---|
| Awareness | the `[SIGNAL:ui]` note: area, record with the server's description, tab, view, count of unsaved fields and validation messages, an open dialog; appended once when it changed (state delivery kind `ui`), a tail entry on a conversation without state delivery; no clock, no client text | `AgentUIStateNotesService`, `AgentStateDelta::KIND_UI` |
| Presence | what a client states with a message (`clientId`, `uiState`), a run-state read (`clientId`, `uiStateJson`) and an answer (`uiStateJson`): normalized (`AgentUIState::normalize()`), kept per conversation (at most 5 clients) and per account for 15 minutes, fresh for 25 s, written at most every 10 s unless something changed | `AgentClientPresenceService`, `AgentClientPresence(s)` |
| Channel | a tool sends a request (random id below 2^53) through the realtime port and waits (≤ 20 s, heartbeat every second); the client gets it from the host's transport or with its run-state read (`clientToolRequest`, `realtimeMessages`); it posts `POST /{aiConversationId}/clientToolRequests/{requestId}/answer` (outcome, text, details, `pictureBase64`, `uiStateJson`); the answer is set if absent (an atomic counter: the first answer wins, 409 after) with a TTL of the wait window + 30 s, and forgotten once the tool read it | `AgentClientToolRequestsService`, `AgentClientToolRequest` (value object), `AgentRealtimeChannelService` |
| Request types | open: the package's `READ_SCREEN`, `LIST_TARGETS`, `OPEN_TARGET`, `HIGHLIGHT`, `SET_WINDOW_MODE` (off unless `AI.agentUI.windowModeRequests`); a package or host adds its own through the tagged `AgentClientToolRequestTypeProvider`. A switched-off or unknown type answers `NOT_ENABLED` | `AgentUIClientToolRequestTypeProviderService` |
| Tools | `getCurrentScreen` (`format` text or picture, `pictureSize` small/medium/large, `renderMode` colour/greyscale/machine), `listScreens`, `openScreen` (at most `AI.agentUI.maxOpenScreenPerUserTurn` per message of the user, default 1), `highlightOnScreen`, `setAgentWindowMode` (only with the view mode requests on): effect READ, never reused within a turn, never parallel, the client's text wrapped as untrusted | `AgentUIMCPPackage`, `AgentUIWindowMCPPackage`, skill `Agent/UI.md` |
| Screen picture | untrusted client bytes: base64 of at most 600 KB, the type from the magic bytes (WebP, JPEG, PNG), the size from Core's pinned `RasterImageDecoder`, at most 4 MP; it rides as an image part of the result (`MCPToolResponseImageProvider`) and the tool picture store keeps it. The result states the fixed limits (`PICTURE_LIMITATIONS`) | `AgentClientToolRequestsService::checkScreenPicture()`, `AgentScreenPicture` |
| Evaluations | `AgentScriptedUIClientService` answers the requests of an evaluation account from fixtures in the folder `AI/Evals/Agent/Screens` below a config root (`setScreen()`, `readLog()` from the host's harness tools) into the same cache keys; never in `APP_ENV=prod` | |

The host provides:

1. A shared cache: `config/app/AI/agentRealtimeChannel.php` `sharedCacheGroup` (null tries `redis`, then Core's default
   group). A cache local to the process (APCu) is none: the tools answer "no client", `app:agent:check-installation`
   warns (`realtimeChannel`).
2. The screen catalog on the server: a same-path subclass of `AgentUIScreenCatalogService::describeScreen()` that maps
   a route to an area (key, label) and describes the record under the caller's rights. The default names the route
   path as the area and no record.
3. The client side: `@mgamadeus/ddd-agents/ui` runs the requests (`ClientToolRequestRunner`) with its adapters
   (`ScreenTargetSource` — `createDomScreenTargetSource()` reads `data-agent-anchor` marks, optionally one
   `groupKey` —, `ScreenNavigator`, `ScreenReader`, `ScreenHighlighter`); optional adapters: `picture`
   (`createDomScreenPictureCapturer()`: presets small/medium/large with pixel and byte budgets, `colour` /
   `greyscale` / `machine`, passwords as dots, `data-agent-secret` blanked, `data-agent-skip` left out, scroll
   containers expanded; `PICTURE_LIMITATIONS` equals the server's), `pathGuide` (`createDomScreenPathGuide()`: Esc or
   a click cancels, skipped under reduced motion, on the target, or without a path element; `createGuideCounter()`
   guides a target twice), `agentWindow` (`createAgentWindowAdapter()`: steps aside before `OPEN_TARGET`, `HIGHLIGHT`
   and a picture, restores after the picture, takes `SET_WINDOW_MODE`), `requestHandlers` (further request types).
   A host transport hands its messages to `acceptArrivedRealtimeMessage()`; `runClientToolRequestForDevelopment()`
   tries the adapters without a model. The answer carries `pictureBase64` and the UI state after the request.
   `@mgamadeus/ddd-admin-ui` brings the adapters of screens built from its blocks.
4. The right: `useUI` (`AI.accountRights`). A host that keeps the tools to staff adds its gate in a same-path subclass
   of the package and repeats `#[MCPRequiresAccountRights(AgentAccountRights::USE_UI)]` (class attributes are not
   inherited).
5. The transport (optional): a same-path subclass of `AgentRealtimeChannelService` (the realtime port, below). Without
   one the client finds a request with its next run-state read.

### The realtime port

`AgentRealtimeChannelService` carries typed messages to the user's open clients, without a table:

- (a) `sendMessage(AgentRealtimeMessage)`: a registered type (`AgentRealtimeMessageTypeProvider`, tagged; the package
  registers `agent.ui.clientToolRequest`), a key, one JSON object as payload (capped per type), an expiry, an audience
  (`AgentRealtimeAudience::forAccount()` / `forConversation()`) and optionally one target client;
- (b) `clearMessage(audience, type, key)`; sending the same type and key again replaces the message;
- (c) `findListenerCredential(accountId, rootConversationId)`: what a client needs to listen on the host's transport
  (`realtimeListenerCredential` of the run-state read);
- (d) `isClientListening(audience)`: the transport's answer, else a fresh presence.

Every message also sits in a mailbox in the shared cache (at most 20 per audience, until it expires or is cleared);
the run-state read delivers the open ones (`realtimeMessages`). A host binds its push channel (WebSocket, Firebase,
SSE …) by overriding the four hooks in a same-path subclass: `publishMessageToTransport()` (true = the transport took
it), `clearMessageOnTransport()`, `issueListenerCredential()`, `askTransportWhetherClientIsListening()` (null = cannot
tell). A hook that throws is logged; the mailbox still delivers. The deprecated
`AIConversationRealtimeSignalService::publishClientToolRequestSignal()` is still rung for a client tool request while
the port publishes nothing, so an existing ping keeps waking the client. The contract test with a fake transport:
`tests/Domain/AI/Services/Conversations/Realtime/AgentRealtimeChannelContractTest.php`.

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
