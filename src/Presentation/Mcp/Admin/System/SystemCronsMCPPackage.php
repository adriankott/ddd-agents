<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Admin\System;

use DDD\Domain\AI\Entities\MCPPackages\LlmRendering\LlmSecretRedaction;
use DDD\Domain\AI\Entities\MCPPackages\LlmRendering\LlmTextRendering;
use DDD\Presentation\Mcp\Admin\Common\Dtos\AdminRecordListResponseDto;
use DDD\Presentation\Mcp\Admin\Common\Exceptions\AdminToolRecordNotFoundException;
use DDD\Presentation\Mcp\Admin\System\Dtos\GetCronRequestDto;
use DDD\Presentation\Mcp\Admin\System\Dtos\GetWorkerTransportRequestDto;
use DDD\Presentation\Mcp\Admin\System\Dtos\ListCronExecutionsRequestDto;
use DDD\Presentation\Mcp\Admin\System\Dtos\ListCronsRequestDto;
use DDD\Presentation\Mcp\Admin\System\Dtos\SystemReportResponseDto;
use DDD\Presentation\Mcp\Admin\System\Lines\CronExecutionLine;
use DDD\Presentation\Mcp\Admin\System\Lines\CronLine;
use DDD\Presentation\Mcp\Admin\System\Lines\SystemLineFormats;
use DDD\Presentation\Mcp\Admin\System\Lines\WorkerMessageClassLine;
use DDD\Presentation\Mcp\Admin\System\Lines\WorkerProcessLine;
use DDD\Presentation\Mcp\Admin\System\Lines\WorkerTransportLine;
use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRights;
use DDD\Domain\AI\Entities\MCPPackages\MCPRequiresAccountRights;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPRequiresAdminWithAccessToAllResources;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolReturnsTrustedContent;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolReturnsUntrustedContent;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolSummary;
use DDD\Domain\Common\Entities\Crons\CronExecutionsPage;
use DDD\Domain\Common\Entities\Crons\CronOverview;
use DDD\Domain\Common\Entities\Workers\WorkerProcess;
use DDD\Domain\Common\Entities\Workers\WorkersOverview;
use DDD\Domain\Common\Entities\Workers\WorkerTransportOverview;
use DDD\Domain\Common\Services\CronsManagementService;
use DDD\Domain\Common\Services\WorkersOverviewService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\NotFoundException;

/**
 * The cron jobs for `System.Crons`: the crons with their state, one cron with its runs, and the runs of every cron.
 * Reads only: nothing here runs, pauses or changes a cron. Every read goes through the service behind the admin's Crons
 * screen (CronsManagementService).
 *
 * The output of a cron command is shown as text the system logged: the first line (the end of the output on one run's
 * card), credentials and e-mail addresses masked, cut, and marked untrusted. Platform operators only: the right
 * {@see AgentAccountRights::OPERATE_PLATFORM} and an account with access to every resource.
 */
#[MCPRequiresAdminWithAccessToAllResources]
#[MCPRequiresAccountRights(AgentAccountRights::OPERATE_PLATFORM)]
class SystemCronsMCPPackage extends MCPPackage
{
    public static ?string $name = 'ddd.system.crons';

    public static ?string $description = 'Read the cron jobs: state, schedule, runs and their output.';

    /** @var string What a result calls a cron */
    public const string CRON_LABEL = 'cron';

    /** @var string The tool that names the crons with their ids */
    public const string CRON_FIND_TOOL = 'listCrons';

    /** @var string What a result calls a run of a cron */
    public const string CRON_EXECUTION_LABEL = 'cron run';

    /** @var string The tool that names the runs with their ids */
    public const string CRON_EXECUTION_FIND_TOOL = 'listCronExecutions';

    /** @var int The runs a cron's card shows */
    public const int MAX_RECENT_EXECUTIONS = 8;

    /** @var int The characters of a run's output its card shows (the end of the output) */
    public const int EXECUTION_OUTPUT_LENGTH = 1500;

    /** @var int The characters of a cron's description its card shows */
    public const int CRON_DESCRIPTION_LENGTH = 400;

    /** @var string[] The states of a cron, the ones that need a person first */
    public const array CRON_STATUSES_IN_ORDER = [
        CronOverview::STATUS_FAILING,
        CronOverview::STATUS_MISSING_COMMAND,
        CronOverview::STATUS_OVERDUE,
        CronOverview::STATUS_RUNNING,
        CronOverview::STATUS_OK,
        CronOverview::STATUS_PAUSED,
    ];

    /** @var string[] Words an operator or a model uses for a result of a run, and the filter they mean */
    public const array EXECUTION_RESULT_ALIASES = [
        'FAILED' => CronExecutionsPage::RESULT_FAILED,
        'FAILING' => CronExecutionsPage::RESULT_FAILED,
        'ERROR' => CronExecutionsPage::RESULT_FAILED,
        'SUCCESSFUL' => CronExecutionsPage::RESULT_SUCCESSFUL,
        'SUCCEEDED' => CronExecutionsPage::RESULT_SUCCESSFUL,
        'SUCCESS' => CronExecutionsPage::RESULT_SUCCESSFUL,
        'OK' => CronExecutionsPage::RESULT_SUCCESSFUL,
        'RUNNING' => CronExecutionsPage::RESULT_RUNNING,
        'ALL' => CronExecutionsPage::RESULT_ALL,
    ];

    /** @var string What the untrusted passage of cron output is */
    public const string UNTRUSTED_CRON_OUTPUT = 'output of cron commands, logged by the system';

    /** @var string The answer of listCrons without a match */
    public const string NO_CRONS = 'No cron matches. Leave out status or searchText to list every cron.';

    /** @var string The answer of listCronExecutions without a match */
    public const string NO_CRON_EXECUTIONS = 'No run matches. Leave out result or cronId; runs older than the retention are deleted.';

    /** @var string Why the times of a schedule read as they do */
    public const string SCHEDULE_TIME_ZONE_NOTE = 'Schedules are cron expressions evaluated in UTC.';


    /**
     * The cron jobs with their state, the ones that need attention first: FAILING (last run failed), MISSING_COMMAND,
     * OVERDUE (planned start passed and it did not run), RUNNING, OK, PAUSED; each with schedule, command name, last
     * and next run and the runs of the last 24 hours. The first line counts the states and says when the runner last
     * started a cron. Use it for "which cron failed", "did cron X run", "when does X run next".
     *
     * @example {}
     * @example {"status": "FAILING"}
     * @example {"searchText": "import"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolSummary('The cron jobs with state (failing, overdue, running, ok, paused), schedule, last and next run.')]
    #[MCPToolReturnsTrustedContent('cron names written by staff, schedules and states')]
    public function listCrons(ListCronsRequestDto $requestDto, CronsManagementService $cronsManagementService): AdminRecordListResponseDto
    {
        $status = $requestDto->status !== null && trim($requestDto->status) !== '' ? mb_strtoupper(str_replace([' ', '-'], '_', trim($requestDto->status))) : null;
        if ($status === 'FAILED') {
            $status = CronOverview::STATUS_FAILING;
        }
        if ($status !== null && !in_array($status, CronOverview::STATUSES, true)) {
            throw new BadRequestException('Unknown status ' . LlmTextRendering::displayValue($requestDto->status) . '. Use one of ' . implode(', ', static::CRON_STATUSES_IN_ORDER) . ', or none for every cron.');
        }
        $searchText = mb_strtolower(trim((string)$requestDto->searchText));
        $cronsOverview = $cronsManagementService->getCronsOverview();
        $matchingCronOverviews = [];
        foreach (static::CRON_STATUSES_IN_ORDER as $statusInOrder) {
            foreach ($cronsOverview->crons->getElements() as $cronOverview) {
                if ($cronOverview->status !== $statusInOrder || ($status !== null && $cronOverview->status !== $status)) {
                    continue;
                }
                if ($searchText !== '' && !str_contains(mb_strtolower($cronOverview->name . ' ' . $cronOverview->commandName . ' ' . $cronOverview->description), $searchText)) {
                    continue;
                }
                $matchingCronOverviews[] = $cronOverview;
            }
        }
        $runner = $cronsOverview->runner;
        $responseDto = new AdminRecordListResponseDto();
        $responseDto->subjectLine = 'Crons: ' . $runner->activeCount . ' active, ' . $runner->pausedCount . ' paused · failing ' . $runner->failingCount
            . ', overdue ' . $runner->overdueCount . ', without command ' . $runner->missingCommandCount . ', running ' . $runner->runningCount
            . ' · last 24 h ' . SystemLineFormats::countOf($runner->runCount24h, 'run') . ', ' . $runner->failureCount24h . ' failed · the runner last started a cron '
            . SystemLineFormats::formatTime($runner->lastRunStartedAt) . ' (now ' . SystemLineFormats::formatTime($runner->serverNow) . ')';
        $responseDto->totalCount = count($matchingCronOverviews);
        $responseDto->skip = $requestDto->getSkip();
        $responseDto->orderDescription = 'the ones that need attention first';
        $responseDto->emptyText = static::NO_CRONS;
        foreach (array_slice($matchingCronOverviews, $responseDto->skip, $requestDto->getPageSize()) as $cronOverview) {
            if (!$responseDto->addRecord($cronOverview->id, new CronLine($cronOverview))) {
                break;
            }
        }
        $responseDto->legendLines[] = static::SCHEDULE_TIME_ZONE_NOTE;
        return $responseDto;
    }

    /**
     * One cron: state, description, schedule, command with its arguments, last and next runs, how its runs went in
     * the last 24 hours and 14 days, and its newest runs with the first line of their output. With cronExecutionId
     * instead: that run of its cron with the end of its output, which is where a failure names its reason.
     *
     * @example {"cronId": 12}
     * @example {"cronExecutionId": 88123}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolSummary('One cron with schedule, command, statistics and newest runs; or one run with the end of its output.')]
    #[MCPToolReturnsUntrustedContent('the output of a cron command is text a command wrote')]
    public function getCron(GetCronRequestDto $requestDto, CronsManagementService $cronsManagementService): SystemReportResponseDto
    {
        $cronId = $requestDto->cronId;
        $cronExecutionDetail = null;
        if ($requestDto->cronExecutionId !== null) {
            try {
                $cronExecutionDetail = $cronsManagementService->getCronExecutionDetail($requestDto->cronExecutionId);
            } catch (NotFoundException) {
                throw AdminToolRecordNotFoundException::forRecord(static::CRON_EXECUTION_LABEL, $requestDto->cronExecutionId, static::CRON_EXECUTION_FIND_TOOL);
            }
            $cronId = $cronExecutionDetail->execution->cronId;
        }
        if ($cronId === null) {
            throw new BadRequestException('cronId is missing. Pass the id of a cron (' . static::CRON_FIND_TOOL . ' names them) or cronExecutionId.');
        }
        try {
            $cronDetail = $cronsManagementService->getCronDetail($cronId);
        } catch (NotFoundException) {
            throw AdminToolRecordNotFoundException::forRecord(static::CRON_LABEL, $cronId, static::CRON_FIND_TOOL);
        }
        $cronOverview = $cronDetail->cron;
        $responseDto = new SystemReportResponseDto();
        $responseDto->setField('cronId', $cronOverview->id);
        $responseDto->setField('status', $cronOverview->status);
        $responseDto->setField('isActive', $cronOverview->isActive);
        $responseDto->setField('schedule', $cronOverview->schedule);
        $responseDto->addLines((new CronLine($cronOverview))->toLlmLine());
        $description = LlmTextRendering::toSingleLine($cronOverview->description);
        if ($description !== '') {
            $responseDto->addLines('Description: ' . LlmTextRendering::shorten(LlmSecretRedaction::redact($description), static::CRON_DESCRIPTION_LENGTH));
        }
        $arguments = LlmTextRendering::toSingleLine((string)($cronOverview->arguments ?? ''));
        $commandName = SystemLineFormats::formatCommandName($cronOverview->commandName);
        $responseDto->addLines(
            'Command: ' . $commandName
            . ($arguments !== '' && $commandName !== LlmSecretRedaction::MASK ? ' ' . LlmSecretRedaction::redact($arguments) : '')
            . ($cronOverview->commandExists ? '' : ' (this command no longer exists in the application)')
            . ' · ' . ($cronOverview->isActive ? 'active' : 'paused')
        );
        $nextRunTexts = [];
        foreach ($cronDetail->nextRuns->getElements() as $cronScheduledRun) {
            $nextRunTexts[] = SystemLineFormats::formatTime($cronScheduledRun->scheduledAt);
        }
        if ($nextRunTexts !== []) {
            $responseDto->addLines('Next planned runs: ' . implode(', ', array_slice($nextRunTexts, 0, 3)) . '. ' . static::SCHEDULE_TIME_ZONE_NOTE);
        }
        $stats14d = $cronDetail->stats14d;
        $responseDto->addLines(
            'Last 14 days: ' . SystemLineFormats::countOf($stats14d->runCount, 'run') . ', ' . $stats14d->failureCount . ' failed'
            . ($stats14d->averageDurationMs !== null ? ' · average ' . SystemLineFormats::formatDuration($stats14d->averageDurationMs) . ', longest ' . SystemLineFormats::formatDuration($stats14d->maxDurationMs) : '')
        );
        if ($cronExecutionDetail !== null) {
            $execution = $cronExecutionDetail->execution;
            $responseDto->setField('cronExecutionId', $execution->id);
            $responseDto->setField('executionState', CronExecutionLine::describeState($execution));
            $output = trim($cronExecutionDetail->output);
            $outputLength = mb_strlen($output);
            $outputEnd = LlmSecretRedaction::redact($outputLength > static::EXECUTION_OUTPUT_LENGTH ? mb_substr($output, -static::EXECUTION_OUTPUT_LENGTH) : $output);
            $responseDto->addLines(
                'Run #' . $execution->id . ': started ' . SystemLineFormats::formatTime($execution->startedAt, true) . ' · '
                . ($execution->durationMs !== null ? SystemLineFormats::formatDuration($execution->durationMs) : 'not finished') . ' · ' . CronExecutionLine::describeState($execution)
                . ' · output ' . number_format($outputLength) . ' chars' . ($outputLength > static::EXECUTION_OUTPUT_LENGTH ? ', its last ' . number_format(static::EXECUTION_OUTPUT_LENGTH) . ' shown' : '')
                . ($output === '' ? ': none.' : ':')
            );
            if ($output !== '') {
                $responseDto->addUntrustedLines(static::UNTRUSTED_CRON_OUTPUT, [$outputEnd]);
            }
            return $responseDto;
        }
        $executionLines = [];
        foreach (array_slice($cronOverview->recentExecutions->getElements(), 0, static::MAX_RECENT_EXECUTIONS) as $cronExecutionOverview) {
            $executionLines[] = (new CronExecutionLine($cronExecutionOverview, false))->toLlmLine();
        }
        if ($executionLines === []) {
            $responseDto->addLines('No run is stored for this cron.');
            return $responseDto;
        }
        $responseDto->addLines('Newest runs (' . count($executionLines) . ', newest first; the end of one run\'s output: this tool with cronExecutionId):');
        $responseDto->addUntrustedLines(static::UNTRUSTED_CRON_OUTPUT, $executionLines);
        return $responseDto;
    }

    /**
     * The runs of the crons, newest first: start, duration, result (SUCCESSFUL, FAILED, RUNNING) and the first line
     * of the output. Narrow with cronId and result. Use it for "which crons failed lately", "how long did the last
     * runs of X take". The end of one run's output (the reason of a failure): getCron with cronExecutionId.
     *
     * @example {"result": "FAILED"}
     * @example {"cronId": 12, "top": 10}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolSummary('Runs of the crons, newest first, optionally of one cron or only failed ones, with duration and first output line.')]
    #[MCPToolReturnsUntrustedContent('the output of a cron command is text a command wrote')]
    public function listCronExecutions(ListCronExecutionsRequestDto $requestDto, CronsManagementService $cronsManagementService): AdminRecordListResponseDto
    {
        $requestedResult = mb_strtoupper(trim((string)$requestDto->result));
        $result = $requestedResult === '' ? CronExecutionsPage::RESULT_ALL : (static::EXECUTION_RESULT_ALIASES[$requestedResult] ?? null);
        if ($result === null) {
            throw new BadRequestException('Unknown result ' . LlmTextRendering::displayValue($requestDto->result) . '. Use one of ' . implode(', ', CronExecutionsPage::RESULTS) . '.');
        }
        $responseDto = new AdminRecordListResponseDto();
        if ($requestDto->cronId !== null) {
            try {
                $cron = $cronsManagementService->findCronOrThrowNotFound($requestDto->cronId);
            } catch (NotFoundException) {
                throw AdminToolRecordNotFoundException::forRecord(static::CRON_LABEL, $requestDto->cronId, static::CRON_FIND_TOOL);
            }
            $responseDto->setSubject('Cron', $cron->id, $cron->name ?? null);
        }
        $cronExecutionsPage = $cronsManagementService->getCronExecutionsPage($requestDto->cronId, $result, $requestDto->getPageSize(), $requestDto->getSkip());
        $responseDto->totalCount = $cronExecutionsPage->totalCount;
        $responseDto->skip = $requestDto->getSkip();
        $responseDto->orderDescription = 'newest first' . ($result !== CronExecutionsPage::RESULT_ALL ? ', only ' . $result : '');
        $responseDto->emptyText = $result === CronExecutionsPage::RESULT_FAILED
            ? 'No failed run is stored' . ($requestDto->cronId !== null ? ' for this cron' : '') . '.'
            : static::NO_CRON_EXECUTIONS;
        $responseDto->untrustedSource = static::UNTRUSTED_CRON_OUTPUT;
        $responseDto->narrowingHint = 'Narrow with cronId or result.';
        foreach ($cronExecutionsPage->executions->getElements() as $cronExecutionOverview) {
            if (!$responseDto->addRecord($cronExecutionOverview->id, new CronExecutionLine($cronExecutionOverview, $requestDto->cronId === null))) {
                break;
            }
        }
        return $responseDto;
    }
}
