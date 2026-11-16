<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentApplicationWording;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Evals\AgentEvalCase;
use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRow;
use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRowHeader;
use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRows;
use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRunMeasurement;
use DDD\Domain\AI\Entities\Evals\AgentEvalCases;
use DDD\Domain\AI\Entities\Evals\AgentEvalNotApplicableReason;
use DDD\Domain\AI\Entities\Evals\AgentEvalRecallRunResult;
use DDD\Domain\AI\Entities\Evals\AgentEvalRunOptions;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteTask;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;
use DDD\Symfony\Commands\Agents\Eval\AgentEvalCaseScopeAndFixtureTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * The live cross-conversation recall evaluation: for each case it SEEDS one or more past conversations with known
 * content (stored and embedded), drives the agent on a FRESH conversation with a prompt that refers back to that
 * content, and checks that the agent reached for one of the expected recall tools and named every seeded fact in its
 * answer (grounded retrieval, not "nothing found" and not a fabrication). The seeded conversations are always deleted
 * afterwards unless `--keep-seed` keeps them.
 *
 * Each case names its own agent space. Nothing the agent would change reaches the space: every write tool is refused.
 * Exits with a failure unless every case that counts passed. LIVE: real model and tool calls.
 *
 * Fixture cases: `{id, agentSpaceId, seedConversations: [{messages: [{role, text}]}], prompt, expect: {tool: [...]},
 * expectMentions: [...]}`. Override point of an application: {@see getDefaultFixturePaths()}.
 */
#[AsCommand(
    name: 'app:ai-agent:eval-recall',
    description: 'LIVE conversation-retrieval eval: seed past conversations, test the agent recalls them, then tear them down.'
)]
class AgentEvalRecallCommand extends AgentEvalConsoleCommand
{
    use AgentEvalCaseScopeAndFixtureTrait;

    /** @var string The name of the default fixture file or folder in the configured fixture paths (`AI.evalSuite.fixturePaths`) */
    public const string FIXTURE_PATH_NAME = 'recall';

    /** @var string The memory limit of a run */
    public const string MEMORY_LIMIT = '2048M';

    /** @var int The time limit of a run in seconds */
    public const int TIME_LIMIT_SECONDS = 1800;

    /** @var int The characters of the answer `--full` prints */
    public const int PRINTED_ANSWER_LENGTH = 500;

    /** @var string The id of a case row whose case names none */
    public const string UNKNOWN_CASE_ID = 'unknown';

    protected function configure(): void
    {
        $this
            ->addOption('fixture', null, InputOption::VALUE_OPTIONAL, 'A single recall fixture JSON (default: the configured recall fixtures)')
            ->addOption('case', null, InputOption::VALUE_OPTIONAL, 'Run only this case id')
            ->addOption('model', null, InputOption::VALUE_OPTIONAL, 'Override the agent model by its catalog name')
            ->addOption('keep-seed', null, InputOption::VALUE_NONE, 'Do NOT tear down the seeded conversations: they survive the run so you can verify they persisted and are searchable (e.g. app:ai-conversation:search-inspect <seededId>). Default: hard-deleted afterwards.')
            ->addOption('full', null, InputOption::VALUE_NONE, 'Show the final answer text for every case')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable JSON output')
            ->addOption('result-file', null, InputOption::VALUE_OPTIONAL, 'Also write the result JSON to this file (what a parent process reads instead of parsing the output).');
        $this->configureBudgetOption();
    }

    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', static::MEMORY_LIMIT);
        // this evaluation judges what the agent RECALLS: nothing it would change may reach the agent space
        MCPToolExecutorService::$everyWriteToolBlocked = true;
        set_time_limit(static::TIME_LIMIT_SECONDS);
        $modelName = $input->getOption('model') !== null ? (string)$input->getOption('model') : null;
        $caseFilter = $input->getOption('case') !== null ? (string)$input->getOption('case') : null;
        $printsJson = (bool)$input->getOption('json');

        $agentEvalCases = $this->findCasesOfFixtures($input->getOption('fixture') !== null ? (string)$input->getOption('fixture') : '', $caseFilter);
        if ($agentEvalCases->count() === 0) {
            $output->writeln('<error>No recall eval cases found.</error>');
            return Command::FAILURE;
        }
        $agentEvalCaseRows = AgentEvalCaseRows::newInstance();
        foreach ($agentEvalCases->getElements() as $agentEvalCase) {
            $agentEvalCaseRow = $this->runCase($agentEvalCase, $modelName, (bool)$input->getOption('keep-seed'));
            $agentEvalCaseRows->add($agentEvalCaseRow);
            if (!$printsJson) {
                $this->printCaseLines($output, $agentEvalCaseRow, (bool)$input->getOption('keep-seed'), (bool)$input->getOption('full'));
            }
        }

        $agentEvalRecallRunResult = AgentEvalRecallRunResult::forCaseRows($agentEvalCaseRows, $modelName);
        $agentEvalResultFilesService = $this->getAgentEvalResultFilesService();
        $resultFile = $input->getOption('result-file');
        if (is_string($resultFile) && $resultFile !== '') {
            $agentEvalResultFilesService->writeResultDocument($resultFile, $agentEvalRecallRunResult->toArray());
        }
        if ($printsJson) {
            $output->writeln($agentEvalResultFilesService->encodeJsonDocument($agentEvalRecallRunResult->toPrintedArray()));
        } else {
            $this->printOverall($output, $agentEvalRecallRunResult);
        }
        return $agentEvalRecallRunResult->hasEveryCasePassed() ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Runs one case: the not-applicable row for a case its agent space cannot serve (not run, not counted), else the
     * recall row.
     */
    protected function runCase(AgentEvalCase $agentEvalCase, ?string $modelName, bool $keepsSeededConversations): AgentEvalCaseRow
    {
        // one options object per case: the recall conversation's run settings carry the read-only switch, the seeded
        // ids are recorded on it also when the drive throws
        $agentEvalRunOptions = AgentEvalRunOptions::forArms();
        $agentEvalRunOptions->aiModelName = $modelName;
        $agentEvalRunOptions->keepSeededConversations = $keepsSeededConversations;
        $agentEvalRunOptions->everyWriteToolBlocked = true;
        $agentEvalRunOptions->progressReporter = $this->getProgressReporter();
        $agentEvalRunnerService = $this->getAgentEvalRunnerService();
        $agentEvalNotApplicableReason = $agentEvalRunnerService->resolveScopeAndFindNotApplicableReasonForCase($agentEvalCase, null, $agentEvalRunOptions);
        if ($agentEvalNotApplicableReason !== null) {
            return $this->buildNotApplicableRow($agentEvalCase, $agentEvalNotApplicableReason);
        }
        $conversation = null;
        $messagesTrace = null;
        $error = null;
        $startedAtMicrotime = microtime(true);
        try {
            $conversation = $this->driveRecallConversation($agentEvalCase, $agentEvalRunOptions);
            // the trace of the driven conversation: the conversation is transient, the case row is its only record
            $messagesTrace = $conversation->messages !== null
                ? $agentEvalRunnerService->buildMessagesTraceWithoutConversationBackReference($conversation->messages)
                : null;
        } catch (Throwable $throwable) {
            $error = $throwable->getMessage();
        }
        return AgentEvalCaseRow::buildForRecallRun(
            $agentEvalCase,
            $conversation,
            $messagesTrace,
            // the seeded ids, also when the drive threw after the seed was stored
            $agentEvalRunOptions->getSeededConversationIds(),
            $error,
            AgentEvalCaseRunMeasurement::measureDurationMsSince($startedAtMicrotime)
        );
    }

    /** Seeds the case's past conversations and drives the agent on a fresh one; returns the driven conversation. */
    protected function driveRecallConversation(AgentEvalCase $agentEvalCase, AgentEvalRunOptions $agentEvalRunOptions): AIConversation
    {
        $agentEvalRunnerService = $this->getAgentEvalRunnerService();
        return $agentEvalRunnerService->runCrossConversationRecallWithSeed(
            $agentEvalRunnerService->resolveEvalScope($agentEvalCase),
            $agentEvalCase,
            $agentEvalRunOptions
        )->getConversation();
    }

    /** The row of a case that does not apply: `id`, `flavor`, the scope the case names (0 when none). */
    protected function buildNotApplicableRow(AgentEvalCase $agentEvalCase, AgentEvalNotApplicableReason $agentEvalNotApplicableReason): AgentEvalCaseRow
    {
        $agentEvalCaseRowHeader = AgentEvalCaseRowHeader::withoutFields()
            ->addField('id', $this->getCaseIdOfRow($agentEvalCase))
            ->addFlavor(AgentEvalSuiteTask::FLAVOR_CALL)
            ->addScope($this->findScopeValueNamedByCase($agentEvalCase) ?? 0);
        return AgentEvalCaseRow::buildNotApplicableOfCase($agentEvalCaseRowHeader, $agentEvalNotApplicableReason);
    }

    /** The id a row names its case by: the case's `id`, {@see UNKNOWN_CASE_ID} when it names none. */
    protected function getCaseIdOfRow(AgentEvalCase $agentEvalCase): string
    {
        return (string)($agentEvalCase->getApplicationValue('id') ?? static::UNKNOWN_CASE_ID);
    }

    /**
     * Prints the line of a case: `N/A  <id>  <detail>` for a case that does not apply, else
     * `PASS|FAIL <id>  seeded=[…]  tools=[…]  mentions: …`, and with `--full` the start of the answer.
     */
    protected function printCaseLines(OutputInterface $output, AgentEvalCaseRow $agentEvalCaseRow, bool $keepsSeededConversations, bool $printsAnswer): void
    {
        if ($agentEvalCaseRow->isNotApplicable()) {
            $output->writeln(sprintf('<comment>N/A </comment> %s  %s', (string)$agentEvalCaseRow->getValue('id'), $agentEvalCaseRow->getNotApplicableDetail()));
            return;
        }
        $missingMentions = (array)$agentEvalCaseRow->getValue('missingMentions');
        $output->writeln(sprintf(
            '%s %s  seeded=[%s]%s  tools=[%s]  mentions:%s%s',
            $agentEvalCaseRow->isPassed() ? '<fg=green>PASS</>' : '<fg=red>FAIL</>',
            (string)$agentEvalCaseRow->getValue('id'),
            implode(',', (array)$agentEvalCaseRow->getValue('seededConversationIds')),
            $keepsSeededConversations ? ' (kept)' : '',
            implode(', ', (array)$agentEvalCaseRow->getValue('calledTools')),
            $missingMentions === [] ? ' all found' : ' MISSING ' . implode(' | ', $missingMentions),
            $agentEvalCaseRow->getError() !== null ? '  ERROR: ' . $agentEvalCaseRow->getError() : ''
        ));
        $finalText = (string)$agentEvalCaseRow->getValue('finalText');
        if ($printsAnswer && $finalText !== '') {
            $output->writeln('  <fg=gray>answer:</> ' . mb_substr($finalText, 0, static::PRINTED_ANSWER_LENGTH));
        }
    }

    /** Prints `Overall: p/t (x.x%)` after an empty line, with the count of the cases that do not apply. */
    protected function printOverall(OutputInterface $output, AgentEvalRecallRunResult $agentEvalRecallRunResult): void
    {
        $output->writeln('');
        $output->writeln(sprintf(
            'Overall: %d/%d (%.1f%%)%s',
            $agentEvalRecallRunResult->passed,
            $agentEvalRecallRunResult->total,
            $agentEvalRecallRunResult->total > 0 ? 100.0 * $agentEvalRecallRunResult->passed / $agentEvalRecallRunResult->total : 0.0,
            $agentEvalRecallRunResult->notApplicable > 0
                ? sprintf(' · %d not applicable on their %s (not counted)', $agentEvalRecallRunResult->notApplicable, $this->getScopeWordOfOverallLine())
                : ''
        ));
    }

    /**
     * The cases of the run: of `$fixturePath`, else of every file of {@see getDefaultFixturePaths()}; every JSON
     * object of a fixture's `cases` that carries an `id`, in the order of the files; with a case filter only the case
     * of that id.
     */
    protected function findCasesOfFixtures(string $fixturePath, ?string $caseFilter): AgentEvalCases
    {
        $agentEvalCases = AgentEvalCases::newInstance();
        $agentEvalSuitePlanService = $this->getAgentEvalSuitePlanService();
        foreach ($fixturePath !== '' ? [$fixturePath] : $this->getDefaultFixturePaths() as $fixtureFilePath) {
            $caseEntries = $agentEvalSuitePlanService->findFixtureAt($fixtureFilePath)?->getApplicationValue('cases');
            foreach (is_array($caseEntries) ? $caseEntries : [] as $caseFields) {
                if (!is_array($caseFields) || !isset($caseFields['id'])) {
                    continue;
                }
                if ($caseFilter !== null && $caseFields['id'] !== $caseFilter) {
                    continue;
                }
                $agentEvalCase = AgentEvalCase::fromArray($caseFields);
                $agentEvalCases->add($agentEvalCase);
            }
        }
        return $agentEvalCases;
    }

    /**
     * Override point: the fixture files of a run without `--fixture`. The default: the files of the configured path
     * named {@see FIXTURE_PATH_NAME} (`AI.evalSuite.fixturePaths`, a file or a folder of `*.json`); none when none is
     * configured.
     *
     * @return string[]
     */
    protected function getDefaultFixturePaths(): array
    {
        $agentEvalSuitePlanService = $this->getAgentEvalSuitePlanService();
        $fixturePath = $agentEvalSuitePlanService->findFixturePath(static::FIXTURE_PATH_NAME);
        return $fixturePath !== null ? $agentEvalSuitePlanService->findFixtureFilesAt($fixturePath) : [];
    }

    /**
     * Override point: the word for the scope of a case in the overall line (`n not applicable on their <word>`). The
     * default: what the application calls an agent space ({@see AgentApplicationWording::$agentSpaceName}).
     */
    protected function getScopeWordOfOverallLine(): string
    {
        return AgentApplicationWording::current()->agentSpaceName;
    }
}
