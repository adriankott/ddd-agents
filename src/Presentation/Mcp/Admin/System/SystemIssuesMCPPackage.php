<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Admin\System;

use DDD\Domain\AI\Entities\MCPPackages\LlmRendering\LlmSecretRedaction;
use DDD\Domain\AI\Entities\MCPPackages\LlmRendering\LlmTextRendering;
use DDD\Presentation\Mcp\Admin\Common\Dtos\AdminRecordListResponseDto;
use DDD\Presentation\Mcp\Admin\Common\Dtos\AdminSavedFieldsResponseDto;
use DDD\Presentation\Mcp\Admin\Common\Exceptions\AdminToolNotSavedException;
use DDD\Presentation\Mcp\Admin\Common\Exceptions\AdminToolRecordNotFoundException;
use DDD\Presentation\Mcp\Admin\System\Dtos\GetIssueGroupRequestDto;
use DDD\Presentation\Mcp\Admin\System\Dtos\GetIssuesTrendRequestDto;
use DDD\Presentation\Mcp\Admin\System\Dtos\ListIssueGroupsRequestDto;
use DDD\Presentation\Mcp\Admin\System\Dtos\SetIssueGroupStatusRequestDto;
use DDD\Presentation\Mcp\Admin\System\Dtos\SystemReportResponseDto;
use DDD\Presentation\Mcp\Admin\System\Lines\IssueEventLines;
use DDD\Presentation\Mcp\Admin\System\Lines\IssueGroupLine;
use DDD\Presentation\Mcp\Admin\System\Lines\SystemLineFormats;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentRequest;
use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRights;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPRequiresAccountRights;
use DDD\Domain\AI\Entities\MCPPackages\MCPRequiresAdminWithAccessToAllResources;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolReturnsTrustedContent;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolRequiresOwnerConfirmation;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolReturnsUntrustedContent;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolSummary;
use DDD\Domain\AI\Services\Conversations\Agent\OwnerConsentsService;
use DDD\Domain\Common\Entities\Charts\DateTimeValueSequence;
use DDD\Domain\Common\Entities\Charts\TimeInterval;
use DDD\Domain\Common\Entities\Issues\IssueGroup;
use DDD\Domain\Common\Entities\Issues\IssueGroupsFilter;
use DDD\Domain\Common\Entities\Issues\IssueGroupStatusCounts;
use DDD\Domain\Common\Services\IssueGroupsService;
use DDD\Domain\Common\Services\IssuesOverviewService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Services\IssuesLogService;
use Throwable;

/**
 * The issues registry for `System.Issues`: the error groups the platform logged (one group per distinct
 * error, per environment), one group with its entry points and newest events, the error events over time, and the
 * status of a group (OPEN, RESOLVED, IGNORED).
 *
 * Every read goes through the services behind the admin's Issues screen (IssuesOverviewService, IssueGroupsService).
 * The one write, setIssueGroupStatus, changes the status of one group in the registry and nothing else: no code, no
 * data, no alert is sent.
 *
 * A group is addressed by its id (the #number of a list row) or by its hash (what an alert shows); a hash can exist
 * in several environments. Messages and stack frames are text the system logged and can quote what a client sent:
 * first line, credentials and e-mail addresses masked, cut, marked untrusted. Never returned: the values of route
 * parameters, the client's identity, breadcrumbs, the raw event payload. Platform operators only: the right
 * {@see AgentAccountRights::OPERATE_PLATFORM} and an account with access to every resource.
 */
#[MCPRequiresAdminWithAccessToAllResources]
#[MCPRequiresAccountRights(AgentAccountRights::OPERATE_PLATFORM)]
class SystemIssuesMCPPackage extends MCPPackage
{
    public static ?string $name = 'ddd.system.issues';

    public static ?string $description = 'Read the error groups of the issues registry (list, one group with entry points and newest events, events over time) and set the status of a group (open, resolved, ignored).';

    /** @var string What a result calls an error group */
    public const string ENTITY_LABEL = 'issue group';

    /** @var string The tool that lists the groups with their ids */
    public const string FIND_TOOL = 'listIssueGroups';

    /** @var string The status filter that lists every status */
    public const string STATUS_ALL = 'ALL';

    /** @var string[] Words an operator or a model uses for a status, and the status they mean */
    public const array STATUS_ALIASES = [
        'OPEN' => IssueGroup::STATUS_OPEN,
        'REOPEN' => IssueGroup::STATUS_OPEN,
        'REOPENED' => IssueGroup::STATUS_OPEN,
        'RESOLVED' => IssueGroup::STATUS_RESOLVED,
        'RESOLVE' => IssueGroup::STATUS_RESOLVED,
        'FIXED' => IssueGroup::STATUS_RESOLVED,
        'DONE' => IssueGroup::STATUS_RESOLVED,
        'IGNORED' => IssueGroup::STATUS_IGNORED,
        'IGNORE' => IssueGroup::STATUS_IGNORED,
        'MUTED' => IssueGroup::STATUS_IGNORED,
    ];

    /** @var string[] Words for an environment, and the registry name they mean */
    public const array ENVIRONMENT_ALIASES = ['production' => 'prod', 'live' => 'prod', 'prd' => 'prod', 'development' => 'dev'];

    /** @var string[] Where an error can happen */
    public const array SECTIONS = [
        IssuesLogService::LOG_MESSAGE_SECTION_HTTP,
        IssuesLogService::LOG_MESSAGE_SECTION_CLI,
        IssuesLogService::LOG_MESSAGE_SECTION_MESSENGER,
        IssuesLogService::LOG_MESSAGE_SECTION_WEB,
        IssuesLogService::LOG_MESSAGE_SECTION_WEB_SERVER,
        IssuesLogService::LOG_MESSAGE_SECTION_MOBILE,
    ];

    /** @var string[] What follows from a new status; the operator has to know it */
    public const array STATUS_FOLLOW_UP_LINES = [
        IssueGroup::STATUS_RESOLVED => 'RESOLVED means fixed: the group leaves the open list; a new event of this error reopens it as a regression and alerts again.',
        IssueGroup::STATUS_IGNORED => 'IGNORED: the group never alerts again, also when new events arrive; its events are still counted.',
        IssueGroup::STATUS_OPEN => 'OPEN: the group counts as an open error again.',
    ];

    /** @var string The kind of step the owner agrees to before a status changes */
    public const string STATUS_CONSENT_ACTION_KIND = 'ISSUE_GROUP_STATUS';

    /**
     * @var string The step the owner is asked about: group, environment and new status. Short on purpose: with the
     *     exception name and the old status the agent's question named less than the line, and the decision model
     *     refused the operator's agreement in every run
     */
    public const string STATUS_CONSENT_TARGET = 'set issue group #%d in %s to %s';

    /** @var string[] What follows from a new status, as the owner reads it before agreeing; names group, environment and status */
    public const array STATUS_CONSENT_CONSEQUENCES = [
        IssueGroup::STATUS_RESOLVED => 'RESOLVED: a new event of #%d in %s reopens it and alerts again',
        IssueGroup::STATUS_IGNORED => 'IGNORED: #%d in %s never alerts again',
        IssueGroup::STATUS_OPEN => 'OPEN: #%d in %s alerts again on its next event',
    ];

    /** @var int The characters of the sample message a group's card shows */
    public const int CARD_MESSAGE_LENGTH = 400;

    /** @var int The entry points a card shows */
    public const int MAX_ENTRY_POINTS = 6;

    /** @var int The events a card shows (the newest) */
    public const int MAX_EVENTS = 3;

    /** @var int Hours getIssuesTrend looks back when none is named */
    public const int DEFAULT_TREND_HOURS = 24;

    /** @var int The buckets a trend lists before it sums them per day */
    public const int MAX_TREND_BUCKETS = 36;

    /** @var string What the untrusted passage of group rows is */
    public const string UNTRUSTED_GROUPS = 'error messages logged by the system';

    /** @var string What the untrusted passage of events is */
    public const string UNTRUSTED_EVENTS = 'error events logged by the system';

    /** @var string The answer of listIssueGroups without a match */
    public const string NO_GROUPS = 'No issue group matches. Other statuses: status "ALL"; other environments are named above.';

    /** @var string[] What a trend error of the log backend means */
    public const array TREND_ERROR_LINES = [
        'backend_unavailable' => 'The log backend could not be read: the event counts are not known, the zeros below are not a measurement.',
        'backend_not_configured' => 'No log backend watches this environment: no event counts exist for it, the zeros below are not a measurement.',
        'backend_partial' => 'A part of the environments or groups could not be read and is left out of the sum.',
    ];

    // ── reads ───────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * The error groups of the issues registry, last seen first: environment, status, where it happens (HTTP, CLI,
     * MESSENGER, WEB, MOBILE), exception and message, event count, last and first seen, entry point. Default: the
     * OPEN groups of the first environment; the first line counts every status and names the other environments.
     * Use it for "which errors are open", "is there a new error", "errors of the workers".
     *
     * @example {"environment": "prod"}
     * @example {"environment": "prod", "status": "RESOLVED", "searchText": "Deadlock"}
     * @example {"section": "MESSENGER"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolSummary('Error groups of the issues registry by environment, status, section and search text, last seen first.')]
    #[MCPToolReturnsUntrustedContent('error messages can quote what a client sent')]
    public function listIssueGroups(
        ListIssueGroupsRequestDto $requestDto,
        IssuesOverviewService $issuesOverviewService,
        IssueGroupsService $issueGroupsService
    ): AdminRecordListResponseDto {
        $issueGroupStatusCounts = $issueGroupsService->countGroupsByEnvironmentAndStatus();
        $environment = $this->resolveEnvironment($requestDto->environment, $issuesOverviewService, $issueGroupStatusCounts);
        $environments = $issuesOverviewService->resolveEnvironmentsToQuery($environment, $issueGroupStatusCounts);
        $requestedStatus = mb_strtoupper(trim((string)$requestDto->status));
        $status = match (true) {
            $requestedStatus === '' => IssueGroup::STATUS_OPEN,
            $requestedStatus === static::STATUS_ALL || $requestedStatus === 'ANY' => null,
            default => static::STATUS_ALIASES[$requestedStatus] ?? false,
        };
        if ($status === false) {
            throw new BadRequestException(
                'Unknown status ' . LlmTextRendering::displayValue($requestDto->status) . '. Use one of ' . implode(', ', IssueGroup::STATUSES) . ' or ' . static::STATUS_ALL . '.'
            );
        }
        $section = $requestDto->section !== null && trim($requestDto->section) !== '' ? mb_strtoupper(str_replace([' ', '-'], '_', trim($requestDto->section))) : null;
        if ($section === 'WORKER' || $section === 'WORKERS') {
            $section = IssuesLogService::LOG_MESSAGE_SECTION_MESSENGER;
        }
        if ($section === 'CRON' || $section === 'CRONS' || $section === 'COMMAND') {
            $section = IssuesLogService::LOG_MESSAGE_SECTION_CLI;
        }
        if ($section !== null && !in_array($section, static::SECTIONS, true)) {
            throw new BadRequestException('Unknown section ' . LlmTextRendering::displayValue($requestDto->section) . '. Use one of ' . implode(', ', static::SECTIONS) . '.');
        }
        $searchText = $requestDto->searchText !== null && trim($requestDto->searchText) !== '' ? trim($requestDto->searchText) : null;
        $release = $requestDto->release !== null && trim($requestDto->release) !== '' ? trim($requestDto->release) : null;

        $responseDto = new AdminRecordListResponseDto();
        $responseDto->subjectLine = $this->describeRegistry($environment, $environments, $issueGroupStatusCounts, $issuesOverviewService);
        $responseDto->skip = $requestDto->getSkip();
        $responseDto->orderDescription = 'last seen first, ' . ($status ?? 'every status')
            . ($section !== null ? ', section ' . $section : '') . ($searchText !== null ? ', matching ' . LlmTextRendering::displayValue($searchText) : '')
            . ($release !== null ? ', release ' . LlmTextRendering::displayValue($release) : '');
        $responseDto->emptyText = static::NO_GROUPS;
        $responseDto->untrustedSource = static::UNTRUSTED_GROUPS;
        $responseDto->narrowingHint = 'Narrow with status, section, searchText or release.';
        $responseDto->totalCount = $issueGroupsService->countGroups(new IssueGroupsFilter(
            status: $status,
            release: $release,
            section: $section,
            environments: array_values($environments),
            search: $searchText,
        ));
        if ($responseDto->totalCount === 0) {
            return $responseDto;
        }
        $issueGroups = $issuesOverviewService->findGroupsForList($environments, $status, $searchText, $section, $release, $requestDto->getPageSize(), $responseDto->skip);
        foreach ($issueGroups->getElements() as $issueGroup) {
            if (!$responseDto->addRecord($issueGroup->id, new IssueGroupLine($issueGroup, $issuesOverviewService->describeEntryPoint($issueGroup)))) {
                break;
            }
        }
        $responseDto->legendLines[] = 'One group in depth (entry points, newest events with stack frames): getIssueGroup with the #number.';
        return $responseDto;
    }

    /**
     * One error group: its line, the whole message and hash, where the code fails, the releases it was first and
     * last seen in, its entry points by events, and its newest events with the first stack frames. Address it by
     * issueGroupId (the #number of a list row) or by groupHash (what an alert shows; add environment when the hash
     * exists in several).
     *
     * @example {"issueGroupId": 10021}
     * @example {"groupHash": "4bf1c0d0a2e94f6b8c1d2e3f4a5b6c7d"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolSummary('One error group: message, origin, releases, entry points and newest events with stack frames.')]
    #[MCPToolReturnsUntrustedContent('error messages and stack frames can quote what a client sent')]
    public function getIssueGroup(
        GetIssueGroupRequestDto $requestDto,
        IssuesOverviewService $issuesOverviewService,
        IssueGroupsService $issueGroupsService
    ): SystemReportResponseDto {
        $issueGroup = $this->findIssueGroup($requestDto->issueGroupId, $requestDto->groupHash, $requestDto->environment, $issuesOverviewService, $issueGroupsService, false);
        $issueGroupDetail = $issuesOverviewService->findIssueGroupDetail($issueGroup);
        $responseDto = new SystemReportResponseDto();
        $responseDto->setField('issueGroupId', $issueGroup->id);
        $responseDto->setField('status', $issueGroup->status);
        $responseDto->setField('environment', $issueGroup->environment);
        $responseDto->setField('groupHash', $issueGroup->groupHash);
        $responseDto->setField('eventCount', $issueGroup->eventCount);
        $headParts = [
            'Issue group #' . $issueGroup->id,
            (string)($issueGroup->environment ?? 'no environment'),
            $issueGroup->status . ($issueGroup->status === IssueGroup::STATUS_RESOLVED && $issueGroup->resolvedAt !== null ? ' since ' . SystemLineFormats::formatTime($issueGroup->resolvedAt) : ''),
        ];
        if (($issueGroup->section ?? '') !== '') {
            $headParts[] = (string)$issueGroup->section;
        }
        $headParts[] = SystemLineFormats::countOf($issueGroup->eventCount, 'event');
        $headParts[] = 'first seen ' . SystemLineFormats::formatTime($issueGroup->firstSeenAt);
        $headParts[] = 'last seen ' . SystemLineFormats::formatTime($issueGroup->lastSeenAt);
        if ($issueGroup->isRegression) {
            $headParts[] = 'regression (came back after it was resolved)';
        }
        $headParts[] = 'hash ' . $issueGroup->groupHash;
        $responseDto->addLines(implode(' · ', $headParts));
        $responseDto->addUntrustedLines(static::UNTRUSTED_GROUPS, ['Error: ' . IssueGroupLine::describeError($issueGroup, static::CARD_MESSAGE_LENGTH)]);
        $originParts = [];
        if (($issueGroup->logOrigin ?? '') !== '') {
            $originParts[] = 'Fails in ' . LlmTextRendering::shorten(LlmSecretRedaction::redact(LlmTextRendering::toSingleLine((string)$issueGroup->logOrigin)), 200);
        }
        if (($issueGroup->releaseFirstSeen ?? '') !== '' || ($issueGroup->releaseLastSeen ?? '') !== '') {
            $originParts[] = 'release first seen ' . (substr((string)$issueGroup->releaseFirstSeen, 0, IssueEventLines::RELEASE_LENGTH) ?: 'unknown')
                . ', last seen ' . (substr((string)$issueGroup->releaseLastSeen, 0, IssueEventLines::RELEASE_LENGTH) ?: 'unknown');
        }
        if ($issueGroup->registeredAt !== null) {
            $originParts[] = 'in the registry since ' . SystemLineFormats::formatTime($issueGroup->registeredAt);
        }
        if ($originParts !== []) {
            $responseDto->addLines(implode(' · ', $originParts));
        }
        $entryPointLines = [];
        $issueSummaries = $issueGroupDetail->issues->getElements();
        foreach (array_slice($issueSummaries, 0, static::MAX_ENTRY_POINTS) as $issueSummary) {
            $entryPointLines[] = '- ' . LlmTextRendering::shorten(LlmSecretRedaction::redact(LlmTextRendering::toSingleLine((string)($issueSummary->entryPoint ?? 'unknown entry point'))), 160)
                . ' · ' . SystemLineFormats::countOf($issueSummary->eventCount, 'event') . ' · last seen ' . SystemLineFormats::formatTime($issueSummary->lastSeenAt);
        }
        $primaryEntryPoint = $issuesOverviewService->describeEntryPoint($issueGroup);
        $primaryEntryPoint = $primaryEntryPoint !== null ? LlmSecretRedaction::redact(LlmTextRendering::toSingleLine($primaryEntryPoint)) : null;
        if ($entryPointLines !== []) {
            $responseDto->addLines(
                'Entry points (' . count($issueSummaries) . (count($issueSummaries) > static::MAX_ENTRY_POINTS ? ', the first ' . static::MAX_ENTRY_POINTS . ' shown' : '') . ')'
                . ($primaryEntryPoint !== null ? ', mainly ' . $primaryEntryPoint : '') . ':',
                ...$entryPointLines
            );
        } elseif ($primaryEntryPoint !== null) {
            $responseDto->addLines('Entry point: ' . $primaryEntryPoint);
        }
        $issueEventDetails = $issueGroupDetail->events->getElements();
        if ($issueGroupDetail->eventsError !== null) {
            $responseDto->addLines('Events: not readable from the log backend (' . LlmSecretRedaction::redactFirstLine($issueGroupDetail->eventsError, 200) . '). The lines above are what the registry stores.');
        } elseif ($issueEventDetails === []) {
            $responseDto->addLines('Events: the log backend holds none any more (it keeps events for a limited time). The lines above are what the registry stores.');
        }
        if ($issueEventDetails !== []) {
            $eventLines = [];
            foreach (array_slice($issueEventDetails, 0, static::MAX_EVENTS) as $issueEventDetail) {
                array_push($eventLines, ...(new IssueEventLines($issueEventDetail))->toLlmLines());
            }
            $responseDto->addLines('Newest events (' . min(count($issueEventDetails), static::MAX_EVENTS) . ' of ' . count($issueEventDetails) . ' read, newest first):');
            $responseDto->addUntrustedLines(static::UNTRUSTED_EVENTS, $eventLines);
        }
        return $responseDto;
    }

    /**
     * The error events over time: of one environment (default: the first of the registry; "all": every watched one),
     * or of one error group (issueGroupId or groupHash). Total, the busiest bucket and the buckets with events. Use
     * it for "since when", "does it get worse", "how many errors today". Default window: 24 hours.
     *
     * @example {"environment": "prod", "sinceHours": 72}
     * @example {"issueGroupId": 10021, "sinceHours": 168}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolSummary('Error events over time for an environment or one error group: total, peak and buckets.')]
    #[MCPToolReturnsTrustedContent('event counts the platform computes')]
    public function getIssuesTrend(
        GetIssuesTrendRequestDto $requestDto,
        IssuesOverviewService $issuesOverviewService,
        IssueGroupsService $issueGroupsService
    ): SystemReportResponseDto {
        $maxHours = intdiv(IssuesOverviewService::TREND_MAX_WINDOW_SECONDS, 3600);
        $sinceHours = max(1, min($maxHours, $requestDto->sinceHours ?? static::DEFAULT_TREND_HOURS));
        $issueGroup = null;
        if ($requestDto->issueGroupId !== null || trim((string)$requestDto->groupHash) !== '') {
            $issueGroup = $this->findIssueGroup($requestDto->issueGroupId, $requestDto->groupHash, $requestDto->environment, $issuesOverviewService, $issueGroupsService, false);
            $environment = (string)($issueGroup->environment ?? '');
        } else {
            $environment = $this->resolveEnvironment($requestDto->environment, $issuesOverviewService, null);
        }
        $endDate = DateTime::fromTimestamp(time());
        $startDate = DateTime::fromTimestamp($endDate->getTimestamp() - $sinceHours * 3600);
        $issuesTrendSelection = $issuesOverviewService->findTrend($environment, $issueGroup?->groupHash, new TimeInterval($startDate, $endDate));
        $series = $issuesTrendSelection->series;
        $total = (int)round($series->getTotal());
        $responseDto = new SystemReportResponseDto();
        $responseDto->setField('environment', $environment);
        $responseDto->setField('sinceHours', $sinceHours);
        $responseDto->setField('totalEvents', $total);
        $responseDto->setField('trendError', $issuesTrendSelection->trendError);
        $scope = $issueGroup !== null
            ? 'issue group #' . $issueGroup->id . ' (' . IssueGroupLine::describeName($issueGroup) . ')'
            : ($environment === IssuesOverviewService::ENVIRONMENT_ALL ? 'every watched environment' : 'environment ' . ($environment !== '' ? $environment : 'none'));
        $responseDto->addLines(
            'Error events of ' . $scope . ', last ' . $sinceHours . ' h (' . SystemLineFormats::formatTime($startDate) . ' to ' . SystemLineFormats::formatTime($endDate) . '): '
            . SystemLineFormats::countOf($total, 'event') . '.'
        );
        if ($issuesTrendSelection->trendError !== null) {
            $responseDto->addLines(static::TREND_ERROR_LINES[$issuesTrendSelection->trendError] ?? 'The log backend answered with a problem: ' . LlmTextRendering::shorten($issuesTrendSelection->trendError, 80) . '.');
        }
        $responseDto->addLines(...$this->buildTrendLines($series, $total));
        return $responseDto;
    }

    // ── write ───────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * ONLY when the operator asked for one of these statuses: RESOLVED (fixed; a new event reopens it as a
     * regression), IGNORED (never alerts again) or OPEN (reopen). NOT FOR "delete" or "remove": a group cannot be
     * deleted, and no status stands in for it; answer that without calling this tool and offer RESOLVED or IGNORED.
     * ASKS THE OWNER FIRST: the first call is refused NOT DONE, changes nothing and names the step and its
     * consequence; ask the operator in those words and call again with the same arguments after the agreement. A status the group already has changes nothing
     * and asks nothing. Sets the status of ONE error group, addressed by issueGroupId or by groupHash (with
     * environment when the hash exists in several); it changes the status in the registry and nothing else. The
     * result names the old and the new status.
     *
     * @example {"issueGroupId": 10021, "status": "RESOLVED"}
     * @example {"groupHash": "4bf1c0d0a2e94f6b8c1d2e3f4a5b6c7d", "status": "IGNORED"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::WRITE)]
    #[MCPToolSummary('Set the status of one error group: RESOLVED, IGNORED or OPEN; asks the owner first.')]
    #[MCPToolRequiresOwnerConfirmation]
    public function setIssueGroupStatus(
        SetIssueGroupStatusRequestDto $requestDto,
        IssuesOverviewService $issuesOverviewService,
        IssueGroupsService $issueGroupsService,
        OwnerConsentsService $ownerConsentsService,
        AgentToolCallProvenance $agentToolCallProvenance
    ): AdminSavedFieldsResponseDto {
        if (trim((string)$requestDto->status) === '') {
            throw AdminToolNotSavedException::missingArgument('status', 'one of ' . implode(', ', IssueGroup::STATUSES));
        }
        $issueGroup = $this->findIssueGroup($requestDto->issueGroupId, $requestDto->groupHash, $requestDto->environment, $issuesOverviewService, $issueGroupsService, true);
        $status = static::STATUS_ALIASES[mb_strtoupper(trim((string)$requestDto->status))] ?? null;
        if ($status === null) {
            throw AdminToolNotSavedException::because(
                'status ' . LlmTextRendering::displayValue($requestDto->status) . ' is unknown; the stored status stays ' . $issueGroup->status,
                'Use one of ' . implode(', ', IssueGroup::STATUSES)
            );
        }
        $responseDto = new AdminSavedFieldsResponseDto();
        $responseDto->entityLabel = static::ENTITY_LABEL;
        $responseDto->entityId = $issueGroup->id;
        $responseDto->entityName = IssueGroupLine::describeName($issueGroup);
        $statusBefore = $issueGroup->status;
        if ($statusBefore === $status) {
            $responseDto->addUnchangedField('status of issue group #' . $issueGroup->id . ' (' . $responseDto->entityName . ')', $status);
            $responseDto->applied = false;
            return $responseDto;
        }
        // asked to delete a group, a model set it IGNORED or RESOLVED instead despite the docblock and the skill: the
        // owner's agreement is checked right before the write
        $ownerConsentReservation = $ownerConsentsService->requireOwnerConsentForStep($agentToolCallProvenance, $this->buildStatusConsentRequest($issueGroup, $status));
        try {
            $issueGroup = match ($status) {
                IssueGroup::STATUS_RESOLVED => $issuesOverviewService->resolveGroup($issueGroup),
                IssueGroup::STATUS_IGNORED => $issuesOverviewService->ignoreGroup($issueGroup),
                default => $issuesOverviewService->reopenGroup($issueGroup),
            };
        } catch (Throwable $throwable) {
            // nothing was written: the agreement stays usable for a retry of the same step, the error goes on
            $ownerConsentsService->releaseConsent($agentToolCallProvenance, $ownerConsentReservation);
            throw $throwable;
        }
        $ownerConsentsService->markConsentUsed($agentToolCallProvenance, $ownerConsentReservation);
        $responseDto->addSavedField('status', $statusBefore, $issueGroup->status);
        $responseDto->noteLines[] = static::STATUS_FOLLOW_UP_LINES[$status];
        return $responseDto;
    }

    /**
     * The step the owner agrees to before a status changes: the group with its environment, the old and the new
     * status, and what follows from the new one.
     *
     * @param IssueGroup $issueGroup The group as stored
     * @param string $status The new status (one of IssueGroup::STATUSES)
     * @return OwnerConsentRequest
     */
    protected function buildStatusConsentRequest(IssueGroup $issueGroup, string $status): OwnerConsentRequest
    {
        $environment = (string)($issueGroup->environment ?? 'no environment');
        return OwnerConsentRequest::forAction(
            'setIssueGroupStatus',
            static::STATUS_CONSENT_ACTION_KIND,
            sprintf(static::STATUS_CONSENT_TARGET, $issueGroup->id, $environment, $status),
            sprintf(static::STATUS_CONSENT_CONSEQUENCES[$status], $issueGroup->id, $environment),
            'issueGroup:' . $issueGroup->id . ':status:' . $status
        );
    }

    // ── shared ──────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * The environment a call means: none = the first of the registry, "all" = every one, a name in any case, a
     * common word for production as its registry name.
     *
     * @param string|null $requestedEnvironment
     * @param IssuesOverviewService $issuesOverviewService
     * @param IssueGroupStatusCounts|null $issueGroupStatusCounts
     * @return string
     * @throws BadRequestException The environment is not in the registry; the message names the ones that are
     */
    protected function resolveEnvironment(?string $requestedEnvironment, IssuesOverviewService $issuesOverviewService, ?IssueGroupStatusCounts $issueGroupStatusCounts): string
    {
        $environmentName = trim((string)$requestedEnvironment);
        if ($environmentName === '') {
            return $issuesOverviewService->resolveEnvironment(null, $issueGroupStatusCounts);
        }
        if (IssuesOverviewService::isAllEnvironments($environmentName)) {
            return IssuesOverviewService::ENVIRONMENT_ALL;
        }
        $knownEnvironments = $issuesOverviewService->getKnownEnvironments($issueGroupStatusCounts);
        $wantedNames = [mb_strtolower($environmentName)];
        if (isset(static::ENVIRONMENT_ALIASES[mb_strtolower($environmentName)])) {
            $wantedNames[] = static::ENVIRONMENT_ALIASES[mb_strtolower($environmentName)];
        }
        foreach ($wantedNames as $wantedName) {
            foreach ($knownEnvironments as $knownEnvironment) {
                if (mb_strtolower($knownEnvironment) === $wantedName) {
                    return $knownEnvironment;
                }
            }
        }
        throw new BadRequestException(
            'Unknown environment ' . LlmTextRendering::displayValue($requestedEnvironment) . '. The environments of the registry: ' . implode(', ', $knownEnvironments) . '; "all" for every one.'
        );
    }

    /**
     * @param string $environment The resolved environment
     * @param string[] $environments The registry environments it stands for
     * @param IssueGroupStatusCounts $issueGroupStatusCounts
     * @param IssuesOverviewService $issuesOverviewService
     * @return string The first line of a list: the status counts of the environment and the open counts of the others
     */
    protected function describeRegistry(string $environment, array $environments, IssueGroupStatusCounts $issueGroupStatusCounts, IssuesOverviewService $issuesOverviewService): string
    {
        if ($environments === []) {
            return 'The issues registry holds no environment yet.';
        }
        $statusCounts = [];
        foreach (IssueGroup::STATUSES as $status) {
            $count = 0;
            foreach ($environments as $environmentName) {
                $count += $issueGroupStatusCounts->getCount($environmentName, $status);
            }
            $statusCounts[] = $count . ' ' . $status;
        }
        $subjectLine = 'Issue groups of ' . ($environment === IssuesOverviewService::ENVIRONMENT_ALL ? 'every environment (' . implode(', ', $environments) . ')' : 'environment ' . $environment)
            . ': ' . implode(', ', $statusCounts);
        $otherEnvironments = [];
        foreach ($issuesOverviewService->getKnownEnvironments($issueGroupStatusCounts) as $knownEnvironment) {
            if (!in_array($knownEnvironment, $environments, true)) {
                $otherEnvironments[] = $knownEnvironment . ' (' . $issueGroupStatusCounts->getCount($knownEnvironment, IssueGroup::STATUS_OPEN) . ' open)';
            }
        }
        return $subjectLine . ($otherEnvironments !== [] ? ' · other environments: ' . implode(', ', $otherEnvironments) : '');
    }

    /**
     * Finds the group a call addresses, by id or by hash.
     *
     * @param int|null $issueGroupId
     * @param string|null $groupHash
     * @param string|null $requestedEnvironment Only with a hash
     * @param IssuesOverviewService $issuesOverviewService
     * @param IssueGroupsService $issueGroupsService
     * @param bool $forWrite Whether a miss is a write that was not saved
     * @return IssueGroup
     * @throws BadRequestException No id and no hash, or a hash that exists in several environments
     */
    protected function findIssueGroup(
        ?int $issueGroupId,
        ?string $groupHash,
        ?string $requestedEnvironment,
        IssuesOverviewService $issuesOverviewService,
        IssueGroupsService $issueGroupsService,
        bool $forWrite
    ): IssueGroup {
        $groupHash = mb_strtolower(trim((string)$groupHash));
        if ($issueGroupId === null && $groupHash === '') {
            if ($forWrite) {
                throw AdminToolNotSavedException::missingArgument('issueGroupId', 'the id of the issue group (the #number of a ' . static::FIND_TOOL . ' row) or its groupHash');
            }
            throw new BadRequestException('issueGroupId is missing. Pass the id of the issue group (the #number of a ' . static::FIND_TOOL . ' row) or its groupHash.');
        }
        if ($issueGroupId !== null) {
            $issueGroup = $issueGroupsService->find($issueGroupId);
            if (!$issueGroup instanceof IssueGroup) {
                throw $forWrite
                    ? AdminToolNotSavedException::unknownRecord(static::ENTITY_LABEL, $issueGroupId, static::FIND_TOOL)
                    : AdminToolRecordNotFoundException::forRecord(static::ENTITY_LABEL, $issueGroupId, static::FIND_TOOL);
            }
            return $issueGroup;
        }
        $issueGroupsOfHash = $issueGroupsService->findGroupsByGroupHash($groupHash)->getElements();
        $issueGroupsOfHashInEveryEnvironment = $issueGroupsOfHash;
        if (trim((string)$requestedEnvironment) !== '' && !IssuesOverviewService::isAllEnvironments($requestedEnvironment)) {
            $environment = $this->resolveEnvironment($requestedEnvironment, $issuesOverviewService, null);
            $issueGroupsOfEnvironment = [];
            foreach ($issueGroupsOfHash as $issueGroupOfHash) {
                if ($issueGroupOfHash->environment === $environment) {
                    $issueGroupsOfEnvironment[] = $issueGroupOfHash;
                }
            }
            $issueGroupsOfHash = $issueGroupsOfEnvironment;
        }
        if ($issueGroupsOfHash === [] && $issueGroupsOfHashInEveryEnvironment !== []) {
            $candidates = [];
            foreach ($issueGroupsOfHashInEveryEnvironment as $issueGroupOfHash) {
                $candidates[] = '#' . $issueGroupOfHash->id . ' in ' . ($issueGroupOfHash->environment ?? 'no environment') . ' (' . $issueGroupOfHash->status . ')';
            }
            $message = 'the hash ' . LlmTextRendering::displayValue($groupHash) . ' is not in the environment ' . LlmTextRendering::displayValue($requestedEnvironment)
                . ', it exists as ' . implode(', ', $candidates);
            throw $forWrite
                ? AdminToolNotSavedException::because($message, 'Pass the issueGroupId of the one that is meant, or leave out environment')
                : new BadRequestException(ucfirst($message) . '. Pass its issueGroupId, or leave out environment.');
        }
        if ($issueGroupsOfHash === []) {
            throw $forWrite
                ? AdminToolNotSavedException::because('no issue group has the hash ' . LlmTextRendering::displayValue($groupHash), 'Find it with ' . static::FIND_TOOL . ' (searchText, status "ALL")')
                : new BadRequestException('No issue group has the hash ' . LlmTextRendering::displayValue($groupHash) . '. Find it with ' . static::FIND_TOOL . ' (searchText, status "ALL").');
        }
        if (count($issueGroupsOfHash) > 1) {
            $candidates = [];
            foreach ($issueGroupsOfHash as $issueGroupOfHash) {
                $candidates[] = '#' . $issueGroupOfHash->id . ' in ' . ($issueGroupOfHash->environment ?? 'no environment') . ' (' . $issueGroupOfHash->status . ')';
            }
            $message = 'the hash ' . LlmTextRendering::displayValue($groupHash) . ' exists in ' . count($issueGroupsOfHash) . ' environments: ' . implode(', ', $candidates);
            throw $forWrite
                ? AdminToolNotSavedException::because($message, 'Pass the issueGroupId of the one that is meant, or environment')
                : new BadRequestException(ucfirst($message) . '. Pass the issueGroupId of the one that is meant, or environment.');
        }
        return $issueGroupsOfHash[0];
    }

    /**
     * @param DateTimeValueSequence $series The events per bucket
     * @param int $total
     * @return string[] The busiest bucket and the buckets with events; summed per day when there are many
     */
    protected function buildTrendLines(DateTimeValueSequence $series, int $total): array
    {
        if ($total === 0) {
            return ['No bucket of the window holds an event.'];
        }
        $countsByTimestamp = [];
        foreach ($series->getElements() as $dateTimeValue) {
            $count = (int)round((float)($dateTimeValue->y ?? 0));
            if ($count > 0) {
                $countsByTimestamp[$dateTimeValue->x->getTimestamp()] = $count;
            }
        }
        ksort($countsByTimestamp);
        $stepSeconds = $series->stepSeconds ?? 0;
        $stepText = $stepSeconds > 0 ? SystemLineFormats::formatDuration($stepSeconds * 1000) : 'unknown';
        $peakTimestamp = array_keys($countsByTimestamp, max($countsByTimestamp), true)[0];
        $lines = [
            'Busiest bucket: ' . gmdate('Y-m-d H:i', $peakTimestamp) . ' UTC with ' . SystemLineFormats::countOf($countsByTimestamp[$peakTimestamp], 'event')
            . ' · ' . count($countsByTimestamp) . ' of ' . count($series->getElements()) . ' buckets hold events (one bucket = ' . $stepText . ').',
        ];
        if (count($countsByTimestamp) <= static::MAX_TREND_BUCKETS) {
            $bucketTexts = [];
            foreach ($countsByTimestamp as $timestamp => $count) {
                $bucketTexts[] = gmdate('m-d H:i', $timestamp) . ' ' . $count;
            }
            $lines[] = 'Buckets with events (UTC, month-day hour:minute and count): ' . implode(' · ', $bucketTexts);
            return $lines;
        }
        $countsByDay = [];
        foreach ($countsByTimestamp as $timestamp => $count) {
            $day = gmdate('Y-m-d', $timestamp);
            $countsByDay[$day] = ($countsByDay[$day] ?? 0) + $count;
        }
        $dayTexts = [];
        foreach ($countsByDay as $day => $count) {
            $dayTexts[] = $day . ' ' . $count;
        }
        $lines[] = 'Events per day (UTC): ' . implode(' · ', $dayTexts);
        return $lines;
    }
}
