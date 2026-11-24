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
 * The Messenger workers for `System.Workers`: the workers overview and one transport with its message classes and
 * processes. Reads only: nothing here restarts a worker. Every read goes through the service behind the admin's
 * Workers screen (WorkersOverviewService).
 *
 * What is never returned: a host name, a process id or a process key, the arguments of a worker loop, the extra
 * settings of a consumer, an environment value, a connection string. A worker is its transport and its state. An
 * error message is shown as text the system logged: the first line, credentials and e-mail addresses masked, cut, and
 * marked untrusted. Platform operators only: the right {@see AgentAccountRights::OPERATE_PLATFORM} and an account
 * with access to every resource.
 */
#[MCPRequiresAdminWithAccessToAllResources]
#[MCPRequiresAccountRights(AgentAccountRights::OPERATE_PLATFORM)]
class SystemWorkersMCPPackage extends MCPPackage
{
    public static ?string $name = 'ddd.system.workers';

    public static ?string $description = 'Read the Messenger workers: queues, processes, failures per transport and message class.';

    /** @var int Hours of message statistics getWorkerTransport reads when none is named */
    public const int DEFAULT_TRANSPORT_HOURS = 24;

    /** @var int The most hours of message statistics */
    public const int MAX_TRANSPORT_HOURS = 168;

    /** @var int The message classes a transport's card shows */
    public const int MAX_MESSAGE_CLASSES = 12;

    /** @var int The processes a card shows */
    public const int MAX_PROCESSES = 12;

    /** @var int Most characters of a list of transport names inside one line; the rest is counted */
    public const int MAX_NAME_LIST_CHARACTERS = 600;

    /** @var int Most characters of the transport lines of one overview; further transports are named with their state */
    public const int MAX_TRANSPORT_LINES_CHARACTERS = 3400;

    /** @var string[] The states of a transport that need a person, most urgent first */
    public const array TRANSPORT_STATES_THAT_NEED_ATTENTION = [
        WorkerTransportOverview::STATE_STUCK,
        WorkerTransportOverview::STATE_NOT_CONSUMED,
        WorkerTransportOverview::STATE_BACKLOG,
        WorkerTransportOverview::STATE_UNDEFINED,
    ];

    /** @var string The answer when the worker monitoring is off */
    public const string MONITORING_OFF = 'The worker monitoring is switched off here: processes, failures and history are not recorded. The transports and what waits in them are still shown.';

    /** @var string What the untrusted passage of worker error messages is */
    public const string UNTRUSTED_WORKER_ERRORS = 'error messages of worker runs, logged by the system';


    /**
     * The Messenger workers right now: totals (live processes, waiting messages, handled and failed in the last
     * hour), every transport that needs attention or did work with its state (IDLE, WORKING, BACKLOG, STUCK,
     * NOT_CONSUMED, UNDEFINED), the idle ones by name, and processes that stopped reporting. Use it for "do the
     * workers run", "is a queue stuck", "what failed". One transport in depth: getWorkerTransport.
     *
     * @example {}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolSummary('Workers overview: totals, each transport with state, waiting messages, processes, handled and failed.')]
    #[MCPToolReturnsTrustedContent('worker states and counts the platform computes')]
    public function getWorkersOverview(WorkersOverviewService $workersOverviewService): SystemReportResponseDto
    {
        $workersOverview = $workersOverviewService->getWorkersOverview();
        $totals = $workersOverview->totals;
        $responseDto = new SystemReportResponseDto();
        $responseDto->setField('liveProcessCount', $totals->liveProcessCount);
        $responseDto->setField('waiting', $totals->waiting);
        $responseDto->setField('monitoringEnabled', $workersOverview->monitoringEnabled);
        $responseDto->addLines(
            'Workers at ' . SystemLineFormats::formatTime($workersOverview->generatedAt ?? DateTime::fromTimestamp(time())) . ': '
            . SystemLineFormats::countOf(count($workersOverview->transports->getElements()), 'transport') . ' · '
            . $totals->liveProcessCount . ($totals->liveProcessCount === 1 ? ' live process' : ' live processes') . ' · '
            . ($totals->waiting !== null ? $totals->waiting : 'unknown') . ' messages waiting · ' . $totals->inFlight . ' in work · last hour '
            . $totals->handledCountLastHour . ' handled, ' . $totals->failedCountLastHour . ' failed.'
        );
        if (!$workersOverview->monitoringEnabled) {
            $responseDto->addLines(static::MONITORING_OFF);
        } elseif (!$workersOverview->samplerActive) {
            $responseDto->addLines('The sampler that counts the queues every minute does not run: the waiting counts can be old.');
        }
        $attentionLines = [];
        $activeLines = [];
        $idleTransportNames = [];
        foreach (static::TRANSPORT_STATES_THAT_NEED_ATTENTION as $attentionState) {
            foreach ($workersOverview->transports->getElements() as $workerTransportOverview) {
                if ($workerTransportOverview->state === $attentionState) {
                    $attentionLines[$workerTransportOverview->transport . ' ' . $workerTransportOverview->state] = (new WorkerTransportLine($workerTransportOverview))->toLlmLine();
                }
            }
        }
        foreach ($workersOverview->transports->getElements() as $workerTransportOverview) {
            if (in_array($workerTransportOverview->state, static::TRANSPORT_STATES_THAT_NEED_ATTENTION, true)) {
                continue;
            }
            $workerTransportLine = new WorkerTransportLine($workerTransportOverview);
            [$handledLastDay, $failedLastDay] = $workerTransportLine->getCountsOfWindow(86400);
            $isQuiet = $workerTransportOverview->state === WorkerTransportOverview::STATE_IDLE && $workerTransportOverview->liveProcessCount === 0
                && ($workerTransportOverview->waiting ?? 0) === 0 && $handledLastDay === 0 && $failedLastDay === 0 && !$workerTransportOverview->restartPending;
            if ($isQuiet) {
                $idleTransportNames[] = $workerTransportOverview->transport;
                continue;
            }
            $activeLines[$workerTransportOverview->transport . ' ' . $workerTransportOverview->state] = $workerTransportLine->toLlmLine();
        }
        $shownAttentionLines = $this->fitTransportLines($attentionLines, static::MAX_TRANSPORT_LINES_CHARACTERS);
        $responseDto->addLines(
            'Transports that need attention (' . count($attentionLines) . ')' . ($attentionLines === [] ? ': none.' : ':'),
            ...$shownAttentionLines
        );
        if ($activeLines !== []) {
            $responseDto->addLines(
                'Transports with a process or with messages in the last 24 h (' . count($activeLines) . '):',
                ...$this->fitTransportLines($activeLines, $this->findCharactersLeftAfter($shownAttentionLines, count($attentionLines), static::MAX_TRANSPORT_LINES_CHARACTERS))
            );
        }
        if ($idleTransportNames !== []) {
            $responseDto->addLines(
                'Idle, no process, nothing waiting, no message in the last 24 h (' . count($idleTransportNames) . '; a transport without a process and without a message is the normal idle state): '
                . $this->joinNamesWithinLimit($idleTransportNames)
            );
        }
        $processLines = $this->buildProcessLinesThatNeedAttention($workersOverview);
        if ($processLines !== []) {
            $responseDto->addLines('Processes that need attention (LOST: stopped reporting without a stop; SILENT: one message for a long time):', ...$processLines);
        }
        return $responseDto;
    }

    /**
     * The transport lines that fit into what is left of the result, and one line that names the rest by transport and
     * state, so a platform with many busy transports never answers with more than a model reads.
     *
     * @param array<string, string> $linesByTransportAndState Full lines, keyed by "<transport> <STATE>"
     * @param int $remainingCharacters What is left for transport lines
     * @return string[]
     */
    protected function fitTransportLines(array $linesByTransportAndState, int $remainingCharacters): array
    {
        $shownLines = [];
        $omittedTransportsAndStates = [];
        foreach ($linesByTransportAndState as $transportAndState => $line) {
            $lineLength = mb_strlen($line) + 1;
            if ($omittedTransportsAndStates === [] && $lineLength <= $remainingCharacters) {
                $shownLines[] = $line;
                $remainingCharacters -= $lineLength;
                continue;
            }
            $omittedTransportsAndStates[] = $transportAndState;
        }
        if ($omittedTransportsAndStates !== []) {
            $shownLines[] = 'Not shown as a line (' . count($omittedTransportsAndStates) . '; one in depth: getWorkerTransport): ' . $this->joinNamesWithinLimit($omittedTransportsAndStates);
        }
        return $shownLines;
    }

    /**
     * The characters left for further transport lines after `$shownLines` of `$lineCount` lines: none once a line had
     * to be left out (the rest is named by transport only).
     *
     * @param string[] $shownLines
     */
    protected function findCharactersLeftAfter(array $shownLines, int $lineCount, int $availableCharacters): int
    {
        if (count($shownLines) !== $lineCount) {
            return 0;
        }
        foreach ($shownLines as $shownLine) {
            $availableCharacters -= mb_strlen($shownLine) + 1;
        }
        return max(0, $availableCharacters);
    }

    /**
     * @param string[] $names
     * @return string The names, comma separated, cut after static::MAX_NAME_LIST_CHARACTERS with the count of the rest
     */
    protected function joinNamesWithinLimit(array $names): string
    {
        $shownNames = [];
        $usedCharacters = 0;
        foreach ($names as $name) {
            $usedCharacters += mb_strlen($name) + 2;
            if ($shownNames !== [] && $usedCharacters > static::MAX_NAME_LIST_CHARACTERS) {
                break;
            }
            $shownNames[] = $name;
        }
        $restCount = count($names) - count($shownNames);
        return implode(', ', $shownNames) . ($restCount > 0 ? ' and ' . $restCount . ' more' : '');
    }

    /**
     * One worker transport in depth: its state line, the message classes it handled in the window (handled, thrown,
     * caught, redelivered, duration, wait, last error with its message) and its live processes. Use it for "how many
     * messages wait in X", "why is X slow", "which message fails on X". The transport name is the one
     * getWorkersOverview shows.
     *
     * @example {"transport": "send_push_notification"}
     * @example {"transport": "import_feed_items", "sinceHours": 72}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolSummary('One worker transport: state, waiting messages, message classes with failures and durations, live processes.')]
    #[MCPToolReturnsUntrustedContent('error messages of worker runs can quote what a client sent')]
    public function getWorkerTransport(GetWorkerTransportRequestDto $requestDto, WorkersOverviewService $workersOverviewService): SystemReportResponseDto
    {
        $transportName = mb_strtolower(str_replace(['-', ' '], '_', trim((string)$requestDto->transport)));
        if ($transportName === '') {
            throw new BadRequestException('transport is missing. Pass the name of a transport; getWorkersOverview names them.');
        }
        $sinceHours = max(1, min(static::MAX_TRANSPORT_HOURS, $requestDto->sinceHours ?? static::DEFAULT_TRANSPORT_HOURS));
        try {
            $workerTransportDetail = $workersOverviewService->getWorkerTransportDetail($transportName, DateTime::fromTimestamp(time() - $sinceHours * 3600));
        } catch (NotFoundException) {
            $knownTransportNames = [];
            foreach ($workersOverviewService->getWorkersOverview()->transports->getElements() as $workerTransportOverview) {
                $knownTransportNames[] = $workerTransportOverview->transport;
            }
            throw new NotFoundException(
                'No transport ' . LlmTextRendering::displayValue($transportName) . '. Tell the operator it does not exist and ask which of these is meant: '
                . implode(', ', $knownTransportNames) . '.'
            );
        }
        $workerTransportOverview = $workerTransportDetail->transport;
        $workerTransportLine = new WorkerTransportLine($workerTransportOverview);
        $responseDto = new SystemReportResponseDto();
        $responseDto->setField('transport', $workerTransportOverview->transport);
        $responseDto->setField('state', $workerTransportOverview->state);
        $responseDto->setField('waiting', $workerTransportOverview->waiting);
        $responseDto->setField('liveProcessCount', $workerTransportOverview->liveProcessCount);
        $responseDto->addLines('Transport ' . $workerTransportLine->toLlmLine());
        $responseDto->addLines(
            'Waiting: ' . $workerTransportLine->describeWaiting()
            . ($workerTransportOverview->waitingSampledAt !== null ? ' (counted ' . SystemLineFormats::formatTime($workerTransportOverview->waitingSampledAt) . ')' : '')
            . ' · scaled by queue length: ' . ($workerTransportOverview->isAutoscaled ? 'yes (no process while nothing waits is normal)' : 'no')
        );
        if ($workerTransportOverview->lastErrorClass !== null && $workerTransportOverview->lastErrorClass !== '') {
            $lastErrorMessage = SystemLineFormats::quoteLoggedText($workerTransportOverview->lastErrorMessage, WorkerMessageClassLine::MESSAGE_LENGTH);
            if ($lastErrorMessage !== '') {
                $responseDto->addUntrustedLines(static::UNTRUSTED_WORKER_ERRORS, ['Last error message: ' . $lastErrorMessage]);
            }
        }
        $messageClassStats = $workerTransportDetail->messageClasses->getElements();
        if ($messageClassStats === []) {
            $responseDto->addLines('No message ran on this transport in the last ' . $sinceHours . ' h.');
        } else {
            $messageClassLines = [];
            foreach (array_slice($messageClassStats, 0, static::MAX_MESSAGE_CLASSES) as $workerMessageClassStat) {
                $messageClassLines[] = (new WorkerMessageClassLine($workerMessageClassStat))->toLlmLine();
            }
            $responseDto->addLines(
                'Message classes of the last ' . $sinceHours . ' h (' . count($messageClassStats)
                . (count($messageClassStats) > static::MAX_MESSAGE_CLASSES ? ', the first ' . static::MAX_MESSAGE_CLASSES . ' shown' : '')
                . '; thrown: the run failed, caught: the handler caught the error itself, redelivered: a retry):'
            );
            $responseDto->addUntrustedLines(static::UNTRUSTED_WORKER_ERRORS, $messageClassLines);
        }
        $processLines = [];
        foreach (array_slice($workerTransportDetail->processes->getElements(), 0, static::MAX_PROCESSES) as $workerProcess) {
            $processLines[] = (new WorkerProcessLine($workerProcess))->toLlmLine();
        }
        $responseDto->addLines(
            'Live processes (' . count($workerTransportDetail->processes->getElements()) . ')' . ($processLines === [] ? ': none.' : ':'),
            ...$processLines
        );
        return $responseDto;
    }

    /**
     * @param WorkersOverview $workersOverview
     * @return string[] One line per live process that stopped reporting or works on one message for a long time
     */
    protected function buildProcessLinesThatNeedAttention(WorkersOverview $workersOverview): array
    {
        $processLines = [];
        foreach ($workersOverview->processes->getElements() as $workerProcess) {
            $displayState = $workerProcess->displayState ?? null;
            if ($displayState !== WorkerProcess::DISPLAY_STATE_LOST && $displayState !== WorkerProcess::DISPLAY_STATE_SILENT) {
                continue;
            }
            if (count($processLines) >= static::MAX_PROCESSES) {
                break;
            }
            $processLines[] = (new WorkerProcessLine($workerProcess))->toLlmLine();
        }
        return $processLines;
    }
}
