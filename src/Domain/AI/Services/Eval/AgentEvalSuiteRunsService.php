<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Eval;

use DateTimeZone;
use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRows;
use DDD\Domain\AI\Entities\Evals\AgentEvalStoredRunOptions;
use DDD\Domain\AI\Entities\Evals\SuiteRuns\AgentEvalSuiteCases\AgentEvalSuiteCases;
use DDD\Domain\AI\Entities\Evals\SuiteRuns\AgentEvalSuiteRun;
use DDD\Domain\AI\Entities\Evals\SuiteRuns\AgentEvalSuiteRuns;
use DDD\Domain\AI\Repo\DB\Evals\SuiteRuns\DBAgentEvalSuiteRun;
use DDD\Domain\AI\Repo\DB\Evals\SuiteRuns\DBAgentEvalSuiteRuns;
use DDD\Domain\Base\Services\EntitiesService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\IssuesLogService;
use Psr\Log\LogLevel;
use Throwable;

/**
 * The record of finished evaluation runs: one {@see AgentEvalSuiteRun} row and one case row per case, written at the
 * end of a run by the evaluation command that owns the run — never by its child processes, whose rows the parent
 * collects. Best effort: a failed write goes to the issues log (not the console, so the command prints exactly what it
 * prints without the record) and never fails the evaluation.
 *
 * @method AgentEvalSuiteRun|null find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method AgentEvalSuiteRuns|null findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method DBAgentEvalSuiteRun getEntityRepoClassInstance()
 * @method DBAgentEvalSuiteRuns getEntitySetRepoClassInstance()
 */
class AgentEvalSuiteRunsService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = AgentEvalSuiteRun::class;

    /**
     * Stores the record of one finished run: the run row (host = this machine, finished = now, both moments UTC) and
     * its case rows, built from the case rows the command aggregated ({@see AgentEvalSuiteRun::forFinishedRun()}).
     *
     * @param string $command the console command that ran
     * @param string|null $suiteLabel what the run covered
     * @param int $startedAtTimestamp when the run started (Unix time)
     * @param AgentEvalStoredRunOptions $runOptions the run's options as the command states them; `model` / `flavor` are the default of a row without its own
     * @param string|null $logDirectoryName the folder of the traces in the results folder
     * @param AgentEvalCaseRows $caseRows the case rows of the run
     * @return AgentEvalSuiteRun|null the stored run, or null when the write failed (logged, the evaluation goes on)
     */
    public function recordSuiteRun(
        string $command,
        ?string $suiteLabel,
        int $startedAtTimestamp,
        AgentEvalStoredRunOptions $runOptions,
        ?string $logDirectoryName,
        AgentEvalCaseRows $caseRows
    ): ?AgentEvalSuiteRun {
        try {
            $agentEvalSuiteRun = AgentEvalSuiteRun::forFinishedRun(
                $command,
                $suiteLabel,
                gethostname() ?: null,
                $this->buildUtcDateTimeFromTimestamp($startedAtTimestamp),
                $this->buildUtcDateTimeFromTimestamp(time()),
                $runOptions,
                $logDirectoryName,
                $caseRows
            );
            return $this->insertSuiteRunWithItsCases($agentEvalSuiteRun);
        } catch (Throwable $throwable) {
            $this->logFailedRecordWrite($throwable, $command, $suiteLabel, $caseRows->count());
            return null;
        }
    }

    /**
     * The failed write goes to the issues log (never the console); a failing log write is swallowed — the evaluation
     * must go on.
     *
     * @param Throwable $throwable
     * @param string $command
     * @param string|null $suiteLabel
     * @param int $caseCount
     * @return void
     */
    protected function logFailedRecordWrite(Throwable $throwable, string $command, ?string $suiteLabel, int $caseCount): void
    {
        try {
            /** @var IssuesLogService $issuesLogService */
            $issuesLogService = DDDService::instance()->getService(IssuesLogService::class);
            $issuesLogService->logThrowable($throwable, LogLevel::ERROR, [
                'agentEvalSuiteRunCommand' => $command,
                'agentEvalSuiteRunLabel' => $suiteLabel,
                'agentEvalSuiteRunCases' => $caseCount,
            ], false, IssuesLogService::LOG_MESSAGE_SECTION_CLI);
        } catch (Throwable) {
            // nothing left to report to: the record is lost, the evaluation's own files and output are untouched
        }
    }

    /**
     * Inserts the run, then its cases in one multi-row write with the run's id. A system write (the command's account
     * is no admin), so the rights are lifted for it.
     *
     * @param AgentEvalSuiteRun $agentEvalSuiteRun a run built by {@see AgentEvalSuiteRun::forFinishedRun()}
     * @return AgentEvalSuiteRun the stored run with its cases
     */
    protected function insertSuiteRunWithItsCases(AgentEvalSuiteRun $agentEvalSuiteRun): AgentEvalSuiteRun
    {
        $agentEvalSuiteCases = isset($agentEvalSuiteRun->agentEvalSuiteCases)
            ? $agentEvalSuiteRun->agentEvalSuiteCases
            : AgentEvalSuiteCases::newInstance();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $insertedAgentEvalSuiteRun = $agentEvalSuiteRun->insert(0) ?? $agentEvalSuiteRun;
            foreach ($agentEvalSuiteCases->getElements() as $agentEvalSuiteCase) {
                $agentEvalSuiteCase->agentEvalSuiteRunId = (int)$insertedAgentEvalSuiteRun->id;
            }
            if ($agentEvalSuiteCases->count() > 0) {
                $agentEvalSuiteCases->batchUpdate();
            }
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        $insertedAgentEvalSuiteRun->agentEvalSuiteCases = $agentEvalSuiteCases;
        return $insertedAgentEvalSuiteRun;
    }

    /**
     * @param int $timestamp Unix time
     * @return DateTime the moment in UTC
     */
    protected function buildUtcDateTimeFromTimestamp(int $timestamp): DateTime
    {
        $utcDateTime = DateTime::fromTimestamp($timestamp);
        $utcDateTime->setTimezone(new DateTimeZone('UTC'));
        return $utcDateTime;
    }
}
