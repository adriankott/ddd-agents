# @mgamadeus/ddd-agents

React frontend of [mgamadeus/ddd-agents](https://github.com/ddd-framework/ddd-agents), the agentic loop for backends
built with mgamadeus/ddd: the agent chat, the widget renderer and the composed-view kit. Released together with the
Composer package of the same version.

While the package is 0.x, a minor release may break: pin `~0.1.0` or the exact version.

```bash
npm install @mgamadeus/ddd-agents@~0.1.1 @types/google.maps@^3.55.0 apexcharts@^3.41.0 dompurify@^3.4.0 \
  highlight.js@^11.12.0 katex@^0.19.0 mermaid@^12.2.0 react-apexcharts@'>=1.4.0 <1.6.0' react-markdown@^10.1.0 \
  remark-directive@^4.0.0 remark-gfm@^4.0.1 remark-parse@^11.0.0 unified@^11.0.5 unist-util-visit@^5.0.0
```

The second part is the peer set (React 19 assumed present). `@mgamadeus/ddd-admin-ui`, `@mgamadeus/ddd-web` and
`lucide-react` are optional peers: only `/voice/admin` loads them.
`react-apexcharts` stays below 1.6.0: from 1.6.0 it needs apexcharts 4, the package draws with apexcharts 3.
The agent skills come with the Composer package (`vendor/mgamadeus/ddd-agents/.claude/skills`), not with this one.

Entries: `@mgamadeus/ddd-agents` (provider and setup), `/provider` (the provider alone), `/chat`, `/renderer`, `/widgets`, `/kit`, `/contract`,
`/client`, `/client/testing`, `/preview`, `/image-render`, `/ui`, `/voice`, `/voice/admin`, and the stylesheet `@mgamadeus/ddd-agents/styles.css`
(import it once). Non-code files (widget documentation, preview fixtures, contracts, the node schema, the
sandbox frame, SCSS tokens) are exported one subpath per file at their path below `src`. React 19 and the libraries listed under `peerDependencies` come from the application.

The request stand-ins of `/preview` (`installWidgetPreviewRequests`) and `/image-render` (`installFrozenWidgetRequests`,
`installLiveWidgetRequests`) replace `XMLHttpRequest` (the transport of axios's browser adapter) until the returned
function is called; the preview and the live capture answer `fetch` as well. The frozen capture leaves `fetch` alone
unless asked: `installFrozenWidgetRequests(responses, fail, { interceptFetch: true })` for an application that reads
through `fetch`. No request needs to route through axios.

## The conversation client over HTTP

`createAgentsHttpConversationClient` (`/client`) binds the client port to the module's own conversation routes
(`AgentConversationReadsTrait`, `AgentConversationWritesTrait`, the application's start route of
`AgentConversationStartTrait`, optionally `AgentSelectableModelsTrait`):

```tsx
import { createAgentsHttpConversationClient } from "@mgamadeus/ddd-agents/client";

const client = createAgentsHttpConversationClient({
  baseUrl: "/api/agents/conversations", // the #[Route] of the application's conversations controller
  fetchOptions: () => ({ headers: { Authorization: `Bearer ${readAccessToken()}` } }), // read per request
});

<AgentsProvider client={client}><AgentChat /></AgentsProvider>;
```

| Port function | Route below `baseUrl` |
|---|---|
| `listConversations` | `GET /listAllConversations` (root conversations, `agentSpaceId` filter for a key above 0, newest first) |
| `getConversationMetadata` | `GET /{id}` |
| `getConversationRunState` | `GET /{id}/runState` |
| `getNewestMessagesPage`, `getOlderMessagesPage`, `getChangedMessages` | `GET /{id}/messages` (query options `filters`, `orderBy`, `top`, `expand`) |
| `getConversationActivity` | `GET /{id}/activity` |
| `searchConversations` | `GET /search` |
| `searchConversationMessages` | `GET /{id}/messages/search` |
| `startConversation` | `POST /start`, or `POST /start/{agentSpaceKey}` for a key above 0 (`buildStartPath` changes it) |
| `postMessage`, `injectSignal`, `retryConversation` | `POST /{id}/postMessage`, `/{id}/injectSignal`, `/{id}/retry` |
| `renameConversation`, `deleteConversation` | `PATCH /{id}/title`, `DELETE /{id}` |
| `getSelectableModels` | `GET /selectable-models`, only with `hasSelectableModelsRoute: true` |

Further options: `fetch` (default: the global one), `extraRequestParameters` (query parameters per operation),
`polling` (the intervals of the live updates, or `false` for a host with its own push transport), `clientContext`
(below). Attachments travel in
the message text (`composeAttachmentMessageText`). Every failure rejects with `AgentsClientError`: the `errorCode` of the
body first (`AI_BUDGET_REACHED`, `RATE_LIMIT_EXCEEDED`, `CONVERSATION_CLOSED`), then the status (429 with `Retry-After`,
404, 401/403, 5xx), a transport failure as `network`, a cancelled request as `other` / `REQUEST_ABORTED`. The wire types
(`Wire…`) and the mappers (`mapWireConversation`, `mapWireConversationMessage`, …) are exported for a binding of its own
over the same routes.

**Client context.** `clientContext(operation)` tells the agent what the owner sees: it is read on every start, posted
message and injected signal and sent as the body field `clientContext` — the route path, the route parameters (a flat
map), the record kind and id, the tab (`AgentsClientContext`). The server stores it on that owner message, and the agent
reads the newest one as one line beside the owner's newest message (never part of the transcript). Caps
(`AGENTS_CLIENT_CONTEXT_LIMITS`): 512 characters for the path and for the parameters as `name=value&…`, at most 20
parameters, 64 for the kind and the tab, 128 for the id. The binding leaves out a field over its cap or with a control
character, never cuts it (`mapClientContextToWire`), so a send never fails on it; the server refuses such a field with
HTTP 400.

```ts
const client = createAgentsHttpConversationClient({
  baseUrl: "/api/agents/conversations",
  clientContext: () => ({ routePath: location.pathname, routeParameters: readRouteParameters(), recordKind: "ticket", recordId: currentTicketId, tab: currentTab }),
});
```

**Live updates.** The client comes with the polling default over `GET /{id}/runState`, which reads at once after a post,
a signal or a retry the server accepted. A binding of its own gets the same from `withPollingChangeSubscription(client)`;
the port's optional `getConversationRunState` is read before `getConversationMetadata` when a binding has it.
`useAgentsConversationClient()` (`/client`, `/chat`, the root and `/provider`) returns the client of the chat around —
the one of `AgentsProvider`, or the `client` an `AgentChat` got as a prop.

### A binding of its own (adapter)

A host may serve the port from its own data layer instead (its API client, its store), with the wire types and the
mappers above. One trap with RTK Query: a binding that reads with forced refetches and holds no subscription (a
`initiate(…, { forceRefetch: true })` per port read, unsubscribed at once) must **not** set `keepUnusedDataFor: 0` on
those endpoints. With 0 the store drops the cache entry the moment the first read ends and aborts a request still in
flight when a second read of the same key arrives — the chat then shows an empty conversation after a page load with a
conversation in the URL. Keep the default (or any value above 0) and let the forced reads refresh the entry.

## Agent.UI on the client (`/ui`)

The UI tools of the agent (`getCurrentScreen`, `listScreens`, `openScreen`, `highlightOnScreen`, optionally
`setAgentWindowMode` and `actOnScreen`) send requests to the user's open client; `useAgentUIClient(adapters)` runs them and the
conversation client posts the answers (`createAgentsHttpConversationClient({ uiClient })`).

```ts
const uiClient = useAgentUIClient({
  targets: createDomScreenTargetSource(),            // the data-agent-anchor marks of the navigation
  navigator, reader, highlighter,                    // the application's router, DomScreenReader, DomScreenHighlighter
  isVisible: () => document.visibilityState === 'visible',
  picture: createDomScreenPictureCapturer(),          // optional: getCurrentScreen(format: picture)
  pathGuide: createDomScreenPathGuide({ findPathElements, currentRoute }), // optional
  agentWindow: createAgentWindowAdapter({ getMode, applyMode, modes: ['docked', 'aside'] }), // optional
  actions: new DomScreenActor({ registry, currentLocation, describeBlockers, highlighter, describeAction, settle }), // optional: actOnScreen
});
```

- **Filter values**: a `ScreenTargetFilter` may say what its value is: `values` (the stored values of an enumeration
  or a sort), `multiple` (a comma list of them), `hint` (`date YYYY-MM-DD`, `id`) and `tabs` (the tabs of a record
  screen whose list the filter belongs to; `screenTargetFiltersOfTab(target, tab)`). `listScreens` names them when the
  search is narrowed to at most `MAX_TARGETS_WITH_FILTER_VALUES` screens, and always for the screen whose key is the
  search text (`formatScreenTargetFilterValues`); a catalogue too long for full lines lists every screen in a short
  line (`formatScreenTargetShortLine`) instead of leaving screens out; an
  opened screen leaves out a value its filter does not take and says so (`details.filtersNotSet`); `setFilters`
  refuses one with `UNKNOWN_FILTER_VALUE`. A navigator that answers `currentTarget()` returns the target with the
  filters of the open tab only, and `describeFilters` names their values in the read.
- **Screen actions** (`actOnScreen`, off on the server unless `AI.agentUI.screenActionRequests`): with the `actions`
  adapter the agent can sort a table, open a row, switch a tab and press a button, and with a navigator that answers
  `currentTarget()` and `applyFilters(target, filters)` set the URL filters the open screen's target declares.
  `DomScreenActor` acts only on an element that declares the action and that a reference of the newest read names; it
  takes no selector, types nothing and submits no form:

  | Declaration | Reference | Action |
  |---|---|---|
  | `data-agent-action="sort"` `data-agent-action-key="created"` on the sort control (the state: `aria-sort` of its cell) | `col.created` | `sort` (`asc`, `desc`, `none`) |
  | `data-agent-action="open"` on the element with `data-row-key` | `row.<key>` | `openRow` |
  | `data-agent-action="tab"` `data-agent-action-key="crashes"`, or `role="tab"` | `t.crashes` | `switchTab` |
  | `data-agent-action="press"` `data-agent-action-key="load-more"` `data-agent-action-effect="view"` | `act.load-more` | `press` |

  A button with any other effect, or without a declaration, is never pressed (`WRITE_ACTION`, `NOT_DECLARED`): declare
  `view` only for a button that changes nothing stored. Before it acts the actor marks the element (the highlighter,
  with the note of `describeAction(action, label)`, which the application translates) and waits `ACTION_MARK_BEAT_MS`;
  unsaved changes block what leaves the screen, an open dialog everything outside it. The reader lists what is
  declared (`Filters`, `Tabs`, `Sort`, `Actions` lines; `describeFilters` names the filters and their URL values), so a
  screen without declarations offers nothing. `@mgamadeus/ddd-admin-ui` declares its tables, tabs and filter buttons
  and brings the navigator and the actor's options.

- A mark: `<a href="/orders" data-agent-anchor="orders" data-agent-group="Sales" data-agent-filters="customerId">`;
  further attributes `data-agent-label`, `data-agent-route` (`/orders/:orderId`), `data-agent-parameters`,
  `data-agent-tabs`, `data-agent-views`, `data-agent-anchor-group` (with `groupKey`).
- In a picture, passwords show as dots, `data-agent-secret` content is blanked and `data-agent-skip` elements are left
  out (mark the agent window `data-agent-skip`); what a picture cannot show is `PICTURE_LIMITATIONS`.
- The agent window steps aside before a screen is opened, marked or pictured, and comes back after a picture. Its
  mode (`getMode`) and the browser size ride with the UI state into the `[SIGNAL:ui]` note.
- `new DomScreenReader({ findRoot, currentLocation, describeUnsavedChanges, registry, findNavigation,
  describeAgentWindow })`: `findNavigation` returns the main menu (entries `data-nav-key`, groups `data-nav-group`,
  read as `nav.<key>`), `describeAgentWindow` says where the agent window is (`full screen: it covers the page`).
- A picture shows the browser window only: elements out of view are not copied.
- A host push transport hands each message to `acceptArrivedRealtimeMessage(conversationId, message, uiClient, sendAnswer)`;
  without one the run-state read delivers. A transport that only pings calls
  `readRunStateAfterClientToolRequestPing(conversationId, client.getConversationRunState, uiClient)`: a read that
  finds no request is repeated once after `CLIENT_TOOL_REQUEST_PING_REREAD_DELAY_MS` (400 ms).
- A client that shows no conversation reads the messages addressed to its account (e.g. a call's ring) with
  `createAccountRealtimePoll({ readAccountRealtime: client.getAccountRealtimeMessages, onMessage, onMessageCleared,
  onListenerCredential, isTransportBound })`: a read at once, then every 5 s while no transport is bound; `onMessage`
  gets each message once, `onMessageCleared` each message a read finds gone, once (the answer holds no message of its
  type and key any more: it was answered or withdrawn on the server, or its time is over; a replacement is an arrival).
  `poll.subscribe({ onMessage, onMessageCleared })` adds a subscriber after the poll was created: it gets the messages
  open now, then every arrival, and the clear only of a message it was handed (a message past its `secondsLeft` when
  the subscriber came is not handed to it, and neither is its clear); the function it returns removes it.
  A transport hands what it carries to `poll.acceptArrivedMessage(message)`; one that only pings calls
  `poll.readAfterPing()` (one re-read after 400 ms when nothing new arrived); while a transport is bound, only such a
  read or `poll.readNow()` tells a clear. The account read always states a client id (query parameter `clientId`, as
  on the run-state read): the server knows a client that only polls by that id. The HTTP binding decides which: the
  `clientId` option of the poll (`createAccountRealtimePoll({ …, clientId })`, for a host that manages its own ids),
  else the id of its `uiClient`, else an id it creates once and keeps while it lives. The conversation reads state a
  client id only with a `uiClient`. `runClientToolRequestForDevelopment(uiClient, 'LIST_TARGETS')` tries the
  adapters without a model.

## Texts and translations

Every interface text of the package goes through `AgentsEnvironment.texts.translate(key, replacements, context)`; the key
is the English text with `%placeholders%`, `context` the plural category (`one` / `other`) where a count decides.
`@mgamadeus/ddd-agents/i18n-keys.json` lists every key: `key`, `defaultText`, `placeholders`, `contexts`, `usedIn` (the
source files). Set `texts` app-wide (`setAgentsEnvironment`) or per subtree (`<AgentsProvider environment={{ texts }}>`).

```ts
setAgentsEnvironment({
  texts: { translate: (key, replacements) => fillPlaceholders(dictionary[key] ?? key, replacements) },
});
```

Icon-only buttons carry their accessible name through the same function (the composer's send button: `Send message`;
the scroll-to-latest button beside it: `Scroll to the latest message`). In the package, `pnpm i18n-keys` rewrites the manifest from the sources; the build and the specs fail when it is stale. A
key the generator cannot see as a literal is wrapped in `textKey("…")`. The admin screens of `/voice/admin` are the one
exception to the first sentence: they ask the text function of `@mgamadeus/ddd-admin-ui` (see there); the manifest lists
their keys with their contexts.

## Links, entities and default components

- `AgentsEnvironment.onNavigate(href, event)` decides where every package link goes — Markdown links, sources, kit links
  and the links opened by code (a video's watch page, an `openUrl` action, a gallery image without a lightbox). Return
  `true` when the host navigated (an in-app route), `false` for the default new tab. `AgentsLink` and
  `useAgentsLinkNavigation()` give a host's own widget the same behaviour.
- `AgentsEnvironment.onOpenEntity(target)` opens the entity of an `openEntity` action on a host route; return `false` to
  open the `entityDetail` canvas as before.
- `AgentsEnvironment.markdownImageHosts` lists the hosts an image in model Markdown may load from (`cdn.example.com`,
  `*.example.com`, https only), beside the page's own origin and inline raster `data:` images. Any other image renders
  as a link: model text is foreign, and an image URL would carry what the model knows to that host.
- `DEFAULT_AGENTS_COMPONENTS` (root, `/provider`) holds the package's default components, so a replacement can wrap one;
  `MarkdownLink` (`/renderer`) is the chat's link component, which a `Markdown` replacement receives as `components.a`.

## Drive file cards (`DriveFiles`)

The widget `DriveFiles` (written by the tool `showDriveFiles`) shows files of a drive store as cards: name, type, size,
date, a thumbnail, open (an office file opens its PDF twin of the same name, else it only downloads), download and a
link to the file's place in the host's drive screen. The block names a store and references only; the cards are read
through the source the host registers once, before the first chat:

```ts
import { registerDriveFileCardsSource } from "@mgamadeus/ddd-agents";

registerDriveFileCardsSource({
  readFileCards: (request, signal) => …,     // DriveFileCard[] of the block, under the viewer's rights
  readFileContent: (card, disposition) => …, // { url, fileName? }: "inline" opens, "attachment" downloads
  buildDriveScreenHref: (card) => …,         // optional: the screen link when a card carries no openLink
});
```

Without a source the card says the drive is not connected. A card's `openLink` is followed only as a path of this app
or a same-origin URL, through `onNavigate`. A cloud drive host uses the source of `@mgamadeus/ddd-clouddrive/agents`.

A store can open its files and folders in the canvas beside the chat instead of a new tab. It registers a canvas kind
and an opener for its store name; stores without an opener keep the tab and the download:

```ts
import { registerCanvasKind, registerDriveFileCanvasOpener } from "@mgamadeus/ddd-agents";
import { canvasInstanceIdForReference, referenceOfCanvasInstanceId } from "@mgamadeus/ddd-agents/renderer";

registerCanvasKind({
  canvasId: "exampleDriveFile",
  behaviour: { lifetime: "pinned", layout: "split", narrowViewport: "overlay", fullBleed: false },
  body: ({ canvas }) => <FileViewer reference={referenceOfCanvasInstanceId(canvas.instanceId)} />,
  openedByWidgets: ["DriveFiles"],
});
registerDriveFileCanvasOpener("drive", (card) =>
  card.isFolder ? null : { canvasId: "exampleDriveFile", instanceId: canvasInstanceIdForReference("exampleDriveFile", card.reference) },
);
```

The opener answers per entry (`null` = no canvas for this one); the instance id carries the store's reference only, and
the body reads the file itself under the viewer's rights. `@mgamadeus/ddd-clouddrive/agents` brings both for the cloud
drive (`registerCloudDriveFileCanvas`).

## Canvas kinds

A canvas is declared by its behaviour (`CanvasBehaviour`: lifetime, layout, narrow viewport) and its body (the component
the pane mounts). An app declares the canvases of its own widgets as before (`AgentsEnvironment.furtherCanvasBehaviours`
+ `registerCanvasBodies`). A further package brings a whole canvas in one call, `registerCanvasKind({ canvasId,
behaviour, body, openedByWidgets?, featureFlag? })`, outside React and before the first render: the app declares
nothing for it. `openedByWidgets` names the widgets in a message the canvas opens from; after a reload its pointer
restores only while the anchor message still carries one of them. An id of the package's own canvases is refused; for
any other id the app's `furtherCanvasBehaviours` entry wins over the kind's behaviour, so a host can tune a kind (its
width, its lifetime). `unregisterCanvasKind(canvasId)` takes a kind off again.

## AgentChat in a host window

| Prop | Default | |
|---|---|---|
| `disableSplitView` | `false` | the message column without the canvas split |
| `hostHasCanvasSurface` | `!disableSplitView` | whether a canvas may open (with the split off: in a surface of the host's own, e.g. `CanvasPane`) |
| `backgroundPaintedByHost` | `true` | `false` paints the pane with `--agents-chat-background` |
| `showsTopBlurBand` | `false` | the frosted band under a floating top bar, following the chat pane |
| `composerRef` | — | `setComposerDraft(text)` puts a draft into the mounted composer, never sends |
| `ThinkingIndicator` | `AgentChatActivityIndicator` | the activity at the tail of the thread; `AgentChatActivityStack` is the stacked feed (one sentence per tool call, older lines drifting up under a top mask, a polite status region for the newest), and `(props) => <AgentChatActivityIndicator {...props} dotsVariant="pulse" />` the calm dots |
| `ThinkingIndicator` mounting | — | always mounted, also between turns: the resting avatar under the last message (`active` and `isAgentWorkingOnTask` false); its props carry the `chatStore` and `unansweredWidgetMessageId` (an open widget question) |
| `AssistantAvatar` | — | a face left of each assistant message, at its top (`AgentChatAssistantAvatarProps`: the message) |
| `onMessageSent` | — | `(messageText) => …` once for every accepted send (typed, a widget's, the opening request's), e.g. a send animation |
| `inputActionsSlot` / `inputActionsSlotStart` | — | host controls in the composer's action row; the package fills only its own icons, a host icon keeps its own fill |
| `activityTiming` | `AgentsEnvironment.chatActivityTiming`, else the package | the activity read window (3000 ms), the read floor while a turn runs (4000), the line paces (2200 / 1400 with a backlog) and the success beat (1400); a host with a realtime push that writes `activityUpdatedAt` lowers the read window so a narrated line shows at once |

The canvas split fits by the width of the chat's own container (`ResizeObserver`), not the viewport: below 850 px the
open canvas covers the chat. The default `SplitView` honours `coversBelowWidth` the same way; while its side pane covers the chat it takes the full width of the split, the covered chat is `inert` (focus moves into the canvas and back when it closes), and the toggle (named `Close canvas` / `Re-open canvas`, with an icon) floats over the canvas. A host's own `SplitView` must give the covering pane its own stacking context with a z-index above the
conversation's layers (they go up to 10; the default uses 20) and an opaque background, and must keep it from
overflowing horizontally (`box-sizing: border-box`, the split clipped with `overflow: hidden`). The default chat starts its
thread at the top of the pane (`--agents-chat-top-padding: 24px` on its chat area); a host with a top bar floating over
the thread sets that variable on `[data-agents-chat-scroll]` or through its own `chatAreaClassName`.

## Voice (`/voice`, `/voice/admin`)

The voice chat with the agent in the browser window (WebRTC straight to the voice provider) and the agent ringing the
owner's open clients. The chat shows the call's conversation; the routes are `AgentVoiceSessionsTrait`.

```tsx
import { createAccountRealtimePoll } from "@mgamadeus/ddd-agents/ui";
import {
  AgentVoiceCallProvider,
  AgentVoiceIncomingCall,
  createAgentVoiceApi,
  createAgentVoiceFetchBaseApi,
  createAgentVoiceRingSource,
} from "@mgamadeus/ddd-agents/voice";

const agentVoiceApi = createAgentVoiceApi({
  baseApi: createAgentVoiceFetchBaseApi({ fetchOptions: () => ({ headers: { Authorization: `Bearer ${readAccessToken()}` } }) }),
  apiPrefix: "/api/agents/voiceSession", // the #[Route] of the application's voice session controller
});
const accountRealtimePoll = createAccountRealtimePoll({ readAccountRealtime: client.getAccountRealtimeMessages });
const agentVoiceRingSource = createAgentVoiceRingSource(accountRealtimePoll);

<AgentsProvider client={client}>
  <AgentVoiceCallProvider
    api={agentVoiceApi}
    ringSource={agentVoiceRingSource}
    onConversationOfCallChanged={(conversationId) => showConversation(conversationId)}
  >
    <App />
    <AgentVoiceIncomingCall />
  </AgentVoiceCallProvider>
</AgentsProvider>;
```

What the application passes to `AgentVoiceCallProvider`:

- **`api`** (`AgentVoiceApi`): six functions, one per voice route (`openSession`, `hangUp`, `reportDeviceStatus`,
  `readCallState`, `answerRing`, `declineRing`). `createAgentVoiceApi({ baseApi, apiPrefix })` builds it for an
  application whose routes are the trait's own paths with the agent space in the body of `open`; `baseApi` is the
  application's request layer as one function (`request({ url, method, body })` answering a Promise), for which
  `createAgentVoiceFetchBaseApi()` is the binding over `fetch`. An application whose `open` names its space in the path
  adds `buildOpenPath: (agentSpaceId) => string` (`AgentVoiceOpenPathBuilder`), the whole path of `open` for the space
  the start was given (undefined when it named none); the body still carries `agentSpaceId`, the other five routes stay
  below `apiPrefix`, and without the option `open` is `${apiPrefix}/open`. A refused request rejects with the route's
  error code where `readAgentVoiceErrorCode` finds it (`error`, `data.error`, `code` or the message).
- **`ringSource`** (`AgentVoiceRingSource`): the stream of the `agent.voice.ring` messages.
  `createAgentVoiceRingSource(accountRealtimePoll)` listens to the account realtime poll of `/ui` while the incoming
  call is mounted: a ring message that arrives rings, and one a read finds gone (answered or declined on another
  client, canceled) ends the ring on this client with that read instead of at its deadline. The poll stays the
  application's, which creates and stops it; the ring source never stops it. An application with its own transport
  passes its own source (and reports a cleared ring through `onRingCleared`). Without a ring source nothing rings. The
  backend rings an account only while it knows one of its clients: the account read of the poll states the client's id
  (see `/ui`), with or without a `uiClient`.
- **`onConversationOfCallChanged(conversationId, agentSpaceId)`**: called once per call when the conversation the call
  writes into is known; the application shows that conversation. The provider does it with
  `useFollowAgentVoiceCallConversation(handler)`, which an application may also call itself in a component of its own
  (a window that follows the call without the provider's handler).

The call lives in a store at module level, so it goes on when the chat that shows it closes. `useAgentVoiceCall()`
returns it: `phase` (`idle`, `connecting`, `live`, `ended`, `failed`), `callId`, `conversationId`, `lines` (what was
said), `isMuted`, `endReason`, `elapsedSeconds`; `useAgentVoiceCallValue(selector)` reads one field without rendering
on every transcript delta. Actions: `startAgentVoiceCall({ agentSpaceId?, conversationId?, callId? })` (call it straight
from the click: the microphone prompt needs the user's gesture), `hangUpAgentVoiceCall(reason?)`,
`toggleAgentVoiceCallMute()`, `dismissAgentVoiceCallOutcome()`. The store asks for the microphone, opens the peer
connection and the provider's event channel, reports the device status, and ends the call when the server says it ended.
A start that fails before `open` answers (a refused microphone, a refusal of `open` such as `no_ai_budget`) is the
store's `failed` phase with its `failureTextKey`, so every mounted band of that agent space shows it, inside a chat or
not.

| Component | Where it goes | Props |
|---|---|---|
| `AgentVoiceCallProvider` | once, above every voice component, below the conversation client (`AgentsProvider`) | `api`, `ringSource`, `onConversationOfCallChanged` |
| `AgentVoiceChatStartButton` | the chat input's send spot while nothing is typed | `agentSpaceId?`, `conversationId?`, `disabled?`, `onStart?()`, `testId?` |
| `AgentVoiceCallBand` | over the chat input | `renderAgentSpot?(call)`, `agentSpaceId?`, `isPresented?`, `actions?`, `testId?` |
| `AgentVoiceCallCaptions` | after the chat's message list, inside the chat | `agentSpaceId?`, `ownerMessageTheme?`, `testId?` |
| `AgentVoiceInputGlow` | the chat input's underlay | `agentSpaceId?`, `testId?` |
| `AgentVoiceIncomingCall` | once, outside the chat | `resolveCallerLabel?(ring)`, `renderAgentSpot?()`, `zIndex?`, `testId?` |

- `onStart` of the start button runs in the click, synchronously, before the microphone prompt and `open`: the place
  for what needs the user's gesture (opening a window for the chat). The button's label shows through the
  environment's `Tooltip` when the application replaces it (`AgentsEnvironment.components.Tooltip`, with the text key
  `Talk to %agentName%`); otherwise it is the button's `title`.
- `actions` of the band are the application's own controls, placed before the band's own buttons; they render only
  while the band shows something (a call, a ring, a refused answer, an outcome).
- `zIndex` of the incoming call sets its stacking order over the page; without it the custom property
  `--agents-voice-incoming-call-z-index` does.

- The band also shows the agent's ring while a chat is in sight (`isPresented`); the incoming call then keeps its own
  card away. A surface that covers the chats reports it with `useAgentVoiceRingCover(true)`.
- The server-side end of a call is read after the change signal of the call's conversation
  (`subscribeToConversationChanges` of the conversation client in the React context). An application without that
  client calls `useAgentVoiceCallServerEnd(subscribeToConversationChanges)` with its own signal, or
  `checkAgentVoiceCallEndedOnServer()` when it knows the conversation changed.
- Texts go through `AgentsEnvironment.texts` like every other text of the package; `%agentName%` is filled from
  `AgentsEnvironment.agentDisplayName`. `useAgentVoiceTranslateText()` is that text function for an application's own
  caller line (`resolveCallerLabel`).
- The styles are part of `styles.css`. Every colour and size is a custom property starting with `--agents-voice-`,
  read with its default (`--agents-voice-surface`, `--agents-voice-on-surface`, `--agents-voice-end`,
  `--agents-voice-accept`, `--agents-voice-band-max-width`, `--agents-voice-glow-color-1` … `-4`, …; each component's
  SCSS module lists its own at the top). Test ids start with `agent-voice-`; a component given `testId` takes it on its
  root and `${testId}-<part>` on its inner elements (the band's `-mute`, `-end`, `-close`, …, the incoming call's
  `-accept`, `-decline`, `-detail`, `-refusal`, the captions' `-caption-agent`, `-caption-owner`; the glow has no inner
  test id), each component's docblock lists its parts.

### The admin screens of the calls (`/voice/admin`)

The calls an administrator may read, on the blocks of `@mgamadeus/ddd-admin-ui`: the RTK Query client of the admin
call routes (`AdminAgentVoiceCallsTrait`) and two screens. This is the one entry that loads the optional peers
`@mgamadeus/ddd-admin-ui` (≥ 0.7.0), `@mgamadeus/ddd-web` (≥ 0.13.0) and `lucide-react`; an application that uses the
other entries installs none of them (`boundary.json` names the three for this entry, `scripts/check-package.mjs` fails
the build when another entry loads one).

```tsx
import { AgentVoiceCallDetailScreen, AgentVoiceCallsScreen, createAgentVoiceAdminApi } from "@mgamadeus/ddd-agents/voice/admin";

export const agentVoiceAdminApi = createAgentVoiceAdminApi({ baseApi: adminApi, apiPrefix: "/api/admin/ai/voiceCalls" });

<AgentVoiceCallsScreen
  api={agentVoiceAdminApi}
  onOpenCall={(callId) => router.push(`/voice-calls/${callId}`)}
  buildConversationHref={(conversationId) => `/conversations/${conversationId}`}
/>;
<AgentVoiceCallDetailScreen api={agentVoiceAdminApi} callId={callId} buildConversationHref={(conversationId) => `/conversations/${conversationId}`} />;
```

- `createAgentVoiceAdminApi({ baseApi, apiPrefix })` injects two queries into the host's RTK Query api (one with the
  React hooks module): `useAgentVoiceCallsQuery({ accountId?, conversationId?, createdFrom?, createdTo?,
  bookedSecondsFrom?, bookedSecondsTo?, endReason?, limit?, offset? })` (`GET <prefix>/calls` with `filters`, `top`,
  `skip`; newest first) and `useAgentVoiceCallQuery({ callId })` (`GET <prefix>/calls/{callId}`).
  `createStaticAgentVoiceAdminApi` answers fixed payloads for reviews and tests.
- `AgentVoiceCallsScreen`: one row per call (when it began, the account, who started it, the state with the end
  reason, the duration, the booked seconds, their costs, the task, the conversation); filters for the account, the day
  the call was created, the booked seconds and the end reason; pages of `pageSize` calls (default 50). Props: `api`,
  `title?`, `description?`, `headerActions?`, `onOpenCall?(callId)`, `accountId?`, `conversationId?`,
  `buildConversationHref?(conversationId)`, `linkComponent?`, `pageSize?`.
- `AgentVoiceCallDetailScreen`: the same values in full, the call's times, its provider, and the link that opens the
  call's conversation, whose messages are what was said. Props: `api`, `callId`, `backLink?`, `headerActions?`,
  `buildConversationHref?(conversationId)`, `linkComponent?`.
- Conversation links (`buildConversationHref`) render with the host's router link: `AdminUiProvider.linkComponent`,
  or a screen's own `linkComponent` prop; without either they are a plain `<a>` (full page load). Without
  `buildConversationHref` the conversation shows as its id. `AgentVoiceCallConversationLink` is the same link on its
  own.
- While the voice channel is switched off (`403 feature_not_available`) both screens say so instead of showing an
  error; a call outside what the signed-in account may read (`404 call_not_found`) reads as not found.
- A call's state (ringing, running, ended) is the server's reading at answer time (`callState`): the screens show it
  as it came and derive none from the call's times, so a call a dead session worker left open reads as ended. The
  duration is the difference of the call's two times. Costs are missing while no price is known for the call's model
  or nothing was booked; the screens then show no amount, never zero.
- Date-times: the backend writes them without an offset in the time zone of its PHP processes, and the screens read
  them as UTC, as the admin UI's date cells do; the day filter sends the bounds of the viewer's day in UTC. So the
  application has to run its PHP processes in UTC; in another zone every time on the screens is off by its offset.
- A call's task is written by the agent or the owner: the screens show it as text only.
- Texts: the screens translate through the admin UI's text function (`useTranslate().t`, the marker `m`), not through
  `AgentsEnvironment.texts`, because the blocks they stand on (status maps, filters, badges) do. Their keys are in
  `i18n-keys.json` too (`usedIn` below `src/voice/admin/`), each with the contexts it is asked in (`agent-voice-call`,
  `agent-voice-call-state`, `agent-voice-call-end-reason`, `agent-voice-call-direction`, `agent-voice-call-filter`,
  `agent-voice-pagination`); in the admin UI's translation bundle the key of a text with a context is
  `<text>__<context>`. Every state and every end reason has its own key.
- The screens need `AdminUiProvider` above them and Tailwind scanning
  `node_modules/@mgamadeus/ddd-agents/dist/voice-admin.js`. They use neither the package stylesheet nor
  `AgentVoiceCallProvider`.

## Stylesheet and theme

- The package's root rules (`:root` variables, the image render's `html, body` reset) sit in the cascade layer
  `agents-defaults`: a plain host rule or a host layer declared after it wins (`@layer agents-defaults, base, …;`).
- Dark: `prefers-color-scheme`, `data-theme="dark|light"` on the root element, and `data-agents-theme="dark|light"`,
  which pins over both. `followHostColorScheme({ darkClassName: "dark" })` or `({ attributeName: "data-mode" })` mirrors
  a host theme kept as a class or an attribute into `data-agents-theme`; it returns the stop.
- Colour tokens: set the plain variables (`--agents-fg`, `--agents-fg-cta`, `--agents-shadow`, …); package rules mix
  them with `color-mix()`. The `--agents-<name>-rgb` triplets of 0.1 keep working at the root element: each plain colour
  of the triplet list defaults to `rgb(var(--agents-<name>-rgb))`.
- `--agents-fg-on-cta` is the text on the `--agents-fg-cta` fill (the primary button; white by default in both themes);
  a host whose CTA colour is light in dark mode sets it dark there.
- `--agents-fg-on-accent` is the text on an accent fill (white in light, dark in dark mode); `--agents-fg-on-dark` stays
  white for text over a dark scrim.
