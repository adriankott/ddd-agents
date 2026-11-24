<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\SystemHealth;

use DDD\Domain\AI\Entities\MCPPackages\LlmRendering\LlmSecretRedaction;
use DDD\Domain\AI\Entities\SystemHealth\SystemHealthReport;
use DDD\Domain\AI\Entities\SystemHealth\SystemHealthSection;
use DDD\Domain\Common\Entities\Crons\CronOverview;
use DDD\Domain\Common\Entities\Issues\IssueGroup;
use DDD\Domain\Common\Entities\Issues\IssueGroupsFilter;
use DDD\Domain\Common\Entities\Workers\WorkerProcess;
use DDD\Domain\Common\Entities\Workers\WorkerTransportOverview;
use DDD\Domain\Common\Services\CronsManagementService;
use DDD\Domain\Common\Services\IssueGroupsService;
use DDD\Domain\Common\Services\IssuesOverviewService;
use DDD\Domain\Common\Services\WorkersOverviewService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Service\Attribute\Required;
use Throwable;

/**
 * The health report of the platform for `System.Health`: one section per part, each with its state, its numbers and
 * the findings that need a person. The package builds the sections of Core's features from the services the admin
 * screens read (workers overview, crons overview, issues registry) and adds no number of its own; an application adds
 * its sections through {@see SystemHealthSectionProvider}.
 *
 * Reads only. A section whose service throws is reported as `unknown` with its reason's first line, masked; the other
 * sections are built. It names no secret and no host: a worker is a transport and a state, a cron its name.
 */
class SystemHealthService extends Service
{
    /** @var int The hours the issues section looks back */
    public const int ISSUES_WINDOW_HOURS = 24;

    /** @var int The most findings of one kind a section names */
    public const int MAX_FINDINGS_PER_KIND = 5;

    /** @var int Seconds after which a cron runner that started nothing counts as not running */
    public const int CRON_RUNNER_SILENT_AFTER_SECONDS = 300;

    /** @var int The longest reason of a section that failed */
    public const int MAX_FAILURE_REASON_CHARS = 160;

    /** @var iterable<SystemHealthSectionProvider> */
    protected iterable $systemHealthSectionProviders = [];

    /** @param iterable<SystemHealthSectionProvider> $systemHealthSectionProviders */
    #[Required]
    public function setSystemHealthSectionProviders(
        #[AutowireIterator(SystemHealthSectionProvider::TAG)]
        iterable $systemHealthSectionProviders
    ): void {
        $this->systemHealthSectionProviders = $systemHealthSectionProviders;
    }

    /**
     * @return string[] Every section name a report can hold: the package's, then the application's, each once
     */
    public function getSectionNames(): array
    {
        $sectionNames = SystemHealthSection::PACKAGE_SECTION_NAMES;
        foreach ($this->systemHealthSectionProviders as $systemHealthSectionProvider) {
            foreach ($systemHealthSectionProvider->getSectionNames() as $sectionName) {
                if (SystemHealthSection::isValidName($sectionName) && !in_array($sectionName, $sectionNames, true)) {
                    $sectionNames[] = $sectionName;
                }
            }
        }
        return $sectionNames;
    }

    /**
     * @param string[] $sectionNames The sections to report (of {@see getSectionNames()}); empty: every section
     * @return SystemHealthReport The sections in the order of {@see getSectionNames()}
     */
    public function getHealth(array $sectionNames = []): SystemHealthReport
    {
        $systemHealthReport = new SystemHealthReport();
        foreach ($this->getSectionNames() as $sectionName) {
            if ($sectionNames !== [] && !in_array($sectionName, $sectionNames, true)) {
                continue;
            }
            try {
                $systemHealthSection = $this->buildSection($sectionName);
            } catch (Throwable $throwable) {
                $systemHealthSection = new SystemHealthSection($sectionName);
                $systemHealthSection->state = SystemHealthSection::STATE_UNKNOWN;
                $systemHealthSection->summary = 'could not be read: ' . LlmSecretRedaction::redactFirstLine($throwable->getMessage(), static::MAX_FAILURE_REASON_CHARS);
            }
            $systemHealthSection->name = $sectionName;
            $systemHealthReport->add($systemHealthSection);
        }
        return $systemHealthReport;
    }

    /**
     * One section: the package's own, else the one of the provider that names it.
     *
     * @throws Throwable
     */
    protected function buildSection(string $sectionName): SystemHealthSection
    {
        return match ($sectionName) {
            SystemHealthSection::WORKERS => $this->buildWorkersSection(),
            SystemHealthSection::CRONS => $this->buildCronsSection(),
            SystemHealthSection::ISSUES => $this->buildIssuesSection(),
            default => $this->buildSectionOfProvider($sectionName),
        };
    }

    /** @throws Throwable */
    protected function buildSectionOfProvider(string $sectionName): SystemHealthSection
    {
        foreach ($this->systemHealthSectionProviders as $systemHealthSectionProvider) {
            if (in_array($sectionName, $systemHealthSectionProvider->getSectionNames(), true)) {
                return $systemHealthSectionProvider->buildSection($sectionName);
            }
        }
        $systemHealthSection = new SystemHealthSection($sectionName);
        $systemHealthSection->state = SystemHealthSection::STATE_UNKNOWN;
        $systemHealthSection->summary = 'no provider builds this section';
        return $systemHealthSection;
    }

    /**
     * @return SystemHealthSection Queues, processes and failures of the Messenger workers
     * @throws Throwable
     */
    protected function buildWorkersSection(): SystemHealthSection
    {
        $systemHealthSection = new SystemHealthSection(SystemHealthSection::WORKERS);
        /** @var WorkersOverviewService $workersOverviewService */
        $workersOverviewService = DDDService::instance()->getService(WorkersOverviewService::class);
        $workersOverview = $workersOverviewService->getWorkersOverview();
        if (!$workersOverview->monitoringEnabled) {
            $systemHealthSection->state = SystemHealthSection::STATE_UNKNOWN;
            $systemHealthSection->summary = 'the worker monitoring is switched off here, so queues and processes cannot be judged';
            return $systemHealthSection;
        }
        $totals = $workersOverview->totals;
        $systemHealthSection->summary = count($workersOverview->transports->getElements()) . ' transports · '
            . $totals->liveProcessCount . ' live processes · ' . ($totals->waiting !== null ? $totals->waiting : 'unknown') . ' messages waiting · '
            . $totals->inFlight . ' in work · last hour ' . $totals->handledCountLastHour . ' handled, ' . $totals->failedCountLastHour . ' failed';
        $findingsByKind = [];
        foreach ($workersOverview->transports->getElements() as $workerTransportOverview) {
            $transportName = $workerTransportOverview->transport;
            match ($workerTransportOverview->state) {
                WorkerTransportOverview::STATE_STUCK => $findingsByKind['stuck'][] = $transportName
                    . ' (' . ($workerTransportOverview->waiting ?? '?') . ' waiting'
                    . ($workerTransportOverview->stuckSince !== null ? ', nothing finished since ' . $workerTransportOverview->stuckSince->format('Y-m-d H:i') . ' UTC' : '') . ')',
                WorkerTransportOverview::STATE_BACKLOG => $findingsByKind['backlog'][] = $transportName . ' (' . ($workerTransportOverview->waiting ?? '?') . ' waiting)',
                WorkerTransportOverview::STATE_NOT_CONSUMED => $findingsByKind['notConsumed'][] = $transportName . ' (' . ($workerTransportOverview->waiting ?? '?') . ' waiting)',
                WorkerTransportOverview::STATE_UNDEFINED => $findingsByKind['undefined'][] = $transportName,
                default => null,
            };
            $failedLastHour = 0;
            foreach ($workerTransportOverview->throughputWindows->getElements() as $workerThroughputWindow) {
                if ($workerThroughputWindow->windowSeconds === 3600) {
                    $failedLastHour = $workerThroughputWindow->failedCount;
                }
            }
            if ($failedLastHour > 0) {
                $findingsByKind['failing'][] = $transportName . ' (' . $failedLastHour . ' failed)';
            }
            if ($workerTransportOverview->restartPending) {
                $findingsByKind['restartPending'][] = $transportName;
            }
        }
        foreach ($workersOverview->processes->getElements() as $workerProcess) {
            $displayState = $workerProcess->displayState ?? null;
            if ($displayState === WorkerProcess::DISPLAY_STATE_LOST || $displayState === WorkerProcess::DISPLAY_STATE_SILENT) {
                $findingsByKind[$displayState === WorkerProcess::DISPLAY_STATE_LOST ? 'lost' : 'silent'][] = (string)($workerProcess->transport ?? '?');
            }
        }
        $findingTexts = [
            'stuck' => 'stuck, messages wait and nothing finishes: ',
            'backlog' => 'backlog, more messages wait than the workers finish: ',
            'notConsumed' => 'messages wait and no worker consumes the transport: ',
            'undefined' => 'a process consumes a transport the worker list does not define: ',
            'failing' => 'failed messages in the last hour: ',
            'lost' => 'a process stopped reporting without a stop (LOST) on: ',
            'silent' => 'a process works on one message for a long time (SILENT) on: ',
        ];
        foreach ($findingTexts as $findingKind => $findingText) {
            if (isset($findingsByKind[$findingKind])) {
                $systemHealthSection->addFinding($findingText . $this->joinFirst($findingsByKind[$findingKind]));
            }
        }
        if (isset($findingsByKind['restartPending'])) {
            $systemHealthSection->notes[] = 'a restart was requested and is not finished on: ' . $this->joinFirst($findingsByKind['restartPending']);
        }
        if (!$workersOverview->samplerActive) {
            $systemHealthSection->addFinding('the sampler that counts the queues every minute does not run, so the waiting counts can be old');
        }
        return $systemHealthSection;
    }

    /**
     * @return SystemHealthSection The cron jobs: failing, overdue, without their command; whether the runner runs
     * @throws Throwable
     */
    protected function buildCronsSection(): SystemHealthSection
    {
        $systemHealthSection = new SystemHealthSection(SystemHealthSection::CRONS);
        /** @var CronsManagementService $cronsManagementService */
        $cronsManagementService = DDDService::instance()->getService(CronsManagementService::class);
        $cronsOverview = $cronsManagementService->getCronsOverview();
        $runner = $cronsOverview->runner;
        $systemHealthSection->summary = $runner->activeCount . ' active, ' . $runner->pausedCount . ' paused · ' . $runner->runningCount . ' running now · last 24 h '
            . $runner->runCount24h . ' runs, ' . $runner->failureCount24h . ' failed · the runner last started a cron '
            . ($runner->lastRunStartedAt !== null ? $runner->lastRunStartedAt->format('Y-m-d H:i') . ' UTC' : 'never');
        $runnerIsSilent = $runner->activeCount > 0
            && ($runner->lastRunStartedAt === null || $runner->serverNow->getTimestamp() - $runner->lastRunStartedAt->getTimestamp() > static::CRON_RUNNER_SILENT_AFTER_SECONDS);
        if ($runnerIsSilent) {
            $systemHealthSection->addFinding('the cron runner started no cron in the last ' . intdiv(static::CRON_RUNNER_SILENT_AFTER_SECONDS, 60)
                . ' minutes: it does not run here (a development environment may run none; on production this is an outage of every cron)');
        }
        $cronNamesByStatus = [];
        foreach ($cronsOverview->crons->getElements() as $cronOverview) {
            $cronNamesByStatus[$cronOverview->status][] = '#' . $cronOverview->id . ' "' . $cronOverview->name . '"';
        }
        if (isset($cronNamesByStatus[CronOverview::STATUS_FAILING])) {
            $systemHealthSection->addFinding('last run failed: ' . $this->joinFirst($cronNamesByStatus[CronOverview::STATUS_FAILING]));
        }
        if (isset($cronNamesByStatus[CronOverview::STATUS_MISSING_COMMAND])) {
            $systemHealthSection->addFinding('the command no longer exists: ' . $this->joinFirst($cronNamesByStatus[CronOverview::STATUS_MISSING_COMMAND]));
        }
        if (isset($cronNamesByStatus[CronOverview::STATUS_OVERDUE]) && !$runnerIsSilent) {
            $systemHealthSection->addFinding('overdue, the planned start passed and it did not run: ' . $this->joinFirst($cronNamesByStatus[CronOverview::STATUS_OVERDUE]));
        }
        if (isset($cronNamesByStatus[CronOverview::STATUS_OVERDUE]) && $runnerIsSilent) {
            $systemHealthSection->notes[] = count($cronNamesByStatus[CronOverview::STATUS_OVERDUE]) . ' crons are overdue because the runner does not run';
        }
        return $systemHealthSection;
    }

    /**
     * @return SystemHealthSection The error groups of the registry's first environment: open, new, regressions
     * @throws Throwable
     */
    protected function buildIssuesSection(): SystemHealthSection
    {
        $systemHealthSection = new SystemHealthSection(SystemHealthSection::ISSUES);
        $dddService = DDDService::instance();
        /** @var IssuesOverviewService $issuesOverviewService */
        $issuesOverviewService = $dddService->getService(IssuesOverviewService::class);
        /** @var IssueGroupsService $issueGroupsService */
        $issueGroupsService = $dddService->getService(IssueGroupsService::class);
        $issueGroupStatusCounts = $issueGroupsService->countGroupsByEnvironmentAndStatus();
        $environment = $issuesOverviewService->resolveEnvironment(null, $issueGroupStatusCounts);
        if ($environment === '') {
            $systemHealthSection->state = SystemHealthSection::STATE_UNKNOWN;
            $systemHealthSection->summary = 'the issues registry knows no environment and holds no error group';
            return $systemHealthSection;
        }
        $since = DateTime::fromTimestamp(time() - static::ISSUES_WINDOW_HOURS * 3600);
        $openCount = $issueGroupStatusCounts->getCount($environment, IssueGroup::STATUS_OPEN);
        $newCount = $issueGroupsService->countNewGroupsRegisteredSince($since, $environment);
        $seenCount = $issueGroupsService->countGroups(new IssueGroupsFilter(status: IssueGroup::STATUS_OPEN, environments: [$environment], lastSeenSince: $since));
        $systemHealthSection->summary = 'environment ' . $environment . ' · ' . $openCount . ' open error groups · ' . $seenCount . ' of them seen in the last '
            . static::ISSUES_WINDOW_HOURS . ' h · ' . $newCount . ' new in the last ' . static::ISSUES_WINDOW_HOURS . ' h';
        if ($newCount > 0) {
            $systemHealthSection->addFinding($newCount . ' new error ' . ($newCount === 1 ? 'group' : 'groups') . ' in the last ' . static::ISSUES_WINDOW_HOURS . ' h');
        }
        $otherEnvironments = array_values(array_diff($issuesOverviewService->getKnownEnvironments($issueGroupStatusCounts), [$environment]));
        if ($otherEnvironments !== []) {
            $systemHealthSection->notes[] = 'other environments of the registry: ' . implode(', ', $otherEnvironments);
        }
        return $systemHealthSection;
    }

    /**
     * @param string[] $texts
     * @return string The first MAX_FINDINGS_PER_KIND texts, comma separated, and how many more there are
     */
    protected function joinFirst(array $texts): string
    {
        $shownTexts = array_slice($texts, 0, static::MAX_FINDINGS_PER_KIND);
        $moreCount = count($texts) - count($shownTexts);
        return implode(', ', $shownTexts) . ($moreCount > 0 ? ' and ' . $moreCount . ' more' : '');
    }
}
