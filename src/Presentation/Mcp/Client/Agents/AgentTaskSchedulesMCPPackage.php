<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\AgentSpaces\AgentSpaceTimezone;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversations;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleCreationLimits;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleOneTimeMoment;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedules;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleStatus;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleTransition;
use DDD\Domain\AI\Entities\Conversations\Schedules\ScheduleBudgetPotStatus;
use DDD\Domain\AI\Entities\MCPPackages\MCPExposedFilterProperties;
use DDD\Domain\AI\Entities\MCPPackages\MCPHiddenQueryOptions;
use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRights;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPRequiresAccountRights;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolParallelSafe;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolRefreshesState;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolSummary;
use DDD\Domain\AI\Entities\Conversations\Widgets\Syntax\WidgetBlockFieldNames;
use DDD\Infrastructure\Libs\Config;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationOwnerReportDeliveryService;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\Base\Entities\QueryOptions\FiltersOptions;
use DDD\Domain\Base\Entities\QueryOptions\OrderByOptions;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Base\QueryOptions\DtoQueryOptions;
use DDD\Presentation\Mcp\Client\Agents\Dtos\BackgroundTaskStartRequestDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\OneTimeTaskScheduleCreateRequestDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\RecurringTaskScheduleCreateRequestDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\TaskRunMcpRow;
use DDD\Presentation\Mcp\Client\Agents\Dtos\TaskRunReportGetRequestDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\TaskRunReportMcpResponseDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\TaskRunsGetRequestDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\TaskRunsMcpResponseDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\TaskScheduleCreateMcpResponseDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\TaskScheduleDeleteMcpResponseDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\TaskScheduleIdRequestDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\TaskScheduleMcpRow;
use DDD\Presentation\Mcp\Client\Agents\Dtos\TaskSchedulesGetRequestDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\TaskSchedulesMcpResponseDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\TaskScheduleUpdateMcpResponseDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\TaskScheduleUpdateRequestDto;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use Throwable;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolReturnsTrustedContent;

/**
 * Scheduled tasks the agent sets up for the owner: a one-off task that runs in the background now, a one-time task at
 * a moment (local time of the space, converted through the schedule's zone), recurring tasks on a cron, the list of
 * schedules and of their runs, a run's report, and changing, pausing, resuming and deleting a schedule (archive policy:
 * never ran → gone; recurring with runs → archived; one-time with a run → stays history). Every run delivers its final
 * report over the schedule's `reportChannel`; the delivery is the platform's, never a tool call of the run.
 *
 * Scope: the space the call names (`agentSpaceId`, or the application's name of it, filled from the conversation when
 * omitted; the whole tool arguments go to {@see AgentSpacesService::findAgentSpaceOfToolCall()}), else the account as
 * a whole (schedules without a space). A schedule is stored for the signed-in account
 * ({@see AIConversationSchedulesService::createSchedule()}). A scheduled run never administers schedules: every tool
 * but `startBackgroundTask` and the reads refuses inside one.
 *
 * Rights: the tools that create or change a schedule — `startBackgroundTask` (it spends budget at once),
 * `scheduleOneTimeTask`, `createRecurringTaskSchedule`, `updateTaskSchedule`, `pauseTaskSchedule`,
 * `resumeTaskSchedule`, `deleteTaskSchedule` — require {@see AgentAccountRights::SCHEDULE_AGENT_TASKS}; the reads
 * (`getTaskSchedules`, `getTaskRuns`, `getTaskRunReport`) require none. An application changes a tool's right by name in
 * `config/app/AI/toolOverlays.php` (`accountRights`, `[]` = none), never by redeclaring the method. An account that may
 * use a space reads that space's schedules and runs, also those that run as another account
 * ({@see isScopeReadWithoutEntityRights()}).
 *
 * Override points for an application (same-path subclass): the payer whose budget pot the tools read
 * ({@see findBudgetPayerOfCall()}), the budget note ({@see composeScheduleBudgetPotNoteOfCall()},
 * {@see composeScheduleBudgetPotNote()}),
 * the monthly cost note ({@see composeMonthlyCostNote()}), the widget of the task list (`AI.agentTasks.taskListWidgetType`,
 * {@see findTaskListWidgetType()}, {@see composeTaskListWidgetNote()}), the phone note of the list
 * ({@see composeTaskListPhoneNote()}), the reads of a scope ({@see findRunConversationsOfScope()},
 * {@see findSchedulesOfScope()}), a schedule a retried call already produced ({@see findScheduleAlreadyProducedByCall()}).
 * The pot of an account (budget, share, spend) is computed in
 * {@see \DDD\Domain\AI\Services\Conversations\Schedules\ScheduleBudgetPotsService}
 * (`findScheduleBudgetPotStatusForAccount()`, read here through
 * {@see AIConversationSchedulesService::buildScheduleBudgetPotStatusForAccount()}); WHICH account's pot the tools read
 * is {@see findBudgetPayerOfCall()}, never {@see requireActingAccount()}: that one only scopes the lists and the
 * targets of a call.
 */
#[MCPToolReturnsTrustedContent('the account\'s task schedules as the platform stores them')]
class AgentTaskSchedulesMCPPackage extends MCPPackage
{
    public static ?string $name = 'agent.tasks';

    public static ?string $description = 'Scheduled tasks: start a one-off background task now, schedule a one-time task at a moment (local time), set up recurring tasks on a cron, read the schedules and the runs that executed (filters, sorting, paging), read a run\'s report, change schedules in place, pause, resume and delete them (archive policy).';

    /** @var int Rows `getTaskSchedules` returns when the call names no `top` */
    public const int DEFAULT_SCHEDULES_PER_PAGE = 50;

    /** @var int Rows `getTaskRuns` returns when the call names no `top` */
    public const int DEFAULT_RUNS_PER_PAGE = 25;

    /** @var int Upper bound of `top` on both reads — the model never pulls an unbounded list into its context */
    public const int MAX_ROWS_PER_PAGE = 100;

    /**
     * @var string|null The widget type that shows the task list on screen, with the space's self-hydration key as its
     * only field; null = the application has none, and `getTaskSchedules` names no widget. The config
     * `AI.agentTasks.taskListWidgetType` wins over it ({@see findTaskListWidgetType()}).
     */
    public const ?string TASK_LIST_WIDGET_TYPE = null;

    /** @var string The config path of the task list's widget type */
    public const string CONFIG_TASK_LIST_WIDGET_TYPE = 'AI.agentTasks.taskListWidgetType';

    /** @var int How many tasks the agent reads aloud on a phone line before it offers the rest */
    public const int PHONE_LINE_TASKS_READ_ALOUD = 3;

    /**
     * Starts a ONE-OFF background task RIGHT NOW: the task runs unattended in a fresh background conversation and its
     * final report reaches the owner over `reportChannel` (EMAIL default). Use it when the user wants a RESULT that
     * first needs real work (an analysis over a past period, a multi-part check) — content you ALREADY have is sent
     * directly, not as a task; a task at a LATER moment is `scheduleOneTimeTask`. Write `task` as the run's complete,
     * self-contained mission (the background run cannot see this chat). Tell the user the report arrives when done.
     *
     * @example {"task": "Analyse the bookings of the last four weeks, summarise the main changes and give three concrete recommendations.", "title": "Booking analysis, last 4 weeks"}
     * @example {"task": "Check every published listing for outdated opening hours and write a prioritised correction list.", "title": "Check listings", "reportChannel": "CHAT"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::WRITE, identicalRepeatDoesNewWork: true)]
    #[MCPHiddenQueryOptions('noCache')]
    #[MCPToolSummary('Start a one-off background task NOW — worked unattended, final report to the owner by email (default). Later moment → scheduleOneTimeTask; repeating → createRecurringTaskSchedule.')]
    #[MCPRequiresAccountRights(AgentAccountRights::SCHEDULE_AGENT_TASKS)]
    public function startBackgroundTask(
        BackgroundTaskStartRequestDto $requestDto,
        AIConversationSchedulesService $aIConversationSchedulesService,
        AgentToolCallProvenance $callProvenance
    ): TaskScheduleCreateMcpResponseDto {
        $agentSpace = $this->findAgentSpaceOfCall($requestDto->agentSpaceId, $callProvenance);
        $actingAccount = $this->requireActingAccount($callProvenance);
        // an immediate task spends NOW: refused on an exhausted pot (of the call's payer) instead of a row that fires
        // after the reset
        $scheduleBudgetPotStatus = $aIConversationSchedulesService->buildScheduleBudgetPotStatusForAccount(
            $this->findBudgetPayerOfCall($agentSpace, $actingAccount, $callProvenance)
        );
        if ($scheduleBudgetPotStatus !== null && $scheduleBudgetPotStatus->isExhausted()) {
            throw new BadRequestException(
                $scheduleBudgetPotStatus->buildAgentFacingSummary() . ' Offer to handle anything urgent DIRECTLY in this chat instead.'
            );
        }
        $schedule = $this->buildAgentSchedule(
            $callProvenance,
            $aIConversationSchedulesService,
            $agentSpace,
            $actingAccount,
            (string)$requestDto->task,
            oneTimeAt: new DateTime(),
            reportChannel: $requestDto->reportChannel,
            title: $requestDto->title,
            purposeName: $requestDto->purposeName
        );
        $schedule = $aIConversationSchedulesService->createSchedule($schedule);
        $backgroundRunConversation = null;
        try {
            $backgroundRunConversation = $aIConversationSchedulesService->startScheduleNow($schedule);
        } catch (Throwable) {
            // the dispatch infrastructure is unavailable: re-arm the occurrence the claim consumed, the due-schedules
            // command fires it once the infrastructure is back
            $aIConversationSchedulesService->refundClaimedOccurrence($schedule, reArmNow: true);
        }

        $responseDto = new TaskScheduleCreateMcpResponseDto();
        $responseDto->scheduleId = isset($schedule->id) ? (int)$schedule->id : null;
        $responseDto->conversationId = $backgroundRunConversation !== null && isset($backgroundRunConversation->id)
            ? (int)$backgroundRunConversation->id
            : null;
        if ($backgroundRunConversation !== null) {
            $responseDto->note = 'Background run started — tell the user in ONE short sentence that the report will arrive '
                . ($schedule->reportChannel === AIConversationSchedule::REPORT_CHANNEL_EMAIL ? 'by email' : 'in the task list')
                . ' when the work is done.';
        } elseif ($schedule->status === AIConversationScheduleStatus::EXPIRED) {
            // the pot ran out between the check above and the start: never promise that it fires on its own
            $responseDto->note = 'The task could not start: the automation budget of this period is used up — scheduled and'
                . ' background tasks pause until it renews; the chat keeps working. Tell the user in plain words and offer to'
                . ' handle anything urgent DIRECTLY in this chat.';
        } else {
            $responseDto->note = 'The schedule was created but the immediate start was not possible — the task will fire shortly on its own.';
        }
        if ($schedule->status !== AIConversationScheduleStatus::EXPIRED) {
            $scheduleBudgetPotNote = $scheduleBudgetPotStatus?->buildAgentFacingSummary() ?? '';
            if ($scheduleBudgetPotNote !== '') {
                $responseDto->note .= ' ' . $scheduleBudgetPotNote;
            }
        }
        return $responseDto;
    }

    /**
     * Schedules a ONE-TIME task at a specific MOMENT — a wish relative to now (in ten minutes) → `runInMinutes: 10`; a
     * stated clock time (today at 15:30) → `runAtLocalTime: "<today> 15:30"` (LOCAL time; the system converts through
     * the schedule's time zone — never do timezone math yourself). Exactly ONE of the two timing fields. HARD RULE: any
     * stated CLOCK TIME, on whatever day, goes into `runAtLocalTime` — never self-compute minutes for an absolute time;
     * `runInMinutes` is only for genuinely relative wishes. A task the user takes back later is `deleteTaskSchedule`,
     * never a re-schedule. The task fires once, runs unattended, reports over `reportChannel`, and closes. Confirm to the
     * user WHAT runs and WHEN (repeat the resolved time from the response).
     * MISSION-ONLY task text: the schedule fields own the WHEN — never write time or recurrence wording into `task` (a run
     * that reads scheduling words in its mission mistakes them for a setup instruction).
     *
     * @example {"task": "Summarise the new customer messages of today and list the ones that need an answer.", "title": "Today's messages", "runInMinutes": 10}
     * @example {"task": "Summarise the reviews of the day and draft an answer to each.", "title": "Daily review summary", "runAtLocalTime": "2026-08-07 15:30"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::WRITE, identicalRepeatDoesNewWork: true)]
    #[MCPHiddenQueryOptions('noCache')]
    #[MCPToolSummary('Schedule a ONE-TIME task at a moment — relative (runInMinutes) or local absolute (runAtLocalTime "YYYY-MM-DD HH:MM"). Fires once, then closes. NOW → startBackgroundTask; repeating → createRecurringTaskSchedule; changing an EXISTING task → updateTaskSchedule.')]
    #[MCPRequiresAccountRights(AgentAccountRights::SCHEDULE_AGENT_TASKS)]
    public function scheduleOneTimeTask(
        OneTimeTaskScheduleCreateRequestDto $requestDto,
        AIConversationSchedulesService $aIConversationSchedulesService,
        AgentToolCallProvenance $callProvenance
    ): TaskScheduleCreateMcpResponseDto {
        $this->refuseScheduleAdministrationFromScheduledRun($callProvenance, 'scheduleOneTimeTask');
        $agentSpace = $this->findAgentSpaceOfCall($requestDto->agentSpaceId, $callProvenance);
        $actingAccount = $this->requireActingAccount($callProvenance);
        // a retried call (a resumed turn, a replayed step) that already produced its schedule answers with it
        $alreadyProducedSchedule = $this->findScheduleAlreadyProducedByCall($requestDto, $callProvenance, $aIConversationSchedulesService);
        if ($alreadyProducedSchedule !== null) {
            $alreadyProducedResponseDto = new TaskScheduleCreateMcpResponseDto();
            $alreadyProducedResponseDto->scheduleId = isset($alreadyProducedSchedule->id) ? (int)$alreadyProducedSchedule->id : null;
            $alreadyProducedResponseDto->nextRunAt = $alreadyProducedSchedule->nextRunAt ?? null;
            $alreadyProducedResponseDto->note = $this->composeScheduleAlreadyProducedNote($alreadyProducedSchedule, $requestDto, $callProvenance);
            return $alreadyProducedResponseDto;
        }
        // the zone is decided before anything is parsed: an explicit override, else the zone this call thinks in, else
        // the zone of the space; the run spawned at fire time inherits exactly this zone
        $validatedOverride = $this->findValidatedTimezoneIdentifier($requestDto->timezoneIdentifier);
        $scheduleTimezoneIdentifier = $validatedOverride ?? $this->findTimezoneIdentifierOfCall($callProvenance, $agentSpace);
        $scheduleTimezoneUnverified = $validatedOverride === null && $this->isTimezoneOfCallUnverified($callProvenance, $agentSpace);
        $now = new DateTime();
        $oneTimeAt = AIConversationScheduleOneTimeMoment::resolveFor(
            $requestDto->runInMinutes,
            $requestDto->runAtLocalTime,
            $scheduleTimezoneIdentifier ?? 'UTC',
            $now
        )->moment;
        $schedule = $this->buildAgentSchedule(
            $callProvenance,
            $aIConversationSchedulesService,
            $agentSpace,
            $actingAccount,
            (string)$requestDto->task,
            oneTimeAt: $oneTimeAt,
            reportChannel: $requestDto->reportChannel,
            title: $requestDto->title,
            purposeName: $requestDto->purposeName
        );
        if ($scheduleTimezoneIdentifier !== null) {
            $schedule->timezone = $scheduleTimezoneIdentifier;
            $schedule->timezoneUnverified = $scheduleTimezoneUnverified;
        }
        $schedule = $aIConversationSchedulesService->createSchedule($schedule);

        $responseDto = new TaskScheduleCreateMcpResponseDto();
        $responseDto->scheduleId = isset($schedule->id) ? (int)$schedule->id : null;
        $responseDto->nextRunAt = $schedule->nextRunAt ?? null;
        $responseDto->note = static::composeOneTimeMomentNote($oneTimeAt, $schedule->timezone ?? 'UTC', $now)
            . static::composeNextFiringNote($schedule)
            . 'VERIFY this matches what the user asked (wrong day → fix it with updateTaskSchedule), then confirm in ONE short sentence what runs and when.';
        $scheduleBudgetPotNote = $this->composeScheduleBudgetPotNoteOfCall($aIConversationSchedulesService, $agentSpace, $actingAccount, $callProvenance);
        if ($scheduleBudgetPotNote !== '') {
            $responseDto->note .= ' ' . $scheduleBudgetPotNote;
        }
        return $responseDto;
    }

    /**
     * Creates a RECURRING background-task schedule on a cron: every firing spawns a fresh unattended run whose final
     * report reaches the owner over `reportChannel` (EMAIL default; a run that finds nothing worth reporting sends no
     * mail). The cron's digits are LOCAL time of day — the schedule carries its own time zone, so "0 7 * * 1" means
     * Mondays 07:00 local time; never convert to any other clock. Pass `timezoneIdentifier` only when the user names
     * another zone. `doneCriteria` (plain language) closes the schedule once fulfilled; `maxRuns` caps the firings.
     * Confirm to the user WHAT will run and WHEN. Deleting it later archives it (its history stays).
     *
     * FREQUENCY LIMITS (every firing is a full agent run that costs budget): at most 4 firings per day — hourly or more
     * frequent crons are REFUSED; prefer specific times of day ("0 8 * * *") or a weekly rhythm. At most 5 new recurring
     * schedules per day. The response states the projected monthly load of the active recurring schedules — relay it.
     * MISSION-ONLY task text: the schedule fields own the WHEN — never write time or recurrence wording into `task`.
     *
     * @example {"task": "Check for new unanswered reviews and summarise them with draft answers.", "title": "Weekly review check", "cronSchedule": "0 7 * * 1"}
     * @example {"task": "Write the monthly report on bookings, reviews and profile views.", "title": "Monthly report", "cronSchedule": "0 8 1 * *", "maxRuns": 12}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::WRITE)]
    #[MCPHiddenQueryOptions('noCache')]
    #[MCPToolSummary('Set up a RECURRING background task on a cron (max 4 firings/day — hourly crons are refused) — each run reports to the owner by email (default). One-off now → startBackgroundTask; one-time at a moment → scheduleOneTimeTask; changing an EXISTING task → updateTaskSchedule.')]
    #[MCPRequiresAccountRights(AgentAccountRights::SCHEDULE_AGENT_TASKS)]
    public function createRecurringTaskSchedule(
        RecurringTaskScheduleCreateRequestDto $requestDto,
        AIConversationSchedulesService $aIConversationSchedulesService,
        AgentToolCallProvenance $callProvenance
    ): TaskScheduleCreateMcpResponseDto {
        $this->refuseScheduleAdministrationFromScheduledRun($callProvenance, 'createRecurringTaskSchedule');
        $agentSpace = $this->findAgentSpaceOfCall($requestDto->agentSpaceId, $callProvenance);
        $actingAccount = $this->requireActingAccount($callProvenance);
        $validatedOverride = $this->findValidatedTimezoneIdentifier($requestDto->timezoneIdentifier);
        $schedule = $this->buildAgentSchedule(
            $callProvenance,
            $aIConversationSchedulesService,
            $agentSpace,
            $actingAccount,
            (string)$requestDto->task,
            cronSchedule: trim((string)$requestDto->cronSchedule),
            reportChannel: $requestDto->reportChannel,
            title: $requestDto->title,
            purposeName: $requestDto->purposeName
        );
        // the zone first, so the frequency floor counts the cron in the zone it fires in
        $scheduleTimezoneIdentifier = $validatedOverride ?? $this->findTimezoneIdentifierOfCall($callProvenance, $agentSpace);
        if ($scheduleTimezoneIdentifier !== null) {
            $schedule->timezone = $scheduleTimezoneIdentifier;
            $schedule->timezoneUnverified = $validatedOverride === null && $this->isTimezoneOfCallUnverified($callProvenance, $agentSpace);
        }
        $this->refuseCronAboveTheFiringsPerDay($schedule);
        $recurringSchedulesCreatedToday = $agentSpace !== null
            ? $aIConversationSchedulesService->countAgentRecurringSchedulesCreatedForAgentSpaceSince(
                $agentSpace,
                AIConversationScheduleCreationLimits::getStartOfCreationDay(new DateTime())
            )
            : $aIConversationSchedulesService->countAgentRecurringSchedulesCreatedForAccountWithoutAgentSpaceSince(
                $actingAccount,
                AIConversationScheduleCreationLimits::getStartOfCreationDay(new DateTime())
            );
        if (AIConversationScheduleCreationLimits::isDailyRecurringCreationCapReached($recurringSchedulesCreatedToday)) {
            throw new BadRequestException(
                'The daily limit of ' . AIConversationScheduleCreationLimits::MAX_RECURRING_SCHEDULES_CREATED_PER_DAY . ' new recurring schedules'
                . ' is reached — tell the user, and offer to set the remaining ones up tomorrow or to merge them into one of'
                . ' today\'s schedules (getTaskSchedules / updateTaskSchedule).'
            );
        }
        $doneCriteria = trim((string)($requestDto->doneCriteria ?? ''));
        if ($doneCriteria !== '') {
            $schedule->doneCriteria = $doneCriteria;
        }
        // 0 is what a model sends for a parameter it does not need: no cap, like the omitted parameter
        if ($requestDto->maxRuns !== null && $requestDto->maxRuns !== 0) {
            if (!AIConversationScheduleCreationLimits::isMaxRunsWithinRange($requestDto->maxRuns)) {
                throw new BadRequestException(
                    'maxRuns caps the number of runs and takes ' . AIConversationScheduleCreationLimits::MAX_RUNS_LOWEST . ' to '
                    . AIConversationScheduleCreationLimits::MAX_RUNS_HIGHEST . '. A task that keeps running until the owner stops it has no cap: leave maxRuns out.'
                );
            }
            $schedule->maxIterations = $requestDto->maxRuns;
        }
        $schedule = $aIConversationSchedulesService->createSchedule($schedule);

        $responseDto = new TaskScheduleCreateMcpResponseDto();
        $responseDto->scheduleId = isset($schedule->id) ? (int)$schedule->id : null;
        $responseDto->nextRunAt = $schedule->nextRunAt ?? null;
        $responseDto->note = static::composeNextFiringNote($schedule)
            . 'Schedule created — confirm to the user in ONE short sentence what will run and when the first firing is.';
        foreach ([
            $this->composeMonthlyCostNote($aIConversationSchedulesService, $agentSpace, $actingAccount),
            $this->composeScheduleBudgetPotNoteOfCall($aIConversationSchedulesService, $agentSpace, $actingAccount, $callProvenance),
        ] as $budgetNote) {
            if ($budgetNote !== '') {
                $responseDto->note .= ' ' . $budgetNote;
            }
        }
        return $responseDto;
    }

    /**
     * The TASK SCHEDULES that are set up. Per schedule: id, one-time or recurring, status (ACTIVE / PAUSED / DONE /
     * CANCELLED / EXPIRED), timing, report channel, its newest run in one line, and the task text of a schedule that
     * can still act. The id source for update / pause / resume / delete. Tasks the owner calls active, still running
     * or still on are schedules in status ACTIVE — this read. What RAN — in a period, how often, with which result — is
     * `getTaskRuns`.
     *
     * Recurring or one-time is a filter on `oneTimeAt` (there is no kind or type property): `oneTimeAt eq null` =
     * recurring tasks, `oneTimeAt ne null` = one-time tasks. Newest first by default; `top` 50
     * (max 100), page with `skip`; the header states how many match. ARCHIVED schedules (deleted after they ran) appear
     * only with `includeArchived: true`. A recurring schedule's cron digits count in the zone named on its row — never
     * convert them.
     *
     * @example {}
     * @example {"filters": "status eq 'ACTIVE'"}
     * @example {"filters": "status in ['ACTIVE','PAUSED'] and oneTimeAt eq null", "orderBy": "nextRunAt asc"}
     * @example {"filters": "status eq 'ACTIVE' and oneTimeAt ne null"}
     * @example {"includeArchived": true, "filters": "lastFiredAt ge '2026-09-01 00:00:00'", "top": 20}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolParallelSafe('reads schedules, newest run lines and budget notes')]
    #[MCPHiddenQueryOptions(DtoQueryOptions::EXPAND, DtoQueryOptions::SELECT, 'skiptoken', 'noCache')]
    #[MCPExposedFilterProperties('status', 'oneTimeAt', 'nextRunAt', 'lastFiredAt', 'created', 'reportChannel', 'failureStreak')]
    #[MCPToolRefreshesState('schedules fire and close in the background — identical arguments legitimately return a changed list')]
    #[MCPToolSummary('The task SCHEDULES that are set up (id, one-time vs recurring, status, timing, newest run line) with filters (recurring: oneTimeAt eq null; one-time: oneTimeAt ne null), sorting and paging — the id source for update / pause / resume / delete. What RAN in a period or how often → getTaskRuns.')]
    public function getTaskSchedules(
        TaskSchedulesGetRequestDto $requestDto,
        AIConversationSchedulesService $aIConversationSchedulesService,
        AIConversationsService $aIConversationsService,
        AgentToolCallProvenance $callProvenance
    ): TaskSchedulesMcpResponseDto {
        // the DTO fields are read before setQueryOptionsFromRequestDto widens the variable's static type
        $agentSpace = $this->findAgentSpaceOfCall($requestDto->agentSpaceId, $callProvenance);
        $actingAccount = $this->requireActingAccount($callProvenance);
        $requestedTop = $requestDto->top;
        $appliedFilterExpression = isset($requestDto->filters) ? $requestDto->filters->toCanonicalExpression() : null;
        $includeArchived = $requestDto->includeArchived === true;

        $responseDto = new TaskSchedulesMcpResponseDto();
        $responseDto->appliedFilterExpression = $appliedFilterExpression !== '' ? $appliedFilterExpression : null;
        $responseDto->archivedSchedulesIncluded = $includeArchived;
        $responseDto->skippedRowCount = max(0, (int)($requestDto->skip ?? 0));
        // query options are static per class and leak across the calls of one turn: the mutation is scoped
        AIConversationSchedules::setDefaultQueryOptionsSnapshot();
        try {
            $scheduleQueryOptions = AIConversationSchedules::getDefaultQueryOptions();
            $scheduleQueryOptions->setQueryOptionsFromRequestDto($requestDto);
            $scheduleQueryOptions->top = min($requestedTop ?: static::DEFAULT_SCHEDULES_PER_PAGE, static::MAX_ROWS_PER_PAGE);
            // the scope and the ARCHIVED hide are AND-connected on top of the model's filter: no filter escapes them
            $mandatoryScopeFilterString = static::buildScopeFilterString($agentSpace, $actingAccount);
            if (!$includeArchived) {
                $mandatoryScopeFilterString .= " and status ne '" . AIConversationScheduleStatus::ARCHIVED . "'";
            }
            $scheduleQueryOptions->addFiltersConnectedByAnd(FiltersOptions::fromString($mandatoryScopeFilterString));
            if ($scheduleQueryOptions->getOrderBy() === null) {
                $newestFirstOrderBy = OrderByOptions::fromString('id desc');
                $scheduleQueryOptions->setOrderBy($newestFirstOrderBy);
            }
            // a fresh load: inside one turn this read follows status writes (pause → list)
            $schedules = $this->findSchedulesOfScope($aIConversationSchedulesService, $agentSpace, $actingAccount);
            $responseDto->totalMatchingCount = $this->countSchedulesOfScope($aIConversationSchedulesService, $agentSpace, $actingAccount);
        } finally {
            AIConversationSchedules::restoreDefaultQueryOptionsSnapshot();
        }
        $scheduleIds = [];
        foreach ($schedules->getElements() as $schedule) {
            $responseDto->schedules[] = TaskScheduleMcpRow::fromSchedule($schedule);
            $scheduleIds[] = (int)$schedule->id;
        }
        // the newest run per schedule in ONE batched query
        $newestRunPerScheduleId = $aIConversationsService->findNewestScheduleRunSummaryPerSchedule($scheduleIds);
        foreach ($responseDto->schedules as $scheduleRow) {
            $newestRun = $newestRunPerScheduleId[$scheduleRow->id] ?? null;
            if ($newestRun === null) {
                continue;
            }
            $scheduleRow->lastRunConversationId = $newestRun->conversationId;
            $scheduleRow->lastRunState = $newestRun->runState;
            $scheduleRow->lastRunOutcome = $newestRun->outcomeExcerpt;
        }
        $budgetNotes = [];
        foreach ([
            $this->composeMonthlyCostNote($aIConversationSchedulesService, $agentSpace, $actingAccount),
            $this->composeScheduleBudgetPotNoteOfCall($aIConversationSchedulesService, $agentSpace, $actingAccount, $callProvenance),
        ] as $budgetNote) {
            if ($budgetNote !== '') {
                $budgetNotes[] = $budgetNote;
            }
        }
        $responseDto->recurringBudgetNote = $budgetNotes !== [] ? implode(' ', $budgetNotes) : null;
        $taskListNote = $callProvenance->isOwnerOnThePhoneLine()
            ? $this->composeTaskListPhoneNote($callProvenance)
            : $this->composeTaskListWidgetNote($agentSpace, $callProvenance);
        $responseDto->taskListWidgetNote = $taskListNote !== '' ? $taskListNote : null;
        return $responseDto;
    }

    /**
     * The TASK RUNS — what actually ran: every execution of every task schedule in scope, archived schedules included,
     * newest first. Per run: its conversation id, the schedule (id + title), the start moment, the run state, the summary
     * title and the outcome line. The read for every question about EXECUTIONS: what ran in a period, how often or when
     * a task ran, which runs failed. One run's full report is `getTaskRunReport`. Which tasks are active or still on is
     * NOT a run question — a task is active between its runs: `getTaskSchedules`.
     *
     * `filters`: `created` is the run's START moment — a period is a `created` range computed from the current date of
     * the conversation; `scheduleId` = the runs of ONE task; `runState`: COMPLETED, FAILED, IDLE (over without either
     * mark), CANCELLED, RUNNING / AWAITING_DELEGATES / QUEUED = in progress. `top` 25 (max 100), page with `skip`. The
     * header states how many runs match — when more exist than shown, page on or say that you show a part.
     *
     * @example {"filters": "created ge '2026-09-20 00:00:00'"}
     * @example {"filters": "scheduleId eq '1559'", "top": 10}
     * @example {"filters": "created bw ['2026-09-01 00:00:00','2026-09-30 23:59:59'] and runState eq 'FAILED'"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolParallelSafe('reads the run conversations of the schedules in scope')]
    #[MCPHiddenQueryOptions(DtoQueryOptions::EXPAND, DtoQueryOptions::SELECT, 'skiptoken', 'noCache')]
    #[MCPExposedFilterProperties('created', 'scheduleId', 'runState')]
    #[MCPToolRefreshesState('runs start and finish in the background — identical arguments legitimately return new runs and new states')]
    #[MCPToolSummary('The task RUNS that actually executed, across ALL task schedules, newest first (conversation id, schedule id + title, start, state, outcome line) — filters on created (start moment) / scheduleId / runState, orderBy, top/skip. One run\'s full report → getTaskRunReport.')]
    public function getTaskRuns(
        TaskRunsGetRequestDto $requestDto,
        AIConversationSchedulesService $aIConversationSchedulesService,
        AIConversationsService $aIConversationsService,
        AgentToolCallProvenance $callProvenance
    ): TaskRunsMcpResponseDto {
        $agentSpace = $this->findAgentSpaceOfCall($requestDto->agentSpaceId, $callProvenance);
        $actingAccount = $this->requireActingAccount($callProvenance);
        $requestedTop = $requestDto->top;
        $responseDto = new TaskRunsMcpResponseDto();
        $appliedFilterExpression = isset($requestDto->filters) ? $requestDto->filters->toCanonicalExpression() : '';
        $responseDto->appliedFilterExpression = $appliedFilterExpression !== '' ? $appliedFilterExpression : null;
        $responseDto->skippedRowCount = max(0, (int)($requestDto->skip ?? 0));

        AIConversations::setDefaultQueryOptionsSnapshot();
        try {
            $runQueryOptions = AIConversations::getDefaultQueryOptions();
            $runQueryOptions->setQueryOptionsFromRequestDto($requestDto);
            $runQueryOptions->top = min($requestedTop ?: static::DEFAULT_RUNS_PER_PAGE, static::MAX_ROWS_PER_PAGE);
            // the scope's conversations that ARE a schedule's run: a real schedule id and a ROOT conversation (a
            // delegation child inherits its parent's scheduleId and would count as a run of its own)
            $runQueryOptions->addFiltersConnectedByAnd(FiltersOptions::fromString(
                static::buildScopeFilterString($agentSpace, $actingAccount) . " and scheduleId gt '0' and delegatedFromMessageId eq 'NULL'"
            ));
            if ($runQueryOptions->getOrderBy() === null) {
                $newestFirstOrderBy = OrderByOptions::fromString('id desc');
                $runQueryOptions->setOrderBy($newestFirstOrderBy);
            }
            $runConversations = $this->findRunConversationsOfScope($aIConversationsService, $agentSpace, $actingAccount);
            $responseDto->totalMatchingCount = $this->countRunConversationsOfScope($aIConversationsService, $agentSpace, $actingAccount);
        } finally {
            AIConversations::restoreDefaultQueryOptionsSnapshot();
        }
        // the schedule titles in ONE load — archived schedules included, their runs stay readable
        $schedulesInScope = $this->findAllSchedulesOfScopeForLabels($aIConversationSchedulesService, $agentSpace, $actingAccount);
        $scheduleLabelPerScheduleId = [];
        foreach ($schedulesInScope->getElements() as $schedule) {
            $scheduleLabelPerScheduleId[(int)$schedule->id] = $schedule->getOwnerFacingLabel();
        }
        foreach ($runConversations->getElements() as $runConversation) {
            $responseDto->runs[] = TaskRunMcpRow::fromRunConversation(
                $runConversation,
                $scheduleLabelPerScheduleId[(int)($runConversation->scheduleId ?? 0)] ?? null
            );
        }
        return $responseDto;
    }

    /**
     * What ONE scheduled or background run did and reported: the run's state, start, summary line and the OWNER REPORT
     * it delivered (subject + full body — the content the owner received). Use it when the user asks WHAT a task found
     * or what its report said. The `conversationId` comes from `getTaskSchedules` (newest-run line) or `getTaskRuns` —
     * never from memory. A run still in progress has no report yet; say so. Answer from the report's content; do not
     * paste the whole report unless the user asks for it verbatim.
     *
     * @example {"conversationId": 108760}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolParallelSafe('reads a finished run\'s stored report')]
    #[MCPHiddenQueryOptions('noCache')]
    #[MCPToolRefreshesState('a run in progress finishes in the background — the same id legitimately returns a report later')]
    #[MCPToolSummary('Read what ONE task run did and the report it delivered (subject + full body) — conversationId from getTaskSchedules / getTaskRuns.')]
    public function getTaskRunReport(
        TaskRunReportGetRequestDto $requestDto,
        AIConversationSchedulesService $aIConversationSchedulesService,
        AIConversationsService $aIConversationsService,
        AIConversationOwnerReportDeliveryService $aIConversationOwnerReportDeliveryService,
        AgentToolCallProvenance $callProvenance
    ): TaskRunReportMcpResponseDto {
        // the run is read like the lists: its schedule must be one the acting account may work with (the target check
        // below); the run conversation itself may belong to the account the schedule runs as
        $runConversation = $this->findRunConversationById((int)$requestDto->conversationId, $aIConversationsService);
        $scheduleId = $runConversation instanceof AIConversation ? ($runConversation->scheduleId ?? null) : null;
        if (!$runConversation instanceof AIConversation || $scheduleId === null || $scheduleId <= 0) {
            throw new NotFoundException('No such task run — the id must be a run conversation from getTaskSchedules / getTaskRuns.');
        }
        $schedule = $this->findScheduleOfActingAccount((int)$scheduleId, $aIConversationSchedulesService, $callProvenance);

        $responseDto = new TaskRunReportMcpResponseDto();
        $responseDto->conversationId = (int)$runConversation->id;
        $responseDto->scheduleId = (int)$schedule->id;
        $responseDto->scheduleLabel = $schedule->getOwnerFacingLabel();
        $responseDto->runState = $runConversation->runState;
        $responseDto->startedAt = $runConversation->getCreatedTime();
        // the RUN's own channel (stamped at spawn) — the schedule's channel may have changed since
        $responseDto->reportChannel = (string)($runConversation->reportChannel ?? $schedule->reportChannel);
        // only a loaded summary: an unset one would start its generation, a model call a read tool never spends
        if (isset($runConversation->summary)) {
            $responseDto->summaryTitle = $runConversation->summary->title ?? null;
            $responseDto->summaryOutcome = $runConversation->summary->outcome ?? null;
        }
        if ($runConversation->isRunInProgress()) {
            $responseDto->note = TaskRunReportMcpResponseDto::NOTE_RUN_IN_PROGRESS;
            return $responseDto;
        }
        // the report rows belong to the run's account: read under the rule of the scope (the schedule was checked above)
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $responseDto->ownerReport = $aIConversationOwnerReportDeliveryService->composeOwnerReportOfRun($runConversation);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        return $responseDto;
    }

    /**
     * PAUSES an ACTIVE schedule — the user wants a task suspended for now, not ended: the schedule stops firing but keeps
     * its id, cron and run history; `resumeTaskSchedule` starts it again. Only an ACTIVE schedule can pause (a one-time
     * task that has not run yet included). Taking a task back FOR GOOD is `deleteTaskSchedule`. Find the id via
     * `getTaskSchedules`; confirm in ONE short sentence.
     *
     * @example {"scheduleId": 42}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::WRITE, identicalRepeatDoesNewWork: true)]
    #[MCPHiddenQueryOptions('noCache')]
    #[MCPToolSummary('PAUSE an ACTIVE task schedule (stops firing, keeps id/cron/history; resumeTaskSchedule restarts it). Stopping for good → deleteTaskSchedule.')]
    #[MCPRequiresAccountRights(AgentAccountRights::SCHEDULE_AGENT_TASKS)]
    public function pauseTaskSchedule(
        TaskScheduleIdRequestDto $requestDto,
        AIConversationSchedulesService $aIConversationSchedulesService,
        AgentToolCallProvenance $callProvenance
    ): TaskScheduleUpdateMcpResponseDto {
        $this->refuseScheduleAdministrationFromScheduledRun($callProvenance, 'pauseTaskSchedule');
        $schedule = $this->findScheduleOfActingAccount((int)$requestDto->scheduleId, $aIConversationSchedulesService, $callProvenance);
        if (!$schedule->canBePaused()) {
            throw new BadRequestException(
                "Schedule {$schedule->id} cannot be paused in status {$schedule->status} — only an ACTIVE schedule pauses"
                . ($schedule->status === AIConversationScheduleStatus::PAUSED ? ' (it is already paused; resumeTaskSchedule restarts it).' : '.')
            );
        }
        $aIConversationSchedulesService->transitionStatus($schedule, AIConversationScheduleStatus::PAUSED);

        $responseDto = new TaskScheduleUpdateMcpResponseDto();
        $responseDto->scheduleId = (int)$schedule->id;
        $responseDto->nextRunAt = null;
        $responseDto->note = 'Schedule paused — it will not fire until resumed (resumeTaskSchedule); id, cron and run history are kept. Confirm to the user in ONE short sentence.';
        return $responseDto;
    }

    /**
     * RESUMES a PAUSED schedule — the user wants a suspended task to run again: the next firing is computed from NOW (a
     * long pause never fires a backlog). Refused with the reason when the platform paused the schedule for the state of
     * the space or the account (it resumes by itself once that state is active again) — relay that reason, never offer a
     * workaround. A one-time task whose moment has passed does not resume — schedule a new one. A schedule whose
     * lifetime is over (ttl passed or runs used up) is closed as EXPIRED instead — tell the user it has ended. Find the
     * id via `getTaskSchedules`; confirm WHEN it fires next.
     *
     * @example {"scheduleId": 42}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::WRITE, identicalRepeatDoesNewWork: true)]
    #[MCPHiddenQueryOptions('noCache')]
    #[MCPToolSummary('RESUME a PAUSED task schedule (next firing computed from now). A schedule the platform paused is refused with the reason; relay it.')]
    #[MCPRequiresAccountRights(AgentAccountRights::SCHEDULE_AGENT_TASKS)]
    public function resumeTaskSchedule(
        TaskScheduleIdRequestDto $requestDto,
        AIConversationSchedulesService $aIConversationSchedulesService,
        AgentToolCallProvenance $callProvenance
    ): TaskScheduleUpdateMcpResponseDto {
        $this->refuseScheduleAdministrationFromScheduledRun($callProvenance, 'resumeTaskSchedule');
        $schedule = $this->findScheduleOfActingAccount((int)$requestDto->scheduleId, $aIConversationSchedulesService, $callProvenance);
        $resumeTransition = $aIConversationSchedulesService->resumeSchedule($schedule);

        $responseDto = new TaskScheduleUpdateMcpResponseDto();
        $responseDto->scheduleId = (int)$schedule->id;
        if ($resumeTransition->targetStatus === AIConversationScheduleStatus::EXPIRED) {
            // its lifetime was over: the resume closed it, it never fires again
            $responseDto->nextRunAt = null;
            $responseDto->note = static::composeEndedOnResumeNote($schedule);
            return $responseDto;
        }
        $responseDto->nextRunAt = $schedule->nextRunAt ?? null;
        $responseDto->note = 'Schedule resumed. ' . static::composeNextFiringNote($schedule) . 'Confirm to the user in ONE short sentence when it fires next.';
        return $responseDto;
    }

    /**
     * Deletes a task schedule under the ARCHIVE POLICY — use it when the user takes back a planned task or stops a
     * recurring one: a schedule that never ran is removed FOR GOOD; a recurring schedule that already ran is ARCHIVED
     * (hidden from default lists, its runs stay readable); a one-time task that already ran stays untouched as history.
     * Find the id via `getTaskSchedules` first; confirm the outcome to the user in one sentence.
     *
     * @example {"scheduleId": 42}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::DELETE)]
    #[MCPHiddenQueryOptions('noCache')]
    #[MCPToolSummary('Delete / stop / CANCEL a task schedule (archive policy: never ran → gone; recurring with runs → archived; one-time with a run → history stays). CHANGING an existing task → updateTaskSchedule, never delete + recreate.')]
    #[MCPRequiresAccountRights(AgentAccountRights::SCHEDULE_AGENT_TASKS)]
    public function deleteTaskSchedule(
        TaskScheduleIdRequestDto $requestDto,
        AIConversationSchedulesService $aIConversationSchedulesService,
        AgentToolCallProvenance $callProvenance
    ): TaskScheduleDeleteMcpResponseDto {
        $this->refuseScheduleAdministrationFromScheduledRun($callProvenance, 'deleteTaskSchedule');
        $schedule = $this->findScheduleOfActingAccount((int)$requestDto->scheduleId, $aIConversationSchedulesService, $callProvenance);
        return TaskScheduleDeleteMcpResponseDto::forActionTaken($aIConversationSchedulesService->deleteScheduleFollowingArchivePolicy($schedule));
    }

    /**
     * Changes an EXISTING task schedule IN PLACE — NEVER delete + recreate for a change (that orphans the run history;
     * the id and its runs stay). Only the sent fields change. The moment moves only on ONE-TIME tasks that have not run
     * yet — exactly one of `runInMinutes` / `runAtLocalTime`, same rules as `scheduleOneTimeTask`. A new cron only on
     * RECURRING schedules — at most 4 firings per day, as at creation. Only an ACTIVE or PAUSED schedule changes; a
     * finished one (DONE, CANCELLED, EXPIRED, ARCHIVED) is refused. Find the id via `getTaskSchedules` first; taking a
     * task back entirely is `deleteTaskSchedule`. Only for a change the owner asks for to THAT task: an order for another
     * report or another rhythm is a NEW schedule beside the existing ones, and a task the owner did not mention is never
     * rewritten. Confirm in ONE short sentence what changed.
     *
     * @example {"scheduleId": 42, "cronSchedule": "0 8 * * 5"}
     * @example {"scheduleId": 17, "runAtLocalTime": "2026-08-12 16:00"}
     * @example {"scheduleId": 23, "title": "Weekly review check", "reportChannel": "CHAT"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::WRITE)]
    #[MCPHiddenQueryOptions('noCache')]
    #[MCPToolSummary('Change an EXISTING task schedule in place (task text, title, timing, cron, report channel, done criteria, run purpose) — NEVER delete + recreate for a change. An order for another report or rhythm is a NEW schedule. Moment: one-time tasks that have not run; cron: recurring only (max 4 firings/day).')]
    #[MCPRequiresAccountRights(AgentAccountRights::SCHEDULE_AGENT_TASKS)]
    public function updateTaskSchedule(
        TaskScheduleUpdateRequestDto $requestDto,
        AIConversationSchedulesService $aIConversationSchedulesService,
        AgentToolCallProvenance $callProvenance
    ): TaskScheduleUpdateMcpResponseDto {
        $this->refuseScheduleAdministrationFromScheduledRun($callProvenance, 'updateTaskSchedule');
        $schedule = $this->findScheduleOfActingAccount((int)$requestDto->scheduleId, $aIConversationSchedulesService, $callProvenance);
        try {
            $aIConversationSchedulesService->assertScheduleMayBeEdited($schedule);
        } catch (BadRequestException $finalScheduleRefusal) {
            throw new BadRequestException(
                $finalScheduleRefusal->getMessage() . ' Create a new one with scheduleOneTimeTask or createRecurringTaskSchedule.'
            );
        }
        $now = new DateTime();
        $editedColumnNames = [];
        $editedTask = trim((string)($requestDto->task ?? ''));
        if ($editedTask !== '') {
            $schedule->task = $editedTask;
            $editedColumnNames[] = 'task';
        }
        $editedTitle = trim((string)($requestDto->title ?? ''));
        if ($editedTitle !== '') {
            $schedule->title = AIConversationScheduleCreationLimits::cutTitle($editedTitle);
            $editedColumnNames[] = 'title';
        }
        if ($requestDto->doneCriteria !== null) {
            // an explicit empty string removes the done criteria
            $schedule->doneCriteria = trim($requestDto->doneCriteria) === '' ? null : trim($requestDto->doneCriteria);
            $editedColumnNames[] = 'doneCriteria';
        }
        if ($requestDto->reportChannel !== null) {
            $schedule->reportChannel = AIConversationScheduleCreationLimits::normalizeReportChannel($requestDto->reportChannel);
            $editedColumnNames[] = 'reportChannel';
        }
        if ($requestDto->purposeName !== null) {
            $aIConversationSchedulesService->applyRunPurposeToStoredSchedule($schedule, $requestDto->purposeName);
            $editedColumnNames[] = 'runSettings';
        }
        $editedColumnNames = [...$editedColumnNames, ...$this->applyTimingEditsToSchedule($schedule, $requestDto, $now)];

        if ($editedColumnNames === []) {
            throw new BadRequestException('Nothing to change — pass at least one field besides scheduleId.');
        }
        $aIConversationSchedulesService->persistScheduleEdit($schedule, $editedColumnNames);

        $responseDto = new TaskScheduleUpdateMcpResponseDto();
        $responseDto->scheduleId = (int)$schedule->id;
        if ($schedule->status === AIConversationScheduleStatus::PAUSED) {
            // the anchor a pause leaves in the row is no firing: the resume computes the next one
            $responseDto->nextRunAt = null;
            $responseDto->note = 'Schedule updated IN PLACE (same id, run history preserved). ' . static::composePausedScheduleFiringNote($schedule);
        } else {
            $responseDto->nextRunAt = $schedule->nextRunAt ?? null;
            $responseDto->note = 'Schedule updated IN PLACE (same id, run history preserved). ' . static::composeNextFiringNote($schedule);
        }
        if (in_array('oneTimeAt', $editedColumnNames, true)) {
            $responseDto->note .= static::composeOneTimeMomentNote($schedule->oneTimeAt, $this->findTimezoneIdentifierForMomentEdit($schedule, $requestDto), $now)
                . 'VERIFY this matches what the user asked (wrong day → fix it with updateTaskSchedule). ';
        }
        $responseDto->note .= 'Confirm to the user in ONE short sentence what changed.';
        return $responseDto;
    }

    /**
     * "One-time task scheduled for <local moment> local time — that is TODAY / TOMORROW / <weekday>. " — the resolved
     * moment the model verifies its plan against (a model that planned "tomorrow 15:30" onto today's date catches it).
     *
     * @param DateTime $oneTimeAt the fire moment
     * @param string $timezoneIdentifier the zone of the schedule
     * @param DateTime $now
     * @return string
     */
    public static function composeOneTimeMomentNote(DateTime $oneTimeAt, string $timezoneIdentifier, DateTime $now): string
    {
        $timezone = new \DateTimeZone($timezoneIdentifier);
        $resolvedLocal = (new \DateTimeImmutable('@' . $oneTimeAt->getTimestamp()))->setTimezone($timezone);
        $nowLocal = (new \DateTimeImmutable('@' . $now->getTimestamp()))->setTimezone($timezone);
        $relativeDayLabel = match ($resolvedLocal->format('Y-m-d')) {
            $nowLocal->format('Y-m-d') => 'TODAY',
            $nowLocal->modify('+1 day')->format('Y-m-d') => 'TOMORROW',
            default => $resolvedLocal->format('l'),
        };
        return 'One-time task scheduled for ' . $resolvedLocal->format('Y-m-d H:i') . " local time — that is {$relativeDayLabel}. ";
    }

    /**
     * The note of a PAUSED schedule after an edit: it names no next firing (the anchor a pause leaves in the row is
     * none), only the firing it will have once resumed — the moment of a one-time task, the next slot of the cron
     * after the resume for a recurring one.
     *
     * @param AIConversationSchedule $schedule
     * @return string
     */
    public static function composePausedScheduleFiringNote(AIConversationSchedule $schedule): string
    {
        $pausedNote = 'It is PAUSED and does not fire until resumed (resumeTaskSchedule); ';
        if ($schedule->isOneTime() && isset($schedule->oneTimeAt)) {
            $scheduleTimezone = new \DateTimeZone($schedule->getCronTimezoneIdentifier());
            $momentLocal = (clone $schedule->oneTimeAt)->setTimezone($scheduleTimezone);
            return $pausedNote . 'once resumed, it fires at ' . $schedule->oneTimeAt->formatForModel($scheduleTimezone)
                . ' (' . $momentLocal->format('l') . '). ';
        }
        return $pausedNote . 'once resumed, it fires at the next slot of its cron after the resume. ';
    }

    /**
     * The note of a resume that closed the schedule: its ttl passed or its runs are used up, so it is EXPIRED and a new
     * schedule is needed if the user still wants the task.
     *
     * @param AIConversationSchedule $schedule
     * @return string
     */
    public static function composeEndedOnResumeNote(AIConversationSchedule $schedule): string
    {
        $endedBecause = isset($schedule->ttlAt) && $schedule->ttlAt->getTimestamp() <= (new DateTime())->getTimestamp()
            ? 'its lifetime (ttl) has passed'
            : 'its runs are used up (' . (int)$schedule->iterationsUsed . ' of ' . (int)$schedule->maxIterations . ')';
        return "Schedule {$schedule->id} was not resumed: it has ended — {$endedBecause} — and is now EXPIRED; it never fires again. "
            . 'Tell the user so in ONE short sentence; if they still want the task, a new schedule is needed '
            . '(scheduleOneTimeTask or createRecurringTaskSchedule).';
    }

    /**
     * "Next firing: <moment in the schedule's zone with offset> (<weekday>). " — or '' when there is no next run. The
     * weekday comes from the same zoned instant, so the model verifies day AND time against one line.
     *
     * @param AIConversationSchedule $schedule
     * @return string
     */
    public static function composeNextFiringNote(AIConversationSchedule $schedule): string
    {
        if (!isset($schedule->nextRunAt)) {
            return '';
        }
        $scheduleTimezone = new \DateTimeZone($schedule->getCronTimezoneIdentifier());
        $nextFiringLocal = (clone $schedule->nextRunAt)->setTimezone($scheduleTimezone);
        return 'Next firing: ' . $schedule->nextRunAt->formatForModel($scheduleTimezone) . ' (' . $nextFiringLocal->format('l') . '). ';
    }

    /**
     * The mandatory scope filter of both reads: the space's rows, or — without a space — the rows the account holds
     * without one.
     *
     * @param AgentSpace|null $agentSpace
     * @param Account $actingAccount
     * @return string
     */
    public static function buildScopeFilterString(?AgentSpace $agentSpace, Account $actingAccount): string
    {
        if ($agentSpace !== null) {
            return "agentSpaceId eq '" . (int)$agentSpace->id . "'";
        }
        return "agentSpaceId eq 'NULL' and accountId eq '" . (int)$actingAccount->id . "'";
    }

    /**
     * The space the call works for: the one the call's arguments name (checked for the account), else the
     * conversation's. The resolver receives the WHOLE arguments of the call ({@see AgentToolCallProvenance::$toolArguments}),
     * so an argument the application names (`AI.agentSpaceToolArgument`) reaches it; the DTO's `agentSpaceId` fills
     * the default name when the arguments are not at hand.
     *
     * @param int|null $agentSpaceId
     * @param AgentToolCallProvenance $callProvenance
     * @return AgentSpace|null null = the account as a whole
     * @throws BadRequestException
     * @throws ForbiddenException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    protected function findAgentSpaceOfCall(?int $agentSpaceId, AgentToolCallProvenance $callProvenance): ?AgentSpace
    {
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        $toolArguments = $callProvenance->toolArguments ?? [];
        if ($agentSpaceId !== null && !isset($toolArguments[AgentSpacesService::TOOL_ARGUMENT_AGENT_SPACE_ID])) {
            $toolArguments[AgentSpacesService::TOOL_ARGUMENT_AGENT_SPACE_ID] = $agentSpaceId;
        }
        return $agentSpacesService->findAgentSpaceOfToolCall($toolArguments, $callProvenance);
    }

    /**
     * The account the call acts for: the owner of the conversation, else the signed-in account.
     *
     * @param AgentToolCallProvenance $callProvenance
     * @return Account
     * @throws ForbiddenException
     */
    protected function requireActingAccount(AgentToolCallProvenance $callProvenance): Account
    {
        $conversation = $callProvenance->conversation;
        $actingAccount = ($conversation !== null ? ($conversation->account ?? null) : null) ?? AuthService::instance()->getAccount();
        if (!$actingAccount instanceof Account || !isset($actingAccount->id)) {
            throw new ForbiddenException('No authenticated account');
        }
        return $actingAccount;
    }

    /**
     * A schedule by id, refused as not found unless the acting account may work with it: its space is usable by the
     * account, or — a schedule without a space — it is the account's own. The target check of every tool that names a
     * schedule.
     *
     * @param int $scheduleId
     * @param AIConversationSchedulesService $aIConversationSchedulesService
     * @param AgentToolCallProvenance $callProvenance
     * @return AIConversationSchedule
     * @throws NotFoundException
     * @throws ForbiddenException
     */
    protected function findScheduleOfActingAccount(
        int $scheduleId,
        AIConversationSchedulesService $aIConversationSchedulesService,
        AgentToolCallProvenance $callProvenance
    ): AIConversationSchedule {
        $actingAccount = $this->requireActingAccount($callProvenance);
        $schedule = $this->findScheduleByIdForScopeCheck($scheduleId, $aIConversationSchedulesService);
        if (!$schedule instanceof AIConversationSchedule || !$this->isScheduleInScopeOfAccount($schedule, $actingAccount)) {
            throw new NotFoundException('No such task schedule — list them with getTaskSchedules.');
        }
        return $schedule;
    }

    /**
     * A schedule by id without the entity read rights, its space loaded with it — the scope check that follows
     * ({@see isScheduleInScopeOfAccount()}) decides, by the same rule as the lists ({@see isScopeReadWithoutEntityRights()}):
     * a schedule the lists show is found by its id too. Null when there is none.
     */
    protected function findScheduleByIdForScopeCheck(int $scheduleId, AIConversationSchedulesService $aIConversationSchedulesService): ?AIConversationSchedule
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $schedule = $aIConversationSchedulesService->find($scheduleId, false);
            if ($schedule instanceof AIConversationSchedule && ($schedule->agentSpaceId ?? null) !== null) {
                // the space is read here too, without the read rights of a sub-account
                $loadedAgentSpace = $schedule->agentSpace ?? null;
            }
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        return $schedule instanceof AIConversationSchedule ? $schedule : null;
    }

    /**
     * Whether a schedule lies in the scope the acting account reads: without a space it is the account's own; with one
     * the account may use the space ({@see AgentSpacesService::requireAgentSpaceUsableByAccount()}) — the rule of the
     * lists.
     */
    protected function isScheduleInScopeOfAccount(AIConversationSchedule $schedule, Account $actingAccount): bool
    {
        if (($schedule->agentSpaceId ?? null) === null) {
            return (int)($schedule->accountId ?? 0) === (int)$actingAccount->id;
        }
        $agentSpaceOfSchedule = $schedule->agentSpace ?? null;
        if (!$agentSpaceOfSchedule instanceof AgentSpace) {
            return false;
        }
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        try {
            $agentSpacesService->requireAgentSpaceUsableByAccount($agentSpaceOfSchedule, $actingAccount);
            return true;
        } catch (ForbiddenException) {
            return false;
        }
    }

    /**
     * A new, not yet stored agent schedule: its space (or, without one, its account), initiator AGENT, the creating
     * conversation, the task, the title, the report channel and the timing (exactly one of a moment or a cron), after
     * the active-schedule cap of the scope.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    protected function buildAgentSchedule(
        AgentToolCallProvenance $callProvenance,
        AIConversationSchedulesService $aIConversationSchedulesService,
        ?AgentSpace $agentSpace,
        Account $actingAccount,
        string $task,
        ?string $cronSchedule = null,
        ?DateTime $oneTimeAt = null,
        ?string $reportChannel = null,
        ?string $title = null,
        ?string $purposeName = null
    ): AIConversationSchedule {
        $task = trim($task);
        if ($task === '') {
            throw new BadRequestException('The task mission must not be empty.');
        }
        $normalizedReportChannel = AIConversationScheduleCreationLimits::normalizeReportChannel($reportChannel ?? AIConversationSchedule::REPORT_CHANNEL_EMAIL);
        $schedulesInScope = $agentSpace !== null
            ? $aIConversationSchedulesService->findSchedulesOfAgentSpace($agentSpace)
            : $aIConversationSchedulesService->findSchedulesOfAccountWithoutAgentSpace($actingAccount);
        $activeScheduleCount = 0;
        foreach ($schedulesInScope->getElements() as $existingSchedule) {
            if ($existingSchedule->status === AIConversationScheduleStatus::ACTIVE) {
                $activeScheduleCount++;
            }
        }
        if (AIConversationScheduleCreationLimits::isActiveScheduleCapReached($activeScheduleCount)) {
            throw new BadRequestException(
                'Task-schedule limit reached (' . AIConversationScheduleCreationLimits::MAX_ACTIVE_SCHEDULES_PER_AGENT_SPACE
                . ' active) — ask the user to clean up existing schedules first (getTaskSchedules / deleteTaskSchedule).'
            );
        }

        $schedule = AIConversationSchedule::newInstance();
        if ($agentSpace !== null) {
            $schedule->agentSpaceId = (int)$agentSpace->id;
            $schedule->agentSpace = $agentSpace;
        } else {
            $schedule->accountId = (int)$actingAccount->id;
            $schedule->account = $actingAccount;
        }
        $schedule->initiatorType = AIConversationSchedule::INITIATOR_TYPE_AGENT;
        $createdByConversationId = $callProvenance->rootConversationId ?? $callProvenance->conversationId;
        if ($createdByConversationId !== null) {
            $schedule->createdByConversationId = (int)$createdByConversationId;
        }
        $schedule->task = $task;
        $trimmedTitle = trim((string)($title ?? ''));
        if ($trimmedTitle !== '') {
            $schedule->title = AIConversationScheduleCreationLimits::cutTitle($trimmedTitle);
        }
        if ($oneTimeAt !== null) {
            $schedule->oneTimeAt = $oneTimeAt;
        } else {
            $schedule->schedule = $cronSchedule;
        }
        $schedule->reportChannel = $normalizedReportChannel;
        $aIConversationSchedulesService->applyRunPurposeToSchedule($schedule, $purposeName, $agentSpace);
        return $schedule;
    }

    /**
     * Refuses a cron that fires more often per day than allowed. An unparseable expression passes: the create or the
     * edit answers it with its own corrective message.
     *
     * @param AIConversationSchedule $schedule its cron and zone set
     * @return void
     * @throws BadRequestException
     */
    protected function refuseCronAboveTheFiringsPerDay(AIConversationSchedule $schedule): void
    {
        $cronFiringsPerDay = AIConversationScheduleCreationLimits::countCronFiringsPerDay($schedule, new DateTime());
        if (AIConversationScheduleCreationLimits::exceedsCronFiringsPerDay($cronFiringsPerDay)) {
            throw new BadRequestException(
                "The cron '{$schedule->schedule}' fires {$cronFiringsPerDay}× per day — at most "
                . AIConversationScheduleCreationLimits::MAX_CRON_FIRINGS_PER_DAY . ' firings per day are allowed (every firing is a full agent run'
                . ' that costs budget). Choose specific times of day (e.g. "0 8 * * *") or a weekly rhythm, and explain the limit to the user.'
            );
        }
    }

    /**
     * A validated IANA identifier from the model, or null when absent (blank counts as absent).
     *
     * @param string|null $timezoneIdentifier
     * @return string|null
     * @throws BadRequestException for an unknown identifier
     */
    protected function findValidatedTimezoneIdentifier(?string $timezoneIdentifier): ?string
    {
        $timezoneIdentifier = trim((string)$timezoneIdentifier);
        if ($timezoneIdentifier === '') {
            return null;
        }
        AIConversationScheduleCreationLimits::assertKnownTimezoneIdentifier($timezoneIdentifier);
        return $timezoneIdentifier;
    }

    /**
     * The zone this call thinks in: the conversation's, else the space's; null = none known (the schedule runs in UTC).
     *
     * @param AgentToolCallProvenance $callProvenance
     * @param AgentSpace|null $agentSpace
     * @return string|null
     */
    protected function findTimezoneIdentifierOfCall(AgentToolCallProvenance $callProvenance, ?AgentSpace $agentSpace): ?string
    {
        $callTimezone = $callProvenance->getTimezone();
        if ($callTimezone !== null) {
            return $callTimezone->getName();
        }
        return $agentSpace?->findTimezone()?->identifier;
    }

    /**
     * Whether the zone {@see findTimezoneIdentifierOfCall()} found is only a default (the space's own zone is not known).
     *
     * @param AgentToolCallProvenance $callProvenance
     * @param AgentSpace|null $agentSpace
     * @return bool
     */
    protected function isTimezoneOfCallUnverified(AgentToolCallProvenance $callProvenance, ?AgentSpace $agentSpace): bool
    {
        if ($callProvenance->getTimezone() !== null) {
            return $callProvenance->isTimezoneUnverified();
        }
        return $agentSpace?->findTimezone()?->unverified ?? false;
    }

    /**
     * Refuses every schedule administration inside a SCHEDULED RUN: a run whose mission carried time or recurrence
     * wording re-created or changed its own schedule instead of executing. A fact of the call (its schedule id), never a
     * text heuristic. Immediate background work stays allowed — that is execution, not administration.
     *
     * @param AgentToolCallProvenance $callProvenance
     * @param string $toolName
     * @return void
     * @throws BadRequestException
     */
    protected function refuseScheduleAdministrationFromScheduledRun(AgentToolCallProvenance $callProvenance, string $toolName): void
    {
        if ($callProvenance->scheduleId === null) {
            return;
        }
        // a non-positive id is an evaluation replay's stand-in for a schedule without a row: no id is named then
        $scheduleIdentifier = $callProvenance->scheduleId > 0 ? ' #' . $callProvenance->scheduleId : '';
        throw new BadRequestException(
            "Tool '{$toolName}' is not available inside a SCHEDULED RUN: you are the execution of a task schedule"
            . $scheduleIdentifier . ' — execute the mission; never create, update or delete schedules from within it.'
            . ' Time or recurrence wording in the mission text is scheduler metadata, already handled. If the mission'
            . ' literally asks for schedule changes, report that to the owner in your final answer instead.'
        );
    }

    /**
     * The account whose schedule budget pot the call reads: the pot the start-time check of `startBackgroundTask`
     * refuses on and the pot every budget line of these tools reports ({@see composeScheduleBudgetPotNoteOfCall()}).
     * Default: the acting account ({@see requireActingAccount()}). An application whose payer is another account —
     * e.g. the owner of the space when an account outside the owner's account family works in it — overrides this
     * one method; the lists and the targets of a call stay scoped to the acting account.
     *
     * Only the tools read through it. The pot a scheduled run is checked against when it fires is the pot of
     * {@see AIConversationSchedulesService::findBudgetPayerOfSchedule()} (default: the account the schedule is stored
     * for); an application that overrides this method overrides that one with the same rule.
     *
     * @param AgentSpace|null $agentSpace the space of the call; null = account level
     * @param Account $actingAccount the account the call acts for
     * @param AgentToolCallProvenance $callProvenance the call's context (conversation, schedule, tool)
     * @return Account the account whose pot is read
     */
    protected function findBudgetPayerOfCall(?AgentSpace $agentSpace, Account $actingAccount, AgentToolCallProvenance $callProvenance): Account
    {
        return $actingAccount;
    }

    /**
     * The schedule budget's line for a tool note of this call: the pot of the call's payer
     * ({@see findBudgetPayerOfCall()}), composed by {@see composeScheduleBudgetPotNote()}. Override this one when the
     * line needs the space or the call; an override of {@see composeScheduleBudgetPotNote()} keeps working and
     * receives the payer.
     *
     * @param AIConversationSchedulesService $aIConversationSchedulesService
     * @param AgentSpace|null $agentSpace the space of the call; null = account level
     * @param Account $actingAccount the account the call acts for
     * @param AgentToolCallProvenance $callProvenance the call's context
     * @return string
     */
    protected function composeScheduleBudgetPotNoteOfCall(
        AIConversationSchedulesService $aIConversationSchedulesService,
        ?AgentSpace $agentSpace,
        Account $actingAccount,
        AgentToolCallProvenance $callProvenance
    ): string {
        return $this->composeScheduleBudgetPotNote(
            $aIConversationSchedulesService,
            $this->findBudgetPayerOfCall($agentSpace, $actingAccount, $callProvenance)
        );
    }

    /**
     * The schedule budget's line for a tool note (warn threshold reached or exhausted; '' below it and when the budget
     * cannot be read — the budget is a brake, never an availability risk). An application without a schedule budget
     * gets ''. The tools call it through {@see composeScheduleBudgetPotNoteOfCall()}.
     *
     * @param AIConversationSchedulesService $aIConversationSchedulesService
     * @param Account $budgetPayerAccount the account whose pot is read ({@see findBudgetPayerOfCall()}; the acting
     *                                    account unless an application decides otherwise)
     * @return string
     */
    protected function composeScheduleBudgetPotNote(AIConversationSchedulesService $aIConversationSchedulesService, Account $budgetPayerAccount): string
    {
        $scheduleBudgetPotStatus = $aIConversationSchedulesService->buildScheduleBudgetPotStatusForAccount($budgetPayerAccount);
        return $scheduleBudgetPotStatus instanceof ScheduleBudgetPotStatus ? $scheduleBudgetPotStatus->buildAgentFacingSummary() : '';
    }

    /**
     * The projected monthly load of the active recurring schedules in scope, for a tool note; '' when there is nothing
     * to say or the projection fails.
     *
     * @param AIConversationSchedulesService $aIConversationSchedulesService
     * @param AgentSpace|null $agentSpace
     * @param Account $actingAccount
     * @return string
     */
    protected function composeMonthlyCostNote(
        AIConversationSchedulesService $aIConversationSchedulesService,
        ?AgentSpace $agentSpace,
        Account $actingAccount
    ): string {
        try {
            $monthlyCostEstimate = $agentSpace !== null
                ? $aIConversationSchedulesService->buildMonthlyCostEstimateForActiveRecurringSchedulesOfAgentSpace($agentSpace)
                : $aIConversationSchedulesService->buildMonthlyCostEstimateForActiveRecurringSchedulesOfAccountWithoutAgentSpace($actingAccount);
            return $monthlyCostEstimate->buildAgentFacingSummary();
        } catch (BadRequestException|ForbiddenException|NotFoundException|InternalErrorException) {
            return '';
        }
    }

    /**
     * How to show the task list on screen, when the application names a widget for it ({@see TASK_LIST_WIDGET_TYPE});
     * '' without one, on a phone line, and in a delegation child (it cannot write into the owner's chat).
     *
     * @param AgentSpace|null $agentSpace
     * @param AgentToolCallProvenance $callProvenance
     * @return string
     */
    protected function composeTaskListWidgetNote(?AgentSpace $agentSpace, AgentToolCallProvenance $callProvenance): string
    {
        $taskListWidgetType = $this->findTaskListWidgetType();
        if ($taskListWidgetType === null || $callProvenance->isOwnerOnThePhoneLine() || $callProvenance->isInDelegationChild()) {
            return '';
        }
        $widgetFields = ['type' => $taskListWidgetType];
        $widgetSelfHydrationKey = $agentSpace?->getWidgetSelfHydrationKey();
        if ($widgetSelfHydrationKey !== null) {
            $widgetFields[WidgetBlockFieldNames::getWidgetSelfHydrationKeyFieldName()] = $widgetSelfHydrationKey;
        }
        return 'When the user wants to SEE the tasks: write a SHORT one-line intro, THEN this widget on its own line'
            . " (it renders the live list itself):\n```widget\n" . json_encode($widgetFields, JSON_UNESCAPED_SLASHES) . "\n```";
    }

    /**
     * The widget type of the task list: the config `AI.agentTasks.taskListWidgetType`, else {@see TASK_LIST_WIDGET_TYPE};
     * null = none.
     *
     * @return string|null
     */
    protected function findTaskListWidgetType(): ?string
    {
        $configuredWidgetType = Config::get(static::CONFIG_TASK_LIST_WIDGET_TYPE);
        if (is_string($configuredWidgetType) && trim($configuredWidgetType) !== '') {
            return trim($configuredWidgetType);
        }
        return static::TASK_LIST_WIDGET_TYPE;
    }

    /**
     * How to read the task list on a phone line, where nothing is shown: a few tasks in words, the rest offered; ''
     * in a delegation child (it does not talk to the owner).
     *
     * @param AgentToolCallProvenance $callProvenance
     * @return string
     */
    protected function composeTaskListPhoneNote(AgentToolCallProvenance $callProvenance): string
    {
        if ($callProvenance->isInDelegationChild()) {
            return '';
        }
        return 'The owner is on the phone: read at most ' . static::PHONE_LINE_TASKS_READ_ALOUD . ' tasks aloud, each as its'
            . ' title and its next time in plain words (weekday, time of day), the most relevant first; say how many more'
            . ' there are and offer to go on. Never read ids, cron expressions, statuses in capitals or filter syntax.';
    }

    /**
     * Whether the reads of the scope run without the entity read rights. The package rule: an account that may use a
     * space reads that space's schedules and runs, also those that run as another account (the read rights follow the
     * authenticated account and would hide them). The mandatory scope filter (the space, or the account's own rows
     * without a space) bounds the read; a space the acting account may not use keeps the entity rights.
     *
     * @param AgentSpace|null $agentSpace
     * @param Account $actingAccount
     * @return bool
     */
    protected function isScopeReadWithoutEntityRights(?AgentSpace $agentSpace, Account $actingAccount): bool
    {
        if ($agentSpace === null) {
            return true;
        }
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        try {
            $agentSpacesService->requireAgentSpaceUsableByAccount($agentSpace, $actingAccount);
            return true;
        } catch (ForbiddenException) {
            return false;
        }
    }

    /**
     * The run conversations of the scope under the query options the caller set (the scope filter among them), read
     * under {@see isScopeReadWithoutEntityRights()}.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    protected function findRunConversationsOfScope(AIConversationsService $aIConversationsService, ?AgentSpace $agentSpace, Account $actingAccount): AIConversations
    {
        $readsWithoutEntityRights = $this->isScopeReadWithoutEntityRights($agentSpace, $actingAccount);
        if ($readsWithoutEntityRights) {
            DDDService::instance()->deactivateEntityRightsRestrictions();
        }
        try {
            return $aIConversationsService->findAll(useEntityRegistrCache: false);
        } finally {
            if ($readsWithoutEntityRights) {
                DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
            }
        }
    }

    /**
     * How many run conversations of the scope match the caller's query options, under the same rule as
     * {@see findRunConversationsOfScope()}.
     */
    protected function countRunConversationsOfScope(AIConversationsService $aIConversationsService, ?AgentSpace $agentSpace, Account $actingAccount): int
    {
        $readsWithoutEntityRights = $this->isScopeReadWithoutEntityRights($agentSpace, $actingAccount);
        if ($readsWithoutEntityRights) {
            DDDService::instance()->deactivateEntityRightsRestrictions();
        }
        try {
            return (int)$aIConversationsService->countAll();
        } finally {
            if ($readsWithoutEntityRights) {
                DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
            }
        }
    }

    /**
     * The schedules of the scope under the query options the caller set, read under
     * {@see isScopeReadWithoutEntityRights()}.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    protected function findSchedulesOfScope(AIConversationSchedulesService $aIConversationSchedulesService, ?AgentSpace $agentSpace, Account $actingAccount): AIConversationSchedules
    {
        $readsWithoutEntityRights = $this->isScopeReadWithoutEntityRights($agentSpace, $actingAccount);
        if ($readsWithoutEntityRights) {
            DDDService::instance()->deactivateEntityRightsRestrictions();
        }
        try {
            return $aIConversationSchedulesService->findAll(useEntityRegistrCache: false);
        } finally {
            if ($readsWithoutEntityRights) {
                DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
            }
        }
    }

    /** How many schedules of the scope match the caller's query options, under the rule of {@see findSchedulesOfScope()} */
    protected function countSchedulesOfScope(AIConversationSchedulesService $aIConversationSchedulesService, ?AgentSpace $agentSpace, Account $actingAccount): int
    {
        $readsWithoutEntityRights = $this->isScopeReadWithoutEntityRights($agentSpace, $actingAccount);
        if ($readsWithoutEntityRights) {
            DDDService::instance()->deactivateEntityRightsRestrictions();
        }
        try {
            return (int)$aIConversationSchedulesService->countAll();
        } finally {
            if ($readsWithoutEntityRights) {
                DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
            }
        }
    }

    /** Every schedule of the scope, archived included (the labels of the run list), under the rule of the scope */
    protected function findAllSchedulesOfScopeForLabels(AIConversationSchedulesService $aIConversationSchedulesService, ?AgentSpace $agentSpace, Account $actingAccount): AIConversationSchedules
    {
        $readsWithoutEntityRights = $this->isScopeReadWithoutEntityRights($agentSpace, $actingAccount);
        if ($readsWithoutEntityRights) {
            DDDService::instance()->deactivateEntityRightsRestrictions();
        }
        try {
            return $agentSpace !== null
                ? $aIConversationSchedulesService->findSchedulesOfAgentSpace($agentSpace, includeArchived: true)
                : $aIConversationSchedulesService->findSchedulesOfAccountWithoutAgentSpace($actingAccount, includeArchived: true);
        } finally {
            if ($readsWithoutEntityRights) {
                DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
            }
        }
    }

    /**
     * A conversation by id without the entity read rights — for `getTaskRunReport`, which checks the run's SCHEDULE
     * against the acting account before it answers anything ({@see findScheduleOfActingAccount()}); null when none.
     */
    protected function findRunConversationById(int $conversationId, AIConversationsService $aIConversationsService): ?AIConversation
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $runConversation = $aIConversationsService->find($conversationId);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        return $runConversation instanceof AIConversation ? $runConversation : null;
    }

    /**
     * Override point: what the model reads when a retried call answers with the schedule it already produced
     * ({@see findScheduleAlreadyProducedByCall()}): nothing was created again; confirm it once.
     */
    protected function composeScheduleAlreadyProducedNote(
        AIConversationSchedule $alreadyProducedSchedule,
        OneTimeTaskScheduleCreateRequestDto $requestDto,
        AgentToolCallProvenance $callProvenance
    ): string {
        return 'This call already produced schedule #' . (int)($alreadyProducedSchedule->id ?? 0)
            . ' — it was NOT created again. ' . static::composeNextFiringNote($alreadyProducedSchedule)
            . 'Confirm it to the user in ONE short sentence; do not schedule it again.';
    }

    /**
     * Override point: the schedule THIS call already produced, when the call is retried (a resumed turn, a replayed
     * step): `scheduleOneTimeTask` answers with it and creates nothing. The default: null (every call creates). An
     * application that can tell a repeated call (a call id it stored on the schedule, the creating conversation and
     * the same task text) returns the stored schedule.
     *
     * @param OneTimeTaskScheduleCreateRequestDto $requestDto
     * @param AgentToolCallProvenance $callProvenance
     * @param AIConversationSchedulesService $aIConversationSchedulesService
     * @return AIConversationSchedule|null
     */
    protected function findScheduleAlreadyProducedByCall(
        OneTimeTaskScheduleCreateRequestDto $requestDto,
        AgentToolCallProvenance $callProvenance,
        AIConversationSchedulesService $aIConversationSchedulesService
    ): ?AIConversationSchedule {
        return null;
    }

    /**
     * The timing edits of updateTaskSchedule: an explicit zone, a new cron, a new one-time moment; the edited column
     * names. A schedule stored without a zone (an older row) changes its zone ONLY with a new cron (the model writes
     * the cron in local digits): {@see applyTimezoneOnCronEditToScheduleWithoutZone()}. A new moment of such a schedule
     * is read in the zone of its space without storing it ({@see findTimezoneIdentifierForMomentEdit()}). Any other
     * edit (title, task, report channel, done criteria, pause) leaves the zone and the next firing untouched.
     *
     * @return string[]
     * @throws BadRequestException
     */
    protected function applyTimingEditsToSchedule(AIConversationSchedule $schedule, TaskScheduleUpdateRequestDto $requestDto, DateTime $now): array
    {
        $editedColumnNames = [];
        // 0 / negative minutes and an empty string mean "not set", as at creation
        $editedRunInMinutes = $requestDto->runInMinutes !== null && $requestDto->runInMinutes > 0 ? $requestDto->runInMinutes : null;
        $editedRunAtLocalTime = trim((string)($requestDto->runAtLocalTime ?? '')) !== '' ? trim((string)$requestDto->runAtLocalTime) : null;

        // an explicit zone is applied first: the cron floor and the moment below are then evaluated in the zone the
        // schedule fires in; a one-time task's zone changes only together with its moment
        $overrideTimezoneIdentifier = $this->findValidatedTimezoneIdentifier($requestDto->timezoneIdentifier);
        if ($overrideTimezoneIdentifier !== null) {
            $timezoneEditOperation = $editedRunAtLocalTime !== null
                ? AIConversationScheduleTransition::OPERATION_EDIT_TIMEZONE_WITH_NEW_MOMENT
                : AIConversationScheduleTransition::OPERATION_EDIT_TIMEZONE;
            if (!$schedule->buildTransitionTo($timezoneEditOperation, $now)->isAllowed) {
                throw new BadRequestException("Changing a one-time task's time zone needs runAtLocalTime in the same call.");
            }
            $schedule->timezone = $overrideTimezoneIdentifier;
            $schedule->timezoneUnverified = false;
            $editedColumnNames[] = 'timezone';
            $editedColumnNames[] = 'timezoneUnverified';
        }

        $editedCronExpression = trim((string)($requestDto->cronSchedule ?? ''));
        if ($editedCronExpression !== '') {
            if (!$schedule->buildTransitionTo(AIConversationScheduleTransition::OPERATION_EDIT_CRON, $now)->isAllowed) {
                throw new BadRequestException(
                    "Schedule {$schedule->id} is a ONE-TIME task — move its moment with runAtLocalTime/runInMinutes; a cron applies to recurring schedules only."
                );
            }
            if (trim((string)($schedule->timezone ?? '')) === '') {
                // a schedule stored without a zone (an older row) gets the zone of its space with a new cron: the model
                // writes the new cron in local digits; it stays on UTC only when none is known
                $editedColumnNames = [...$editedColumnNames, ...$this->applyTimezoneOnCronEditToScheduleWithoutZone($schedule, $requestDto)];
            }
            $schedule->schedule = $editedCronExpression;
            $this->refuseCronAboveTheFiringsPerDay($schedule);
            $editedColumnNames[] = 'schedule';
        }

        if ($editedRunInMinutes !== null || $editedRunAtLocalTime !== null) {
            $momentEditTransition = $schedule->buildTransitionTo(AIConversationScheduleTransition::OPERATION_EDIT_ONE_TIME_MOMENT, $now);
            if ($momentEditTransition->refusalReason === AIConversationScheduleTransition::REFUSAL_RECURRING_HAS_NO_ONE_TIME_MOMENT) {
                throw new BadRequestException(
                    "Schedule {$schedule->id} is a RECURRING schedule — change its firing times with cronSchedule; runAtLocalTime/runInMinutes apply to one-time tasks only."
                );
            }
            if ($momentEditTransition->refusalReason === AIConversationScheduleTransition::REFUSAL_ONE_TIME_ALREADY_RAN) {
                throw new BadRequestException(
                    "Schedule {$schedule->id} already ran — a one-time task that ran is history; schedule a new one with scheduleOneTimeTask instead."
                );
            }
            $schedule->oneTimeAt = AIConversationScheduleOneTimeMoment::resolveFor(
                $editedRunInMinutes,
                $editedRunAtLocalTime,
                $this->findTimezoneIdentifierForMomentEdit($schedule, $requestDto),
                $now
            )->moment;
            $editedColumnNames[] = 'oneTimeAt';
        }
        return $editedColumnNames;
    }

    /**
     * Override point: the zone a schedule stored without one gets when its cron is edited — by default the zone a new
     * schedule of its scope gets ({@see applyTimezoneForNewScheduleToScheduleWithoutZone()}). Returns the edited column
     * names.
     *
     * @return string[]
     */
    protected function applyTimezoneOnCronEditToScheduleWithoutZone(AIConversationSchedule $schedule, TaskScheduleUpdateRequestDto $requestDto): array
    {
        return $this->applyTimezoneForNewScheduleToScheduleWithoutZone($schedule);
    }

    /**
     * Override point: the zone a new one-time moment (`runAtLocalTime`) is read in — the schedule's stored zone, else
     * (an older row without one) the zone a new schedule of its scope gets, which is NOT stored; UTC when none is known.
     */
    protected function findTimezoneIdentifierForMomentEdit(AIConversationSchedule $schedule, TaskScheduleUpdateRequestDto $requestDto): string
    {
        if (trim((string)($schedule->timezone ?? '')) !== '') {
            return $schedule->getCronTimezoneIdentifier();
        }
        return $this->findTimezoneOfScopeForScheduleWithoutZone($schedule)?->identifier ?? $schedule->getCronTimezoneIdentifier();
    }

    /**
     * The zone a new schedule of the schedule's scope gets ({@see AgentSpacesService::findTimezoneForNewSchedule()});
     * null when none is known.
     */
    protected function findTimezoneOfScopeForScheduleWithoutZone(AIConversationSchedule $schedule): ?AgentSpaceTimezone
    {
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        try {
            return $agentSpacesService->findTimezoneForNewSchedule($schedule);
        } catch (BadRequestException|InternalErrorException|InvalidArgumentException|ReflectionException) {
            return null;
        }
    }

    /**
     * Gives a schedule stored without a zone the zone a new schedule of its scope gets
     * ({@see AgentSpacesService::findTimezoneForNewSchedule()}); no answer leaves it on UTC. Returns the edited column
     * names. Called for a cron edit only ({@see applyTimezoneOnCronEditToScheduleWithoutZone()}).
     *
     * @param AIConversationSchedule $schedule
     * @return string[]
     */
    protected function applyTimezoneForNewScheduleToScheduleWithoutZone(AIConversationSchedule $schedule): array
    {
        $agentSpaceTimezone = $this->findTimezoneOfScopeForScheduleWithoutZone($schedule);
        if ($agentSpaceTimezone === null) {
            return [];
        }
        $schedule->timezone = $agentSpaceTimezone->identifier;
        $schedule->timezoneUnverified = $agentSpaceTimezone->unverified;
        return ['timezone', 'timezoneUnverified'];
    }
}
