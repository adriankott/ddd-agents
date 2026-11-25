# Call life and the session worker

**Reader:** host developer and package maintainer

Contents: the call · admission · the session worker · the two transports and their pools · booking · when a call
ends · stale calls · the gated part · trace events · tests.

## The call

A call is the entity `AgentVoiceCall`: whose it is, the conversation it runs on, the provider's session, the
direction (`INBOUND`: the owner called the agent; `OUTBOUND`: the agent rang the owner), ringing until, answered at,
ended at, the end reason (`COMPLETED`, `ENDED_BY_AGENT`, `LIMIT_REACHED`, `FAILED`, `NOT_ANSWERED`, `DECLINED`,
`EXPIRED`, `CANCELED`), the task the agent was given and the booked seconds. Its state (`RINGING`, `IN_PROGRESS`,
`ENDED`) is derived (`getCallStateAt()`). A call has no agent space of its own: its space is the space of its
conversation, and a call is read under the read rights of its conversation.

Every read and write goes through the port `AgentVoiceCallStoreService`. The store behind it is chosen by `callStore`;
`entity`, the one store the package ships, keeps calls in the package's table through
`AgentVoiceCallEntityStoreService`. A configured name no store answers to is refused on every read and write with an
error that lists the known names.

The package's call table is in use only while voice is switched on and `callStore` is `entity`
(`AgentVoiceConfiguration::isCallTableInUse()`). Two readers outside voice ask exactly that: the installation check
(the table is required then) and the evaluation purge (`AIConversationEvalPurgeService`: the calls of a purged
conversation are deleted then, and no statement touches the table otherwise). Package maintainer: code outside
the store that issues a statement on the call table asks the same method first.

The life after creation is `AgentVoiceCallsService`: answered, the ONE end path (`endCallNow()`,
`persistCallEnd()`, for an unanswered call `persistUnansweredCallEndOnce()` over the store's atomic claim), the call
markers and the transcript rows in the call's conversation, the conversation a call without one runs on.

## Admission

`AgentVoiceCallAdmissionService` answers whether a call may start (`findRefusalOfCallStart()`) and whether a running
call may go on (`findRefusalOfRunningCall()`). Null means yes; a refusal (`AgentVoiceCallRefusal`) carries the code a
route answers and the end reason a running call gets.

A call spends from its first second, and the audio goes straight to the provider, outside every path a model call
takes. So this check is the only thing that can stop the spend, and it is asked BEFORE a session is negotiated.

| Order | At a call start | For a running call |
|---|---|---|
| 1 | `canVoiceRunHere()` | whether the call has run `limits.maximumCallSeconds` since its answer (`maximum_call_seconds_reached`) |
| 2 | the right `useVoice`; a right that cannot be read is not held | the daily limit |
| 3 | the daily limit `limits.dailySecondsPerAccount` against the seconds the account's calls of today booked | the model-call gate |
| 4 | the application's model-call gate (`AIModelCallGateService`): the session is described as a model call, with the cost of the next slice as the estimate | |

- Nothing else ends a call at its longest length: the worker waits on its socket, and no time limit of the process
  counts that wait.
- A gate stage that fails for another reason than a refusal (the budget cannot be read) lets the call through and is
  logged. A call nobody can be billed for is refused.
- A process reads its config once, so a session worker never sees the switch change under a running call; a call
  that runs when voice is switched off goes on to its end or to the restart of its worker.

## The session worker

One message per call, `VoiceLiveSessionAttachMessage`, handled by `AgentLiveVoiceSessionsService`, which stays with
the call until it ends. The voice model drives the session: it starts and ends its own turns, holds no tools and takes
instructions append-only. The worker:

- attaches to the session's event channel (a WebSocket beside the device's media connection; the audio never passes
  the worker);
- answers a delegation of the voice model by running the agent on the call's conversation (`AgentCallTasksService`:
  `startAgentTaskOnTranscript()` answers an `AgentCallTaskStart`, `findAgentTaskOutcome()` an `AgentCallTaskOutcome`),
  returns the answer to the session, and says what the agent does while the turn runs
  (`AgentVoiceTaskProgressSchedule::decideUpdateAt()` answers an `AgentVoiceTaskProgressUpdate`);
- assembles the transcript deltas into sentences (`LiveTranscriptAssembler` completes each as a
  `LiveTranscriptSentence` and answers the completed ones as the set `LiveTranscriptSentences`, in the order they
  closed; two equal sentences are two) and writes them as conversation rows;
- books the billed seconds;
- asks every `limits.admissionRecheckSeconds` whether the call may go on.

The worker survives its own death: the attach message is acknowledged only when the call is over, a lease in the
shared cache keeps two workers off one session, and a successor books from the stored baseline. The fields of the
service are the state of the one call the process accompanies; `resetStateOfThePreviousCall()` puts them back at
every attach. A SIGTERM that arrives during a call (a deploy, a node drain) ends it at the next watcher tick
after one short goodbye without a reason (`buildWorkerStoppingGoodbyeInstruction()`, prompt `AI.Voice.WorkerStoppingGoodbye`),
through the normal teardown with the end reason `COMPLETED`; the owner can call again at once and reaches another
worker. With `endCallOnTerminationSignal: false` the call runs to its end instead. Either way the signal is passed on
to the process when the call is over.

## The two transports and their pools

`AgentVoiceMessengerTransports` lists them; the framework registers neither. An application that does not run voice
copies none of it.

| Transport | Message | Pool |
|---|---|---|
| `voice_live_session` (`AgentVoiceMessengerTransports::LIVE_SESSION`) | `VoiceLiveSessionAttachMessage` | one message = one running call, and the handler returns only when the call is over: the pool size is the number of calls at the same time. A process may take the next call afterwards, so the consumer needs no limit per process. A stop ends a running call within seconds (goodbye plus hang-up): a stop grace of a minute is enough. Only with `endCallOnTerminationSignal: false` must the grace exceed `limits.maximumCallSeconds`. The worker needs outbound WebSocket access to the provider's host |
| `voice_ring_deadline` (`AgentVoiceMessengerTransports::RING_DEADLINE`) | `AgentVoiceRingDeadlineMessage` | one short message per ring, delayed by the ring length plus a few seconds: the transport must keep delayed messages. Any pool size. The handler never throws and a repeated look is harmless, so no retry is needed |

A message the application does not route runs synchronously in the dispatching request: for the attach message that
request would stay with the call until it ends; for the ring deadline the look runs at once, sees a ring inside its
window and does nothing, so the call is ended only when its conversation's next turn starts or its account is looked
at again.

Both message classes are stored names: a rename needs drained queues or an alias in the application.

## Booking

Seconds are booked by the worker, never by the admission service: every slice of at least
`limits.usageBookingMinimumSeconds` through `AISessionUsageService::recordSessionSeconds()`, on the model call the
running-call check asks the gate with (`buildSessionModelCallOfRunningCall()`), and the same seconds onto the call
(`addBookedSeconds()`). A second is recorded as usage once; seconds a failed write of the call did not take go with
its next write. A call nobody can be billed for records no usage; its seconds still land on the call. The names of the
usage scope, the usage envelope and the prompt are methods of the admission service (`getSessionUsageScope()`,
`getSessionEnvelopeScope()`, `getSessionPromptName()`), so an application that already stores other names keeps them.

## When a call ends

| Cause | What the worker does | End reason |
|---|---|---|
| the owner hung up (the route `hangUp`) | the route ends the call | `COMPLETED`; `FAILED` when the client reports that its media connection failed |
| the provider closed the session | ends the call with the adapter's reading of the close reason (`mapSessionCloseReasonToEndReason()`) | `COMPLETED` for a hang-up or a lost connection, `LIMIT_REACHED` for a session the provider let expire, else `FAILED` |
| the agent ended it (the tool `endVoiceCall`, or a goodbye of the agent) | lets the model say its sentence, then hangs up | `ENDED_BY_AGENT` |
| the admission refused the running call | one short goodbye (a used-up budget or daily limit is named; any other refusal, also the longest length, without a reason), then the end | the refusal's, `LIMIT_REACHED` for the shipped ones |
| nobody spoke for `limits.speechSilentNudgeSeconds` | the model asks once; after `limits.speechSilentHangUpSeconds` a short goodbye, then the end | `COMPLETED` |
| no event from the provider for `limits.sessionSilentSeconds`, or the session is gone when the worker attaches | hangs up and ends the call | `FAILED` |
| the worker of a running call is gone for good | the stale-call sweep (below) | `reaped_stale` (`AgentVoiceCallsService::END_REASON_REAPED_STALE`) |
| the provider did not create the session at `open` | the route ends the call | `FAILED` |

Every exit goes through the one end path of `AgentVoiceCallsService`.

## Stale calls

A call whose worker is gone stays marked running. `app:agent:reap` closes such calls in its pass over channel
sessions (`closeStaleChannelSessions()` → `AgentReapStuckConversationsCommand::closeStaleVoiceCalls()` →
`AgentVoiceCallsService::closeStaleRunningCalls()`): a call still running `getRunningCallStaleAfterMinutes()` after
its answer — twice `limits.maximumCallSeconds`, at least 120 minutes — is hung up at the provider and ended. The
threshold never ends a call a worker still drives. Only the calls of the environment's own conversations are closed;
the sweep answers them as the set `AgentVoiceCalls`.

- While `enabled` is false the sweep reads nothing and prints nothing.
- It holds a lock of its own (`AgentReapStuckConversationsCommand::STALE_VOICE_CALLS_LOCK_NAME`): a run that finds the
  previous sweep still closing calls skips the sweep and says so.
- It prints one line per closed call and one count line, each starting with "Reaper:"; a sweep that fails is one
  line and never stops the other passes of the command.
- An application command that overrides `closeStaleChannelSessions()` for a channel of its own calls the parent, or
  the package's sweep no longer runs.

## The gated part

The session worker needs a WebSocket library the package only suggests. `AgentsModule::getNamespacesRequiringClasses()`
maps the two namespaces of `AgentsModule::VOICE_SESSION_WORKER_NAMESPACES` to a class of that library
(`AgentsModule::VOICE_SESSION_WORKER_LIBRARY_CLASS`); without the class the framework registers no service of those
namespaces and no code loads them.

Package maintainer:

- A class that imports the library lives in one of the two `SessionWorker` folders, nowhere else. The module class
  is the one named exception (it names the class the gate asks for).
- Code outside those folders never imports the library and reaches a gated class only behind
  `AgentVoiceCallAdmissionService::canVoiceRunHere()`: the routes fetch the worker from the container after
  `requireAccountOfVoiceRequest()` passed. A class name as a string (the routing table, the gate's own question)
  loads nothing. `AgentVoiceCallsService` hangs a session up through the adapter (`hangUpSession()`), not through
  the worker, so the stale-call sweep and the end path work without the library.
- `tests/Kernel/VoiceWithoutSessionWorkerLibraryTest.php` boots a kernel whose class loader refuses the library and
  pins all of it: the gate map, the imports, the compiled container, the answers of the one place, the messenger's
  handler list and the finding of the installation check.

## Trace events

The route, the ring and the worker build a call's trace events with `AgentVoiceCallTraceEventsService` and write them
into the trace table through `AIConversationTraceEventsService`; the event kinds are constants of `AgentVoiceCallTraceSummary`
(`EVENT_TYPE_*`), which also reads the start-up times of a call from them. A trace write is best effort and never
breaks a call. Audio-typed events are dropped before logging.

The adapter's two REST calls (create a session, hang up) are the package's one raw HTTP client in voice, listed with
its reason in `config/untrusted-sinks-allowlist.php`: the host comes from the committed config, the paths are fixed.
A new adapter that builds an HTTP client of its own gets its own entry.

## Tests

The voice tests live in `tests/Voice/` and run in the package suite, under the package kernel, with voice switched
off by default. Their base classes are `AgentVoiceUnitTestCase` and `AgentVoiceIntegrationTestCase`
(`tests/Voice/Support/`). A test switches voice on through `tests/Voice/Support/AgentVoiceTestConfiguration.php`
(`AgentVoiceTestConfiguration`: declaring a configuration answers the values before, which the test puts back in its
tear-down; `AgentVoiceTestConfigurationTrait` does both for a test of the voice folder). A service is replaced through
its small `protected` getter or through the integration base. A value object is compared strictly, as its class and
every field (`AgentVoiceValueObjectAssertionsTrait`).

| What | Pinned by |
|---|---|
| the typed rows of a call and of its trace summary | `AgentVoiceTypedRowsTest`, `AgentVoiceCallTraceRowsTest` |
| the caller context in the published ring | `AgentVoiceRingCallerContextTest` |
| a ring while only a polling client is open | `AgentVoiceCallStartsServiceStartRingOfAPollingClientTest`, `AgentVoiceRingReachabilityTest` |
| a completed sentence the worker's hook keeps out of the conversation | `AgentLiveVoiceSessionSentenceHoldTest` |
| the voice namespaces of the module | `AgentsModuleVoiceNamespacesTest` |
| an installation without the WebSocket library | `tests/Kernel/VoiceWithoutSessionWorkerLibraryTest.php` |
| the sweep of the reap command | `tests/Symfony/Commands/Agents/AgentReapStaleVoiceCallsSweepTest.php`, `AgentReapStaleVoiceCallsSweepRightsTest` |
| an application with a further medium | the double `AnotherMediumAgentVoiceCall` (`tests/Voice/Doubles/`) |

The three atomic writes of the call store (`claimRingingCallAnswer()`, `claimUnansweredCallEnd()`,
`addBookedSeconds()`) need a database test on MariaDB and on PostgreSQL before a change to them is released.
