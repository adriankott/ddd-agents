<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals\SuiteRuns;

use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRows;
use DDD\Domain\AI\Entities\Evals\AgentEvalStoredRunOptions;
use DDD\Domain\AI\Entities\Evals\SuiteRuns\AgentEvalSuiteCases\AgentEvalSuiteCase;
use DDD\Domain\AI\Entities\Evals\SuiteRuns\AgentEvalSuiteCases\AgentEvalSuiteCases;
use DDD\Domain\AI\Repo\DB\Evals\SuiteRuns\DBAgentEvalSuiteRun;
use DDD\Domain\AI\Services\Eval\AgentEvalResultFilesService;
use DDD\Domain\AI\Services\Eval\AgentEvalSuiteCasesService;
use DDD\Domain\AI\Services\Eval\AgentEvalSuiteRunsService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * One finished run of an evaluation command: which command ran with which label and options on which host, when it
 * started and ended (UTC), how many of its cases ended in which verdict, and the folder its full traces were written
 * to on that host. Written once at the end of the run by the evaluation command that owns the run
 * ({@see AgentEvalSuiteRunsService::recordSuiteRun()}), so an agent without a shell can read evaluation results; the
 * traces themselves stay files on the host that ran the run.
 *
 * Read rights: an admin with access to all resources reads every row, every other account none
 * ({@see DBAgentEvalSuiteRun::applyReadRightsQuery()}).
 *
 * @method static AgentEvalSuiteRunsService getService()
 * @method static DBAgentEvalSuiteRun getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentEvalSuiteRun::class)]
class AgentEvalSuiteRun extends Entity implements LlmMarkdownRenderable
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    /** @var int The longest stored command name; a longer one is cut. */
    public const int COMMAND_MAX_LENGTH = 64;

    /** @var int The longest stored label; a longer one is cut. */
    public const int SUITE_LABEL_MAX_LENGTH = 128;

    /** @var int The longest stored host name; a longer one is cut. */
    public const int HOST_MAX_LENGTH = 128;

    /** @var int The longest stored folder name; a longer one is cut. */
    public const int LOG_DIRECTORY_NAME_MAX_LENGTH = 191;

    /** @var string|null What the run covered: the suite run's `--label`, the tool suite's skill or case scope, the purpose of a purposed run. */
    #[Length(max: 128)]
    public ?string $suiteLabel = null;

    /** @var string|null The name of the console command that recorded the run. */
    #[NotNull]
    #[Length(max: 64)]
    public ?string $command = null;

    /** @var string|null The host name of the machine that ran the run (the traces are files there). */
    #[Length(max: 128)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $host = null;

    /** @var DateTime|null When the run started (UTC). */
    #[NotNull]
    public ?DateTime $startedAt = null;

    /** @var DateTime|null When the run ended and its results were written (UTC). */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?DateTime $finishedAt = null;

    /** @var int Cases recorded, every verdict. */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public int $casesTotal = 0;

    /** @var int Cases with the verdict PASS. */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public int $casesPassed = 0;

    /** @var int Cases with the verdict FAIL. */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public int $casesFailed = 0;

    /** @var int Cases with the verdict ERROR (the case did not finish: an exception, a timeout, no result). */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public int $casesErrored = 0;

    /** @var int Cases with the verdict NOT_APPLICABLE (the case's agent space cannot serve it; neither pass nor fail). */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public int $casesNotApplicable = 0;

    /** @var int Cases with the verdict EXPECTED_FAIL (a case marked as failing by design). */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public int $casesFailedAsExpected = 0;

    /** @var string|null The run's options as a small JSON object: models, flavors, suites, arms, and the like. */
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_TEXT)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $runOptionsJson = null;

    /** @var string|null The name of the folder (or file) in the results folder on the host that holds the full traces. */
    #[Length(max: 191)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $logDirectoryName = null;

    /** @var AgentEvalSuiteCases|null The run's cases. */
    #[LazyLoad(repoType: LazyLoadRepo::DB)]
    public ?AgentEvalSuiteCases $agentEvalSuiteCases;

    /**
     * The record of one finished run, built from the case rows the evaluation commands aggregate: one
     * {@see AgentEvalSuiteCase} per row and the count per verdict. Built as the application's class when it has one
     * at the same path. Pure: nothing is stored here.
     *
     * @param string $command the console command that ran
     * @param string|null $suiteLabel what the run covered
     * @param string|null $host the host name of the machine that ran the run
     * @param DateTime $startedAt when the run started (UTC)
     * @param DateTime $finishedAt when the run ended (UTC)
     * @param AgentEvalStoredRunOptions $runOptions the run's options; `model` / `flavor` are the default of a row without its own
     * @param string|null $logDirectoryName the folder of the traces in the results folder
     * @param AgentEvalCaseRows $caseRows the case rows of the run, in the order they finished
     * @return static
     */
    public static function forFinishedRun(
        string $command,
        ?string $suiteLabel,
        ?string $host,
        DateTime $startedAt,
        DateTime $finishedAt,
        AgentEvalStoredRunOptions $runOptions,
        ?string $logDirectoryName,
        AgentEvalCaseRows $caseRows
    ): static {
        $agentEvalSuiteRun = static::newInstance();
        $agentEvalSuiteRun->applyFinishedRun($command, $suiteLabel, $host, $startedAt, $finishedAt, $runOptions, $logDirectoryName, $caseRows);
        return $agentEvalSuiteRun;
    }

    /**
     * Fills this run from a finished run (see {@see forFinishedRun()}): the header cut to its column lengths, the
     * options as JSON (none stored when empty), one case per row and the count per verdict. A row without its own
     * `model` or `flavor` takes the run's (`runOptions.model` / `runOptions.flavor`).
     *
     * @param string $command
     * @param string|null $suiteLabel
     * @param string|null $host
     * @param DateTime $startedAt
     * @param DateTime $finishedAt
     * @param AgentEvalStoredRunOptions $runOptions
     * @param string|null $logDirectoryName
     * @param AgentEvalCaseRows $caseRows
     * @return void
     */
    public function applyFinishedRun(
        string $command,
        ?string $suiteLabel,
        ?string $host,
        DateTime $startedAt,
        DateTime $finishedAt,
        AgentEvalStoredRunOptions $runOptions,
        ?string $logDirectoryName,
        AgentEvalCaseRows $caseRows
    ): void {
        $this->command = mb_substr($command, 0, static::COMMAND_MAX_LENGTH);
        $this->suiteLabel = $suiteLabel !== null && $suiteLabel !== '' ? mb_substr($suiteLabel, 0, static::SUITE_LABEL_MAX_LENGTH) : null;
        $this->host = $host !== null && $host !== '' ? mb_substr($host, 0, static::HOST_MAX_LENGTH) : null;
        $this->startedAt = $startedAt;
        $this->finishedAt = $finishedAt;
        $this->runOptionsJson = !$runOptions->isEmpty() ? (string)json_encode($runOptions->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
        $this->logDirectoryName = $logDirectoryName !== null && $logDirectoryName !== ''
            ? mb_substr($logDirectoryName, 0, static::LOG_DIRECTORY_NAME_MAX_LENGTH)
            : null;
        $runModelName = $runOptions->getModelName();
        $runFlavor = $runOptions->getFlavor();
        $agentEvalSuiteCases = AgentEvalSuiteCases::newInstance();
        foreach ($caseRows->getElements() as $caseRow) {
            $agentEvalSuiteCase = AgentEvalSuiteCase::newInstance();
            $agentEvalSuiteCase->applyEvalCaseRow($caseRow, $runModelName, $runFlavor);
            $this->countCaseVerdict($agentEvalSuiteCase->verdict);
            $agentEvalSuiteCases->add($agentEvalSuiteCase);
        }
        $this->agentEvalSuiteCases = $agentEvalSuiteCases;
    }

    /**
     * Adds one case of the given verdict to the run's counts; a verdict the run does not know counts as FAIL.
     *
     * @param string|null $verdict
     * @return void
     */
    protected function countCaseVerdict(?string $verdict): void
    {
        $this->casesTotal++;
        match ($verdict) {
            AgentEvalSuiteCase::VERDICT_PASS => $this->casesPassed++,
            AgentEvalSuiteCase::VERDICT_ERROR => $this->casesErrored++,
            AgentEvalSuiteCase::VERDICT_NOT_APPLICABLE => $this->casesNotApplicable++,
            AgentEvalSuiteCase::VERDICT_EXPECTED_FAIL => $this->casesFailedAsExpected++,
            default => $this->casesFailed++,
        };
    }

    /**
     * One page of this run's stored cases, optionally only the given verdicts
     * ({@see AgentEvalSuiteCasesService::findCasesOfSuiteRun()}).
     *
     * @param string[]|null $verdicts
     * @param int $top
     * @param int $skip
     * @return AgentEvalSuiteCases
     */
    public function findCases(?array $verdicts, int $top, int $skip): AgentEvalSuiteCases
    {
        /** @var AgentEvalSuiteCasesService $agentEvalSuiteCasesService */
        $agentEvalSuiteCasesService = DDDService::instance()->getService(AgentEvalSuiteCasesService::class);
        return $agentEvalSuiteCasesService->findCasesOfSuiteRun($this, $verdicts, $top, $skip);
    }

    /**
     * How many of this run's stored cases carry one of the given verdicts (all when null).
     *
     * @param string[]|null $verdicts
     * @return int
     */
    public function countCases(?array $verdicts): int
    {
        /** @var AgentEvalSuiteCasesService $agentEvalSuiteCasesService */
        $agentEvalSuiteCasesService = DDDService::instance()->getService(AgentEvalSuiteCasesService::class);
        return $agentEvalSuiteCasesService->countCasesOfSuiteRun($this, $verdicts);
    }

    /**
     * The run's options decoded ({@see $runOptionsJson}); empty options when none were stored or the column holds no
     * JSON object.
     */
    public function getRunOptions(): AgentEvalStoredRunOptions
    {
        $runOptions = $this->runOptionsJson !== null ? json_decode($this->runOptionsJson, true) : null;
        return AgentEvalStoredRunOptions::fromStoredDocument(is_array($runOptions) ? $runOptions : []);
    }

    /** The cases that counted: every case but the not-applicable ones. */
    public function getCountedCases(): int
    {
        return $this->casesTotal - $this->casesNotApplicable;
    }

    /** One line: id, command, label, start, duration and the counts per verdict. */
    public function getSummaryLine(): string
    {
        $durationText = $this->startedAt !== null && $this->finishedAt !== null
            ? ' · ' . $this->renderDuration($this->finishedAt->getTimestamp() - $this->startedAt->getTimestamp())
            : '';
        $countParts = [sprintf('%d/%d passed', $this->casesPassed, $this->getCountedCases())];
        foreach ([
            'failed' => $this->casesFailed,
            'errors' => $this->casesErrored,
            'failed as expected' => $this->casesFailedAsExpected,
            'not applicable' => $this->casesNotApplicable,
        ] as $countName => $count) {
            if ($count > 0) {
                $countParts[] = "$count $countName";
            }
        }
        return sprintf(
            '#%s %s%s · started %s UTC%s · %s',
            (string)($this->id ?? '?'),
            (string)$this->command,
            $this->suiteLabel !== null ? ' "' . $this->suiteLabel . '"' : '',
            $this->startedAt?->format('Y-m-d H:i') ?? '?',
            $durationText,
            implode(', ', $countParts)
        );
    }

    /** The run's header: the summary line, host, trace folder and the options. */
    public function toLlmMarkdown(): string
    {
        $lines = ['Eval run ' . $this->getSummaryLine()];
        $lines[] = sprintf('Host: %s · %s', $this->host ?? 'unknown', $this->describeTraceFolder());
        if ($this->runOptionsJson !== null) {
            $lines[] = 'Options: ' . $this->runOptionsJson;
        }
        return implode("\n", $lines);
    }

    /**
     * Where the run's traces are, after "Host: <host> · ": the configured results folder
     * ({@see AgentEvalResultFilesService::getConfiguredResultsDirectory()}) and the run's folder in it. An application
     * that names the place otherwise writes its sentence in its same-path subclass.
     *
     * @return string
     */
    protected function describeTraceFolder(): string
    {
        /** @var AgentEvalResultFilesService $agentEvalResultFilesService */
        $agentEvalResultFilesService = DDDService::instance()->getService(AgentEvalResultFilesService::class);
        return sprintf('traces under %s/%s on that host', $agentEvalResultFilesService->getConfiguredResultsDirectory(), $this->logDirectoryName ?? '(not written)');
    }

    /**
     * A duration as "<h> h <mm> min" from an hour on, else "<m> min <ss> s"; a negative one counts as zero.
     *
     * @param int $seconds
     * @return string
     */
    protected function renderDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        return $seconds >= 3600
            ? sprintf('%d h %02d min', intdiv($seconds, 3600), intdiv($seconds % 3600, 60))
            : sprintf('%d min %02d s', intdiv($seconds, 60), $seconds % 60);
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->id ?? spl_object_id($this));
    }
}
