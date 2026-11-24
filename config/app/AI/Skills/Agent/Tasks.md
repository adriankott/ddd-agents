---
requiredAccountRights: ['scheduleAgentTasks']
decisionCriteria: "Work outside this turn: a deep job in the background now, something at a set time, or recurring checks."
shortDescription: "runs tasks for you: background work now, one-time at a set moment, recurring"
description: >-
  Work outside this chat turn: a report worked out in the background, a task at a later moment or after a delay,
  recurring checks and reports, the existing schedules (read, change, pause, resume, cancel) and what ran: the runs
  of a period, how often and when a task ran, what a run reported.
---
# Scheduled and background tasks

**Scope.** Work outside the present turn: an analysis that must first be produced, work at a stated time, recurring
runs, and the management of schedules including past reports and the run history. Content you ALREADY have, or can
write in THIS turn (advice, a checklist, a summary of this chat), is no task: answer it or send it directly. A task is
for an analysis over weeks of data or several parts, for a later moment, or for a repetition.

**Two things, two reads.** A SCHEDULE is what is set up (`getTaskSchedules`); a RUN is one execution of it
(`getTaskRuns`). Every question about what ran — in a period, how often, when last, which runs failed — is answered
from the runs, never from the schedule rows; what ONE run reported is `getTaskRunReport`. An owner who asks which
tasks are active, still running or still on asks for SCHEDULES in status ACTIVE — a task is active between its runs
too. Only a question about an execution under way at this moment is a run in progress.

Every run is a conversation of its own; its final report reaches the owner over the task's `reportChannel` — EMAIL
(default, mail to the owner's own address) or CHAT (task list only). The platform delivers it at the end of each run;
a run never sends its final report through another tool.

**When a scheduling tool is not available.** An application may let only some users plan work for later. When the
user asks for something timed or recurring and you do not have the tool for it, say plainly that their account
administrator has to allow scheduled tasks for them, and offer what you CAN do: handle it now in this chat, or run it
as a background task and report back.

## The task text is the MISSION — never the schedule

The `task` text is what the run DOES; the WHEN lives only in `cronSchedule` / `runAtLocalTime` / `runInMinutes`.
NEVER write time-of-day or recurrence wording into the task text (`task: "Every day at 12:00, write…"` → wrong;
`task: "Write…"` → right): a run that reads scheduling words in its mission mistakes them for a setup instruction.
State that carries across runs (a counter, a rotation) never goes into the text either — each run receives the
previous run's outcome line and continues from it.

## Choosing the right tool

- **Work now, in the background → `startBackgroundTask`**: anything too big for this turn. Write `task` as a
  complete, self-contained mission; the background run cannot see this chat.
- **At a specific moment → `scheduleOneTimeTask`**: a wish RELATIVE to now → `runInMinutes`; a stated CLOCK TIME,
  with or without a day → `runAtLocalTime: "YYYY-MM-DD HH:MM"` (local time, the clock the conversation shows you; the
  system converts — never do timezone math yourself). A stated clock time ALWAYS goes into `runAtLocalTime`; pass
  exactly ONE of the two. Fires once, then closes. A timed call to the owner is a one-time task whose text says to
  call, with `reportChannel: "CHAT"` (the call itself is the delivery).
- **Repeatedly → `createRecurringTaskSchedule`**: a cron whose digits are LOCAL time of day (`"0 7 * * 1"` = Mondays
  07:00 local time; the schedule carries its own zone — never convert). Pass `timezoneIdentifier` only when the user
  names another zone. At most four firings per day — an hourly cron is refused. A recurring run that finds nothing
  worth reporting sends no mail.
- **Changing an existing task → `updateTaskSchedule`**: task text, title, timing, cron, report channel — in place,
  NEVER delete and recreate (that orphans the run history). A one-time task moves only while it has not run; a new
  cron applies to recurring tasks only. Only when the owner speaks of THAT task: an order for another report or
  rhythm is a NEW schedule beside the existing ones.
- **What is set up → `getTaskSchedules`**: id, one-time or recurring, status, next firing and task — the id source
  for changing, pausing, resuming and deleting. Only ACTIVE schedules still fire; PAUSED ones wait for
  `resumeTaskSchedule`; DONE / CANCELLED / EXPIRED are history. Narrow with `filters`; the header states how many
  match.
- **What ran → `getTaskRuns`**: the executions across ALL schedules, archived ones included. A period is a filter on
  the start moment (`filters: "created ge '<seven days ago> 00:00:00'"`); one task's runs → `scheduleId eq '<id>'`;
  failed runs → `runState eq 'FAILED'`. A run that is over without the COMPLETED mark is never reported as
  completed. Take every number from the header, never from your own row count; when more exist, page on or say that
  you show a part. A schedule row's "firings claimed" is its quota use, not the number of executions.
- **What one run reported → `getTaskRunReport`**: the run's `conversationId` from `getTaskRuns` (or a schedule's
  newest-run line). Answer from its content in your own words; quote it only when asked. A run in progress has no
  report yet.
- **Pausing for now → `pauseTaskSchedule`**, back on → **`resumeTaskSchedule`** (next firing computed from now —
  confirm that moment). A wish limited in time → pause; a wish that ends the task → delete. A schedule the platform
  paused cannot be resumed by hand — relay the reason the tool gives.
- **Taking a task back → `deleteTaskSchedule`**: every cancel or undo wish means DELETE the schedule, never
  re-schedule or just acknowledge: list, find it, delete it (never ran → gone; recurring that ran → archived, its runs
  stay readable; one-time that ran → stays as history — say so).

## Working rules

- After creating or changing a task, confirm in ONE short sentence WHAT runs and WHEN (repeat the resolved time from
  the tool response).
- After a delete, confirm the outcome the tool reports.
- Name ONLY schedules, runs and times from the CURRENT tool result — never from memory of earlier turns. Runs happen
  in the background: read again when the owner asks again.
- At most 20 active task schedules per space.
- Inside a scheduled run you execute the mission: the tools that create, change or delete schedules refuse there.
