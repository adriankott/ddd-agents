<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Admin\System;

use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRights;
use DDD\Domain\AI\Entities\MCPPackages\LlmRendering\LlmTextRendering;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPRequiresAccountRights;
use DDD\Domain\AI\Entities\MCPPackages\MCPRequiresAdminWithAccessToAllResources;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolReturnsTrustedContent;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolSummary;
use DDD\Domain\AI\Services\SystemHealth\SystemHealthService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Presentation\Mcp\Admin\System\Dtos\GetSystemHealthRequestDto;
use DDD\Presentation\Mcp\Admin\System\Dtos\SystemReportResponseDto;
use DDD\Presentation\Mcp\Admin\System\Lines\SystemLineFormats;

/**
 * The health of the platform for `System.Health`: one report over the workers, the crons, the error groups and the
 * sections the application adds ({@see \DDD\Domain\AI\Services\SystemHealth\SystemHealthSectionProvider}).
 *
 * Reads only. Nothing here restarts a worker, runs a cron or changes a record. The report names transports, crons and
 * error groups, never a host, a process id, a command line with its arguments, an environment value or a connection
 * string. The details of a part are the tools of the sub-skills `System.Workers`, `System.Crons` and `System.Issues`.
 * Platform operators only: the right {@see AgentAccountRights::OPERATE_PLATFORM} and an account with access to every
 * resource.
 */
#[MCPRequiresAdminWithAccessToAllResources]
#[MCPRequiresAccountRights(AgentAccountRights::OPERATE_PLATFORM)]
class SystemHealthMCPPackage extends MCPPackage
{
    public static ?string $name = 'ddd.system.health';

    public static ?string $description = 'Read whether the platform is healthy: workers, crons, error groups and the sections the application adds.';

    /** @var string The first line of a health report */
    public const string HEALTH_HEADLINE = 'Platform health at %s: %s.';

    /** @var string The verdict of a report without a finding */
    public const string HEALTH_ALL_OK = 'no section reports a problem';

    /** @var string The verdict of a report with findings */
    public const string HEALTH_ATTENTION = '%d of %d sections need attention (%s)';

    /** @var string Where the details of the sections are read; shown when a section needs attention */
    public const string HEALTH_DETAILS_HINT = 'Details: workers in the sub-skill System.Workers, crons in System.Crons, error groups in System.Issues.';

    /**
     * Whether the platform is healthy, one line per part: WORKERS (queues, processes, failures), CRONS (failing,
     * overdue, is the runner alive), ISSUES (open and new error groups), and the sections the application adds. A part
     * reads "ok", "ATTENTION" with what is wrong, or "unknown" when it cannot be judged. Without sections: all of them.
     *
     * @example {}
     * @example {"sections": ["WORKERS"]}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolSummary('Platform health: workers, crons, error groups and the application\'s sections, each ok or ATTENTION with findings.')]
    #[MCPToolReturnsTrustedContent('states and counts the platform computes; names of transports, crons and error groups')]
    public function getSystemHealth(GetSystemHealthRequestDto $requestDto, SystemHealthService $systemHealthService): SystemReportResponseDto
    {
        $knownSectionNames = $systemHealthService->getSectionNames();
        $sectionNames = [];
        foreach ($requestDto->sections ?? [] as $requestedSection) {
            $sectionName = mb_strtoupper(trim((string)$requestedSection));
            if ($sectionName === '') {
                continue;
            }
            if (!in_array($sectionName, $knownSectionNames, true)) {
                throw new BadRequestException(
                    'Unknown section ' . LlmTextRendering::displayValue($requestedSection) . '. Use any of ' . implode(', ', $knownSectionNames) . ', or no section for all of them.'
                );
            }
            $sectionNames[] = $sectionName;
        }
        $systemHealthReport = $systemHealthService->getHealth($sectionNames);
        $sectionsThatNeedAttention = $systemHealthReport->getNamesOfSectionsThatNeedAttention();
        $responseDto = new SystemReportResponseDto();
        $responseDto->addLines(sprintf(
            static::HEALTH_HEADLINE,
            SystemLineFormats::formatTime(DateTime::fromTimestamp(time())),
            $sectionsThatNeedAttention === []
                ? static::HEALTH_ALL_OK
                : sprintf(static::HEALTH_ATTENTION, count($sectionsThatNeedAttention), $systemHealthReport->count(), implode(', ', $sectionsThatNeedAttention))
        ));
        foreach ($systemHealthReport->getElements() as $systemHealthSection) {
            $responseDto->addLines(...$systemHealthSection->toLlmLines());
            $responseDto->setField('state' . ucfirst(mb_strtolower($systemHealthSection->name)), $systemHealthSection->state);
        }
        if ($sectionsThatNeedAttention !== []) {
            $responseDto->addLines(static::HEALTH_DETAILS_HINT);
        }
        $responseDto->setField('sectionsThatNeedAttention', implode(',', $sectionsThatNeedAttention));
        return $responseDto;
    }
}
