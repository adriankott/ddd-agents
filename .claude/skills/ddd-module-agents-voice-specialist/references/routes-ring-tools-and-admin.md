# Routes, ring, tools and admin

**Reader:** host developer

Contents: the routes · the guards of a route · the codes · the ring · the agent's tools and skill · the core's three
questions · the admin list · the web half.

## The routes

`AgentVoiceSessionsTrait`, mounted by the application like the chat traits. The five routes that name a call are the
trait's; the two that name what the agent works for are declared by the application and call a builder method,
because only the application knows where its request names the agent space.

| Route | Declared by | Body | Answer |
|---|---|---|---|
| `POST …/open` | application → `buildVoiceSessionOpenedResponse()` | `sdpOffer`; optional `conversationId`, `callId` (the ringing call this client answered) | `agentVoiceCall`, `sdpAnswer`, `conversationId` |
| `POST …/ring` (optional) | application → `buildVoiceCallRingingResponse()` | optional `task`, `conversationId` | `callId`, `conversationId`, `ringExpiresAt`, `anyDeviceReached` |
| `POST /{callId}/answer` | trait | `answerToken` | `won`, `conversationId` |
| `POST /{callId}/decline` | trait | `answerToken` | `acknowledged` |
| `POST /{callId}/hangUp` | trait | optional `reason` | `acknowledged` |
| `POST /{callId}/deviceStatus` | trait | `status`; optional `iceCandidateType`, `roundTripTimeMs`, `detail`, `occurredAtUnixMs` | `acknowledged` |
| `GET /{callId}` | trait | | `callId`; once the call is over `endedAt`, `endReason` |

The class docblock of the trait carries a complete controller as an example. All routes but `GET /{callId}` write.

Two ways into a call. The owner calls the agent: `open` creates the call and its session. The agent calls the owner:
the ring reaches every open client of the account, a client answers it with `answer`, and the client that won opens
the session with `open` and the call id. The audio flows between the client and the provider and never passes the
application.

## The guards of a route

1. `authorizeConversationAccess()` of the chat's hooks trait, then `authorizeVoiceSessionAccess()`, which does nothing
   by default and is the place of an application that is stricter for voice. The package ships no request listener.
2. `requireAccountOfVoiceRequest()`: the one place every route resolves the account of its request. It answers
   `403 feature_not_available` unless `AgentVoiceCallAdmissionService::canVoiceRunHere()`, before the call store is
   read.
3. For a call start (`open` without a call id, `ring`): `AgentVoiceCallAdmissionService::findRefusalOfCallStart()`;
   its refusal is answered as 403 with its code.
4. The routes that name a call act on a call of the signed-in account (`findOwnCall()`) or of the account the hook
   `findRingAccountOfCallRequest()` names for the request and the space of the call's conversation (the signed-in
   account by default); any other call reads as `404 call_not_found` (`findCallOfVoiceRequest()`). The hook gets
   the call's space read with the entity rights lifted, so an override checks itself that the signed-in user acts for
   that space before it names another account, and names the signed-in account otherwise.
5. `ring` starts the ring for the account `findRingAccountOfCallRequest()` names for the request and the space the
   route resolved (the signed-in account by default): that account is rung, owns the call and is the one the
   one-call check reads; `open` of the rung call asks the admission again for it. An application in which an agency
   user or a sub-user presses "call me" for the owner of the agent space overrides this ONE method.

## The codes

| HTTP | Code | When |
|---|---|---|
| 400 | `invalid_sdp` | the offer does not start with `v=` |
| 400 | `call_not_answered` | `open` with a `callId` no client has answered yet |
| 400 | `invalid_answer_token` | `answer` or `decline` with a token that is not this call's |
| 400 | `no_registered_devices` | `ring` while no client of the account listens (the ring, below) |
| 403 | `feature_not_available`, `no_ai_budget`, `daily_limit_reached` | voice cannot run here, or the admission refused |
| 404 | `call_not_found`, `conversation_not_found` | not there, or not the account's |
| 409 | `call_already_running`, `already_answered` | one call per account; another client won |
| 410 | `ring_expired` | the ring deadline passed |
| 502 | `provider_unavailable` | the provider did not create the session; the call is ended `FAILED` |

The codes are the message of the framework's exceptions. The provider's own words never reach a client: they go to
the log and the trace.

## The ring

A ring is one message on the realtime port (`AgentRealtimeChannelService`), of a type the package registers
(`AgentVoiceRealtimeMessageTypeProviderService`). There is no ring row: the call is the state of its ring.

| | |
|---|---|
| Type | `agent.voice.ring` |
| Key | the call id |
| Audience | the account: every open client |
| Expires | at the call's ring deadline |
| Payload | `callId`, `conversationId`, `answerToken`, `ringExpiresAt`, `ringExpiresAtUnixMs`, `callerLabelKey`, `callerContext` (an object the application fills: its subclass of `AgentVoiceRingCallerContext`) |
| Cleared | when a client answered or declined, the deadline passed, or the call was withdrawn |

- **Who is rung:** the account of the call. Every open client of that account shows the ring, so a user only sees
  their own rings.
- **Who must listen:** a ring starts only while a client of that account listens
  (`AgentVoiceRingService::isAnyClientOfAccountListening()`, the realtime port's `isClientListening()` for the account
  audience). Otherwise `startRing()` refuses with `no_registered_devices` and `startVoiceCall()` answers that no ring
  reaches the owner now. A bound transport that answers decides. Without its answer a presence statement of a client
  of the account counts while it is fresh (25 s): a client that shows a conversation states it with its run-state
  read, a client that shows none with the account-level read it repeats, each through the query parameter `clientId`.
  So a client that only polls is rung. A client that states no `clientId` is not counted, although the account-level
  read still delivers a ring to it.
- **The answer token** rides the message, is bound to one call and expires with the ring deadline; it is opaque to the
  client. Exactly one client answers (the store's atomic answer claim); every other one is told `already_answered`.
- **A decline** in one client stops the ring in every client; the call ends `DECLINED`.
- **A cleared ring** (answered or declined in another client, withdrawn) stops in a client that only polls with its
  next account-level read: the read no longer holds the message, and the web half's poll reports it as cleared. The
  ring does not run to its deadline there.
- **Expiry:** a ring nobody answered ends the call `EXPIRED` at its deadline: through the delayed message
  `AgentVoiceRingDeadlineMessage` on the transport `voice_ring_deadline`, whenever the account starts or opens a call,
  and at the turn start of the call's conversation. The three meet in the one end claim, so the end is written once.
  A deadline message that is lost leaves no ring behind.
- **The agent is told:** how the call ended reaches the conversation as the call end marker. An `EXPIRED` end of a
  call the agent started begins ONE follow-up turn in which the agent asks what to do; a `DECLINED` ring begins none.
- `callerLabelKey` is a text key of the web half, never a sentence.

The application's realtime binding needs four things for a ring: a path for an account audience, a rule that lets
the account's clients read it, a listener credential issued without a conversation, and its own answer whether a
client of the account listens (`askTransportWhetherClientIsListening()`: a client with a bound transport stops
polling, so its presence statement runs out). Without a bound transport the ring arrives through the account-level
read of the chat traits, within its poll interval, and the same read states the polling client's presence
(`ddd-module-agents-widgets-specialist`, realtime and push).

| Symptom | Cause |
|---|---|
| `ring` answers `no_registered_devices`, or `startVoiceCall` answers that no ring reaches the owner, while a client is open | no client of the account is known as listening: its account-level read states no `clientId` (the package's HTTP client always states one; a conversation client binding of the application's own must), it reads less often than every 25 s, the installation has no shared cache, or a bound transport does not answer for the account audience |
| The ring goes on in a second client after the first answered | that client is not told the clear: its poll was stopped, or it takes the ring from a transport of the application's own, whose ring source reports a cleared ring itself |

## The agent's tools and skill

`AgentVoiceCallsMCPPackage`, bound to the skill `Agent.Voice` (`config/app/AI/Skills/Agent/Voice.md`), requires the
right `useVoice`:

| Tool | Arguments | Answer |
|---|---|---|
| `startVoiceCall` | `conversation` (`CURRENT` or `NEW`); optional `task`, `purposeName` | `callId`, `conversationId`, `ringSeconds`, `note`; a refusal as a sentence |
| `endVoiceCall` | optional `callId` (none: the call of this conversation) | `callId`, `outcome`, `note` |
| `getVoiceCalls` | `onlyRunning` | the calls of the signed-in account |

The tools act on the signed-in account's calls only. The package is available only while voice can run
(`AgentVoiceCallsMCPPackage::isAvailable()` asks `canVoiceRunHere()`), so the skill is offered to nobody otherwise.

`startVoiceCall()` of `AgentVoiceCallStartsService`, in order: nothing for an agent space or account that takes no
automation any more; a purpose only for a call in a NEW conversation; never while the owner is on a call with the
agent already; then the medium (`resolveCallRoute()`), then the ring (`startRing()`). A ring that reaches no target
ends the call `FAILED` at once.

## The core's three questions

The agent core asks `AgentVoiceChannelService` for the channel of a live answered call, which call channels reach the
owner now, and the task texts of a conversation's calls. The package answers them itself through
`AgentVoiceChannelProviderService`, which is available while voice can run. An application registers nothing.

## The admin list

`AdminAgentVoiceCallsTrait`, mounted in a controller of the application's admin API: the list of calls (query
options) and one call.

- **Whose calls:** decided on the server by `findAccountIdsOfListableVoiceCalls()` (the account ids; null means every
  account), as a filter of the store's query for the list and the detail alike. The default answers only the signed-in
  account's own id; an application with an oversight role overrides it. A call outside the answer reads
  `404 call_not_found`. `throwForbiddenUnlessVoiceCallsAccessAllowed()` decides who may use the endpoints at all.
- **Switched off** (`AI.agentVoice.enabled` false): both routes answer `403 feature_not_available`. The list reads
  the plain switch, not `canVoiceRunHere()`: an installation without the library still reads the calls it has.
- **Filter and sort** through the query options: `accountId`, `startedAt`, `bookedSeconds`, `endReason` (also `id`,
  `direction`, `endedAt`, `aiConversationId`, `voiceProvider`). Neither the duration nor the costs is a column: both
  are filtered and sorted through `bookedSeconds`.
- **What the server derives:** the list answers `agentVoiceCallDerivedValues` beside `agentVoiceCalls` (one entry per
  call, same order), the detail `callState` and `bookedCosts`. Both fields of the list are object sets, so each is an
  object with `elements` on the wire; an entry of `AdminAgentVoiceCallsDerivedValues` is held under its call's id
  (`callId`). The state (`RINGING`, `IN_PROGRESS`, `ENDED`) is the server's reading at answer time; a row a dead
  worker left open reads `ENDED`. `bookedCosts` is the call's booked seconds at the catalog's present price per
  session second; no price, or nothing booked: the field is missing. An application with a cost ledger answers its
  own value in `findBookedCostsOfVoiceCall()`.
- **Transcript:** the detail carries the conversation id; a screen opens the conversation.
- **Date-times** are serialized in the PHP default time zone of the process, without an offset. The admin screens
  read them as UTC: an application whose processes do not run in UTC shows shifted times there.
- A call's task text is written by the agent or the owner: a screen shows it as text only.

## The web half

Two entries of the package's npm half; `web/public-api.json` lists every name they export:

- `@mgamadeus/ddd-agents/voice`: the adapter the application fills (`createAgentVoiceApi` builds it for an
  application whose routes are the trait's own paths, over `createAgentVoiceFetchBaseApi` or a request function of
  the application), the call store outside React with its hooks, `AgentVoiceCallProvider` (mounted once) and the
  components of a chat (`AgentVoiceChatStartButton`, `AgentVoiceCallBand`, `AgentVoiceCallCaptions`,
  `AgentVoiceInputGlow`), `AgentVoiceIncomingCall`, and the ring source.
- `@mgamadeus/ddd-agents/voice/admin`: the api of the admin routes (`createAgentVoiceAdminApi`), the list of calls
  (`AgentVoiceCallsScreen`) and one call (`AgentVoiceCallDetailScreen`).

| | |
|---|---|
| Texts | keys of the manifest `i18n-keys.json`, translated by the application through the text function of the package's environment (`AgentsEnvironment`, its field "texts"). `AgentVoiceCallProvider` takes no translate prop; `useAgentVoiceTranslateText` is that function for a voice component of the application's own |
| Styles | part of the package's one stylesheet (`@mgamadeus/ddd-agents/styles.css`); voice has none of its own. Every colour and size is a CSS custom property `--agents-voice-*` with a default; class names are module-scoped, so an application styles through the properties, never through a class selector |
| Optional peers | `@mgamadeus/ddd-admin-ui`, `@mgamadeus/ddd-web` and `lucide-react`, loaded by the admin entry alone. `web/boundary.json` names per subpath the optional peers it may import (`optionalPeerImports`); check 10 of `web/scripts/check-package.mjs` fails when another entry loads one. An application without the admin screens installs none |
| Ring source | `createAgentVoiceRingSource` takes the application's account realtime poll (`createAccountRealtimePoll` of `@mgamadeus/ddd-agents/ui`): it subscribes and never stops that poll, which the application creates and stops. An application with a transport of its own passes its own source |
| A cleared ring | the poll tells its subscribers a message a read no longer holds (`AccountRealtimePollSubscriber`, `onMessageCleared`), so a ring answered or declined in another client, or withdrawn, stops with that read. A subscriber is told the clear only of a message it was handed |
| The client id | the HTTP conversation client states a client id on every account read: the poll's `clientId` option, else the id of the bound UI client, else one it creates once (`createAgentUIClientId`). It is what lets the server count a client that only polls as listening |
