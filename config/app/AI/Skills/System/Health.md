---
decisionCriteria: "The operator asks whether the platform itself runs (does everything run, system status, outage) or wants the health of workers, crons and error groups at a glance."
shortDescription: "reports platform health: workers, crons, error groups and the application's sections"
requiredAccountRights: ['operatePlatform']
subSkillNames:
  - System.Workers
  - System.Crons
  - System.Issues
description: >-
  State of the platform itself (does everything run, system status, outage, Systemstatus, Störung): workers, crons,
  open error groups and the sections the application adds, in one report. Queues and worker processes:
  System.Workers. Cron jobs and their runs: System.Crons. Error groups (exceptions) with stack frames and their
  status: System.Issues.
---
# Platform health

"Does everything run?" is one call: `getSystemHealth`. Each part reads `ok`, `ATTENTION` or `unknown` and says what
is wrong: WORKERS, CRONS, ISSUES and the sections the application adds. One part only: `sections` with that part.

- Report the parts that need attention first, each finding in the result's words; then name the parts that are ok.
  A part that reads `unknown` is not measured here: say so, never call it healthy.
- A finding names a transport, a cron or a count. Do not guess its cause. The detail is read with the sub-skill
  named below: load it when the operator asks for the detail or the cause.
- The result says what is normal on a development environment (no cron runner, workers without a process): report
  it as worded, not as an outage of production.

Sub-skill: queues, worker transports and processes System.Workers.

Sub-skill: cron jobs with schedule, runs and output System.Crons.

Sub-skill: error groups (exceptions) with entry points, events, trend, and their status System.Issues.

Not available here: restarting workers, running, pausing or changing a cron, the database schema diff, deployments.
Say so and name the admin's maintenance screens for it.
