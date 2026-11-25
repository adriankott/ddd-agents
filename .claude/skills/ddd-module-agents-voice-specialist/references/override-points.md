# Override points of the voice channel

**Reader:** host developer and package maintainer

Contents: the rule · the ports · can voice run here · admission and who pays · a store of the application's own ·
a provider adapter · a further ring target or medium · the briefing and the prompts · the routes and the admin list ·
the reap sweep · adding an override point.

## The rule

Every port is a service with a default, replaced by a subclass at the same path under the application's root
namespace. A subclass overrides the `protected` methods the class docblock lists as override points and never a
`get…Service()` method outside a test. An application's own columns on the call are a same-path subclass of the
entity, its set and its repository; the package builds them with `X::newInstance()`. The package holds no branch for
a medium it does not ship.

## The ports

| Port | Default | An application overrides it to |
|---|---|---|
| `AgentVoiceCallAdmissionService` | the switch and the library, the right `useVoice`, the daily cap, the model-call gate; for a running call also the longest a call may last | answer whether voice can run with a worker of its own; add conditions for a call start and for a running call; say who pays |
| `AgentVoiceCallStoreService` | the store `entity`: the package's call table | map a further store name to a store of its own |
| `AgentVoiceCallsService` | one medium (the application's window), one provider family | set its own columns on a new call, stop the ring or a leg of a further medium, name the call markers |
| `AgentVoiceCallStartsService` | a call the agent starts rings the owner's open clients | route a call start to another medium, add a ring target |
| `AgentVoiceRingService` | one ring message per call to the account's open clients | fill the caller context (`buildCallerContext()`), name the caller label key, stop a ring of its own |
| `AgentVoiceSessionContextsService` | the briefing of the voice model: instructions, the conversation so far, the delegation rules, the memories | add what it knows of the agent space; take a block from a source of its own |
| the provider adapter | `OpenAILiveVoiceProviderAdapter` | register another adapter under `provider.adapters` |
| `AgentLiveVoiceSessionsService` | the worker of a call of the shipped provider | hooks of a medium of its own; where the voice log is written |
| `AgentVoiceCallsMCPPackage` | three tools on the signed-in account's calls | add arguments of its own, answer its own DTO subclass |
| `AgentVoiceSessionsTrait`, `AdminAgentVoiceCallsTrait` | the routes and the admin list | access rules, whose calls, the costs of a call |

## Can voice run here

`AgentVoiceCallAdmissionService::canVoiceRunHere()` is the one place that answers it; the admission of a call start,
`AgentVoiceChannelProviderService::isVoiceChannelAvailable()`, `AgentVoiceCallsMCPPackage::isAvailable()`,
`AgentVoiceSessionsTrait::requireAccountOfVoiceRequest()` and the installation check ask it.

| Method | Default | Override to |
|---|---|---|
| `canVoiceRunHere()` (public) | `enabled` AND `isSessionWorkerLibraryInstalled()` | add a condition of the installation as a whole (never one of an account: that is a refusal of a call start) |
| `isSessionWorkerLibraryInstalled()` (protected) | whether the gated session worker is part of the installation (`AgentsModule::isClassOfAnInactivePart()` over the worker's class) | answer true for an application whose calls are carried by a worker of its own that does not need the package's session worker |
| `isVoiceSwitchedOnWithoutSessionWorkerLibrary()` (public) | `enabled` and not the above; read by the installation check for its finding | follows the two above |

The configuration value keeps the plain `enabled` reading; nothing but this service combines it with the library.
An application that answers true for a worker of its own also overrides `openProviderSessionForCall()` of the
routes trait, the one place that attaches the package's session worker to an opened call.

## Admission and who pays

- `findRefusalOfCallStart()`, `findRefusalOfRunningCall()`: conditions of the application before or after the
  default (a feature switch, an exemption an operator set). A refusal with a code of the application:
  `AgentVoiceCallRefusal::forCode()`, with the end reason a running call gets.
- `isVoiceRightHeld()`, `findAccountRightsOfCallStart()`: how the right is answered and from which rights.
- `findBilledAccountOfConversation()`, `findBilledEntity()`: who pays. For a running call
  `buildSessionModelCallOfRunningCall()` is the ONE place that says so, to the gate and to the booking alike: an
  application that bills another account overrides it and nothing else.
- `getSessionUsageScope()`, `getSessionEnvelopeScope()`, `getSessionPromptName()`: the stored names the usage is
  booked under.
- `getSecondsOfNextSlice()`: how far ahead the budget is asked.

## A store of the application's own

`AI.agentVoice.callStore` names the store. An application maps a further name in a same-path subclass of
`AgentVoiceCallStoreService` (`getCallStoreClassNames()`), to a service that implements the contract
`AgentVoiceCallStore`. The three claims and counters of the contract (`claimRingingCallAnswer()`,
`claimUnansweredCallEnd()`, `addBookedSeconds()`) must be atomic in such a store too: two clients answer one ring
within the same second. With another store than `entity` the package's call table is not required.

## A provider adapter

A subclass of `RealtimeVoiceProviderAdapter`, registered by name in `provider.adapters` with its settings under
`provider.<name>`. What only a provider can answer is abstract: its model, the wire shape of a session
(`buildSessionCreationPayload()`, `createSessionFromSdpOffer()`), its event channel (`buildSidebandWebSocketUrl()`,
`buildSidebandWebSocketHeaders()`, `isSessionGoneConnectFailure()`, `mapSessionCloseReasonToEndReason()`), hanging up
(`hangUpSession()`), its size limits. The session is driven by the model; the seam knows no tool definitions and no
per-turn events. An adapter reads its key from the environment variable its settings name.

`buildSessionCreationPayload()` gets the seed rows as the set `AgentVoiceSeedInputMessages` (each row an
`AgentVoiceSeedInputMessage`: a role and a text; null means no rows), the most important first; it caps their count
and size and is the one place that turns them into the provider's shape. The set keeps every row under its own
object: two equal rows are two rows, in the order they were added.

A second endpoint of a provider the package ships an adapter for is a subclass of that adapter under its own name,
never the same class under a second name.

## A further ring target or medium

- **A ring target** (another way the same call reaches the owner): `getRingTargetNames()`,
  `isRingTargetReachableNow()`, `ringCallOnTarget()` and `buildRingTargetTraceEvent()` of
  `AgentVoiceCallStartsService`; `AgentVoiceRingService::clearRing()` stops it at the moments the package's ring
  stops; `AgentVoiceCallsService::stopRingingCallBestEffort()` when the call ends before an answer.
- **A medium** (a call that does not run in the application's window): the application overrides `startVoiceCall()`
  with its own arguments after the base's, decides with its subclass of `AgentVoiceCallRoute` and starts its own
  medium; `resolveCallRoute()` is the decision of the base. The entity's `getChannelType()` names the medium of a
  call; `AgentVoiceCallsService::endFurtherLegsOfAnsweredCallBestEffort()`, `hangUpProviderSession()` and
  `getOwnerDeviceOfCallChannelType()` are its points in the call's life; the worker's hooks
  (`prepareApplicationStateOfAttachedCall()`, `applyApplicationSessionEvent()`, `finishApplicationPartOfTheCall()`
  and the others its docblock lists) do nothing by default. `keepCompletedSentenceOutOfTheConversation()` is handed
  each completed sentence as a `LiveTranscriptSentence`.
- **The caller context of a ring** (what the application tells the ringing client about who calls): the fields of
  the application's subclass of `AgentVoiceRingCallerContext` at the same path, named in its `toPayloadArray()`
  and filled in `AgentVoiceRingService::buildCallerContext()`. `AgentVoiceRingPayload::toPayloadJson()` is the one
  place the payload of the ring message is rendered.
- **Who counts as listening** for the package's own ring is the realtime port's answer (`isClientListening()` for
  the account audience). An application with a transport answers it in `askTransportWhetherClientIsListening()`; the
  presence a polling client states is written by the hook `stampUIClientOfAccount()` of the chat traits, whether
  voice is switched on or not (`ddd-module-agents-widgets-specialist`, realtime and push).
- `applyApplicationValuesToNewCall()` (starts and call life) sets the application's columns on a new call,
  `applyApplicationValuesToNewCallConversation()` what it sets on the conversation of a call.

## The briefing and the prompts

`AgentVoiceSessionContextsService`: `findEffectiveVoiceSettingsOfCall()` (the voice settings of a call),
`applyApplicationAdditions()` (what the application adds from what it knows of the agent space, in the fields of its
subclass of `AgentVoiceSessionContext`), a source of its own for one block (`findCallTask()`,
`findCharacterCoreOfConversation()`, `findMemoryDirectivesBlockOfConversation()`,
`findProductCapabilitiesOfConversation()`), and the prompt names of the notes sent while a call runs
(`getSilenceCheckPromptName()`, `getGoodbyePromptName()`, `getBudgetEndPromptName()` and their siblings).

Further seed rows of the application are `buildApplicationSeedInputMessages()` of its subclass of
`AgentVoiceSessionContext`: it returns the set `AgentVoiceSeedInputMessages` (built with
`AgentVoiceSeedInputMessages::fromMessages()`, an empty set for none), placed after the package's own rows.

A sentence itself is changed by replacing its prompt file in the application's config folder
(`config/app/AI/Prompts/AI/Voice/`), not by code.

## The routes and the admin list

`AgentVoiceSessionsTrait`: `authorizeVoiceSessionAccess()`, `requireAccountOfVoiceRequest()`, `findOwnCall()`,
`findConversationOfVoiceRequest()`, `throwForbiddenIfCallStartIsRefused()`, `openProviderSessionForCall()`,
`getVoiceProviderAdapterOfCall()`, `findClientIdOfVoiceRequest()`; each routed method's body is a protected builder
an application calls from its own redeclared route, and each request DTO is read as the application's subclass of it.

`AdminAgentVoiceCallsTrait`: `findAccountIdsOfListableVoiceCalls()`, `throwForbiddenUnlessVoiceCallsAccessAllowed()`,
`findBookedCostsOfVoiceCall()`.

## The reap sweep

`AgentReapStuckConversationsCommand`: `closeStaleChannelSessions()` (an application with a channel of its own adds
its sweep and calls the parent), `closeStaleVoiceCalls()`, `buildClosedStaleVoiceCallLine()`. The age at which a
running call counts as stale is `AgentVoiceCallsService::getRunningCallStaleAfterMinutes()`; the sweep itself,
`closeStaleRunningCalls()`, answers the closed calls as the set `AgentVoiceCalls`.

## Adding an override point

Package maintainer: a new point is a `protected` method with a default, named in the class docblock's list of
override points and in this file; a service the method needs is fetched through a small `protected` getter. No
closure parameter, no returned closure. A decision more than one caller needs stays in ONE method the others ask.

A list of objects on a public or protected signature or on a DTO is an object set, never an array of objects, and
the element class says in its `uniqueKey()` what makes an element one: the object itself where two equal elements are
two and the order counts (seed rows, sentences), a content key where the content is the identity (the derived values
of a call: the call's id). An array stays only as state of a service that is never serialised and never returned,
with a comment that says so; scalar lists and wire, storage and config documents stay arrays.
