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

The second part is the peer set (React 19 assumed present; `axios` ^0.28.0 only for the optional axios client).
`react-apexcharts` stays below 1.6.0: from 1.6.0 it needs apexcharts 4, the package draws with apexcharts 3.
The agent skills come with the Composer package (`vendor/mgamadeus/ddd-agents/.claude/skills`), not with this one.

Entries: `@mgamadeus/ddd-agents` (provider and setup), `/provider` (the provider alone), `/chat`, `/renderer`, `/widgets`, `/kit`, `/contract`,
`/client`, `/client/testing`, `/preview`, `/image-render`, and the stylesheet `@mgamadeus/ddd-agents/styles.css`
(import it once). Non-code files (widget documentation, preview fixtures, contracts, the node schema, the
sandbox frame, SCSS tokens) are exported one subpath per file at their path below `src`. React 19 and the libraries listed under `peerDependencies` come from the application.

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
`polling` (the intervals of the live updates, or `false` for a host with its own push transport). Attachments travel in
the message text (`composeAttachmentMessageText`). Every failure rejects with `AgentsClientError`: the `errorCode` of the
body first (`AI_BUDGET_REACHED`, `RATE_LIMIT_EXCEEDED`, `CONVERSATION_CLOSED`), then the status (429 with `Retry-After`,
404, 401/403, 5xx), a transport failure as `network`, a cancelled request as `other` / `REQUEST_ABORTED`. The wire types
(`Wire…`) and the mappers (`mapWireConversation`, `mapWireConversationMessage`, …) are exported for a binding of its own
over the same routes.

**Live updates.** The client comes with the polling default over `GET /{id}/runState`, which reads at once after a post,
a signal or a retry the server accepted. A binding of its own gets the same from `withPollingChangeSubscription(client)`;
the port's optional `getConversationRunState` is read before `getConversationMetadata` when a binding has it.
`useAgentsConversationClient()` (`/client`, `/chat`, the root and `/provider`) returns the client of the chat around —
the one of `AgentsProvider`, or the `client` an `AgentChat` got as a prop.

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

In the package, `pnpm i18n-keys` rewrites the manifest from the sources; the build and the specs fail when it is stale. A
key the generator cannot see as a literal is wrapped in `textKey("…")`.

## Links, entities and default components

- `AgentsEnvironment.onNavigate(href, event)` decides where every package link goes — Markdown links, sources, kit links
  and the links opened by code (a video's watch page, an `openUrl` action, a gallery image without a lightbox). Return
  `true` when the host navigated (an in-app route), `false` for the default new tab. `AgentsLink` and
  `useAgentsLinkNavigation()` give a host's own widget the same behaviour.
- `AgentsEnvironment.onOpenEntity(target)` opens the entity of an `openEntity` action on a host route; return `false` to
  open the `entityDetail` canvas as before.
- `DEFAULT_AGENTS_COMPONENTS` (root, `/provider`) holds the package's default components, so a replacement can wrap one;
  `MarkdownLink` (`/renderer`) is the chat's link component, which a `Markdown` replacement receives as `components.a`.

## AgentChat in a host window

| Prop | Default | |
|---|---|---|
| `disableSplitView` | `false` | the message column without the canvas split |
| `hostHasCanvasSurface` | `!disableSplitView` | whether a canvas may open (with the split off: in a surface of the host's own, e.g. `CanvasPane`) |
| `backgroundPaintedByHost` | `true` | `false` paints the pane with `--agents-chat-background` |
| `showsTopBlurBand` | `false` | the frosted band under a floating top bar, following the chat pane |
| `composerRef` | — | `setComposerDraft(text)` puts a draft into the mounted composer, never sends |

The canvas split fits by the width of the chat's own container (`ResizeObserver`), not the viewport: below 850 px the
open canvas covers the chat. The default `SplitView` honours `coversBelowWidth` the same way. The default chat starts its
thread at the top of the pane (`--agents-chat-top-padding: 24px` on its chat area); a host with a top bar floating over
the thread sets that variable on `[data-agents-chat-scroll]` or through its own `chatAreaClassName`.

## Stylesheet and theme

- The package's root rules (`:root` variables, the image render's `html, body` reset) sit in the cascade layer
  `agents-defaults`: a plain host rule or a host layer declared after it wins (`@layer agents-defaults, base, …;`).
- Dark: `prefers-color-scheme`, `data-theme="dark|light"` on the root element, and `data-agents-theme="dark|light"`,
  which pins over both. `followHostColorScheme({ darkClassName: "dark" })` or `({ attributeName: "data-mode" })` mirrors
  a host theme kept as a class or an attribute into `data-agents-theme`; it returns the stop.
- Colour tokens: set the plain variables (`--agents-fg`, `--agents-fg-cta`, `--agents-shadow`, …); package rules mix
  them with `color-mix()`. The `--agents-<name>-rgb` triplets of 0.1 keep working at the root element: each plain colour
  of the triplet list defaults to `rgb(var(--agents-<name>-rgb))`.
- `--agents-fg-on-accent` is the text on an accent fill (white in light, dark in dark mode); `--agents-fg-on-dark` stays
  white for text over a dark scrim.
