<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Evals\AgentEvalAnalyzeCase;
use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRows;
use DDD\Domain\AI\Services\Eval\AgentEvalResultFilesService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The offline failure analysis of a stored evaluation run in the results folder: for each failed case it reads the
 * case's message trace (from the case file beside a run folder's `_summary.json`, else from the row itself), classifies
 * WHY it failed ({@see AgentEvalAnalyzeCase}) and prints a block with the trace's tool calls, the final text and a
 * ready-to-paste reproduce command; then the count per class. No live call: file analysis only.
 *
 * The run: a run file, a run folder (its `_summary.json`) or a run name, as given or in the results folder; without
 * one, the most recently written run.
 *
 * Override point of an application: {@see buildReproduceArguments()} (the options its reproduce command needs).
 */
#[AsCommand(
    name: 'app:agent:eval-analyze',
    aliases: ['app:ai-agent:eval-analyze'],
    description: 'Classify the failures of a stored eval run (in the results folder) and print reproduce commands.'
)]
class AgentEvalAnalyzeCommand extends AgentEvalConsoleCommand
{
    /** @var string The command a reproduce line runs: the single-case run of the tool evaluation */
    public const string REPRODUCE_COMMAND_NAME = 'app:agent:eval-tools';

    /** @var int The characters of a tool call's arguments in a block */
    public const int PRINTED_ARGUMENTS_LENGTH = 60;

    /** @var int The characters of the final text in a block */
    public const int PRINTED_FINAL_TEXT_LENGTH = 100;

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'Run file or run directory in the results folder (or just the run name). Default: the most recent run.')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Also list passing cases (default: only failures).');
    }

    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        $runFile = $this->findRunFile($input->getArgument('path'), $output);
        if ($runFile === null) {
            return Command::FAILURE;
        }
        $runDocument = json_decode((string)file_get_contents($runFile), true);
        if (!is_array($runDocument) || !is_array($runDocument['cases'] ?? null)) {
            $output->writeln("<error>Not a valid run file (no 'cases'): $runFile</error>");
            return Command::FAILURE;
        }
        $modelName = is_string($runDocument['model'] ?? null) ? $runDocument['model'] : null;
        $agentEvalCaseRows = AgentEvalCaseRows::fromRowArrays($runDocument['cases']);

        $output->writeln('');
        $output->writeln(sprintf('<info>Fail analysis</info> — %s', $runFile));
        $output->writeln(sprintf(
            'model=%s · %d cases · %d pass · %d fail',
            $modelName ?? '(default)',
            $agentEvalCaseRows->count(),
            $agentEvalCaseRows->countPassed(),
            $agentEvalCaseRows->count() - $agentEvalCaseRows->countPassed()
        ));
        $output->writeln('');
        $caseCountsByClass = $this->analyseCases($runFile, $agentEvalCaseRows, $modelName, (bool)$input->getOption('all'), $input, $output);
        $this->printSummary($output, $caseCountsByClass);
        return Command::SUCCESS;
    }

    /**
     * Classifies and prints every failed case (counting the passed ones under {@see AgentEvalAnalyzeCase::CLASS_PASS}
     * with `--all`).
     *
     * @return int[] the analysed cases per class, by class
     */
    protected function analyseCases(string $runFile, AgentEvalCaseRows $agentEvalCaseRows, ?string $modelName, bool $countsPassedCases, InputInterface $input, OutputInterface $output): array
    {
        $agentEvalResultFilesService = $this->getAgentEvalResultFilesService();
        $caseCountsByClass = [];
        foreach ($agentEvalCaseRows->getElements() as $agentEvalCaseRow) {
            if ($agentEvalCaseRow->isPassed()) {
                if ($countsPassedCases) {
                    $caseCountsByClass[AgentEvalAnalyzeCase::CLASS_PASS] = ($caseCountsByClass[AgentEvalAnalyzeCase::CLASS_PASS] ?? 0) + 1;
                }
                continue;
            }
            // a run folder's summary holds the rows without their traces: the case file beside it holds the trace
            $fullCaseRow = $agentEvalResultFilesService->findFullCaseRowOfSummaryRow($runFile, $agentEvalCaseRow) ?? $agentEvalCaseRow;
            $agentEvalAnalyzeCase = AgentEvalAnalyzeCase::fromCaseRow($fullCaseRow);
            $failureClass = $agentEvalAnalyzeCase->classify();
            $caseCountsByClass[$failureClass] = ($caseCountsByClass[$failureClass] ?? 0) + 1;
            $this->printFailedCase($output, $agentEvalAnalyzeCase, $failureClass, $modelName, $input);
        }
        return $caseCountsByClass;
    }

    /** Prints the block of one failed case: class and case, want/got, error, failures, tool calls, final text, reproduce line. */
    protected function printFailedCase(OutputInterface $output, AgentEvalAnalyzeCase $agentEvalAnalyzeCase, string $failureClass, ?string $modelName, InputInterface $input): void
    {
        $agentEvalCaseRow = $agentEvalAnalyzeCase->caseRow;
        $caseId = (string)($agentEvalCaseRow->getValue('case') ?? '?');
        $calledTools = $agentEvalCaseRow->getValue('calledTools');
        $output->writeln(sprintf('<fg=%s>[%s]</> <options=bold>%s</>', $this->getColourOfClass($failureClass), $failureClass, $caseId));
        $output->writeln(sprintf(
            '  want: %s  |  got: [%s]',
            (string)($agentEvalCaseRow->getValue('tool') ?? '?'),
            is_array($calledTools) ? implode(', ', array_map('strval', $calledTools)) : ''
        ));
        if ($agentEvalCaseRow->hasError()) {
            $output->writeln(sprintf('  error: %s', (string)$agentEvalCaseRow->getError()));
        }
        foreach ($agentEvalAnalyzeCase->getFailures() as $failure) {
            $output->writeln(sprintf('  <fg=gray>✗ %s</>', $failure));
        }
        // the compact trace: each tool call with its status, then the start of the final text
        foreach ($agentEvalAnalyzeCase->toolCalls->getElements() as $agentEvalAnalyzeToolCall) {
            $output->writeln(sprintf(
                '  <fg=gray>→ %s(%s) [%s]</>',
                $agentEvalAnalyzeToolCall->toolName !== '' ? $agentEvalAnalyzeToolCall->toolName : '?',
                $this->shorten($agentEvalAnalyzeToolCall->toolArguments, static::PRINTED_ARGUMENTS_LENGTH),
                $agentEvalAnalyzeToolCall->status !== '' ? $agentEvalAnalyzeToolCall->status : '?'
            ));
        }
        $finalText = trim($agentEvalAnalyzeCase->getFinalText());
        $output->writeln(sprintf('  <fg=gray>finalText: %s</>', $finalText === '' ? '<empty>' : $this->shorten($finalText, static::PRINTED_FINAL_TEXT_LENGTH)));
        $output->writeln(sprintf(
            '  <fg=green>repro:</> php bin/console %s %s --full',
            static::REPRODUCE_COMMAND_NAME,
            $this->buildReproduceArguments($caseId, $modelName, $input)
        ));
        $output->writeln('');
    }

    /**
     * Override point: the arguments of the reproduce line between the command and `--full`: `--case=<id>` and, for a
     * run on a named model, `--model=<name>`. An application adds the options its run needs (e.g. its scope option).
     */
    protected function buildReproduceArguments(string $caseId, ?string $modelName, InputInterface $input): string
    {
        return '--case=' . $caseId . ($modelName !== null && $modelName !== '' ? ' --model=' . $modelName : '');
    }

    /**
     * Prints the analysed cases per class, the most frequent first, or that there was no failure.
     *
     * @param int[] $caseCountsByClass by class
     */
    protected function printSummary(OutputInterface $output, array $caseCountsByClass): void
    {
        $output->writeln('<info>── Summary ──</info>');
        arsort($caseCountsByClass);
        foreach ($caseCountsByClass as $failureClass => $caseCount) {
            $output->writeln(sprintf('  %-20s %d', $failureClass, $caseCount));
        }
        if ($caseCountsByClass === []) {
            $output->writeln('  (no failures 🎉)');
        }
    }

    /** The colour a class is printed in. */
    protected function getColourOfClass(string $failureClass): string
    {
        return match ($failureClass) {
            AgentEvalAnalyzeCase::CLASS_FIXTURE_TOO_STRICT => 'yellow',
            AgentEvalAnalyzeCase::CLASS_STALL, AgentEvalAnalyzeCase::CLASS_ERROR => 'red',
            AgentEvalAnalyzeCase::CLASS_TOOL_DATA_BUG => 'magenta',
            AgentEvalAnalyzeCase::CLASS_ANSWERED_WITHOUT_TOOL => 'blue',
            default => 'cyan',
        };
    }

    /** A text on one line (runs of white space as one space), cut to `$maxLength` characters with ` …`. */
    protected function shorten(string $text, int $maxLength): string
    {
        $text = (string)preg_replace('/\s+/', ' ', trim($text));
        return mb_strlen($text) > $maxLength ? mb_substr($text, 0, $maxLength) . ' …' : $text;
    }

    /**
     * The run file the argument names (a file, a run folder or a run name, as given or in the results folder), else the
     * most recently written run; null with its error line when there is none.
     */
    protected function findRunFile(mixed $pathArgument, OutputInterface $output): ?string
    {
        $agentEvalResultFilesService = $this->getAgentEvalResultFilesService();
        if (is_string($pathArgument) && $pathArgument !== '') {
            $runFile = $agentEvalResultFilesService->findRunFileByReference($pathArgument);
            if ($runFile === null) {
                $output->writeln("<error>Run not found: $pathArgument</error>");
            }
            return $runFile;
        }
        $resultsDirectory = $agentEvalResultFilesService->getResultsDirectory();
        if (!$agentEvalResultFilesService->hasResultsDirectory()) {
            $output->writeln("<error>No runs directory: $resultsDirectory</error>");
            return null;
        }
        $runFile = $agentEvalResultFilesService->findMostRecentRunFile();
        if ($runFile === null) {
            $output->writeln("<error>No runs found under $resultsDirectory</error>");
        }
        return $runFile;
    }

    /** The service of the result files (the application's class, when it replaces it). */
    protected function getAgentEvalResultFilesService(): AgentEvalResultFilesService
    {
        /** @var AgentEvalResultFilesService $agentEvalResultFilesService */
        $agentEvalResultFilesService = DDDService::instance()->getService(AgentEvalResultFilesService::class);
        return $agentEvalResultFilesService;
    }
}
