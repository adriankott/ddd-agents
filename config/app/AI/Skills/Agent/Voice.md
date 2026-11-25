---
requiredAccountRights: ['useVoice']
decisionCriteria: "Voice calls between {%AGENT_NAME%} and the owner: a call now, the calls ringing or running and hanging up, past calls."
shortDescription: "calls with {%AGENT_NAME%}: it calls you in the app now, running calls and hanging up, the call log"
description: >-
  Voice calls between {%AGENT_NAME%} and the owner: call the owner now (call me, ring me, let us talk: the app rings
  on every open client of the owner), whether a call can reach the owner right now, which calls are ringing or
  running and ending one (hang up), the call history. A call at a later time or after a delay is a scheduled task
  (Agent.Tasks) whose text says to call.
---
# Voice calls with the owner

**Scope and boundaries.** Every question about calls, ringing or running now or past, belongs HERE (`getVoiceCalls`
knows them all), and so does ending one (`endVoiceCall`). `startVoiceCall` calls NOW; a call at a stated time or after
a delay is a task (Agent.Tasks) whose text says to call. What was said on a call is in the conversation the call log
names for it; a call made from this chat left its transcript right here.

## Reachable now

Whether a call can reach the owner at this moment (checked by the platform for this message: a state, never a reason
to refuse a request; answer "can you call me?" from it):

### [voiceAppRing=ringsNow]
- The app rings right now: a client of the owner is open.

### [voiceAppRing=notRingableNow]
- Nothing can ring right now: no client of the owner is open. To be called, the owner opens the app or keeps it open.

### [voiceAppRing=notIncluded]
- Calls are not available to this owner: never offer to call.

## Working rules

- **Calling the owner (`startVoiceCall`)**: the app rings on every open client of the owner at once; the answer names
  for how many seconds.
  - **task**: the call's complete mission written like a full user request (what to go through with the owner and
    what to ask), never a fragment. When the owner gave no topic ("call me"), leave it out: the call then opens by
    asking what the owner needs.
  - **conversation**: `CURRENT` (default) while the call continues this chat's topic: the spoken transcript and
    everything worked on the call land right here, so the owner continues seamlessly in chat. `NEW` only for a goal
    different from this chat's topic.
  - **purposeName** (with `NEW` only): only when the owner asks for exactly that purpose.
  - Answer the owner right away that the app is ringing. A TIMED call (a stated clock time or a delay) is a schedule
    whose task says to call; `startVoiceCall` itself always calls NOW.
  - **Nobody answers**: the call ends on its own; a call-end marker appears in this conversation (reason DECLINED or
    EXPIRED). When a call YOU started rang out (EXPIRED), the platform wakes you once with a follow-up signal: write
    ONE short message, they did not pick up, and ASK whether to try again now or later. Never call in that turn:
    `startVoiceCall` only after the owner says yes in a later message. DECLINED means the owner refused: no follow-up,
    never push; answer the marker only at the owner's next message.
- **Ending a call (`endVoiceCall`)**: ONLY when the owner asks; never end a call unasked. On a call, "hang up", "end
  the call", "bye, that's all" → `endVoiceCall` without `callId`: the line drops right after your answer, so answer
  with ONE short goodbye and nothing else. The request must be in the owner's NEWEST line of THIS call: a hang-up
  asked for on an earlier call of this conversation was done when that call ended (its ended marker says so) and is
  never a reason to end the call running now; an owner asking for anything else is not asking to hang up. In a chat,
  "end the running call" → `getVoiceCalls` with `onlyRunning: true`; one call runs → `endVoiceCall` with its `callId`;
  several → ask which one. A ringing call is withdrawn. Nothing runs → say so plainly, never pretend a call was ended.
- **Call markers in the conversation**: a running or finished call leaves marker messages (a call started; a call
  ended, with its duration and end reason). They are STATUS, not requests: never answer or repeat them; use them as
  context (a call that ENDED means nobody is on the line any more; EXPIRED means the owner did not pick up, DECLINED
  that the owner refused).
- **A refused call**: the refusal names why and the next step (the owner opens the app or keeps it open): pass that on
  plainly. When the AI budget or the call time of the day is used up, calls pause; do NOT retry.
- **Calls running and past**: "which calls are running?" → `getVoiceCalls` with `onlyRunning: true`; "which calls
  were there?" → `getVoiceCalls` (state, who started it, duration, end reason, task; the call of this conversation is
  marked). The CONTENT of a call lives in its linked conversation: for a call from THIS chat it is already here; for
  any other call the call log names the conversation that holds it.
