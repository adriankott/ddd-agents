<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Evals\AgentEvalCompactionTreeRunResult;
use DDD\Domain\AI\Entities\Evals\AgentEvalJudgedRunOptions;
use DDD\Domain\AI\Entities\Evals\AgentEvalScope;
use DDD\Domain\AI\Entities\Evals\AgentEvalSimCase;
use DDD\Domain\AI\Services\Conversations\AIConversationCompactionService;
use DDD\Domain\AI\Services\Eval\AgentEvalJudgedRunService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Symfony\Commands\Agents\Eval\AgentEvalCompactionTreeCaseFile;
use DDD\Symfony\Commands\Agents\Eval\AgentEvalCompactionTreeParameters;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Live judged evaluation of the hierarchical compaction tree. A driver model keeps a long, content-rich conversation
 * going with frequent compaction (few messages per leaf, a small keep window and fan-in), the tree is written down after
 * every owner turn, and a judge model rates the tree structure (incremental growth, roll-ups, a recency-graded cover
 * without gaps or overlaps, facts that survive the roll-ups) from the per-turn snapshots, the final tree and the
 * parameters. Driver and judge read the parameters. Live: real model calls and tools; it costs tokens. The run itself
 * is {@see AgentEvalJudgedRunService::runCompactionTreeEval()}.
 *
 * The case — the driver's goal, the opening owner message, the owner's facts, the rubric and the template of the
 * parameters text — comes from `--case-file` ({@see AgentEvalCompactionTreeCaseFile}); an application with a case of
 * its own returns its file from {@see getDefaultCaseFile()}, and the option is then optional. The space is the case
 * file's `agentSpaceId`; the scope options name the space of a case file without one (as in every evaluation
 * command, the case's own space wins).
 *
 *   php bin/console app:ai-agent:eval-compaction-tree --case-file=<case.json> --agentSpaceId=<id> --ignore-budget
 *   php bin/console app:ai-agent:eval-compaction-tree --case-file=<case.json> --max-turns=16 --fan-in=2 --json
 *
 * Exit code: success only when the verdict is PASS and — with `--persist` — the stored build validates.
 */
#[AsCommand(
    name: 'app:ai-agent:eval-compaction-tree',
    description: 'LIVE: drive a long sim with frequent compaction + judge the compaction TREE structure (per turn + final).'
)]
class AgentEvalCompactionTreeCommand extends AgentEvalConsoleCommand
{
    /** @var string The option that names the case file */
    public const string OPTION_CASE_FILE = 'case-file';

    /** @var string The parameters text a case file without its own template gets */
    public const string DEFAULT_COMPACTION_PARAMS_TEMPLATE = 'segmentMessages={{segmentMessages}} (one level-0 leaf per {{segmentMessages}} messages), '
        . 'keepRecentMessages={{keepRecentMessages}} (raw tail), tierFanIn={{tierFanIn}} (K — {{tierFanIn}} nodes roll up into a '
        . 'higher one), levelRetention={{levelRetention}}, triggerTokens={{triggerTokens}} (fires nearly every turn), '
        . 'maxTurns={{maxTurns}}. The expected tree depth grows with log_{{tierFanIn}}(messages/{{segmentMessages}}).';

    protected function configure(): void
    {
        $this->configureEvalScopeOptions();
        $this
            ->addOption(self::OPTION_CASE_FILE, null, InputOption::VALUE_REQUIRED, 'Path of the JSON case file; required unless the command has a default case')
            ->addOption('agent-model', null, InputOption::VALUE_OPTIONAL, 'Agent AIModel name (default = the conversation default, resolved via the AIModelsService AGENTIC scope)')
            ->addOption('judge-model', null, InputOption::VALUE_OPTIONAL, 'Judge model (default: the judged run\'s judge model)')
            ->addOption('driver-model', null, InputOption::VALUE_OPTIONAL, 'Driver model (default: the judged run\'s driver model)')
            ->addOption('max-turns', null, InputOption::VALUE_OPTIONAL, 'How many user turns to drive', '14')
            ->addOption('segment-messages', null, InputOption::VALUE_OPTIONAL, 'Messages per level-0 leaf (compaction roughly every N messages)', '3')
            ->addOption('keep-messages', null, InputOption::VALUE_OPTIONAL, 'Recent messages kept raw', '3')
            ->addOption('fan-in', null, InputOption::VALUE_OPTIONAL, 'Roll-up fan-in K', '2')
            ->addOption('retention', null, InputOption::VALUE_OPTIONAL, 'Per-level retention', '1')
            ->addOption('trigger-tokens', null, InputOption::VALUE_OPTIONAL, 'Absolute compaction trigger (low = fires every turn)', '200');
        $this->configureBudgetOption();
        $this
            ->addOption('persist', null, InputOption::VALUE_NONE, 'PERSIST the conversation (non-ephemeral) so the run exercises the stored cover/frontier/tail load paths; prints the id for `app:ai-agent:compact <id> --validate`. Compaction still runs inline (no workers).')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit the raw verdict + snapshots as JSON');
    }

    /**
     * Reads the case, runs the judged compaction-tree evaluation and prints its verdict.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int `INVALID` without a readable case or a space; `FAILURE` when the run fails, the verdict is no PASS or
     *             the stored build does not validate
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '2048M');
        set_time_limit(3600);
        $agentEvalCompactionTreeCaseFile = $this->readCaseFile($input, $output);
        if ($agentEvalCompactionTreeCaseFile === null) {
            return Command::INVALID;
        }
        $missingScopeMessage = $this->findMissingScopeMessage($input, $agentEvalCompactionTreeCaseFile);
        if ($missingScopeMessage !== null) {
            $output->writeln("<error>{$missingScopeMessage}</error>");
            return Command::INVALID;
        }
        $agentEvalCompactionTreeParameters = $this->buildCompactionTreeParameters($input);
        $compactionParamsText = $agentEvalCompactionTreeParameters->buildParametersText(
            $agentEvalCompactionTreeCaseFile->getCompactionParamsTemplate($this->getDefaultCompactionParamsTemplate())
        );
        $agentEvalSimCase = $agentEvalCompactionTreeCaseFile->buildSimCase($agentEvalCompactionTreeParameters, $compactionParamsText);

        $output->writeln("<comment>Compaction-tree judge eval — $compactionParamsText</comment>");
        try {
            $agentEvalScope = $this->resolveTreeEvalScope($input, $agentEvalCompactionTreeCaseFile);
            $agentEvalCompactionTreeRunResult = $this->runCompactionTreeEvaluation($agentEvalScope, $agentEvalSimCase, $this->buildJudgedRunOptions($input));
        } catch (Throwable $runError) {
            $output->writeln("<error>eval failed: {$runError->getMessage()}</error>");
            return Command::FAILURE;
        }
        $storedBuildValid = $this->validateStoredBuildWhenPersisted($input, $output, $agentEvalCompactionTreeRunResult);
        $passed = $agentEvalCompactionTreeRunResult->getAgentEvalJudgeVerdict()->isPass();
        if ((bool)$input->getOption('json')) {
            $this->printJsonDocument($output, $agentEvalCompactionTreeRunResult);
        } else {
            $this->printTreeText($output, $agentEvalCompactionTreeRunResult);
        }
        return ($passed && $storedBuildValid) ? Command::SUCCESS : Command::FAILURE;
    }

    // ------------------------------------------------------------------ the case

    /**
     * The case file an application runs when `--case-file` is not given. The default: none (the option is required).
     *
     * @return string|null an absolute path, or null
     */
    protected function getDefaultCaseFile(): ?string
    {
        return null;
    }

    /**
     * The template of the parameters text for a case file without its own. The default:
     * {@see DEFAULT_COMPACTION_PARAMS_TEMPLATE}.
     *
     * @return string
     */
    protected function getDefaultCompactionParamsTemplate(): string
    {
        return static::DEFAULT_COMPACTION_PARAMS_TEMPLATE;
    }

    /**
     * The case of `--case-file`, else of {@see getDefaultCaseFile()}; prints why and answers null when there is none,
     * the file does not exist, is no valid JSON or lacks a required key.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return AgentEvalCompactionTreeCaseFile|null
     */
    protected function readCaseFile(InputInterface $input, OutputInterface $output): ?AgentEvalCompactionTreeCaseFile
    {
        $caseFileOption = trim((string)($input->getOption(self::OPTION_CASE_FILE) ?? ''));
        $caseFilePath = $caseFileOption !== '' ? $caseFileOption : $this->getDefaultCaseFile();
        if ($caseFilePath === null) {
            $output->writeln('<error>--' . self::OPTION_CASE_FILE . ' is required (this command has no default case)</error>');
            return null;
        }
        if (!is_file($caseFilePath)) {
            $output->writeln("<error>Case file not found: {$caseFilePath}</error>");
            return null;
        }
        try {
            $agentEvalCompactionTreeCaseFile = AgentEvalCompactionTreeCaseFile::fromJson((string)file_get_contents($caseFilePath));
        } catch (JsonException $jsonError) {
            $output->writeln("<error>Case file {$caseFilePath} is no valid JSON: {$jsonError->getMessage()}</error>");
            return null;
        }
        $missingRequiredKeys = $agentEvalCompactionTreeCaseFile->findMissingRequiredKeys();
        if ($missingRequiredKeys !== []) {
            $output->writeln("<error>Case file {$caseFilePath} lacks: " . implode(', ', $missingRequiredKeys) . '</error>');
            return null;
        }
        return $agentEvalCompactionTreeCaseFile;
    }

    /**
     * The parameters of the options.
     *
     * @param InputInterface $input
     * @return AgentEvalCompactionTreeParameters
     */
    protected function buildCompactionTreeParameters(InputInterface $input): AgentEvalCompactionTreeParameters
    {
        return AgentEvalCompactionTreeParameters::forRun(
            (int)$input->getOption('segment-messages'),
            (int)$input->getOption('keep-messages'),
            (int)$input->getOption('fan-in'),
            (int)$input->getOption('retention'),
            (int)$input->getOption('trigger-tokens'),
            (int)$input->getOption('max-turns')
        );
    }

    // ------------------------------------------------------------------ the scope and the run

    /**
     * Why the run has no space, or null when it has one: the scope options name none and neither does the case file.
     * An application whose scope option is required overrides it with its own message.
     *
     * @param InputInterface $input
     * @param AgentEvalCompactionTreeCaseFile $agentEvalCompactionTreeCaseFile
     * @return string|null
     */
    protected function findMissingScopeMessage(InputInterface $input, AgentEvalCompactionTreeCaseFile $agentEvalCompactionTreeCaseFile): ?string
    {
        if ($agentEvalCompactionTreeCaseFile->namesAgentSpace() || $this->buildScopeArgumentsForChild($input) !== []) {
            return null;
        }
        return '--' . self::OPTION_AGENT_SPACE_ID . ' is required (or a case file that names its ' . self::OPTION_AGENT_SPACE_ID . ')';
    }

    /**
     * The scope of the run: the case file's space, else the space of the scope options, with its owner
     * ({@see AgentEvalJudgedRunService::resolveEvalScope()}).
     *
     * @param InputInterface $input
     * @param AgentEvalCompactionTreeCaseFile $agentEvalCompactionTreeCaseFile
     * @return AgentEvalScope
     * @throws Throwable when no space or no owner is found
     */
    protected function resolveTreeEvalScope(InputInterface $input, AgentEvalCompactionTreeCaseFile $agentEvalCompactionTreeCaseFile): AgentEvalScope
    {
        $agentEvalJudgedRunService = $this->getJudgedRunService();
        return $agentEvalJudgedRunService->resolveEvalScope($agentEvalCompactionTreeCaseFile->buildEvalCase(), $this->resolveFallbackAgentSpaceId($input));
    }

    /**
     * The options of the judged run: the models of the options (null = the run's defaults; the agent's default is the
     * conversation's agent model, never the summarizer), whether the conversation is stored, the progress reporter.
     *
     * @param InputInterface $input
     * @return AgentEvalJudgedRunOptions
     */
    protected function buildJudgedRunOptions(InputInterface $input): AgentEvalJudgedRunOptions
    {
        $agentModelOption = $input->getOption('agent-model');
        $agentEvalJudgedRunOptions = AgentEvalJudgedRunOptions::forArms();
        $agentEvalJudgedRunOptions->aiModelName = $agentModelOption !== null ? (string)$agentModelOption : null;
        $agentEvalJudgedRunOptions->judgeModelName = $input->getOption('judge-model');
        $agentEvalJudgedRunOptions->driverModelName = $input->getOption('driver-model');
        $agentEvalJudgedRunOptions->persistConversations = (bool)$input->getOption('persist');
        $agentEvalJudgedRunOptions->progressReporter = $this->getProgressReporter();
        return $agentEvalJudgedRunOptions;
    }

    /**
     * The judged compaction-tree run.
     *
     * @param AgentEvalScope $agentEvalScope
     * @param AgentEvalSimCase $agentEvalSimCase
     * @param AgentEvalJudgedRunOptions $agentEvalJudgedRunOptions
     * @return AgentEvalCompactionTreeRunResult
     */
    protected function runCompactionTreeEvaluation(AgentEvalScope $agentEvalScope, AgentEvalSimCase $agentEvalSimCase, AgentEvalJudgedRunOptions $agentEvalJudgedRunOptions): AgentEvalCompactionTreeRunResult
    {
        $agentEvalJudgedRunService = $this->getJudgedRunService();
        return $agentEvalJudgedRunService->runCompactionTreeEval($agentEvalScope, $agentEvalSimCase, $agentEvalJudgedRunOptions);
    }

    /**
     * With `--persist`: re-reads the stored conversation, validates its compaction build and prints the result; true
     * without `--persist` or without a stored conversation.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param AgentEvalCompactionTreeRunResult $agentEvalCompactionTreeRunResult
     * @return bool whether the stored build is valid
     */
    protected function validateStoredBuildWhenPersisted(InputInterface $input, OutputInterface $output, AgentEvalCompactionTreeRunResult $agentEvalCompactionTreeRunResult): bool
    {
        $persistedConversation = $agentEvalCompactionTreeRunResult->getConversation();
        if (!(bool)$input->getOption('persist') || !isset($persistedConversation->id)) {
            return true;
        }
        $output->writeln("\n<info>PERSISTED conversation id = {$persistedConversation->id}</info> — re-loading from DB + validating the build…");
        $aIConversationCompactionService = $this->getCompactionService();
        $compactionConsistencyReport = $aIConversationCompactionService->validateCompactionConsistency($persistedConversation);
        $storedBuildValid = $compactionConsistencyReport->isAllPass();
        $output->writeln(($storedBuildValid ? '<info>DB BUILD VALIDATION: ALL PASS</info>' : '<error>DB BUILD VALIDATION: FAILED</error>')
            . ' — efficiency=' . json_encode($compactionConsistencyReport->getEfficiencyCounts()));
        foreach ($compactionConsistencyReport->getChecks() as $compactionConsistencyCheck) {
            if (!$compactionConsistencyCheck->isPass()) {
                $output->writeln("  <error>FAIL</error> {$compactionConsistencyCheck->getCheckName()}");
            }
        }
        $output->writeln("  re-inspect: php bin/console app:ai-agent:compact {$persistedConversation->id} --show-only --validate --ignore-budget");
        return $storedBuildValid;
    }

    // ------------------------------------------------------------------ printing

    /**
     * The `--json` document: `verdict`, `turns`, `stopReason`, `finalTree`, `treeSnapshots`.
     *
     * @param OutputInterface $output
     * @param AgentEvalCompactionTreeRunResult $agentEvalCompactionTreeRunResult
     * @return void
     */
    protected function printJsonDocument(OutputInterface $output, AgentEvalCompactionTreeRunResult $agentEvalCompactionTreeRunResult): void
    {
        $output->writeln((string)json_encode([
            'verdict' => $agentEvalCompactionTreeRunResult->getAgentEvalJudgeVerdict()->toArray(),
            'turns' => $agentEvalCompactionTreeRunResult->getTurns(),
            'stopReason' => $agentEvalCompactionTreeRunResult->getStopReason(),
            'finalTree' => $agentEvalCompactionTreeRunResult->getFinalTree(),
            'treeSnapshots' => $agentEvalCompactionTreeRunResult->getTreeSnapshots(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * The text output: the tree after every turn, the final tree, the verdict line and the verdict (green when it is
     * a PASS).
     *
     * @param OutputInterface $output
     * @param AgentEvalCompactionTreeRunResult $agentEvalCompactionTreeRunResult
     * @return void
     */
    protected function printTreeText(OutputInterface $output, AgentEvalCompactionTreeRunResult $agentEvalCompactionTreeRunResult): void
    {
        $agentEvalJudgeVerdict = $agentEvalCompactionTreeRunResult->getAgentEvalJudgeVerdict();
        $passed = $agentEvalJudgeVerdict->isPass();
        foreach ($agentEvalCompactionTreeRunResult->getTreeSnapshots() as $turn => $treeSnapshot) {
            $output->writeln("\n<fg=gray>{$this->buildTurnSnapshotHeading((int)$turn)}</>");
            $output->writeln($treeSnapshot);
        }
        $output->writeln("\n<comment>{$this->getFinalTreeHeading()}</comment>\n" . $agentEvalCompactionTreeRunResult->getFinalTree());
        $output->writeln(sprintf("\n<comment>VERDICT</comment> (%d turns, stop=%s)", $agentEvalCompactionTreeRunResult->getTurns(), $agentEvalCompactionTreeRunResult->getStopReason()));
        $output->writeln(($passed ? '<info>' : '<error>') . (string)json_encode($agentEvalJudgeVerdict->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ($passed ? '</info>' : '</error>'));
    }

    /**
     * The heading above the tree after owner turn `$turn`.
     *
     * @param int $turn
     * @return string
     */
    protected function buildTurnSnapshotHeading(int $turn): string
    {
        return "── after turn $turn ──";
    }

    /**
     * The heading above the final tree.
     *
     * @return string
     */
    protected function getFinalTreeHeading(): string
    {
        return 'FINAL TREE';
    }

    // ------------------------------------------------------------------ services

    /**
     * The judged-run service (the application's class, when it replaces it).
     *
     * @return AgentEvalJudgedRunService
     */
    protected function getJudgedRunService(): AgentEvalJudgedRunService
    {
        /** @var AgentEvalJudgedRunService $agentEvalJudgedRunService */
        $agentEvalJudgedRunService = DDDService::instance()->getService(AgentEvalJudgedRunService::class);
        return $agentEvalJudgedRunService;
    }

    /**
     * The compaction service (the application's class, when it replaces it).
     *
     * @return AIConversationCompactionService
     */
    protected function getCompactionService(): AIConversationCompactionService
    {
        /** @var AIConversationCompactionService $aIConversationCompactionService */
        $aIConversationCompactionService = DDDService::instance()->getService(AIConversationCompactionService::class);
        return $aIConversationCompactionService;
    }
}
