<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Discovery\AgentSourceRoots;
use DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons\ToolCallComparisonCaseOutcome;
use DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons\ToolCallComparisonDefinitionsDump;
use DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons\ToolCallComparisonRecording;
use DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons\ToolCallComparisonScript;
use DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons\ToolCallComparisonSession;
use DDD\Domain\AI\Entities\MCPPackages\ToolDefinitions;
use DDD\Domain\AI\Services\Conversations\Discovery\AgentSourceRootsService;
use DDD\Domain\AI\Services\Eval\ToolCallComparisonsService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\DDDService;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * The tool-call comparison of one MCP package ({@see ToolCallComparisonSession}). `--record` runs the package's
 * comparison script on the unchanged build and writes the recording (plus the definitions dump beside it);
 * `--replay-and-compare` runs the same script on the build under test, answers the tools' Argus calls (and the calls of
 * the application's own recorders) from the recording, and exits 1 with a list of every difference. No model is
 * involved.
 *
 *   php bin/console app:mcp:compare-tool-calls --script=NotesMCPPackage --record=var/tool-call-comparisons/NotesMCPPackage.json
 *   php bin/console app:mcp:compare-tool-calls --script=NotesMCPPackage --replay-and-compare=var/tool-call-comparisons/NotesMCPPackage.json
 *
 * The package and bundle classes are found below `Presentation/Mcp` of the source roots {@see getMcpClassSourceRoots()}
 * names, the application's root first: an application class wins over a module class of the same short name
 * ({@see ToolCallComparisonsService::findPackageClassesByShortName()}). An application adapts where its scripts live
 * ({@see getScriptsDirectory()}), what a relative path resolves against ({@see getRelativePathBaseDirectory()}), the
 * account a run works as ({@see findComparisonAccount()}) and the lines it prints for its own recorders.
 *
 * Exit codes: 0 recorded / identical, 1 differences or a failed run, 2 invalid input.
 */
#[AsCommand(
    name: 'app:mcp:compare-tool-calls',
    description: 'Run an MCP package\'s comparison script in-process and record it, or replay it against a recording and list every difference (exit 1).',
)]
class MCPCompareToolCallsCommand extends AgentConsoleCommand
{
    /** Where the committed comparison scripts live, relative to the application's root directory. */
    public const string SCRIPTS_DIRECTORY = 'config/app/AI/ToolCallComparisons';

    /** The prefix of a line the preparation or the release of the run's agent space reported. */
    public const string PROGRESS_LINE_PREFIX = 'comparison: ';

    protected function configure(): void
    {
        $this
            ->addOption('script', null, InputOption::VALUE_REQUIRED, 'The comparison script: a path, or a package name (resolved to ' . static::SCRIPTS_DIRECTORY . '/<PackageName>.json)')
            ->addOption('record', null, InputOption::VALUE_REQUIRED, 'Run the script and write the recording to this path (a relative path resolves against the root directory of the application)')
            ->addOption('replay-and-compare', null, InputOption::VALUE_REQUIRED, 'Run the script against this recording and compare; exit 1 on a difference')
            ->setHelp($this->buildHelpText());
        $this->configureIgnoreBudgetOption();
    }

    /**
     * Checks the options, finds the script and its packages, validates the script, authenticates the run's account and
     * records or replays.
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '2048M');
        set_time_limit(0);
        $recordingPathToWrite = $input->getOption('record');
        $recordingPathToReplay = $input->getOption('replay-and-compare');
        if ((string)$input->getOption('script') === '' || (is_string($recordingPathToWrite) === is_string($recordingPathToReplay))) {
            $output->writeln('<error>Pass --script and exactly one of --record / --replay-and-compare.</error>');
            return Command::INVALID;
        }
        $toolCallComparisonsService = $this->getToolCallComparisonsService();
        $mcpClassSourceRoots = $this->getMcpClassSourceRoots();
        $packageClassesByShortName = $toolCallComparisonsService->findPackageClassesByShortName($mcpClassSourceRoots);
        $bundleClasses = $toolCallComparisonsService->findBundleClasses($mcpClassSourceRoots);
        try {
            $scriptPath = $this->resolveScriptPath((string)$input->getOption('script'));
            $toolCallComparisonScript = ToolCallComparisonScript::fromJson((string)file_get_contents($scriptPath), basename($scriptPath));
            $packageClass = $toolCallComparisonsService->resolvePackageClass($toolCallComparisonScript->packageName, $packageClassesByShortName);
            $additionalPackageClasses = [];
            foreach ($toolCallComparisonScript->additionalPackageNames as $additionalPackageName) {
                $additionalPackageClasses[] = $toolCallComparisonsService->resolvePackageClass($additionalPackageName, $packageClassesByShortName);
            }
        } catch (RuntimeException $scriptError) {
            $output->writeln('<error>' . $scriptError->getMessage() . '</error>');
            return Command::INVALID;
        }
        $account = $this->findComparisonAccount($toolCallComparisonScript);
        $validationErrors = $toolCallComparisonScript->findValidationErrors($this->findToolNamesOfPackages($packageClass, $additionalPackageClasses), (int)($account->id ?? 0));
        if ($validationErrors !== []) {
            foreach ($validationErrors as $validationError) {
                $output->writeln("<error>{$toolCallComparisonScript->scriptName}: {$validationError}</error>");
            }
            return Command::INVALID;
        }
        $this->actAsAccount($account);
        if (is_string($recordingPathToWrite)) {
            return $this->record($toolCallComparisonScript, $packageClass, $additionalPackageClasses, $bundleClasses, $this->resolvePath($recordingPathToWrite), $output);
        }
        return $this->replayAndCompare($toolCallComparisonScript, $packageClass, $additionalPackageClasses, $bundleClasses, $this->resolvePath((string)$recordingPathToReplay), $output);
    }

    /**
     * @param string $packageClass the package's class name
     * @param string[] $additionalPackageClasses
     * @param string[] $bundleClasses
     */
    protected function record(ToolCallComparisonScript $toolCallComparisonScript, string $packageClass, array $additionalPackageClasses, array $bundleClasses, string $recordingPath, OutputInterface $output): int
    {
        $toolCallComparisonSession = $this->getToolCallComparisonsService()->buildSession($toolCallComparisonScript, $packageClass, $additionalPackageClasses);
        $harnessFailures = [];
        try {
            $toolCallComparisonSession->start($bundleClasses);
            $harnessFailures = $toolCallComparisonSession->runFixtureSteps();
            foreach ($toolCallComparisonScript->cases->getElements() as $case) {
                $output->writeln($this->describeCaseOutcome($toolCallComparisonSession->runCase($case)));
            }
        } catch (Throwable $runError) {
            $output->writeln('<error>The recording run failed: ' . $runError::class . ': ' . $runError->getMessage() . '</error>');
            return Command::FAILURE;
        } finally {
            $toolCallComparisonSession->stop();
            $this->writeProgressLines($toolCallComparisonSession, $output);
        }
        foreach ($harnessFailures as $harnessFailure) {
            $output->writeln("<comment>fixture: {$harnessFailure}</comment>");
        }
        $definitionsDumpPath = $recordingPath . '.definitions.json';
        $toolCallComparisonSession->recording->definitionsDumpFileName = basename($definitionsDumpPath);
        $writeFailure = $this->writeFile($recordingPath, $toolCallComparisonSession->recording->buildStoredJson())
            ?? $this->writeFile($definitionsDumpPath, (string)$toolCallComparisonSession->definitionsDump?->buildStoredJson());
        if ($writeFailure !== null) {
            $output->writeln("<error>The recording was NOT written: {$writeFailure}</error>");
            return Command::FAILURE;
        }
        $output->writeln($this->describeRecording($toolCallComparisonSession, $recordingPath, $definitionsDumpPath));
        return Command::SUCCESS;
    }

    /**
     * @param string $packageClass the package's class name
     * @param string[] $additionalPackageClasses
     * @param string[] $bundleClasses
     */
    protected function replayAndCompare(ToolCallComparisonScript $toolCallComparisonScript, string $packageClass, array $additionalPackageClasses, array $bundleClasses, string $recordingPath, OutputInterface $output): int
    {
        try {
            $recordingJson = @file_get_contents($recordingPath);
            if ($recordingJson === false) {
                throw new RuntimeException("{$recordingPath} cannot be read");
            }
            $toolCallComparisonRecording = ToolCallComparisonRecording::fromJson($recordingJson, $recordingPath);
            $toolCallComparisonSession = $this->getToolCallComparisonsService()->buildSession($toolCallComparisonScript, $packageClass, $additionalPackageClasses, $toolCallComparisonRecording);
        } catch (RuntimeException $recordingError) {
            $output->writeln('<error>' . $recordingError->getMessage() . '</error>');
            return Command::INVALID;
        }
        $differenceCount = 0;
        $harnessFailures = [];
        try {
            $toolCallComparisonSession->start($bundleClasses);
            $harnessFailures = $toolCallComparisonSession->runFixtureSteps();
            foreach ($toolCallComparisonScript->cases->getElements() as $case) {
                $differenceCount += $this->replayCase($toolCallComparisonSession, $case->id, $toolCallComparisonSession->runCase($case), $output);
            }
        } catch (Throwable $runError) {
            $output->writeln('<error>The replay run failed: ' . $runError::class . ': ' . $runError->getMessage() . '</error>');
            return Command::FAILURE;
        } finally {
            $toolCallComparisonSession->stop();
            $this->writeProgressLines($toolCallComparisonSession, $output);
        }
        foreach ($harnessFailures as $harnessFailure) {
            $output->writeln("<comment>fixture: {$harnessFailure}</comment>");
        }
        foreach ($toolCallComparisonSession->runDifferences as $runDifference) {
            $differenceCount++;
            $output->writeln("<error>  {$runDifference}</error>");
        }
        $differenceCount += $this->compareDefinitionsDumps($toolCallComparisonSession, $recordingPath, $output);
        if ($differenceCount === 0) {
            $output->writeln(sprintf('<info>tool-call comparison IDENTICAL: %d cases of %s, definitions byte-identical</info>', $toolCallComparisonScript->cases->count(), $toolCallComparisonScript->scriptName));
            return Command::SUCCESS;
        }
        $output->writeln(sprintf('<error>tool-call comparison DIFFERS: %d difference(s) in %s</error>', $differenceCount, $toolCallComparisonScript->scriptName));
        return Command::FAILURE;
    }

    /**
     * Prints the comparison of one replayed case: `identical`, or the number of differences and one line each.
     *
     * @return int the number of differences
     */
    protected function replayCase(ToolCallComparisonSession $toolCallComparisonSession, string $caseId, ToolCallComparisonCaseOutcome $replayedOutcome, OutputInterface $output): int
    {
        $caseDifferences = $toolCallComparisonSession->buildCaseDifferences($replayedOutcome);
        $output->writeln($caseDifferences === [] ? "  {$caseId}: identical" : "<error>  {$caseId}: " . count($caseDifferences) . ' difference(s)</error>');
        foreach ($caseDifferences as $caseDifference) {
            $output->writeln("      {$caseDifference}");
        }
        return count($caseDifferences);
    }

    /**
     * Writes the replay's definitions dump beside the recording and compares it with the recorded one, byte for byte.
     *
     * @return int the number of differences
     */
    protected function compareDefinitionsDumps(ToolCallComparisonSession $toolCallComparisonSession, string $recordingPath, OutputInterface $output): int
    {
        $differenceCount = 0;
        $replayDefinitionsDumpJson = (string)$toolCallComparisonSession->definitionsDump?->buildStoredJson();
        $writeFailure = $this->writeFile($recordingPath . '.replay-definitions.json', $replayDefinitionsDumpJson);
        if ($writeFailure !== null) {
            $differenceCount++;
            $output->writeln("<error>  the replayed definitions dump was NOT written: {$writeFailure}</error>");
        }
        $recordedDumpFileName = $toolCallComparisonSession->recording->definitionsDumpFileName;
        $recordedDefinitionsDumpJson = @file_get_contents(dirname($recordingPath) . '/' . $recordedDumpFileName);
        $toolCallComparisonDefinitionsDump = $toolCallComparisonSession->definitionsDump ?? ToolCallComparisonDefinitionsDump::newInstance();
        $definitionsDifferences = $recordedDefinitionsDumpJson === false
            ? ['definitions: the recorded dump ' . $recordedDumpFileName . ' cannot be read']
            : $toolCallComparisonDefinitionsDump->buildDifferences($recordedDefinitionsDumpJson, $replayDefinitionsDumpJson);
        foreach ($definitionsDifferences as $definitionsDifference) {
            $differenceCount++;
            $output->writeln("<error>  {$definitionsDifference}</error>");
        }
        return $differenceCount;
    }

    /** One record line per case: what the call produced, in counts. */
    protected function describeCaseOutcome(ToolCallComparisonCaseOutcome $caseOutcome): string
    {
        return sprintf(
            '  %s (%s): %s%s, %d statements, %d dispatches, %d rows, %d Argus%s',
            $caseOutcome->caseId,
            $caseOutcome->toolName,
            $caseOutcome->executionError !== null ? 'EXECUTION ERROR' : ($caseOutcome->isError ? 'isError' : 'ok'),
            $caseOutcome->refusalKind !== null ? " ({$caseOutcome->refusalKind})" : '',
            $caseOutcome->countWriteStatements(),
            $caseOutcome->countDispatches(),
            $caseOutcome->countAppendedRows(),
            $caseOutcome->argusCallCount,
            $caseOutcome->statementAuditGap !== null ? " — AUDIT GAP: {$caseOutcome->statementAuditGap}" : ''
        );
    }

    /** The closing line of a record run. */
    protected function describeRecording(ToolCallComparisonSession $toolCallComparisonSession, string $recordingPath, string $definitionsDumpPath): string
    {
        return sprintf(
            '<info>recorded %d cases of %s (%d Argus calls) → %s (+ %s)</info>',
            $toolCallComparisonSession->recording->caseOutcomes->count(),
            $toolCallComparisonSession->script->scriptName,
            $toolCallComparisonSession->recording->countArgusCalls(),
            $recordingPath,
            basename($definitionsDumpPath)
        );
    }

    /**
     * The account the run works as: the owner of the agent space the script names, else the default account for
     * console work. Null: none known (the script's validation decides whether that refuses the run).
     */
    protected function findComparisonAccount(ToolCallComparisonScript $toolCallComparisonScript): ?Account
    {
        if ($toolCallComparisonScript->agentSpaceId === null) {
            return $this->findDefaultAccountForConsoleWork();
        }
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $agentSpacesService = $this->getAgentSpacesService();
            $agentSpace = $agentSpacesService->find($toolCallComparisonScript->agentSpaceId);
            return $agentSpace instanceof AgentSpace ? $this->findOwnerAccountOfAgentSpace($agentSpace) : null;
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    /**
     * The tools of the package under test and the additional packages.
     *
     * @param string[] $additionalPackageClasses
     * @return string[]
     */
    protected function findToolNamesOfPackages(string $packageClass, array $additionalPackageClasses): array
    {
        $availableToolNames = [];
        foreach (ToolDefinitions::forPackages(array_merge([$packageClass], $additionalPackageClasses))->getElements() as $toolDefinition) {
            $availableToolNames[] = $toolDefinition->name;
        }
        return $availableToolNames;
    }

    /**
     * The script the option names: the path as given, relative to {@see getRelativePathBaseDirectory()}, relative to the
     * application's root directory, or a package name in {@see getScriptsDirectory()}.
     *
     * @throws RuntimeException when the script cannot be found
     */
    protected function resolveScriptPath(string $scriptOption): string
    {
        $candidatePaths = [
            $scriptOption,
            $this->getRelativePathBaseDirectory() . '/' . $scriptOption,
            $this->getApplicationRootDirectory() . '/' . $scriptOption,
            $this->getScriptsDirectory() . '/' . $scriptOption . '.json',
        ];
        foreach ($candidatePaths as $candidatePath) {
            if (is_file($candidatePath)) {
                return $candidatePath;
            }
        }
        throw new RuntimeException("no comparison script {$scriptOption} (looked in " . $this->describeScriptSearchPlaces() . ')');
    }

    /** Where {@see resolveScriptPath()} looks, as the refusal names it. */
    protected function describeScriptSearchPlaces(): string
    {
        return 'the working directory, ' . $this->getRelativePathBaseDirectory() . ', ' . $this->getApplicationRootDirectory() . ' and ' . $this->getScriptsDirectory();
    }

    /** An absolute path stays; a relative one resolves against {@see getRelativePathBaseDirectory()}. */
    protected function resolvePath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : $this->getRelativePathBaseDirectory() . '/' . $path;
    }

    /** The directory of the committed comparison scripts. */
    protected function getScriptsDirectory(): string
    {
        return $this->getApplicationRootDirectory() . '/' . static::SCRIPTS_DIRECTORY;
    }

    /** The directory a relative script or recording path resolves against. The default: the application's root directory. */
    protected function getRelativePathBaseDirectory(): string
    {
        return $this->getApplicationRootDirectory();
    }

    /** The root directory of the application. */
    protected function getApplicationRootDirectory(): string
    {
        return DDDService::instance()->getRootDir();
    }

    /**
     * The source roots whose `Presentation/Mcp` folders hold the packages and bundles: the roots of the discovery
     * contract ({@see AgentSourceRootsService}), the application's first.
     */
    protected function getMcpClassSourceRoots(): AgentSourceRoots
    {
        /** @var AgentSourceRootsService $agentSourceRootsService */
        $agentSourceRootsService = DDDService::instance()->getService(AgentSourceRootsService::class);
        return $agentSourceRootsService->getSourceRoots();
    }

    /** Writes a file, creating its directory; returns why it failed, or null when every byte was written. */
    protected function writeFile(string $filePath, string $fileContent): ?string
    {
        $directory = dirname($filePath);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return "the directory {$directory} cannot be created";
        }
        $writtenByteCount = @file_put_contents($filePath, $fileContent);
        if ($writtenByteCount !== strlen($fileContent)) {
            return "{$filePath} cannot be written" . ($writtenByteCount === false ? '' : " ({$writtenByteCount} of " . strlen($fileContent) . ' bytes)');
        }
        return null;
    }

    /** What the preparation and the release of the run's agent space reported, one comment line each. */
    protected function writeProgressLines(ToolCallComparisonSession $toolCallComparisonSession, OutputInterface $output): void
    {
        foreach ($toolCallComparisonSession->progressLines as $progressLine) {
            $output->writeln('<comment>' . $this->getProgressLinePrefix() . $progressLine . '</comment>');
        }
    }

    /** The prefix of a progress line of the run's agent space. */
    protected function getProgressLinePrefix(): string
    {
        return static::PROGRESS_LINE_PREFIX;
    }

    /** The help of the command: the protocol, what is compared, the script format. */
    protected function buildHelpText(): string
    {
        return <<<'HELP'
Proves that a change of an MCP package changed nothing the model receives.

  # on the unchanged build:
  php bin/console app:mcp:compare-tool-calls --script=NotesMCPPackage --record=var/tool-call-comparisons/NotesMCPPackage.json
  # on the build under test, back to back:
  php bin/console app:mcp:compare-tool-calls --script=NotesMCPPackage --replay-and-compare=var/tool-call-comparisons/NotesMCPPackage.json

Each case runs in-process through MCPToolExecutorService::execute() — real gates, hydration and rendering — with the
provenance the script states, the clock and the minted ids pinned (moment + seed stored in the recording), no Argus or
application cache, and every Messenger dispatch kept instead of sent. Compared per case: the tool result as the loop
hands it to the model (text, structured loop-control fields, error frame, isError, refusal kind), the rows appended to
the harness conversations, every INSERT/UPDATE/DELETE the call issued, every dispatched message, the requests of the
application's own recorders and the Argus request bodies (the replay answers in-tool Argus calls from the recording;
never a tool result). The wire schemas, the HTTP documentation, the summaries and the skill tool lists are dumped to
<recording>.definitions.json and compared byte for byte. Normalised: database auto-increment ids, and the wall clock
the run could not pin — a moment under a time-named key or column written between the start of the run and the end of
the call (<clock>), the declared derived-moment keys/columns (<derived-clock>) and duration keys (<duration>), in
statements, JSON payloads and dispatched messages alike. Every call (case, fixture, before) starts in the process
state of a fresh worker job, so it reads the database; a global catalog row a case fills on first use is filled by the
script's fixture in both runs ("expectsError": true marks a warm-up call that is refused on purpose after its reads).
Unused recorded calls of harness calls are no difference.

SCRIPT FORMAT (config/app/AI/ToolCallComparisons/<PackageName>.json):
{
  "package": "NotesMCPPackage",                    short class name or class name of the package under test
  "additionalPackages": [],                        other packages offered beside it
  "agentSpaceId": 42,                              the agent space the run works in (optional: account level)
  "description": "…",
  "derivedMomentColumns": ["table.column"],        columns holding now+x (statement audit reads <derived-clock>)
  "derivedMomentKeys": ["scheduledAt"],            keys (message bodies, JSON values) holding now+x (<derived-clock>)
  "durationKeys": ["elapsedMs"],                   keys holding a wall-clock duration (<duration>)
  "wallClockTextPrefixes": ["(started "],          text right before an unswitched site writes a wall-clock H:i (<clock>)
  "opaqueTokenKeys": ["idempotencyKey"],           keys holding a token derived from a harness id (<key#n> per distinct value)
  "conversations": {                               harness conversations, created anew in each run (one id per run)
    "chat":  {"ownerRows": ["…"]},
    "plan":  {"purposeName": "…", "ownerRows": ["…"], "agentWorkingState": {"taskPlan": {…}}},
    "child": {"childOf": "plan"},
    "run":   {"scheduleId": -1}
  },
  "fixture": [{"tool": "…", "arguments": {…}, "conversation": "chat", "expectsError": false}],   harness calls before the first case
  "cases": [{"id": "unique.id", "tool": "…", "arguments": {…}, "conversation": "chat",
             "provenance": "agent" | "evalHarness" | "none",
             "before": [{"tool": "…", "arguments": {…}, "conversation": "chat"}], "note": "…"}]
}
"{{agentSpaceId}}" in an argument is the id of the run's agent space. The agent space and the harness conversations
are normalised like minted ids, so record and replay compare although their ids differ. Every call names a
conversation. Fixture and before calls run with eval-harness provenance unless they say otherwise and are not compared.
Exit codes: 0 recorded / identical, 1 differences or a failed run, 2 invalid input.
HELP;
    }

    protected function getToolCallComparisonsService(): ToolCallComparisonsService
    {
        /** @var ToolCallComparisonsService $toolCallComparisonsService */
        $toolCallComparisonsService = DDDService::instance()->getService(ToolCallComparisonsService::class);
        return $toolCallComparisonsService;
    }
}
