<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SendOwnerReportTool;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleStatus;
use DDD\Domain\AI\Entities\Evals\AgentEvalCase;
use DDD\Domain\AI\Entities\Evals\AgentEvalScheduleRunStructure;
use DDD\Domain\AI\Entities\Evals\AgentEvalScheduleRunStructures;
use DDD\Domain\AI\Entities\Evals\AgentEvalScope;
use DDD\Domain\AI\Services\Conversations\Agent\AgentEvalAccountsService;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationOwnerReportDeliveryService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Eval\AgentEvalRunHooksService;
use DDD\Domain\AI\Services\Eval\AgentEvalRunnerService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Runs ONE recurring task several times in a row in an agent space of an evaluation account: creates an evaluation
 * schedule (`runSettings.evalRun` — its runs mail nothing), fires it `--runs` times through the cron's own arm, claim
 * and spawn ({@see AIConversationSchedulesService::fireEvalScheduleNow()}), waits for each run on the workers and
 * prints per run whether it read its earlier reports, which outcome it stated and the structure of its report
 * (headings and widget types). The verdict: how many runs kept the structure of the run before them
 * ({@see AgentEvalScheduleRunStructures}). The schedule is cancelled at the end unless `--keep`. Runs where the
 * workers are up.
 *
 * The space is the one the scope options name; its owner must be an evaluation account
 * ({@see AgentEvalAccountsService::getEvalAccountIds()}). The schedule is attached to the space by
 * {@see AgentEvalRunHooksService::attachNewScheduleToScope()}. An application adds options of its own
 * ({@see configureReportOptions()}), checks them ({@see validateReportOptions()}), may do something else than firing
 * ({@see runInsteadOfFiring()}) and acts on every ended run ({@see afterRunEnded()}), e.g. to send the run's report
 * somewhere; it names the scope and the source of the evaluation accounts in its words ({@see describeScope()},
 * {@see describeEvalAccountsSource()}, {@see describeRequiredOptions()}).
 */
#[AsCommand(
    name: 'app:ai-agent:eval-schedule-runs',
    description: 'Fire one eval task schedule N times in a row and compare the structure of its reports.',
)]
class AgentEvalScheduleRunsCommand extends AgentEvalConsoleCommand
{
    /** @var int Seconds between two reads of a running run */
    public const int POLL_SECONDS = 5;

    /** @var string The cron expression of the evaluation schedule (it never fires on its own before it is cancelled) */
    public const string SCHEDULE_CRON_EXPRESSION = '0 8 * * 1';

    /** @var int The newest messages of a run that are read for its tool calls */
    public const int RUN_MESSAGES_READ = 300;

    /** @var string The tool a run calls to read the reports of the runs before it */
    public const string EARLIER_REPORTS_TOOL_NAME = 'getPreviousTaskRunReports';

    /** @var string[] The run states that end a run */
    public const array ENDED_RUN_STATES = [AIConversationRunState::COMPLETED, AIConversationRunState::FAILED, AIConversationRunState::CANCELLED];

    protected function configure(): void
    {
        $this->configureEvalScopeOptions();
        $this->addOption('task', null, InputOption::VALUE_REQUIRED, 'The task text of the schedule');
        $this->addOption('title', null, InputOption::VALUE_OPTIONAL, 'The schedule title', 'Eval schedule runs');
        $this->addOption('runs', null, InputOption::VALUE_OPTIONAL, 'How many runs in a row', '3');
        $this->addOption('reportChannel', null, InputOption::VALUE_OPTIONAL, 'EMAIL or CHAT', AIConversationSchedule::REPORT_CHANNEL_EMAIL);
        $this->addOption('timeoutPerRun', null, InputOption::VALUE_OPTIONAL, 'Seconds to wait for one run', '900');
        $this->addOption('keep', null, InputOption::VALUE_NONE, 'Keep the schedule active at the end');
        $this->configureBudgetOption();
        $this->configureReportOptions();
    }

    /** The run reads and writes as an operator: the evaluation account's rows are read whoever runs the command. */
    protected function liftsEntityRightsRestrictions(): bool
    {
        return true;
    }

    /**
     * Checks the options, resolves the scope and its owner, then fires the runs (or does what the application does
     * instead) and prints them with the verdict.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int `FAILURE` on a missing option, an owner that is no evaluation account, or an error of the run
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(0);
        $symfonyStyle = new SymfonyStyle($input, $output);
        $task = trim((string)$input->getOption('task'));
        if (!$this->hasScopeOption($input) || ($task === '' && !$this->isRunWithoutFiring($input))) {
            $symfonyStyle->error($this->describeRequiredOptions());
            return Command::FAILURE;
        }
        if (!$this->validateReportOptions($input, $symfonyStyle)) {
            return Command::FAILURE;
        }
        try {
            $agentEvalRunnerService = $this->getAgentEvalRunnerService();
            $agentEvalScope = $agentEvalRunnerService->resolveEvalScopeWithFallbackAccount(AgentEvalCase::fromArray([]), $this->resolveFallbackAgentSpaceId($input), $this->resolveFallbackAccountId($input));
            $agentEvalRunnerService->authenticateOwnerOfScope($agentEvalScope);
            if (!$this->isOwnerAnEvalAccount($agentEvalScope)) {
                $symfonyStyle->error(sprintf(
                    '%s belongs to account %d, which is no configured eval account (%s).',
                    ucfirst($this->describeScope($agentEvalScope)),
                    $agentEvalScope->getOwnerAccountId(),
                    $this->describeEvalAccountsSource()
                ));
                return Command::FAILURE;
            }
            $exitCodeWithoutFiring = $this->runInsteadOfFiring($input, $symfonyStyle, $agentEvalScope);
            if ($exitCodeWithoutFiring !== null) {
                return $exitCodeWithoutFiring;
            }
            $this->fireAndPrintRuns($input, $symfonyStyle, $agentEvalScope, $task);
        } catch (Throwable $evalError) {
            $symfonyStyle->error($evalError->getMessage());
            return Command::FAILURE;
        }
        return Command::SUCCESS;
    }

    /**
     * Creates the evaluation schedule, fires its runs one after the other, prints each and the verdict, and cancels
     * the schedule unless `--keep`.
     *
     * @param InputInterface $input
     * @param SymfonyStyle $symfonyStyle
     * @param AgentEvalScope $agentEvalScope
     * @param string $task
     * @return void
     * @throws Throwable
     */
    protected function fireAndPrintRuns(InputInterface $input, SymfonyStyle $symfonyStyle, AgentEvalScope $agentEvalScope, string $task): void
    {
        $runCount = max(1, (int)$input->getOption('runs'));
        $timeoutPerRunSeconds = max(60, (int)$input->getOption('timeoutPerRun'));
        $aIConversationSchedulesService = $this->getSchedulesService();
        $schedule = $this->createEvalSchedule($input, $agentEvalScope, $task);
        $symfonyStyle->writeln("Eval schedule {$schedule->id} on {$this->describeScope($agentEvalScope)} (account {$agentEvalScope->getOwnerAccountId()}), {$runCount} runs");
        $agentEvalScheduleRunStructures = AgentEvalScheduleRunStructures::newInstance();
        try {
            for ($runNumber = 1; $runNumber <= $runCount; $runNumber++) {
                $schedule = $aIConversationSchedulesService->find((int)$schedule->id, useEntityRegistrCache: false);
                $runConversation = $aIConversationSchedulesService->fireEvalScheduleNow($schedule);
                if (!$runConversation instanceof AIConversation) {
                    $symfonyStyle->error("Run {$runNumber}: the schedule did not fire (claim lost or a guardrail skipped it).");
                    break;
                }
                $finishedRunConversation = $this->findRunConversationOnceEnded((int)$runConversation->id, $timeoutPerRunSeconds);
                $structureLines = $this->printRun($symfonyStyle, $runNumber, $finishedRunConversation ?? $runConversation, $finishedRunConversation !== null);
                $agentEvalScheduleRunStructure = AgentEvalScheduleRunStructure::forRun($runNumber, $structureLines);
                $agentEvalScheduleRunStructures->add($agentEvalScheduleRunStructure);
                if ($finishedRunConversation !== null) {
                    $this->afterRunEnded($input, $symfonyStyle, $finishedRunConversation);
                }
            }
            $symfonyStyle->writeln($agentEvalScheduleRunStructures->buildVerdictLine());
        } finally {
            if (!$input->getOption('keep')) {
                $aIConversationSchedulesService->transitionStatus($schedule, AIConversationScheduleStatus::CANCELLED);
                $symfonyStyle->writeln("Schedule {$schedule->id} cancelled.");
            }
        }
    }

    /**
     * Creates and stores the evaluation schedule of the run: the task, the title, the report channel, a weekly cron
     * expression and run settings that mark it an evaluation schedule, attached to the scope.
     *
     * @param InputInterface $input
     * @param AgentEvalScope $agentEvalScope
     * @param string $task
     * @return AIConversationSchedule the stored schedule
     */
    protected function createEvalSchedule(InputInterface $input, AgentEvalScope $agentEvalScope, string $task): AIConversationSchedule
    {
        $schedule = AIConversationSchedule::newInstance();
        $this->getAgentEvalRunHooksService()->attachNewScheduleToScope($schedule, $agentEvalScope);
        $schedule->task = $task;
        $schedule->title = (string)$input->getOption('title');
        $schedule->schedule = static::SCHEDULE_CRON_EXPRESSION;
        $schedule->reportChannel = (string)$input->getOption('reportChannel');
        $schedule->runSettings = RunSettings::newInstance();
        $schedule->runSettings->evalRun = true;
        return $this->getSchedulesService()->createSchedule($schedule);
    }

    /**
     * The run conversation once its run ended (completed, failed, cancelled, or idle with its report obligation
     * settled); null on timeout.
     *
     * @param int $runConversationId
     * @param int $timeoutSeconds
     * @return AIConversation|null
     */
    protected function findRunConversationOnceEnded(int $runConversationId, int $timeoutSeconds): ?AIConversation
    {
        $aIConversationsService = $this->getConversationsService();
        $deadline = time() + $timeoutSeconds;
        while (time() < $deadline) {
            sleep(static::POLL_SECONDS);
            $runConversation = $aIConversationsService->find($runConversationId, useEntityRegistrCache: false);
            if (!$runConversation instanceof AIConversation) {
                continue;
            }
            $runState = $runConversation->runState;
            if (in_array($runState, static::ENDED_RUN_STATES, true)
                || ($runState === AIConversationRunState::IDLE && $runConversation->getReportObligation()->isDelivered())
            ) {
                return $runConversation;
            }
        }
        return null;
    }

    /**
     * Prints one run: its state, whether it read its earlier reports, the outcome it stated, the tools it called, the
     * subject and the structure of its report.
     *
     * @param SymfonyStyle $symfonyStyle
     * @param int $runNumber
     * @param AIConversation $runConversation
     * @param bool $runEnded false = the wait for the run timed out
     * @return string[] the structure lines of the run's report
     */
    protected function printRun(SymfonyStyle $symfonyStyle, int $runNumber, AIConversation $runConversation, bool $runEnded): array
    {
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        /** @var AIConversationOwnerReportDeliveryService $aIConversationOwnerReportDeliveryService */
        $aIConversationOwnerReportDeliveryService = DDDService::instance()->getService(AIConversationOwnerReportDeliveryService::class);
        $calledToolNames = [];
        $statedOutcome = '-';
        foreach ($aIConversationMessagesService->findNewestMessages($runConversation, static::RUN_MESSAGES_READ)->getElements() as $message) {
            $toolCall = $message->toolCall ?? null;
            if ($toolCall === null) {
                continue;
            }
            $calledToolNames[] = (string)$toolCall->name;
            if ($toolCall->name === SendOwnerReportTool::NAME) {
                $statedOutcome = (string)($toolCall->arguments['outcome'] ?? '?');
            }
        }
        $ownerReport = $aIConversationOwnerReportDeliveryService->findModelWrittenOwnerReportOfRun($runConversation);
        $structureLines = $ownerReport !== null ? $ownerReport->buildStructureLines() : [];
        $symfonyStyle->writeln(sprintf(
            'Run %d · conversation %d · %s%s · read earlier reports: %s · outcome %s · tools: %s',
            $runNumber,
            (int)$runConversation->id,
            $runConversation->runState,
            $runEnded ? '' : ' (TIMEOUT)',
            in_array(static::EARLIER_REPORTS_TOOL_NAME, $calledToolNames, true) ? 'yes' : 'no',
            $statedOutcome,
            implode(', ', $calledToolNames)
        ));
        $symfonyStyle->writeln('  subject: ' . ($ownerReport !== null ? $ownerReport->subject : '(no model-written report)'));
        foreach ($structureLines as $structureLine) {
            $symfonyStyle->writeln('  ' . $structureLine);
        }
        return $structureLines;
    }

    /**
     * Whether the input names the scope: `--agentSpaceId` or an option of the agent-spaces service has a value (a
     * default the command declared included). Reads nothing.
     *
     * @param InputInterface $input
     * @return bool
     */
    protected function hasScopeOption(InputInterface $input): bool
    {
        $agentSpaceIdValue = $input->hasOption(self::OPTION_AGENT_SPACE_ID) ? trim((string)$input->getOption(self::OPTION_AGENT_SPACE_ID)) : '';
        return $agentSpaceIdValue !== '' || $this->findGivenAgentSpaceScopeOptionValues($input) !== [];
    }

    /**
     * Whether the owner of the scope is one of the evaluation accounts.
     *
     * @param AgentEvalScope $agentEvalScope
     * @return bool
     */
    protected function isOwnerAnEvalAccount(AgentEvalScope $agentEvalScope): bool
    {
        /** @var AgentEvalAccountsService $agentEvalAccountsService */
        $agentEvalAccountsService = DDDService::instance()->getService(AgentEvalAccountsService::class);
        return in_array((int)$agentEvalScope->getOwnerAccountId(), $agentEvalAccountsService->getEvalAccountIds(), true);
    }

    // ------------------------------------------------------------------ override points

    /**
     * Declares the options of what the application does with a run (none in the module). Called at the end of
     * `configure()`.
     *
     * @return void
     */
    protected function configureReportOptions(): void
    {
    }

    /**
     * Checks the options {@see configureReportOptions()} declared, printing why when they do not fit. The default:
     * they fit.
     *
     * @param InputInterface $input
     * @param SymfonyStyle $symfonyStyle
     * @return bool
     */
    protected function validateReportOptions(InputInterface $input, SymfonyStyle $symfonyStyle): bool
    {
        return true;
    }

    /**
     * Whether the run fires nothing ({@see runInsteadOfFiring()} does the work), so no task is needed. The default: no.
     *
     * @param InputInterface $input
     * @return bool
     */
    protected function isRunWithoutFiring(InputInterface $input): bool
    {
        return false;
    }

    /**
     * What the run does instead of firing the schedule, after the scope and its owner were checked; the exit code, or
     * null to fire. The default: null.
     *
     * @param InputInterface $input
     * @param SymfonyStyle $symfonyStyle
     * @param AgentEvalScope $agentEvalScope
     * @return int|null
     */
    protected function runInsteadOfFiring(InputInterface $input, SymfonyStyle $symfonyStyle, AgentEvalScope $agentEvalScope): ?int
    {
        return null;
    }

    /**
     * What the application does with a run that ended (not one that timed out), after it was printed. The default:
     * nothing.
     *
     * @param InputInterface $input
     * @param SymfonyStyle $symfonyStyle
     * @param AIConversation $finishedRunConversation
     * @return void
     */
    protected function afterRunEnded(InputInterface $input, SymfonyStyle $symfonyStyle, AIConversation $finishedRunConversation): void
    {
    }

    /**
     * The scope in the lines of the run, lower case (`Eval schedule <id> on <scope> …`). The default: `agent space <id>`.
     *
     * @param AgentEvalScope $agentEvalScope
     * @return string
     */
    protected function describeScope(AgentEvalScope $agentEvalScope): string
    {
        return 'agent space ' . (int)$agentEvalScope->getAgentSpaceId();
    }

    /**
     * Where the evaluation accounts are configured, as the refusal of an owner that is none names it.
     *
     * @return string
     */
    protected function describeEvalAccountsSource(): string
    {
        return 'the evaluation accounts service';
    }

    /**
     * The refusal of a run without a scope or a task.
     *
     * @return string
     */
    protected function describeRequiredOptions(): string
    {
        return 'A scope option (--' . self::OPTION_AGENT_SPACE_ID . ') and --task are required.';
    }

    // ------------------------------------------------------------------ services

    /** The schedules service. */
    protected function getSchedulesService(): AIConversationSchedulesService
    {
        /** @var AIConversationSchedulesService $aIConversationSchedulesService */
        $aIConversationSchedulesService = DDDService::instance()->getService(AIConversationSchedulesService::class);
        return $aIConversationSchedulesService;
    }

    /** The conversations service. */
    protected function getConversationsService(): AIConversationsService
    {
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
        return $aIConversationsService;
    }

    /** The runner service (the scope of the run and its owner). */
    protected function getAgentEvalRunnerService(): AgentEvalRunnerService
    {
        /** @var AgentEvalRunnerService $agentEvalRunnerService */
        $agentEvalRunnerService = DDDService::instance()->getService(AgentEvalRunnerService::class);
        return $agentEvalRunnerService;
    }

    /** The run hooks service (attaching the schedule to the scope). */
    protected function getAgentEvalRunHooksService(): AgentEvalRunHooksService
    {
        /** @var AgentEvalRunHooksService $agentEvalRunHooksService */
        $agentEvalRunHooksService = DDDService::instance()->getService(AgentEvalRunHooksService::class);
        return $agentEvalRunHooksService;
    }
}
