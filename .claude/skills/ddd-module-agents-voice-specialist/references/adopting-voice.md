# Adopting voice in an application

**Reader:** host developer

Contents: what an application without voice gets · the steps · the library and what works without it · the
configuration · the price of a second · checking the installation.

## What an application without voice gets

Nothing to do. The shipped `config/app/AI/agentVoice.php` sets `enabled` to false, and then:

- the tool package `AgentVoiceCallsMCPPackage` is not available, so the skill `Agent.Voice` is not offered (the shipped
  `skillPackages.php` binds it; a binding to an unavailable package is left out);
- the call table is not required (`app:agent:check-installation`, check `missingTables`);
- the right `useVoice` is not reported as held by nobody;
- every route of `AgentVoiceSessionsTrait` an application mounted answers `403 feature_not_available`;
- `app:agent:reap` reads no call.

## The steps

1. **The library.** `composer require ratchet/pawl react/event-loop`: the session worker speaks to the provider over
   a WebSocket. The package only suggests the two, because not every application runs voice.
2. **The config.** Copy `config/app/AI/agentVoice.php` into the application (`AI.agentVoice`, replaced as a whole) and
   set `enabled`, the provider's host, model and default voice, and the limits (table below).
3. **The provider key.** One environment variable; the config names it (`apiKeyEnvironmentVariable`, default
   `API_OPENAI_REALTIME_API_KEY`). The key is never in a config file. It is read through the framework's
   `Config::getEnv()`, that is from the process environment a dotenv file fills, not through `getenv()`. Without a key
   no session is requested: `open` answers `502 provider_unavailable` and the log names the variable.
4. **The call table,** through the schema diff: the table of `AgentVoiceCall`. The installation check requires it
   only while `enabled` is true and `callStore` is `entity` (the default).
5. **The routes.** Mount `AgentVoiceSessionsTrait` in a controller of the application under its own route and
   firewall, and declare `open` (and `ring`, for a "call me" button) over the trait's builder methods
   ([routes, ring, tools and admin](routes-ring-tools-and-admin.md)).
6. **Messenger.** Copy the two transports of `AgentVoiceMessengerTransports::ALL` and the routing of
   `AgentVoiceMessengerTransports::ROUTING_BY_MESSAGE_CLASS` into the application's `messenger.yaml`; run a pool for
   `voice_live_session` and a consumer for `voice_ring_deadline`
   ([call life and the session worker](call-life-and-session-worker.md)). The framework registers neither. Both are
   a commented block headed "only when voice is enabled" in the Messenger template of
   `ddd-module-agents-specialist` and in `examples/workers.yaml`: uncomment the transports, their routing and the
   two consumers.
7. **The realtime binding** must carry an account audience: the ring goes to every open client of the account, not
   to a conversation, and the binding answers whether a client of the account listens. Without a bound transport the
   ring still arrives through the account-level read of the chat traits, within its poll interval; the same read
   states the polling client's presence, so a client that only polls is rung
   ([the ring](routes-ring-tools-and-admin.md)).
8. **The right.** The package declares `useVoice` (`AgentVoiceAccountRights::USE_VOICE`); the application maps it in
   its `AI.accountRights`. The shipped mapping does not name it: nobody holds it until the application says who.
9. **The skill** `Agent.Voice` is bound to the tool package by the shipped `skillPackages.php`. An application that
   replaced that file adds the line `AISkill::AGENT_VOICE => [AgentVoiceCallsMCPPackage::class]`.
10. **Stale calls** need no entry of their own: the cron line of `app:agent:reap` closes them. An application
    command that overrides `closeStaleChannelSessions()` calls the parent.
11. **The price** of a session second for the provider's model in the model catalog (below); without one the seconds
    land on the call and no cost is booked.
12. **The client.** Mount `AgentVoiceCallProvider` of `@mgamadeus/ddd-agents/voice` once (with the api and the ring
    source), the start button and the band of a call in the chat, the incoming call in the layout. The styles come
    with the package's one stylesheet, the texts are the application's translations of the keys in `i18n-keys.json`.
    Optional: the admin list of calls (`AdminAgentVoiceCallsTrait`, the screens of
    `@mgamadeus/ddd-agents/voice/admin`, which need the three optional peers). Details:
    [the web half](routes-ring-tools-and-admin.md).

## The library and what works without it

Whether voice can run in an installation is one answer, `AgentVoiceCallAdmissionService::canVoiceRunHere()`:
`enabled` is true AND the session worker is part of the installation (`isSessionWorkerLibraryInstalled()`).

| Installation | What happens |
|---|---|
| `enabled` false, library or not | switched off (above) |
| `enabled` true, library installed | voice runs |
| `enabled` true, no library | the container compiles; the session worker and the handler of its message are no services; voice reads as switched off for the admission, the channel provider, the tools, the skill and the routes; the installation check reports one WARNING of the check `voiceChannel` that names the two packages. Nothing fails |

The classes that need the library are the ones below `AgentsModule::VOICE_SESSION_WORKER_NAMESPACES`; everything
else in voice (the call, the store, the ring, the admin list, the adapter's session creation) loads without it.

An application whose calls are carried by a worker of its own, without the package's session worker, overrides
`isSessionWorkerLibraryInstalled()` in a same-path subclass of the admission service and answers for its worker
([override points](override-points.md)).

## The configuration

`config/app/AI/agentVoice.php`, read only through the typed value `AgentVoiceConfiguration::current()`:

| Key | Default | Meaning |
|---|---|---|
| `enabled` | `false` | the switch |
| `callStore` | `entity` | the store that keeps calls: `entity` (the package's table) or a name the application maps to a store of its own |
| `provider.default` | `openAiLive` | the adapter a new call uses; a call keeps the name of its adapter |
| `provider.adapters` | `openAiLive` → `OpenAILiveVoiceProviderAdapter` | the registry; an application adds its own adapters here. One class answers to ONE name and reads that name's settings; the same class under a second name is refused when a call resolves it |
| `provider.openAiLive.apiHost` | `api.openai.com` | a provider project with a region of its own has its own host |
| `provider.openAiLive.apiKeyEnvironmentVariable` | `API_OPENAI_REALTIME_API_KEY` | the variable that holds the key |
| `provider.openAiLive.model` | `gpt-live-1` | also the catalog model the seconds are priced by |
| `provider.openAiLive.defaultVoice` | `marin` | |
| `provider.openAiLive.storeSessionAtProvider` | `false` | true sends the provider's documented storage field, false sends nothing |
| `language` | `null` | null: the conversation's language |
| `limits.maximumCallSeconds` | `3600` | a call is ended at this length: the running-call check refuses it there (asked every `limits.admissionRecheckSeconds`, so at most that much later, plus the goodbye) |
| `limits.dailySecondsPerAccount` | `null` | null: no daily cap |
| `limits.ringSeconds` | `90` | how long a ring waits for an answer |
| `limits.speechSilentNudgeSeconds` | `45` | nobody spoke: the model asks once |
| `limits.speechSilentHangUpSeconds` | `90` | still nobody: a short goodbye, then the call ends |
| `limits.sessionSilentSeconds` | `300` | no event from the provider: the call is gone |
| `limits.admissionRecheckSeconds` | `30` | how often a running call is asked whether it may go on |
| `limits.usageBookingMinimumSeconds` | `30` | the smallest slice of seconds that is booked |

The agent's name in every text comes from the persona (`AI.agentPersona.agentName`).

## The price of a second

The price is not in this file: it is the catalog price of the model (`costsPerSessionSecondInUSD` of the model named
by `provider.openAiLive.model`), which an application sets in its `models.php`. A model without that price is not
priced by session time: the worker still writes the seconds onto the call, books no cost, and the admin list shows no
costs.

## Checking the installation

`app:agent:check-installation` after the steps:

| Finding | Meaning |
|---|---|
| `voiceChannel`, subject `AI.agentVoice.enabled` | switched on without the library (step 1) |
| `voiceChannel`, several providers | the application registered two or more voice channel providers of its own; only the first available one in container order is asked. One provider of the application's own beside the package's is no finding: the application's is asked first, the package's is the fallback and is not counted |
| `missingTables` | the call table is missing (step 4) |
| `rightsHeldByNobody`, `useVoice` | voice is on and no role holds the right (step 8) |
