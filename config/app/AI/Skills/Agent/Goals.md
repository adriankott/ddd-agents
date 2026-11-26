---
requiredAccountRights: ['useAgentGoals']
decisionCriteria: "What the owner wants to reach: read, save or update a goal of the owner or a goal the assistant pursues for them."
shortDescription: "the owner's goal register: read, add, confirm, achieve, drop, block"
description: >-
  What the owner wants to reach and what {%AGENT_NAME%} pursues for them: read the goal register, save a goal the owner
  states (a direction, a goal for the coming months or one for the coming weeks), propose a goal for the owner to
  confirm, and mark a goal confirmed, achieved, dropped, paused or blocked.
---
# The owner's goals

**Scope.** Everything about what the owner wants to reach: a question about their goals → `getGoals`; a goal the owner
states ("I want the weekends booked out", "a second shop by next spring") → `addGoal`; a goal confirmed, reached,
given up, paused or blocked → `updateGoalStatus`. A goal is durable and outlives the conversation. It is not a memory
(a fact, or a rule for how you work: that is the memory skill) and not a step of this conversation's plan.

**Two holders.** An OWNER goal is what the owner wants, in their meaning; it is corrected or dropped, never blocked.
An AGENT goal is what you pursue in service of an owner goal (`parentGoalId` = the owner goal it serves); it has work
beneath it and can be BLOCKED. An owner goal never hangs under an agent goal; a tree is at most three levels deep.

**The tools.**
- `getGoals` — the register, one line per goal with its `#<id>`, holder, horizon, status and scope. Once before the
  first write of a conversation; not again after your own write (the write returns the goal). `includeFinished` adds
  the achieved, dropped and paused goals.
- `addGoal` — ONE goal, the moment it is stated: `statement` as one neutral sentence of the state wanted (the content
  of the owner's words, not your framing); `horizonLevel` only when the owner's words carry one (VISION = a direction
  that never completes, LONG_TERM, SHORT_TERM); `targetDate` only when the owner named a day. `statedByOwner: false`
  stores YOUR proposal as PROPOSED: ask the owner to confirm it. The same statement again changes nothing unless it
  corrects the horizon or the day; a reworded goal replaces the old version.
- `updateGoalStatus` — ONE goal by `#<id>`: ACTIVE (the owner confirms a proposal, or resumes), ACHIEVED (a note on
  what was reached; never a VISION), DROPPED (why), PAUSED, BLOCKED (a goal you hold; the note names the blocker,
  `requiresUserAction` when only the owner can unblock it).
- `getGoalBlueprint` — when it is offered: the standard goal tree of this kind of business, assessed (✅ done, ⬜ open,
  ❔ not yet measured). Propose the most valuable OPEN nodes; add an adopted one with `addGoal`, `blueprintKey` = the
  node's key and `statedByOwner: false`.

**Rules.**
- One goal per call, saved when it is said — never collected at the end of the conversation.
- Never invent a goal for the owner: what they did not say is at most your proposal (`statedByOwner: false`).
- Standard goals come only from `getGoalBlueprint`; never make one up.
- A result that opens with "NOT SAVED AS THE OWNER'S GOAL" saved nothing: follow its note, and never tell the owner
  their goal is saved.
- To change the wording of a goal, add the new wording; never mark a goal ACHIEVED that is not reached.
