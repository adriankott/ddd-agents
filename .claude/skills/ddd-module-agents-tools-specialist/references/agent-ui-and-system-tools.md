# Agent.UI and the System tools

**Reader:** host developer

Contents: Agent.UI (awareness, channel, tools, what the host provides) · System.* (packages, rights, health sections).

## Agent.UI: the user's UI, navigation and guidance

Three things, one skill `Agent.UI` ↔ `agent.ui` (`AgentUIMCPPackage`, plus `AgentUIWindowMCPPackage` while the host
switches the view mode requests on and `AgentUIActionsMCPPackage` while it switches the screen actions on; right
`AgentAccountRights::USE_UI`). No table: everything short-lived lives in the
shared cache of the realtime channel (`AI.agentRealtimeChannel.sharedCacheGroup`, Redis or Valkey).

| Part | What it is | Where |
|---|---|---|
| Awareness | the `[SIGNAL:ui]` note: area, record with the server's description, tab, view, count of unsaved fields and validation messages, an open dialog, the agent window's mode and the browser window's width class (narrow under 640 px, medium under 1024 px, wide; never the pixels, so a resize within a class is no new note); appended once when it changed (state delivery kind `ui`), a tail entry on a conversation without state delivery; no clock, no client text | `AgentUIStateNotesService`, `AgentStateDelta::KIND_UI` |
| Presence | what a client states with a message (`clientId`, `uiState`), a run-state read (`clientId`, `uiStateJson`) and an answer (`uiStateJson`): normalized (`AgentUIState::normalize()`), kept per conversation (at most 5 clients) and per account for 15 minutes, fresh for 25 s, written at most every 10 s unless something changed. A client that shows no conversation states only that it is there, with the account-level realtime read (`clientId`): an entry of its own per account, never a target of a client tool request (the widgets skill, realtime and push) | `AgentClientPresenceService`, `AgentClientPresence(s)` |
| Channel | a tool sends a request (random id below 2^53) through the realtime port and waits (≤ 20 s, heartbeat every second); the client gets it from the host's transport or with its run-state read (`clientToolRequest`, `realtimeMessages`); it posts `POST /{aiConversationId}/clientToolRequests/{requestId}/answer` (outcome, text, details, `pictureBase64`, `uiStateJson`); the answer is set if absent (an atomic counter: the first answer wins, 409 after) with a TTL of the wait window + 30 s, and forgotten once the tool read it | `AgentClientToolRequestsService`, `AgentClientToolRequest` (value object), `AgentRealtimeChannelService` |
| Request types | open: the package's `READ_SCREEN`, `LIST_TARGETS`, `OPEN_TARGET`, `HIGHLIGHT`, `SET_WINDOW_MODE` (off unless `AI.agentUI.windowModeRequests`), `ACT_ON_SCREEN` (off unless `AI.agentUI.screenActionRequests`); a package or host adds its own through the tagged `AgentClientToolRequestTypeProvider`. A switched-off or unknown type answers `NOT_ENABLED` | `AgentUIClientToolRequestTypeProviderService` |
| Tools | `getCurrentScreen` (`format` text or picture, `pictureSize` small/medium/large, `renderMode` colour/greyscale/machine), `listScreens`, `openScreen` (at most `AI.agentUI.maxOpenScreenPerUserTurn` per message of the user, default 1), `highlightOnScreen`, `setAgentWindowMode` (only with the view mode requests on), `actOnScreen` (only with the screen actions on; below): effect READ, never reused within a turn, never parallel, the client's text wrapped as untrusted | `AgentUIMCPPackage`, `AgentUIWindowMCPPackage`, `AgentUIActionsMCPPackage`, skill `Agent/UI.md` |
| Screen picture | untrusted client bytes: base64 of at most 600 KB, the type from the magic bytes (WebP, JPEG, PNG), the size from Core's pinned `RasterImageDecoder`, at most 4 MP; it rides as an image part of the result (`MCPToolResponseImageProvider`) and the tool picture store keeps it. The result states the fixed limits (`PICTURE_LIMITATIONS`) | `AgentClientToolRequestsService::checkScreenPicture()`, `AgentScreenPicture` |
| Evaluations | `AgentScriptedUIClientService` answers the requests of an evaluation account from fixtures in the folder `AI/Evals/Agent/Screens` below a config root (`setScreen()`, `readLog()` from the host's harness tools) into the same cache keys; never in `APP_ENV=prod`. For `ACT_ON_SCREEN` a fixture declares `filters` (the filter keys of the screen), `actions` (reference => `sort`, `open`, `tab`, `press`, or press:write for a button declared with a writing effect) and `screensByAction` (`"setFilters"` or `"<action> <reference>"` => the fixture shown afterwards); the log reads `set filters status=OPEN`, `sorted col.created desc`, `opened row row.10669`, `switched tab t.crashes`, `pressed act.load-more`, `refused press b.2: not declared`. The fixture `catalog` may hold `filterValues` (target key => what its filters take): a `listScreens` answer narrowed to at most 4 screens names them below the screen's line, as the real client does | |

The host provides:

1. A shared cache: `config/app/AI/agentRealtimeChannel.php` `sharedCacheGroup` (null tries `redis`, then Core's default
   group). A cache local to the process (APCu) is none: the tools answer "no client", `app:agent:check-installation`
   warns (`realtimeChannel`).
2. The screen catalog on the server: a same-path subclass of `AgentUIScreenCatalogService::describeScreen()` that maps
   a route to an area (key, label) and describes the record under the caller's rights. The default names the route
   path as the area and no record.
3. The client side: `@mgamadeus/ddd-agents/ui` runs the requests (`ClientToolRequestRunner`) with its adapters
   (`ScreenTargetSource` — `createDomScreenTargetSource()` reads `data-agent-anchor` marks, optionally one
   `groupKey` —, `ScreenNavigator`, `ScreenReader`, `ScreenHighlighter`). `DomScreenReader` writes the agent window
   and browser size (`describeAgentWindow`), per section a `Text:` line (plain text no other line carries, 1,200
   characters), tables and cards before fields (15 rows, cells cut at 60, empty columns left out, a row's selection
   checkbox as `(selected)`), and with `findNavigation` the main menu as `nav.<key>` references (`data-nav-key`,
   `data-nav-group`) after the page, never cut away. A read has `MAX_SCREEN_CHARS` (12,000, half the answer cap),
   the server's cut (`AgentUIToolResponseDto::MAX_CONTENT_CHARS`, a spec compares them). Optional adapters: `picture`
   (`createDomScreenPictureCapturer()`: presets small/medium/large with pixel and byte budgets, `colour` /
   `greyscale` / `machine`, passwords as dots, `data-agent-secret` blanked, `data-agent-skip` left out; only the
   elements in the browser window, clipped by their scroll containers, are copied, a declaration only where it
   differs from the tag's browser default (an empty frame, cached per tag) or from the parent, and a table part never
   gets its page height (a copy with fewer rows drew a blank body); `PICTURE_LIMITATIONS` equals the server's),
   `pathGuide` (`createDomScreenPathGuide()`: Esc or
   a click cancels, skipped under reduced motion, on the target, or without a path element; `createGuideCounter()`
   guides a target twice), `actions` (`new DomScreenActor({ registry, currentLocation, describeBlockers, highlighter, describeAction, settle })`:
   the typed actions of `actOnScreen`, above), `agentWindow` (`createAgentWindowAdapter()`: steps aside before `OPEN_TARGET`, `HIGHLIGHT`, `ACT_ON_SCREEN`
   and a picture, restores after the picture, takes `SET_WINDOW_MODE`, `getMode()` fills the UI state's
   `agentWindow`; `AgentUIClient` adds `viewportWidth` / `viewportHeight`), `requestHandlers` (further request types).
   A host transport hands its messages to `acceptArrivedRealtimeMessage()`; `runClientToolRequestForDevelopment()`
   tries the adapters without a model. The answer carries `pictureBase64` and the UI state after the request.
   `@mgamadeus/ddd-admin-ui` brings the adapters of screens built from its blocks.
4. The right: `useUI` (`AI.accountRights`). A host that keeps the tools to staff adds its gate in a same-path subclass
   of the package and adds its own `#[MCPRequiresAccountRights(...)]` there; the package's `useUI` gate is kept (the
   gates of a package class are read through its parents).
5. The transport (optional): a same-path subclass of `AgentRealtimeChannelService` (the realtime port, below). Without
   one the client finds a request with its next run-state read.

### Screen actions: `actOnScreen`

Off by default. A host switches `AI.agentUI.screenActionRequests` on, binds `AgentUIActionsMCPPackage` (the package's
`skillPackages.php` does; a host that replaces the file repeats the line), and gives its client the `actions` adapter.
One tool, a closed list of typed actions; the model never writes a selector and never types into the page:

| `action` | Takes | The client does | Needs on the screen |
|---|---|---|---|
| `setFilters` | `filters`: 1 to 6 `key=value`, an empty value clears | changes only these keys of the open screen's URL (`ScreenNavigator.currentTarget()` / `applyFilters()`); every other parameter stays | the target of the open route declares the key (`ScreenTarget.filters`) |
| `sort` | `reference` `col.<key>`, `direction` `asc` / `desc` / `none` | activates the sort control until the column's `aria-sort` shows the direction (at most 3 times) | `data-agent-action` = sort, plus `data-agent-action-key` |
| `openRow` | `reference` `row.<key>` | activates the row or card | `data-agent-action` = open on the element with `data-row-key` |
| `switchTab` | `reference` `t.<key>` | activates the tab | `data-agent-action` = tab plus the key, or the ARIA role tab |
| `press` | `reference` `act.<key>` | activates the button | `data-agent-action` = press plus the key and `data-agent-action-effect` = view |

- **Nothing here writes.** A button without a declaration (the reader's `b.…`) is refused on the server before
  anything is sent (`NOT_DECLARED`); a button declared with another effect than `view` is refused by the client
  (`WRITE_ACTION`). Both refusals tell the model to use its data tool or to mark the button (`highlightOnScreen`) and
  let the user press it. There is no action that types into a field or submits a form.
- **Visible.** `DomScreenActor` marks the element through the highlighter with the host's translated note
  (`describeAction`), waits a beat (`ACTION_MARK_BEAT_MS`, 700 ms), then dispatches one pointer activation
  (`activateElement`: pointerdown, mousedown, pointerup, mouseup, click; no coordinates, no keyboard) and waits for the
  host's `settle`. The agent window steps aside as for `HIGHLIGHT`.
- **Blocked by the user's work.** Unsaved changes refuse what leaves the screen (`setFilters`, `openRow`, a link tab)
  with `UNSAVED_CHANGES`; an open dialog refuses every element outside it and what leaves the screen inside it
  (`DIALOG_OPEN`).
- **Discoverable only where declared.** The reader adds lines only for what the screen declares: `Filters (actOnScreen,
  action setFilters): status=OPEN · priority` (option `describeFilters`), `Tabs (…action switchTab): …`, per table
  `Sort (…action sort): col.created "Created" (desc) · …` and `Rows open with actOnScreen (action openRow, reference
  row.<key>).`, and `Actions (actOnScreen, action press): act.load-more "Load more"`. A screen without declarations
  reads as before.
- **Capped.** At most `AI.agentUI.maxScreenActionsPerUserTurn` (default 3) actions are carried out for one message of
  the user, counted on success in the shared cache; over the cap the tool answers `SCREEN_ACTION_CAP` and sends
  nothing. Wait 15 s (`ACT_WAIT_SECONDS`), time limit 25 s.
- **Untrusted.** The client's sentence (labels of the screen) and the route after the action stay inside the
  untrusted block of the result, as for `openScreen`.
- Refusal codes of the client: `ACTIONS_UNAVAILABLE` (no `actions` adapter, or a navigator without
  `applyFilters`), `UNKNOWN_ACTION`, `NO_FILTERS`, `UNKNOWN_FILTER` (with the screen's filter keys),
  `UNKNOWN_FILTER_VALUE` (a value the filter does not take, with its values), `NOT_ON_SCREEN`,
  `NOT_DECLARED`, `WRITE_ACTION`, `DISABLED`, `NO_EFFECT` (a sort control that never showed the direction), plus the
  blockers above; sentences in `AgentUIActionsMCPPackage::ACTION_REFUSALS`.
- A host that keeps the UI tools to staff repeats its gate in a same-path subclass of `AgentUIActionsMCPPackage`, as
  for the other two packages.

#### Filter values

A client's screen target may say what each filter takes (`ScreenTargetFilter`: `values`, `multiple`, `hint`, and
`tabs` for the list of one tab of a record screen). The model then learns the values from the application, not from a
skill text:

- `listScreens` narrowed to at most 4 screens adds `filter values: …` and `filters of tab <tab>: …` below a screen's
  line, and always below the screen whose key is the search text (it comes first); the whole catalogue names keys only
  and says how to get the values. A catalogue too long for full lines (`MAX_TARGET_LIST_CHARS`) lists every screen in a
  short line (`formatScreenTargetShortLine`) instead of leaving screens out.
- The screen read's `Filters` line names the filters of the open tab with their values.
- `openScreen` opens the screen without a value its filter does not take: the client's detail `filtersNotSet` rides
  inside the untrusted mark as `Filter not set: …` (`AgentUIMCPPackage::FILTERS_NOT_SET`), the server closes with
  `AgentUIMCPPackage::FILTERS_NOT_SET_NOTE` (`AgentUIMCPPackage::buildOpenedResponse()`); `actOnScreen` `setFilters`
  refuses it with `UNKNOWN_FILTER_VALUE`.

### The realtime port

`AgentRealtimeChannelService` carries typed messages to the user's open clients, without a table:

- (a) `sendMessage(AgentRealtimeMessage)`: a registered type (`AgentRealtimeMessageTypeProvider`, tagged; the package
  registers `agent.ui.clientToolRequest`), a key, one JSON object as payload (capped per type), an expiry, an audience
  (`AgentRealtimeAudience::forAccount()` / `forConversation()`) and optionally one target client;
- (b) `clearMessage(audience, type, key)`; sending the same type and key again replaces the message;
- (c) `findListenerCredential(accountId, rootConversationId)`: what a client needs to listen on the host's transport
  (`realtimeListenerCredential` of the run-state read);
- (d) `isClientListening(audience)`: the transport's answer, else a fresh presence (for an account audience also the
  one a client without a conversation states with the account-level realtime read).

Every message also sits in a mailbox in the shared cache (at most 20 per audience, until it expires or is cleared);
the run-state read delivers the open ones (`realtimeMessages`). A host binds its push channel (WebSocket, Firebase,
SSE …) by overriding the four hooks in a same-path subclass: `publishMessageToTransport()` (true = the transport took
it), `clearMessageOnTransport()`, `issueListenerCredential()`, `askTransportWhetherClientIsListening()` (null = cannot
tell). The mailbox is written before the transport is rung, and cleared before the transport is told. A hook that
throws is logged; the mailbox still delivers. The deprecated
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
