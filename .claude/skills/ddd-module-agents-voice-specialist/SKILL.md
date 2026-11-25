---
name: ddd-module-agents-voice-specialist
description: "Builds, wires and changes the voice channel of mgamadeus/ddd-agents: the owner talks to the agent in the application's window (WebRTC), the agent rings the owner's open clients. Covers the switch in agentVoice.php and the suggested WebSocket library (canVoiceRunHere), the call and its store port (callStore), call admission (right useVoice, daily cap, budget gate, longest call), the session worker (transcript rows, delegation to the agent, booked seconds, silence and limit stops), the provider seam with the GPT-Live adapter, the routes trait and its error codes, the ring message agent.voice.ring, the admin list of calls, the tools startVoiceCall, endVoiceCall, getVoiceCalls with the skill Agent.Voice, the stale-call sweep of app:agent:reap, the web entries voice and voice/admin. Use when adopting voice, adding a provider adapter or a second medium, a call does not start or ring, open answers 403, 409, 410 or 502, a call never ends or is not booked, sizing the worker pool, or changing a voice prompt or limit."
metadata:
  author: mgamadeus
  version: "0.1.0"
  module: mgamadeus/ddd-agents
---

# Agents Voice Specialist

The **voice channel** of the agent: the owner talks to the agent in the application's window, and the agent can ring
the owner. The browser speaks to the voice provider over WebRTC; a worker of the application stays with the call from
its start to its end, writes what was said into the conversation, hands every request of the voice model to the real
agent and speaks the agent's answer. The channel is optional and ships switched off.

> Installation of the agent, transports, the installation check: `ddd-module-agents-specialist`. The realtime port the
> ring is sent over: `ddd-module-agents-widgets-specialist`. Writing a tool or a skill:
> `ddd-module-agents-tools-specialist`, `ddd-module-agents-skills-and-purposes-specialist`.

PHP lives in a folder `Voice/` of every layer: `DDD\Domain\AI\{Entities,Services,Repo\DB,MessageHandlers,Exceptions}\Voice`
and `DDD\Presentation\{Api\Agents,Api\Admin,Mcp\Client}\Voice`. Short names below are relative to the layer's `Voice`
folder.

## Scope

| This skill | Another skill |
|---|---|
| the switch, the config file, the suggested library and what works without it | the installation check as a whole, the cron list (`ddd-module-agents-specialist`) |
| the call, its store port, call life, admission, start and ring | the realtime port and the account-level read (`ddd-module-agents-widgets-specialist`) |
| the session worker, its transports and pool | the six transports of the loop (`ddd-module-agents-specialist`) |
| the provider seam and the shipped adapter | the model catalog and the price per session second (`ddd-module-ai-specialist`) |
| the routes trait, the admin list, the three tools and the agent skill `Agent.Voice` | tool attributes, skill files (`ddd-module-agents-tools-specialist`, `ddd-module-agents-skills-and-purposes-specialist`) |
| the trace events of a call | the trace table and its readers (`ddd-module-agents-observability-specialist`) |
| the core's three questions about calls, answered by `AgentVoiceChannelProviderService` | the seam that asks them, `AgentVoiceChannelService` (`ddd-module-agents-specialist`) |
| the enum of the call's signal sources: its cases and who writes each | what a signal source is, the questions it answers, the registry (`ddd-module-agents-widgets-specialist`) |

Not in the package: a second medium beside the application's window, a ring on a device that has no open client,
written messages to the owner. An application that has them keeps them and binds them to the override points.

## Rules that always hold

1. **Voice is optional and off.** `AI.agentVoice.enabled` is false in the shipped file. Switched off: no tool, no
   skill, no required table; every route answers `403 feature_not_available` before the call store is read; the
   stale-call sweep reads nothing.
2. **One place answers whether voice can run here:** `AgentVoiceCallAdmissionService::canVoiceRunHere()` — switched
   on AND the session worker's WebSocket library installed. The admission of a call start, the channel provider, the
   tool package with its skill, the routes and `app:agent:check-installation` ask it; no other code combines the two.
   The plain `enabled` reading stays for what only reads or tidies the call table (the admin list, the ring expiry
   at a turn start, the stale-call sweep, the table condition).
3. **The library is suggested, never required.** `ratchet/pawl` and `react/event-loop` are in the package's
   `suggest`. Only the classes below the two namespaces of `AgentsModule::VOICE_SESSION_WORKER_NAMESPACES` may import
   them (`AgentsModule::getNamespacesRequiringClasses()`); without the library those classes are no services and are
   never loaded, the container still compiles, and switched-on voice is one named WARNING of the installation check
   (check `voiceChannel`), never a fatal.
4. **Configuration is read only through `AgentVoiceConfiguration::current()`.** A new value is a typed field with
   its default there and in `agentVoice.php`. The provider key is read from the environment variable the config names.
5. **Calls are read and written only through `AgentVoiceCallStoreService`.** A new read or write goes into the
   contract `AgentVoiceCallStore`, the shipped store and the port. Every write names its columns;
   `claimRingingCallAnswer()`, `claimUnansweredCallEnd()` and `addBookedSeconds()` are single atomic statements
   (identifiers quoted through the dialect: MariaDB and PostgreSQL).
6. **A call ends once,** through `AgentVoiceCallsService`. An unanswered end (deadline, decline, account-wide expiry)
   is claimed first; only the winner writes the end signal and the follow-up.
7. **Stored values keep their spelling:** end reasons, directions, trace event kinds, the signal sources of a call
   (`PhoneConversationSignalSource`), the usage scope, envelope and prompt name of a session (public getters of the
   admission service). Applications bind tables that hold them.
8. **Every sentence the voice model is told is a prompt file.** A new sentence is a name in
   `AgentVoiceAIPromptNames`, a file below `config/app/AI/Prompts/AI/Voice/` and a `protected` name getter where it is
   read. A missing file costs its block, never the call.
9. **The provider's wire carries only fields its own reference documents** (the docblock names the page). A failed
   session action is a `BadGatewayException` with the code `provider_unavailable` and the provider's words in the
   previous exception (`describeProviderFailure()`): log and trace only, never a client.
10. **An adapter is resolved by the name on the call** (`RealtimeVoiceProviderAdapter::forCall()`); `open` pins the
    name on a call that carries none before the session is created. One adapter class answers to ONE name: a second
    endpoint of the same provider is a subclass registered under its own name.
11. **The session worker holds one call at a time;** `resetStateOfThePreviousCall()` resets every per-call field at
    an attach. A new field of the worker is added there.
12. **Who pays for a running call is said in one place,**
    `AgentVoiceCallAdmissionService::buildSessionModelCallOfRunningCall()`: the running-call check asks the gate with
    it and the worker books the seconds under it.
13. **An application's second medium, own columns or own rules are same-path subclasses** over the `protected`
    override points each class docblock lists. No branch for a medium the package does not ship.
14. **No application in the package:** no product or agent name, no application's entity or flag. The agent's name
    reaches a text only through the persona (`AI.agentPersona.agentName`).

## Pieces

| Piece | Class / file |
|---|---|
| Config | `config/app/AI/agentVoice.php` → `Entities\AgentVoiceConfiguration::current()`, the only reader |
| Can voice run | `AgentVoiceCallAdmissionService::canVoiceRunHere()`, override point `isSessionWorkerLibraryInstalled()`; the gate `AgentsModule::getNamespacesRequiringClasses()` |
| The call | `AgentVoiceCall` / `AgentVoiceCalls` (state derived: `getCallStateAt()`; medium: `getChannelType()`), repository `DBAgentVoiceCall` / `DBAgentVoiceCalls`; read rights are those of the call's conversation |
| Call store | contract `AgentVoiceCallStore`, port `AgentVoiceCallStoreService` (`getCallStoreClassNames()`), shipped store `AgentVoiceCallEntityStoreService`; the table is in use, and required, only while `enabled` and `callStore` is `entity` (`AgentVoiceConfiguration::isCallTableInUse()`, asked by `AgentVoiceFeatureTableConditionProviderService` and by the evaluation purge) |
| Call life | `AgentVoiceCallsService` (create, answered, the one end path, lifecycle signals on the conversation, `closeStaleRunningCalls()`), `AgentVoiceCallEndsService`, `AgentVoiceCallFollowUpsService`, `AgentVoiceCallTraceEventsService` with `AgentVoiceCallTraceSummary` |
| Call signal sources | the enum `PhoneConversationSignalSource` in `src/Domain/AI/Entities/Conversations/SignalSources/`, outside the `Voice` folders: the registry of signal sources finds it there. Written by `AgentVoiceCallsService` (`PHONE_CALL_STARTED`, `PHONE_CALL_ENDED`), `AgentCallTasksService` (`PHONE_DELEGATION`) and `AgentVoiceCallFollowUpsService` (`PHONE_CALL_UNANSWERED_FOLLOW_UP`); `PHONE_VOICEMAIL` is written by no class of the package and stays for an application that stores it |
| Admission | `AgentVoiceCallAdmissionService` (`findRefusalOfCallStart()`, `findRefusalOfRunningCall()`, `buildSessionModelCall()`, the three stored names of a session's usage), `AgentVoiceCallRefusal` |
| Start and ring | `AgentVoiceCallStartsService` (`startVoiceCall()`, `startRing()`, `resolveCallRoute()`, `getRingTargetNames()`), `AgentVoiceRingService` (answer token, `answerRing()`, `declineRing()`, `expireOverdueRingsOfAccount()`), `AgentVoiceRealtimeMessageTypeProviderService` (`agent.voice.ring`), `AgentVoiceRingDeadlineMessage` and its handler; the ring's document `AgentVoiceRingPayload` with `AgentVoiceRingCallerContext` |
| Provider | seam `RealtimeVoiceProviderAdapter` (`forCall()`, registry `provider.adapters`), `OpenAILiveVoiceProviderAdapter` |
| Briefing | `AgentVoiceSessionContextsService`, `AgentVoiceSessionContext`, `AgentVoiceSettings`, prompt names `AgentVoiceAIPromptNames`, texts through `AgentVoicePromptText::forName()` |
| Session worker | `AgentLiveVoiceSessionsService` (`attachToCallSession()`), `VoiceLiveSessionAttachMessage` and its handler — both in a `SessionWorker` folder, the gated part; `AgentCallTasksService` with `AgentCallTaskStart` and `AgentCallTaskOutcome`; `AgentVoiceTaskProgressSchedule`; transports `AgentVoiceMessengerTransports` |
| Core's call questions | `AgentVoiceChannelProviderService` (the tagged `AgentVoiceChannelProvider`; available while voice can run) |
| Routes | `AgentVoiceSessionsTrait` with its DTOs; builders `buildVoiceSessionOpenedResponse()`, `buildVoiceCallRingingResponse()` |
| Admin | `AdminAgentVoiceCallsTrait` (hooks `findAccountIdsOfListableVoiceCalls()`, `throwForbiddenUnlessVoiceCallsAccessAllowed()`, `findBookedCostsOfVoiceCall()`) |
| Tools | `AgentVoiceCallsMCPPackage`, agent skill `config/app/AI/Skills/Agent/Voice.md` (`AISkill::AGENT_VOICE`, bound in the shipped `skillPackages.php`), right `AgentVoiceAccountRights::USE_VOICE` |
| Stale calls | the sweep of `app:agent:reap` (`AgentReapStuckConversationsCommand::closeStaleVoiceCalls()`) |
| Web | the entries `@mgamadeus/ddd-agents/voice` (the api `createAgentVoiceApi`, the call store, `AgentVoiceCallProvider` and the components, the ring source `createAgentVoiceRingSource`) and `@mgamadeus/ddd-agents/voice/admin` (`createAgentVoiceAdminApi`, the admin screens, three optional peers); texts are keys of `i18n-keys.json`, styles part of the package's stylesheet with custom properties `--agents-voice-*` |

## Codes a client reads

| HTTP | Code | When |
|---|---|---|
| 400 | `invalid_sdp`, `call_not_answered`, `invalid_answer_token`, `no_registered_devices` | a malformed offer; `open` of a ringing call nobody answered; a token that is not this call's; `ring` while no client of the account listens |
| 403 | `feature_not_available`, `no_ai_budget`, `daily_limit_reached` | voice cannot run here, or the admission refused |
| 404 | `call_not_found`, `conversation_not_found` | not there, or not the account's |
| 409 | `call_already_running`, `already_answered` | one call per account; another client won the ring |
| 410 | `ring_expired` | the ring deadline passed |
| 502 | `provider_unavailable` | the provider did not create the session; the call is ended `FAILED` |

A running call that is refused is told first: a used-up budget or daily limit is named, any other refusal — also
`maximum_call_seconds_reached`, the call that has lasted `limits.maximumCallSeconds` — ends without a reason
(`AgentVoiceSessionContextsService::buildRefusedCallGoodbyeInstruction()`); then the worker ends the call with the
refusal's end reason (`LIMIT_REACHED` for every refusal the package ships).

## Task or symptom → reference

### Host developer

| Task or symptom | Reference |
|---|---|
| Switch voice on in an application: the library, the config, the table, the controller, the transports, the right, the price | [adopting voice](references/adopting-voice.md) |
| Voice is switched on and nothing is offered; the installation check reports `voiceChannel`; the right `useVoice` is held by nobody | [adopting voice](references/adopting-voice.md) |
| Which config key does what; a limit or the language changes | [adopting voice](references/adopting-voice.md) |
| Declare `open` and `ring`; a route answers 403, 409, 410 or 502; the ring does not arrive, `ring` answers `no_registered_devices`, a client that only polls is not rung or keeps ringing; build the client (entries, texts, styles, optional peers) | [routes, ring, tools and admin](references/routes-ring-tools-and-admin.md) |
| The agent should call the owner; the tools and the skill; the admin list of calls and its costs | [routes, ring, tools and admin](references/routes-ring-tools-and-admin.md) |
| Size the worker pool; a deploy cuts calls; a call stays running after its worker died; seconds are not booked | [call life and the session worker](references/call-life-and-session-worker.md) |
| Add a condition for a call start, another payer, a store of the application's own, a provider adapter, a further ring target or medium, a worker of the application's own | [override points](references/override-points.md) |

### Package maintainer

| Task or symptom | Reference |
|---|---|
| Change what a call is, how it ends, what is booked, when the worker stops | [call life and the session worker](references/call-life-and-session-worker.md) |
| Add a class that needs the WebSocket library; the container fails without the library | [call life and the session worker](references/call-life-and-session-worker.md) |
| Add an override point, a store method, an adapter method | [override points](references/override-points.md) |
| Run the voice tests; switch voice on in a test | [call life and the session worker](references/call-life-and-session-worker.md) |

## Cross-references

- `ddd-module-agents-specialist` — installation, the installation check, transports and workers, the cron line of
  `app:agent:reap`, the seam `AgentVoiceChannelService`.
- `ddd-module-agents-widgets-specialist` — the realtime port (`AgentRealtimeChannelService`), message types, the
  account-level read a client without a conversation polls.
- `ddd-module-agents-observability-specialist` — trace events (`AIConversationTraceEventsService`), the console
  commands.
- `ddd-module-agents-autonomy-specialist` — the eligibility gate a call start asks before it rings.
- `ddd-module-ai-specialist` — the model catalog, the budget gate (`AIModelCallGateService`), usage envelopes.
