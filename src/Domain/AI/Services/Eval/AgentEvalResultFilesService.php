<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Evals\AgentEvalBaselineChoice;
use DDD\Domain\AI\Entities\Evals\AgentEvalCaseIdsByRunFolder;
use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRow;
use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRows;
use DDD\Domain\AI\Entities\Evals\AgentEvalResultsRetentionSweep;
use DDD\Domain\AI\Entities\Evals\AgentEvalRunSummary;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteProgress;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteRecord;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteTask;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteTasks;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use RuntimeException;

/**
 * The files of the evaluation runs, in one results folder (`AI.evalSuite.resultsDirectory`):
 *
 * - a run of the tools command: a loose file `<date>_<time>-<model>-<scope>.json` (a run in one process, the case
 *   rows with their message traces) or a folder of that name (a run in child processes) holding one file per case run
 *   (`<caseId>.json`, `<caseId>.rep<k>.json`) and `_summary.json` (the case rows without their message traces);
 * - a loose run file of another evaluation command: `<date>_<time>-<model>-<name>.json` ({@see writeLooseRunFile()});
 * - a suite run: a folder `<date>_<time>-suite[-<label>]` holding a folder per model and flavor
 *   (`<model>-<flavor>/`) with one file per case (`<suite>-<caseId>.json`), the child's result file while it runs
 *   (`.result-<suite>-<caseId>.json`) and `_summary.json`; beside them `_progress.json` while the run is on,
 *   `_suite.json` at its end and the operator's `STOP` file;
 * - the baseline read: the former suite runs, the cases each of them measured and the run summary to compare with;
 * - the retention sweep: a run entry whose name carries a date older than the retention is deleted. An entry whose
 *   name the sweep does not recognise ({@see getRetainedFolderNamePatterns()}, {@see getRetainedFileNamePatterns()})
 *   is never touched, nor is a symbolic link followed.
 *
 * Every JSON file is written pretty-printed, with unescaped slashes and unicode ({@see JSON_FLAGS}; the progress file
 * without unescaped unicode, {@see PROGRESS_JSON_FLAGS}), its keys in the order of the object's `toArray()`.
 *
 * An application replaces this service by a class of the same name at its mirrored path and adds the name patterns
 * of its own dated folders in the results folder through {@see getRetainedFolderNamePatterns()}.
 */
class AgentEvalResultFilesService extends Service
{
    /** @var string The configuration key of the results folder */
    public const string CONFIG_KEY_RESULTS_DIRECTORY = 'AI.evalSuite.resultsDirectory';

    /** @var string The results folder when the configuration names none, relative to the kernel project directory */
    public const string DEFAULT_RESULTS_DIRECTORY = 'var/agent-evals';

    /** @var int Days a dated run entry stays before the retention sweep deletes it */
    public const int DEFAULT_RETENTION_DAYS = 28;

    /**
     * @var string The name of a run of the tools command and of a suite run (`2026-07-13_205441-…`), as a folder or as
     * a loose file; the named groups year, month and day carry its date
     */
    public const string RUN_ENTRY_NAME_PATTERN = '/^(?<year>20\d{2})-(?<month>\d{2})-(?<day>\d{2})_\d{6}-/';

    /** @var int How every JSON file of a run is encoded */
    public const int JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /** @var int How the progress file of a suite run is encoded */
    public const int PROGRESS_JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;

    /** @var string The run summary in a run folder */
    public const string SUMMARY_FILE_NAME = '_summary.json';

    /** @var string The record of a suite run, written at its end */
    public const string SUITE_RECORD_FILE_NAME = '_suite.json';

    /** @var string The progress of a suite run, rewritten while it runs and removed at its end */
    public const string PROGRESS_FILE_NAME = '_progress.json';

    /** @var string The file an operator creates in a suite folder to stop the run gracefully */
    public const string STOP_FILE_NAME = 'STOP';

    /** @var string The prefix of the result file a child process of a suite run writes */
    public const string CHILD_RESULT_FILE_PREFIX = '.result-';

    /** @var string What the name of a suite folder carries after its date */
    public const string SUITE_RUN_NAME_MARKER = '-suite';

    /** @var string The folder name of a run without a model of its own */
    public const string DEFAULT_MODEL_FOLDER_NAME = 'default';

    // ------------------------------------------------------------------ the folder and the names

    /**
     * The results folder: the configured path, relative to the kernel project directory unless it is absolute, with
     * `.` and `..` segments resolved (no symbolic link is resolved).
     */
    public function getResultsDirectory(): string
    {
        $configuredDirectory = Config::get(static::CONFIG_KEY_RESULTS_DIRECTORY);
        $resultsDirectory = is_string($configuredDirectory) && trim($configuredDirectory) !== '' ? trim($configuredDirectory) : static::DEFAULT_RESULTS_DIRECTORY;
        if (!str_starts_with($resultsDirectory, '/')) {
            $resultsDirectory = DDDService::instance()->getRootDir() . '/' . $resultsDirectory;
        }
        return $this->normalizePath($resultsDirectory);
    }

    /**
     * The results folder as configured (`AI.evalSuite.resultsDirectory`, else {@see DEFAULT_RESULTS_DIRECTORY}),
     * not resolved against this host's project directory: the form that names the folder on any host.
     */
    public function getConfiguredResultsDirectory(): string
    {
        $configuredDirectory = Config::get(static::CONFIG_KEY_RESULTS_DIRECTORY);
        return is_string($configuredDirectory) && trim($configuredDirectory) !== '' ? rtrim(trim($configuredDirectory), '/') : static::DEFAULT_RESULTS_DIRECTORY;
    }

    /** Whether the results folder exists. */
    public function hasResultsDirectory(): bool
    {
        return is_dir($this->getResultsDirectory());
    }

    /** Days a dated run entry stays before {@see sweepRunEntriesOlderThanRetention()} deletes it. */
    public function getRetentionDays(): int
    {
        return static::DEFAULT_RETENTION_DAYS;
    }

    /**
     * The name patterns of the dated FOLDERS the retention sweep deletes once their date is older than the retention.
     * Each pattern carries the named groups `year`, `month` and `day`. An application adds the patterns of its own
     * dated folders in the results folder; a folder that matches no pattern is never deleted.
     *
     * @return string[]
     */
    public function getRetainedFolderNamePatterns(): array
    {
        return [static::RUN_ENTRY_NAME_PATTERN];
    }

    /**
     * The name patterns of the dated loose FILES the retention sweep deletes once their date is older than the
     * retention (a run of the tools command in one process). Same groups as {@see getRetainedFolderNamePatterns()}.
     *
     * @return string[]
     */
    public function getRetainedFileNamePatterns(): array
    {
        return [static::RUN_ENTRY_NAME_PATTERN];
    }

    /**
     * A string made safe for one segment of a file name: every run of other characters than letters, digits, `.`, `_`,
     * `-` becomes `_`; empty becomes `x`. The same rule names a task's run folder
     * ({@see AgentEvalSuiteTask::sanitizeForFolderName()}), so folder and file names agree.
     */
    public function getSafeFileNameSegment(string $value): string
    {
        $agentEvalSuiteTask = AgentEvalSuiteTask::newInstance();
        return $agentEvalSuiteTask->sanitizeForFolderName($value);
    }

    /**
     * The folder of one model and flavor in a suite run, `<model>-<flavor>` (no model: `default`): the name a task of
     * that model and flavor gives its run folder ({@see AgentEvalSuiteTask::getRunFolderName()}).
     */
    public function getRunFolderName(?string $modelName, string $flavor): string
    {
        $agentEvalSuiteTask = AgentEvalSuiteTask::newInstance();
        $agentEvalSuiteTask->modelName = $modelName;
        $agentEvalSuiteTask->flavor = $flavor;
        return $agentEvalSuiteTask->getRunFolderName();
    }

    /** How a document of a run is written to its file ({@see JSON_FLAGS}). */
    public function encodeJsonDocument(array $document): string
    {
        return (string)json_encode($document, static::JSON_FLAGS);
    }

    // ------------------------------------------------------------------ a run of the tools command

    /**
     * The scope part of the name of a run of the tools command: the skill it ran, else `case-<caseId>`, else `all`.
     * It also labels the stored record of the run.
     */
    public function getScopeTagOfToolsRun(?string $skillName, ?string $caseId): string
    {
        if ($skillName !== null && $skillName !== '') {
            return $this->getSafeFileNameSegment($skillName);
        }
        return $caseId !== null && $caseId !== '' ? 'case-' . $this->getSafeFileNameSegment($caseId) : 'all';
    }

    /** The name of a run of the tools command, `<date>_<time>-<model>-<scope>` (no model: `default`). */
    public function getToolsRunName(int $startedAtTimestamp, ?string $modelName, string $scopeTag): string
    {
        return date('Y-m-d_His', $startedAtTimestamp) . '-' . $this->getSafeFileNameSegment($modelName ?? static::DEFAULT_MODEL_FOLDER_NAME) . '-' . $scopeTag;
    }

    /** The folder of a run of the tools command in child processes. */
    public function getToolsRunDirectory(string $runName): string
    {
        return $this->getResultsDirectory() . '/' . $runName;
    }

    /** The loose file of a run of the tools command in one process. */
    public function getToolsRunFile(string $runName): string
    {
        return $this->getResultsDirectory() . '/' . $runName . '.json';
    }

    /** Creates the folder of a run of the tools command in child processes when it is missing; returns its path. */
    public function prepareToolsRunDirectory(string $runName): string
    {
        $runDirectory = $this->getToolsRunDirectory($runName);
        $this->prepareDirectory($runDirectory);
        return $runDirectory;
    }

    /** The file of one case run in the folder of a run of the tools command: `<caseId>.json`, a repetition `<caseId>.rep<k>.json`. */
    public function getCaseFileNameOfToolsRun(string $caseId, int $repetition): string
    {
        return $this->getSafeFileNameSegment($caseId) . ($repetition > 0 ? '.rep' . $repetition : '') . '.json';
    }

    /**
     * Writes the row of one case run, with its message trace, into the folder of a run of the tools command.
     *
     * @param int $repetition 0 for a run without repetitions
     */
    public function writeCaseFileOfToolsRun(string $runDirectory, string $caseId, int $repetition, AgentEvalCaseRow $caseRow): void
    {
        $this->writeFile($runDirectory . '/' . $this->getCaseFileNameOfToolsRun($caseId, $repetition), $this->encodeJsonDocument($caseRow->toArray()));
    }

    /**
     * Writes the result of a run of the tools command: in its run folder as `_summary.json`, the case rows without
     * their message traces (each case file holds its trace), or as the loose run file with the traces
     * ({@see AgentEvalRunSummary::toArray()}).
     *
     * @param bool $runWroteFolder whether the run wrote a run folder (child processes)
     * @return string the written file
     */
    public function writeToolsRunSummary(string $runName, bool $runWroteFolder, AgentEvalRunSummary $runSummary): string
    {
        if (!$runWroteFolder) {
            $runFile = $this->getToolsRunFile($runName);
            $this->writeFile($runFile, $this->encodeJsonDocument($runSummary->toArray(true)));
            return $runFile;
        }
        $summaryFile = $this->getToolsRunDirectory($runName) . '/' . static::SUMMARY_FILE_NAME;
        $this->writeFile($summaryFile, $this->encodeJsonDocument($runSummary->toArray()));
        return $summaryFile;
    }

    /**
     * Writes a result document a parent process reads that is no run summary, creating its folder when it is missing.
     *
     * @param string $resultFile
     * @param array $document the stored document of the result file (an object's `toArray()`, e.g. the result of a
     *                        recall run), encoded with {@see JSON_FLAGS}
     * @return void
     */
    public function writeResultDocument(string $resultFile, array $document): void
    {
        $this->writeFile($resultFile, $this->encodeJsonDocument($document));
    }

    /** Writes the whole result of a run (the message traces included) to the result file a parent process reads. */
    public function writeResultFile(string $resultFile, AgentEvalRunSummary $runSummary): void
    {
        $this->writeFile($resultFile, $this->encodeJsonDocument($runSummary->toArray(true)));
    }

    // ------------------------------------------------------------------ a loose run file of another command

    /**
     * The name of a loose run file of an evaluation command, `<date>_<time>-<model>-<name>.json` (no model:
     * `default`): the date-led name the retention sweep recognises.
     */
    public function getLooseRunFileName(int $writtenAtTimestamp, ?string $modelName, string $runFileName): string
    {
        return date('Y-m-d_His', $writtenAtTimestamp) . '-' . $this->getSafeFileNameSegment($modelName ?? static::DEFAULT_MODEL_FOLDER_NAME)
            . '-' . $this->getSafeFileNameSegment($runFileName) . '.json';
    }

    /**
     * Writes the stored document of one run of an evaluation command as a loose file in the results folder
     * ({@see getLooseRunFileName()}, the moment of writing as its date), creating the folder when it is missing.
     *
     * @param string|null $modelName the model of the run; null = `default`
     * @param string $runFileName what the name carries after the model (e.g. the name of the evaluation)
     * @param array $document the stored document of the run (an object's `toArray()`)
     * @param int $jsonFlags how the document is encoded ({@see JSON_FLAGS} unless the file has always been written
     *                       otherwise)
     * @return string the written file
     */
    public function writeLooseRunFile(?string $modelName, string $runFileName, array $document, int $jsonFlags = self::JSON_FLAGS): string
    {
        $runFile = $this->getResultsDirectory() . '/' . $this->getLooseRunFileName(time(), $modelName, $runFileName);
        $this->writeFile($runFile, (string)json_encode($document, $jsonFlags));
        return $runFile;
    }

    // ------------------------------------------------------------------ a suite run

    /**
     * Creates the folder of a suite run, `<date>_<time>-suite[-<label>]`; returns its path.
     *
     * @throws RuntimeException when the folder cannot be created
     */
    public function createSuiteRunDirectory(int $startedAtTimestamp, ?string $label): string
    {
        $suiteDirectory = $this->getResultsDirectory() . '/' . date('Y-m-d_His', $startedAtTimestamp) . static::SUITE_RUN_NAME_MARKER
            . ($label !== null ? '-' . $this->getSafeFileNameSegment($label) : '');
        if (!is_dir($suiteDirectory) && !@mkdir($suiteDirectory, 0775, true) && !is_dir($suiteDirectory)) {
            throw new RuntimeException("Could not create the run folder $suiteDirectory");
        }
        return $suiteDirectory;
    }

    /** The folder of the model and flavor of a task in a suite run. */
    public function getRunDirectoryOfSuiteTask(string $suiteDirectory, AgentEvalSuiteTask $agentEvalSuiteTask): string
    {
        return $suiteDirectory . '/' . $agentEvalSuiteTask->getRunFolderName();
    }

    /** The file of a task's case in a suite run, `<model>-<flavor>/<suite>-<caseId>.json`. */
    public function getCaseFileOfSuiteTask(string $suiteDirectory, AgentEvalSuiteTask $agentEvalSuiteTask): string
    {
        return $this->getRunDirectoryOfSuiteTask($suiteDirectory, $agentEvalSuiteTask) . '/'
            . $this->getSafeFileNameSegment($agentEvalSuiteTask->suite . '-' . $agentEvalSuiteTask->caseId) . '.json';
    }

    /**
     * Creates the run folder of a task's model and flavor when it is missing and returns the result file its child
     * process writes, `<model>-<flavor>/.result-<suite>-<caseId>.json`.
     */
    public function prepareChildResultFileOfSuiteTask(string $suiteDirectory, AgentEvalSuiteTask $agentEvalSuiteTask): string
    {
        $runDirectory = $this->getRunDirectoryOfSuiteTask($suiteDirectory, $agentEvalSuiteTask);
        $this->prepareDirectory($runDirectory);
        return $runDirectory . '/' . static::CHILD_RESULT_FILE_PREFIX . $this->getSafeFileNameSegment($agentEvalSuiteTask->suite . '-' . $agentEvalSuiteTask->caseId) . '.json';
    }

    /**
     * The first case row of a child's result file, and the file is removed; null when the child left no file or no
     * case row.
     */
    public function findCaseRowOfChildResultFile(string $resultFile): ?AgentEvalCaseRow
    {
        if (!is_file($resultFile)) {
            return null;
        }
        $resultDocument = json_decode((string)file_get_contents($resultFile), true);
        @unlink($resultFile);
        if (!is_array($resultDocument) || !is_array($resultDocument['cases'][0] ?? null)) {
            return null;
        }
        return AgentEvalCaseRow::fromArray($resultDocument['cases'][0]);
    }

    /** Writes a task's case row, with its message trace, into its run folder of the suite run. */
    public function writeCaseFileOfSuiteTask(string $suiteDirectory, AgentEvalSuiteTask $agentEvalSuiteTask, AgentEvalCaseRow $caseRow): void
    {
        $this->writeFile($this->getCaseFileOfSuiteTask($suiteDirectory, $agentEvalSuiteTask), $this->encodeJsonDocument($caseRow->toArray()));
    }

    /**
     * Stamps the infrastructure error a retried task's first attempt ended with (`retriedAfter`) onto its case file;
     * the rest of the file stays as written. A missing or unreadable file stays as it is.
     */
    public function stampRetryOnCaseFileOfSuiteTask(string $suiteDirectory, AgentEvalSuiteTask $agentEvalSuiteTask, string $retriedAfter): void
    {
        $caseFile = $this->getCaseFileOfSuiteTask($suiteDirectory, $agentEvalSuiteTask);
        $storedCaseRow = is_file($caseFile) ? json_decode((string)file_get_contents($caseFile), true) : null;
        if (!is_array($storedCaseRow)) {
            return;
        }
        $storedCaseRow['retriedAfter'] = $retriedAfter;
        $this->writeFile($caseFile, $this->encodeJsonDocument($storedCaseRow));
    }

    /** Writes the summary of one model and flavor of a suite run, `<model>-<flavor>/_summary.json`. */
    public function writeRunSummaryOfSuiteRun(string $suiteDirectory, ?string $modelName, string $flavor, AgentEvalRunSummary $runSummary): void
    {
        $this->writeFile($suiteDirectory . '/' . $this->getRunFolderName($modelName, $flavor) . '/' . static::SUMMARY_FILE_NAME, $this->encodeJsonDocument($runSummary->toArray()));
    }

    /** Rewrites the progress file of a running suite run ({@see PROGRESS_JSON_FLAGS}). */
    public function writeProgressOfSuiteRun(string $suiteDirectory, AgentEvalSuiteProgress $agentEvalSuiteProgress): void
    {
        $this->writeFile($suiteDirectory . '/' . static::PROGRESS_FILE_NAME, (string)json_encode($agentEvalSuiteProgress->toArray(), static::PROGRESS_JSON_FLAGS));
    }

    /** Writes the record of a finished suite run (`_suite.json`) and removes its progress file. */
    public function writeRecordOfSuiteRun(string $suiteDirectory, AgentEvalSuiteRecord $agentEvalSuiteRecord): void
    {
        $this->writeFile($suiteDirectory . '/' . static::SUITE_RECORD_FILE_NAME, $this->encodeJsonDocument($agentEvalSuiteRecord->toArray()));
        @unlink($suiteDirectory . '/' . static::PROGRESS_FILE_NAME);
    }

    /** Whether the operator asked for a graceful stop of the suite run by a `STOP` file in its folder. */
    public function isStopRequestedForSuiteRun(string $suiteDirectory): bool
    {
        return is_file($suiteDirectory . '/' . static::STOP_FILE_NAME);
    }

    // ------------------------------------------------------------------ the baseline

    /**
     * The baseline a suite run compares its performance with, and why (null without a reference): a named suite
     * folder is taken whatever its overlap ({@see AgentEvalBaselineChoice::takeNamedRun()}); for `latest` the NEWEST
     * former suite run that shares enough of the planned cases ({@see AgentEvalBaselineChoice::considerFormerRun()}).
     * The newest runs are read first and the reading stops at the first one that qualifies; the runs skipped on the
     * way are named in the choice.
     *
     * @param string|null $baselineReference a suite folder (path, or name in the results folder), `latest`, or null
     * @throws RuntimeException when a named folder is no suite run
     */
    public function findBaselineChoice(?string $baselineReference, string $currentSuiteDirectory, AgentEvalSuiteTasks $plannedTasks): ?AgentEvalBaselineChoice
    {
        if ($baselineReference === null) {
            return null;
        }
        $agentEvalBaselineChoice = AgentEvalBaselineChoice::forPlannedCases($plannedTasks->buildPlannedCaseIdsByRunFolder());
        if ($baselineReference !== 'latest') {
            $namedDirectory = $this->findNamedBaselineSuiteRunDirectory($baselineReference);
            $agentEvalBaselineChoice->takeNamedRun($namedDirectory, $this->findMeasuredCaseIdsByRunFolder($namedDirectory));
            return $agentEvalBaselineChoice;
        }
        foreach ($this->findFormerSuiteRunDirectoriesNewestFirst($currentSuiteDirectory) as $suiteRunDirectory) {
            if ($agentEvalBaselineChoice->considerFormerRun($suiteRunDirectory, $this->findMeasuredCaseIdsByRunFolder($suiteRunDirectory))) {
                break;
            }
        }
        return $agentEvalBaselineChoice;
    }

    /**
     * The folder of a suite run named as a baseline: the reference as a path when it is a folder, else a folder of
     * that name in the results folder; without a trailing slash.
     *
     * @throws RuntimeException when the folder holds no suite record
     */
    public function findNamedBaselineSuiteRunDirectory(string $baselineReference): string
    {
        $candidateDirectory = is_dir($baselineReference) ? $baselineReference : $this->getResultsDirectory() . '/' . $baselineReference;
        if (!is_file($candidateDirectory . '/' . static::SUITE_RECORD_FILE_NAME)) {
            throw new RuntimeException("The baseline $baselineReference is no suite run (no " . static::SUITE_RECORD_FILE_NAME . ')');
        }
        return rtrim($candidateDirectory, '/');
    }

    /**
     * The folders of the finished former suite runs (a suite record in them), the current one left out, newest first
     * (the names start with the date and time).
     *
     * @return string[]
     */
    public function findFormerSuiteRunDirectoriesNewestFirst(string $currentSuiteDirectory): array
    {
        $suiteRunDirectories = [];
        foreach (glob($this->getResultsDirectory() . '/*' . static::SUITE_RUN_NAME_MARKER . '*', GLOB_ONLYDIR) ?: [] as $suiteRunDirectory) {
            if ($suiteRunDirectory !== $currentSuiteDirectory && is_file($suiteRunDirectory . '/' . static::SUITE_RECORD_FILE_NAME)) {
                $suiteRunDirectories[] = $suiteRunDirectory;
            }
        }
        rsort($suiteRunDirectories);
        return $suiteRunDirectories;
    }

    /**
     * The case ids a suite run MEASURED per run folder: the rows of its `<model>-<flavor>/_summary.json` files that
     * carry a `performance` block and a case id (an error row has none and is never compared).
     */
    public function findMeasuredCaseIdsByRunFolder(string $suiteRunDirectory): AgentEvalCaseIdsByRunFolder
    {
        $measuredCaseIds = AgentEvalCaseIdsByRunFolder::newInstance();
        foreach (glob($suiteRunDirectory . '/*/' . static::SUMMARY_FILE_NAME) ?: [] as $summaryFile) {
            $summaryDocument = json_decode((string)file_get_contents($summaryFile), true);
            if (!is_array($summaryDocument) || !is_array($summaryDocument['cases'] ?? null)) {
                continue;
            }
            $agentEvalCaseRows = AgentEvalCaseRows::fromRowArrays($summaryDocument['cases']);
            $agentEvalCaseRows->addMeasuredCaseIdsTo($measuredCaseIds, basename(dirname($summaryFile)));
        }
        return $measuredCaseIds;
    }

    /** The summary of one model and flavor of the baseline suite run; null when it has none or it is unreadable. */
    public function findBaselineRunSummary(string $baselineDirectory, ?string $modelName, string $flavor): ?AgentEvalRunSummary
    {
        $baselineSummaryFile = $baselineDirectory . '/' . $this->getRunFolderName($modelName, $flavor) . '/' . static::SUMMARY_FILE_NAME;
        if (!is_file($baselineSummaryFile)) {
            return null;
        }
        $baselineSummaryDocument = json_decode((string)file_get_contents($baselineSummaryFile), true);
        return is_array($baselineSummaryDocument) ? AgentEvalRunSummary::fromArray($baselineSummaryDocument) : null;
    }

    // ------------------------------------------------------------------ reading a run back

    /**
     * The run file a reference names: the reference as a file, a run folder (its `_summary.json`) or a run name
     * (`<name>.json`), tried as given and then in the results folder. Null when none exists.
     */
    public function findRunFileByReference(string $runReference): ?string
    {
        foreach ([$runReference, $this->getResultsDirectory() . '/' . $runReference] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
            if (is_dir($candidate) && is_file($candidate . '/' . static::SUMMARY_FILE_NAME)) {
                return $candidate . '/' . static::SUMMARY_FILE_NAME;
            }
            if (is_file($candidate . '.json')) {
                return $candidate . '.json';
            }
        }
        return null;
    }

    /**
     * The most recently written run file of the results folder: per entry its file, or for a folder its
     * `_summary.json`, the newest by modification time. Null when the folder is missing or holds none.
     */
    public function findMostRecentRunFile(): ?string
    {
        $resultsDirectory = $this->getResultsDirectory();
        if (!is_dir($resultsDirectory)) {
            return null;
        }
        $modificationTimeByRunFile = [];
        foreach ((array)scandir($resultsDirectory) as $entryName) {
            if (!is_string($entryName) || $entryName === '.' || $entryName === '..') {
                continue;
            }
            $entryPath = $resultsDirectory . '/' . $entryName;
            $runFile = is_dir($entryPath) ? $entryPath . '/' . static::SUMMARY_FILE_NAME : $entryPath;
            if (is_file($runFile)) {
                $modificationTimeByRunFile[$runFile] = filemtime($runFile) ?: 0;
            }
        }
        if ($modificationTimeByRunFile === []) {
            return null;
        }
        arsort($modificationTimeByRunFile);
        return (string)array_key_first($modificationTimeByRunFile);
    }

    /**
     * The full case row (with its message trace) of a row of a run summary: a run folder's `_summary.json` holds the
     * rows without their traces, the case file beside it holds the trace. A row of a suite run (it names its suite)
     * is in `<suite>-<caseId>.json`, a row of a run of the tools command in `<caseId>.json` or `<caseId>.rep<k>.json`.
     * Null for a loose run file (its rows carry their traces) and when the case file is missing or unreadable.
     */
    public function findFullCaseRowOfSummaryRow(string $summaryFile, AgentEvalCaseRow $summaryCaseRow): ?AgentEvalCaseRow
    {
        if (basename($summaryFile) !== static::SUMMARY_FILE_NAME) {
            return null;
        }
        $summaryRowValues = $summaryCaseRow->toArray();
        $caseId = (string)($summaryRowValues['case'] ?? '');
        if ($caseId === '') {
            return null;
        }
        $suiteName = (string)($summaryRowValues['suite'] ?? '');
        $caseFileName = $suiteName !== ''
            ? $this->getSafeFileNameSegment($suiteName . '-' . $caseId) . '.json'
            : $this->getCaseFileNameOfToolsRun($caseId, (int)($summaryRowValues['repetition'] ?? 0));
        $caseFile = dirname($summaryFile) . '/' . $caseFileName;
        $caseRowValues = is_file($caseFile) ? json_decode((string)file_get_contents($caseFile), true) : null;
        return is_array($caseRowValues) ? AgentEvalCaseRow::fromArray($caseRowValues) : null;
    }

    // ------------------------------------------------------------------ the retention sweep

    /**
     * Deletes the dated run entries of the results folder whose date is older than {@see getRetentionDays()}: a
     * folder whose name matches {@see getRetainedFolderNamePatterns()}, a loose file whose name matches
     * {@see getRetainedFileNamePatterns()}. The date is the one IN THE NAME (a modification time changes when a file
     * is touched). An entry no pattern recognises is never touched, a symbolic link is neither followed nor removed,
     * and a link inside a deleted run folder is removed as a link, its target stays.
     *
     * @param int|null $nowTimestamp the moment the retention counts back from; null = now
     */
    public function sweepRunEntriesOlderThanRetention(?int $nowTimestamp = null): AgentEvalResultsRetentionSweep
    {
        $agentEvalResultsRetentionSweep = AgentEvalResultsRetentionSweep::withRetentionDays($this->getRetentionDays());
        $resultsDirectory = $this->getResultsDirectory();
        if (!is_dir($resultsDirectory)) {
            return $agentEvalResultsRetentionSweep;
        }
        $cutoffTimestamp = ($nowTimestamp ?? time()) - $this->getRetentionDays() * 86400;
        foreach ((array)scandir($resultsDirectory) as $entryName) {
            if (!is_string($entryName) || $entryName === '.' || $entryName === '..') {
                continue;
            }
            $entryPath = $resultsDirectory . '/' . $entryName;
            $entryTimestamp = $this->findDateOfRetainedEntry($entryPath, $entryName);
            if ($entryTimestamp === null || $entryTimestamp >= $cutoffTimestamp) {
                continue;
            }
            $agentEvalResultsRetentionSweep->addDeletedEntry($this->deleteRunEntry($entryPath));
        }
        return $agentEvalResultsRetentionSweep;
    }

    /**
     * The date (midnight, local time) in the name of an entry of the results folder that the sweep recognises; null
     * for a symbolic link and for an entry no pattern of its kind matches.
     */
    protected function findDateOfRetainedEntry(string $entryPath, string $entryName): ?int
    {
        if (is_link($entryPath)) {
            return null;
        }
        if (is_dir($entryPath)) {
            $namePatterns = $this->getRetainedFolderNamePatterns();
        } elseif (is_file($entryPath)) {
            $namePatterns = $this->getRetainedFileNamePatterns();
        } else {
            return null;
        }
        foreach ($namePatterns as $namePattern) {
            if (preg_match($namePattern, $entryName, $nameMatch) === 1 && isset($nameMatch['year'], $nameMatch['month'], $nameMatch['day'])) {
                $entryTimestamp = mktime(0, 0, 0, (int)$nameMatch['month'], (int)$nameMatch['day'], (int)$nameMatch['year']);
                return $entryTimestamp === false ? null : $entryTimestamp;
            }
        }
        return null;
    }

    /**
     * Deletes one run entry and returns the bytes of the files it held: a file, a folder with everything below it, a
     * symbolic link as a link (its target is never entered).
     */
    protected function deleteRunEntry(string $entryPath): int
    {
        if (is_link($entryPath)) {
            @unlink($entryPath);
            return 0;
        }
        if (!is_dir($entryPath)) {
            $freedBytes = (int)@filesize($entryPath);
            @unlink($entryPath);
            return $freedBytes;
        }
        $freedBytes = 0;
        foreach ((array)scandir($entryPath) as $childName) {
            if (!is_string($childName) || $childName === '.' || $childName === '..') {
                continue;
            }
            $freedBytes += $this->deleteRunEntry($entryPath . '/' . $childName);
        }
        @rmdir($entryPath);
        return $freedBytes;
    }

    // ------------------------------------------------------------------ helpers

    /** Writes a file, creating its folder when it is missing. */
    protected function writeFile(string $filePath, string $content): void
    {
        $this->prepareDirectory(dirname($filePath));
        file_put_contents($filePath, $content);
    }

    /** Creates a folder with its parents when it is missing. */
    protected function prepareDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
    }

    /** An absolute path with its `.` and `..` segments resolved and no trailing slash; symbolic links stay. */
    protected function normalizePath(string $path): string
    {
        $normalizedSegments = [];
        foreach (explode('/', $path) as $pathSegment) {
            if ($pathSegment === '' || $pathSegment === '.') {
                continue;
            }
            if ($pathSegment === '..') {
                array_pop($normalizedSegments);
                continue;
            }
            $normalizedSegments[] = $pathSegment;
        }
        return '/' . implode('/', $normalizedSegments);
    }
}
