<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleStatus;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * The schedule state sweep (daily, beside the reaper): closes the schedules of agent spaces and accounts that are no
 * longer eligible for automated work, and re-arms the ones the platform paused once their state cleared. It does not
 * depend on the path by which a space or an account ended: the fire path's own gate only makes such rows harmless,
 * this sweep makes them disappear from the owner's view.
 *
 * Per candidate ({@see AIConversationSchedulesService::findScheduleIdsForAutomationStateSweep()} — a cheap
 * pre-filter) the scope is evaluated fresh and the schedule decides
 * ({@see AIConversationSchedule::decideTransitionForAutomationState()}): ACTIVE on an ended scope → CANCELLED; ACTIVE on
 * a suspended scope → PAUSED with a reason; an owner-paused row on an ended scope → CANCELLED; an owner-paused row on a
 * suspended scope → untouched; a platform-paused row → re-armed when eligible again. A close also cancels the
 * schedule's in-flight runs and closes their owed report obligations
 * ({@see AIConversationSchedulesService::applyAutomationStateSweepToSchedule()}).
 *
 * An application whose cron rows call the sweep by another name declares that name beside this one in its subclass's
 * `#[AsCommand]` (`name: 'app:agent:close-schedules-for-inactive-agent-spaces|<its name>'`), so the framework removes this
 * command's service and both names run the application's class.
 *
 *   php bin/console app:agent:close-schedules-for-inactive-agent-spaces
 *   php bin/console app:agent:close-schedules-for-inactive-agent-spaces --batch=500 --dry-run
 */
#[AsCommand(
    name: 'app:agent:close-schedules-for-inactive-agent-spaces',
    aliases: ['app:ai-schedule:close-for-inactive-agent-spaces'],
    description: 'Close the AI schedules of ended or suspended agent spaces and accounts; re-arm platform-paused ones whose state cleared.',
)]
class AgentCloseSchedulesForInactiveAgentSpacesCommand extends AgentConsoleCommand
{
    use LockableTrait;

    /** @var string The candidate cap per pass */
    public const string OPTION_BATCH = 'batch';

    /** @var string Lists the decisions without applying a transition */
    public const string OPTION_DRY_RUN = 'dry-run';

    /** @var array<string, int> The decided transitions of the current run, by target status */
    protected array $decidedCountsByTargetStatus = [];

    /** @var int Candidates the sweep left as they were in the current run */
    protected int $untouchedCount = 0;

    /** @var int Candidates whose sweep failed in the current run */
    protected int $failedCount = 0;

    protected function configure(): void
    {
        $this->addOption(self::OPTION_BATCH, null, InputOption::VALUE_OPTIONAL, 'Per-pass candidate cap', (string)AIConversationSchedulesService::STATE_SWEEP_BATCH_LIMIT);
        $this->addOption(self::OPTION_DRY_RUN, null, InputOption::VALUE_NONE, 'List the decisions without applying any transition');
    }

    /** The sweep reads every account's schedules in a cron without an authenticated account. */
    protected function liftsEntityRightsRestrictions(): bool
    {
        return true;
    }

    /**
     * One pass over the candidates under the lock of the command; prints each decision and the counts.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws Throwable a failure of the candidate read ends the run (each candidate catches its own)
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            $output->writeln('Schedule state sweep: a previous run is still active — skipping this tick.');
            return Command::SUCCESS;
        }
        $batchLimit = (int)$input->getOption(self::OPTION_BATCH) ?: AIConversationSchedulesService::STATE_SWEEP_BATCH_LIMIT;
        $dryRun = (bool)$input->getOption(self::OPTION_DRY_RUN);
        $aIConversationSchedulesService = $this->getAIConversationSchedulesService();
        $this->decidedCountsByTargetStatus = [AIConversationScheduleStatus::CANCELLED => 0, AIConversationScheduleStatus::PAUSED => 0, AIConversationScheduleStatus::ACTIVE => 0];
        $this->untouchedCount = 0;
        $this->failedCount = 0;
        try {
            foreach ($aIConversationSchedulesService->findScheduleIdsForAutomationStateSweep($batchLimit) as $candidateScheduleId) {
                try {
                    $this->sweepSchedule((int)$candidateScheduleId, $dryRun, $aIConversationSchedulesService, $output);
                } catch (Throwable $sweepError) {
                    $this->failedCount++;
                    $output->writeln("<error>Failed: schedule {$candidateScheduleId}: {$sweepError->getMessage()}</error>");
                }
            }
        } finally {
            $this->release();
        }

        $output->writeln(sprintf(
            'Schedule state sweep: %d closed (CANCELLED), %d paused, %d re-armed, %d untouched, %d failed.',
            $this->decidedCountsByTargetStatus[AIConversationScheduleStatus::CANCELLED],
            $this->decidedCountsByTargetStatus[AIConversationScheduleStatus::PAUSED],
            $this->decidedCountsByTargetStatus[AIConversationScheduleStatus::ACTIVE],
            $this->untouchedCount,
            $this->failedCount
        ));
        return Command::SUCCESS;
    }

    /**
     * Applies (or, in a dry run, only decides) the sweep for one schedule, counts the outcome and prints a decided
     * transition.
     *
     * @param int $candidateScheduleId
     * @param bool $dryRun
     * @param AIConversationSchedulesService $aIConversationSchedulesService
     * @param OutputInterface $output
     * @return void
     * @throws Throwable
     */
    protected function sweepSchedule(int $candidateScheduleId, bool $dryRun, AIConversationSchedulesService $aIConversationSchedulesService, OutputInterface $output): void
    {
        $schedule = $aIConversationSchedulesService->find($candidateScheduleId, useEntityRegistrCache: false);
        if (!$schedule instanceof AIConversationSchedule) {
            return;
        }
        $stateSweepTransition = $aIConversationSchedulesService->applyAutomationStateSweepToSchedule($schedule, $dryRun);
        if ($stateSweepTransition === null) {
            $this->untouchedCount++;
            return;
        }
        $targetStatus = (string)$stateSweepTransition->targetStatus;
        $stateReason = $stateSweepTransition->stateReason;
        $this->decidedCountsByTargetStatus[$targetStatus] = ($this->decidedCountsByTargetStatus[$targetStatus] ?? 0) + 1;
        $output->writeln(sprintf(
            '%s: schedule %d (%s) → %s%s%s',
            $dryRun ? 'Would transition (dry-run)' : 'Transitioned',
            $candidateScheduleId,
            $this->describeScheduleScope($schedule),
            $targetStatus,
            $stateReason !== null ? " ({$stateReason})" : ' (re-armed, reason cleared)',
            // a dry run asks the application nothing: a pause or a close is applied only when it refuses
            $dryRun && $stateSweepTransition->appliesOnlyWhenTheApplicationRefuses
                ? ' — would ask the application (' . AIConversationSchedulesService::STATE_SWEEP_REFUSAL_OPERATION_PREFIX . $targetStatus . '), applied only if it refuses'
                : ''
        ));
    }

    /**
     * Whom a schedule works for, as its decision line prints it. The default: `account <id>, agent space <id>`, a
     * dash for an id the schedule does not carry.
     *
     * @param AIConversationSchedule $schedule
     * @return string
     */
    protected function describeScheduleScope(AIConversationSchedule $schedule): string
    {
        return sprintf('account %s, agent space %s', $schedule->accountId ?? '-', $schedule->agentSpaceId ?? '-');
    }

    /** @return AIConversationSchedulesService the schedules service the container returns (the application's class, when it replaces it) */
    protected function getAIConversationSchedulesService(): AIConversationSchedulesService
    {
        /** @var AIConversationSchedulesService $aIConversationSchedulesService */
        $aIConversationSchedulesService = DDDService::instance()->getService(AIConversationSchedulesService::class);
        return $aIConversationSchedulesService;
    }
}
