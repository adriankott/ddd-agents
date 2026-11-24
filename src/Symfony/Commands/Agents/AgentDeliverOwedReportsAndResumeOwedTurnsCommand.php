<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentApplicationWording;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationOwnerReportDeliveryService;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\AIConversationRunClaimsService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * The agent-run recovery cron — one cron (about every five minutes, beside the reaper), self-clearing passes:
 *
 * Pass 1 — OWED REPORTS ({@see AIConversationsService::findConversationIdsOwingReports()}): a terminal conversation
 * stamped `reportOwedAt` whose inline delivery never happened (a worker crash between stamp and append, or the
 * reaper's set-based expiry, which delivers nothing itself) → the report is composed and delivered as the terminal
 * chat message and `reportDeliveredAt` is stamped ({@see AIConversationAgentService::deliverOwedReportLate()}). An
 * obligation whose agent space or account is no longer eligible is closed or deferred instead
 * ({@see AIConversationOwnerReportDeliveryService::handleReportObligationForIneligibleScope()}).
 *
 * Pass 2 — OWED TURNS ({@see AIConversationsService::findConversationIdsOwingContinuations()}): a chunk yield stamped
 * the {@see AIConversation::$agentTurnOwedAt} handover marker, but no worker ever claimed — the message that fires the
 * next chunk was lost. The newest rows decide: a trailing final assistant TEXT means the run ended (it raced a healthy
 * drive) → the marker is cleared; anything else means a turn is owed → it is resumed (roots and yielded delegation
 * children alike — the child settles into its batch, which wakes the parent through the normal fan-in).
 *
 * Pass 3 — LOST RESUMES ({@see AIConversationsService::findConversationIdsWithInboundLeftIdle()}): an owner message
 * landed, its resume was consumed by a worker that died before claiming, and the broker's redelivery was rejected —
 * the row sits IDLE with `lastInboundMessageAt` newer than `runHeartbeatAt` and no handover marker, so neither the
 * reaper nor pass 2 sees it. A trailing final assistant TEXT means answered; else the handover marker is stamped (so
 * pass 2 owns any further retry with its quiet window and starvation guard) and the conversation is resumed. The same
 * pass catches a turn that failed after its first heartbeat (counted attempts under the cap and a tail that still
 * owes a turn); a row a worker claimed meanwhile is never dispatched a second time.
 *
 * Then the application's own recovery passes ({@see runApplicationRecoveryPasses()}; the module has none).
 *
 * The lookups run with the entity rights lifted; each delivery and resume runs as the conversation's own account
 * ({@see actAsAccount()}), so the worker restores the right rights context. Two runs never overlap: a run that finds
 * the previous one still active ends at once.
 *
 *   php bin/console app:agent:deliver-owed-reports-and-resume-owed-turns
 *   php bin/console app:agent:deliver-owed-reports-and-resume-owed-turns --quiet-seconds=600 --batch=20 --dry-run
 */
#[AsCommand(
    name: 'app:agent:deliver-owed-reports-and-resume-owed-turns',
    aliases: ['app:ai-conversation:deliver-owed-reports-and-resume-owed-turns'],
    description: 'Deliver run reports still owed to the owner and resume runs that still owe a turn — both self-clearing.',
)]
class AgentDeliverOwedReportsAndResumeOwedTurnsCommand extends AgentConsoleCommand
{
    use LockableTrait;

    /** @var int The newest rows read to find the last wire message — bookkeeping rows after it are skipped by the walk */
    public const int NEWEST_ROWS_FOR_THE_LAST_WIRE_MESSAGE = 30;

    /** @var string The quiet window of the owed turns, in seconds */
    public const string OPTION_QUIET_SECONDS = 'quiet-seconds';

    /** @var string The candidate cap per pass */
    public const string OPTION_BATCH = 'batch';

    /** @var string Lists the candidates without delivering or resuming */
    public const string OPTION_DRY_RUN = 'dry-run';

    /** @var int Reports delivered in the current run */
    protected int $deliveredReportCount = 0;

    /** @var int Report obligations closed or deferred for an ineligible scope in the current run */
    protected int $suppressedReportCount = 0;

    /** @var int Owed turns resumed in the current run */
    protected int $resumedOwedTurnCount = 0;

    /** @var int Handover markers cleared in the current run */
    protected int $clearedMarkerCount = 0;

    /** @var int Lost resumes recovered in the current run */
    protected int $recoveredLostResumeCount = 0;

    protected function configure(): void
    {
        $this->addOption(
            self::OPTION_QUIET_SECONDS,
            null,
            InputOption::VALUE_OPTIONAL,
            'Owed-turns quiet window: a handover stamp older than this counts as a lost re-fire',
            (string)AIConversationsService::AGENT_TURN_OWED_QUIET_SECONDS
        );
        $this->addOption(
            self::OPTION_BATCH,
            null,
            InputOption::VALUE_OPTIONAL,
            'Per-pass candidate cap',
            (string)AIConversationsService::OWED_CONVERSATIONS_BATCH_LIMIT
        );
        $this->addOption(self::OPTION_DRY_RUN, null, InputOption::VALUE_NONE, 'List the candidates of all passes without delivering or re-firing');
    }

    /** The lookups and dispatches run in a cron without an authenticated account. */
    protected function liftsEntityRightsRestrictions(): bool
    {
        return true;
    }

    /**
     * Runs the passes in order under the lock of the command and prints the counts of the run.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws Throwable a failure of a candidate read ends the run (each candidate catches its own)
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        // a run slower than its interval must never overlap itself: both would select the same candidates
        if (!$this->lock()) {
            $output->writeln('Owed-run recovery: a previous run is still active — skipping this tick.');
            return Command::SUCCESS;
        }
        $quietSeconds = (int)$input->getOption(self::OPTION_QUIET_SECONDS) ?: AIConversationsService::AGENT_TURN_OWED_QUIET_SECONDS;
        $batchLimit = (int)$input->getOption(self::OPTION_BATCH) ?: AIConversationsService::OWED_CONVERSATIONS_BATCH_LIMIT;
        $dryRun = (bool)$input->getOption(self::OPTION_DRY_RUN);
        $this->deliveredReportCount = 0;
        $this->suppressedReportCount = 0;
        $this->resumedOwedTurnCount = 0;
        $this->clearedMarkerCount = 0;
        $this->recoveredLostResumeCount = 0;
        $aIConversationsService = $this->getAIConversationsService();
        try {
            foreach ($aIConversationsService->findConversationIdsOwingReports($batchLimit) as $owingConversationId) {
                $this->deliverOwedReport((int)$owingConversationId, $dryRun, $aIConversationsService, $output);
            }
            foreach ($aIConversationsService->findConversationIdsOwingContinuations($quietSeconds, $batchLimit) as $strandedConversationId) {
                $this->resumeOwedTurn((int)$strandedConversationId, $dryRun, $aIConversationsService, $output);
            }
            foreach ($aIConversationsService->findConversationIdsWithInboundLeftIdle($quietSeconds, $batchLimit) as $idleConversationId) {
                $this->recoverLostResume((int)$idleConversationId, $dryRun, $aIConversationsService, $output);
            }
            $this->runApplicationRecoveryPasses($batchLimit, $dryRun, $aIConversationsService, $output);
        } finally {
            $this->release();
        }
        $output->writeln($this->describeRunSummary());
        return Command::SUCCESS;
    }

    /**
     * The application's own recovery passes, run after the module's three under the lock, with the entity rights
     * lifted; each acts as the account it works for ({@see actAsAccount()}). The default: none.
     *
     * @param int $batchLimit the candidate cap per pass (`--batch`)
     * @param bool $dryRun true = list, write nothing (`--dry-run`)
     * @param AIConversationsService $aIConversationsService
     * @param OutputInterface $output
     * @return void
     * @throws Throwable
     */
    protected function runApplicationRecoveryPasses(int $batchLimit, bool $dryRun, AIConversationsService $aIConversationsService, OutputInterface $output): void
    {
    }

    /**
     * The counts of the application's own passes, each as a phrase of the summary line ("3 items repaired"). The
     * default: none.
     *
     * @return string[]
     */
    protected function describeApplicationRecoveryPassCounts(): array
    {
        return [];
    }

    /**
     * The summary line of the run: the module's counts, then the application's.
     *
     * @return string
     */
    protected function describeRunSummary(): string
    {
        $countPhrases = [
            "{$this->deliveredReportCount} report(s) delivered",
            "{$this->suppressedReportCount} obligation(s) closed/deferred for an inactive " . AgentApplicationWording::current()->billingEntityName . ' or account',
            "{$this->resumedOwedTurnCount} turn(s) re-fired",
            "{$this->clearedMarkerCount} marker(s) cleared",
            "{$this->recoveredLostResumeCount} lost resume(s) recovered",
            ...$this->describeApplicationRecoveryPassCounts(),
        ];
        return 'Owed-run recovery: ' . implode(', ', $countPhrases) . '.';
    }

    /**
     * Pass 1 for one conversation: closes or defers the obligation of an ineligible scope, else delivers the owed
     * report as the conversation's account. A failure pushes the conversation to the back of the queue, so one that
     * keeps failing never holds a head slot every tick (the selection orders by `reportOwedAt`).
     *
     * @param int $owingConversationId
     * @param bool $dryRun
     * @param AIConversationsService $aIConversationsService
     * @param OutputInterface $output
     * @return void
     */
    protected function deliverOwedReport(int $owingConversationId, bool $dryRun, AIConversationsService $aIConversationsService, OutputInterface $output): void
    {
        $aIConversationOwnerReportDeliveryService = $this->getAIConversationOwnerReportDeliveryService();
        $owingConversation = null;
        try {
            $owingConversation = $aIConversationsService->find($owingConversationId, useEntityRegistrCache: false);
            if (!$owingConversation instanceof AIConversation) {
                return; // row deleted between the id selection and the load
            }
            // the obligation's fate follows the state of the agent space and the account, read fresh before any claim
            if (!$dryRun && $aIConversationOwnerReportDeliveryService->handleReportObligationForIneligibleScope($owingConversation, claimHeld: false)) {
                $this->suppressedReportCount++;
                $output->writeln("OwedReports: conversation {$owingConversationId} — obligation " . (isset($owingConversation->suppressionReason) ? "closed ({$owingConversation->suppressionReason})" : 'deferred (scope suspended)'));
                return;
            }
            if ($dryRun) {
                $output->writeln("OwedReports (dry-run): conversation {$owingConversationId} owes a report");
                return;
            }
            if (!$this->actAsAccount($this->findAccountOfConversation($owingConversation))) {
                $output->writeln("<error>OwedReports: conversation {$owingConversationId} has no resolvable account — skipped (never deliver under a foreign impersonation)</error>");
                return;
            }
            $aIConversationAgentService = $this->getAIConversationAgentService();
            $aIConversationAgentService->deliverOwedReportLate($owingConversation);
            $this->deliveredReportCount++;
            $output->writeln("OwedReports: delivered the owed report for conversation {$owingConversationId}");
        } catch (Throwable $recoveryError) {
            $output->writeln("<error>OwedReports: failed for conversation {$owingConversationId}: {$recoveryError->getMessage()}</error>");
            try {
                if ($owingConversation instanceof AIConversation) {
                    $aIConversationOwnerReportDeliveryService->deferOwedReport($owingConversation);
                }
            } catch (Throwable) {
                // best effort — the next tick retries regardless
            }
        }
    }

    /**
     * Pass 2 for one conversation: clears the marker of a run that ended, else resumes the owed turn as the
     * conversation's account. A failure stamps the marker again, which restarts the quiet window, so a conversation
     * that keeps failing cannot occupy the batch every tick.
     *
     * @param int $strandedConversationId
     * @param bool $dryRun
     * @param AIConversationsService $aIConversationsService
     * @param OutputInterface $output
     * @return void
     */
    protected function resumeOwedTurn(int $strandedConversationId, bool $dryRun, AIConversationsService $aIConversationsService, OutputInterface $output): void
    {
        $strandedConversation = null;
        try {
            $strandedConversation = $aIConversationsService->find($strandedConversationId, useEntityRegistrCache: false);
            if (!$strandedConversation instanceof AIConversation) {
                return; // row deleted between the id selection and the load
            }
            $this->loadNewestMessagesOfConversation($strandedConversation);
            if ($strandedConversation->lastWireMessageIsFinalAssistantText()) {
                // raced a healthy drive that finished before the load — nothing owed
                if (!$dryRun) {
                    $aIConversationsService->clearAgentTurnOwed($strandedConversation);
                }
                $this->clearedMarkerCount++;
                $output->writeln("OwedTurns: conversation {$strandedConversationId} ended normally — cleared the handover marker");
                return;
            }
            if ($dryRun) {
                $output->writeln("OwedTurns (dry-run): conversation {$strandedConversationId} owes a turn");
                return;
            }
            if (!$this->actAsAccount($this->findAccountOfConversation($strandedConversation))) {
                $output->writeln("<error>OwedTurns: conversation {$strandedConversationId} has no resolvable account — skipped (never re-fire under a foreign impersonation)</error>");
                return;
            }
            $aIConversationAgentService = $this->getAIConversationAgentService();
            $aIConversationAgentService->resumeConversationIfReady($strandedConversation, async: true);
            $this->resumedOwedTurnCount++;
            $output->writeln("OwedTurns: re-fired the lost continuation for conversation {$strandedConversationId}");
        } catch (Throwable $recoveryError) {
            $output->writeln("<error>OwedTurns: failed for conversation {$strandedConversationId}: {$recoveryError->getMessage()}</error>");
            try {
                if ($strandedConversation instanceof AIConversation) {
                    $aIConversationsService->deferOwedTurn($strandedConversation);
                }
            } catch (Throwable) {
                // best effort — the next expiry of the quiet window retries regardless
            }
        }
    }

    /**
     * Pass 3 for one conversation: resumes a conversation whose resume was lost, as its account, after stamping the
     * handover marker — should this resume be lost too, pass 2 owns the retry from then on (quiet window, starvation
     * guard, the attempt cap of the loop) instead of this pass resuming every tick.
     *
     * @param int $idleConversationId
     * @param bool $dryRun
     * @param AIConversationsService $aIConversationsService
     * @param OutputInterface $output
     * @return void
     */
    protected function recoverLostResume(int $idleConversationId, bool $dryRun, AIConversationsService $aIConversationsService, OutputInterface $output): void
    {
        try {
            $idleConversation = $aIConversationsService->find($idleConversationId, useEntityRegistrCache: false);
            if (!$idleConversation instanceof AIConversation || $idleConversation->isClosed()) {
                return;
            }
            if (!$this->isLostResumeStillOwed($idleConversation)) {
                return;
            }
            if ($dryRun) {
                $output->writeln("LostResumes (dry-run): conversation {$idleConversationId} has an unanswered owner message and no owed marker");
                return;
            }
            if (!$this->actAsAccount($this->findAccountOfConversation($idleConversation))) {
                $output->writeln("<error>LostResumes: conversation {$idleConversationId} has no resolvable account — skipped (never re-fire under a foreign impersonation)</error>");
                return;
            }
            $aIConversationsService->deferOwedTurn($idleConversation);
            $aIConversationAgentService = $this->getAIConversationAgentService();
            $aIConversationAgentService->resumeConversationIfReady($idleConversation, async: true);
            $this->recoveredLostResumeCount++;
            $output->writeln("LostResumes: re-fired the lost resume for conversation {$idleConversationId}");
        } catch (Throwable $recoveryError) {
            $output->writeln("<error>LostResumes: failed for conversation {$idleConversationId}: {$recoveryError->getMessage()}</error>");
        }
    }

    /**
     * Whether an idle conversation pass 3 selected still owes a turn: its newest wire message is no final assistant
     * text (else the answer landed after the last heartbeat — the coarse selection's usual false positive), a row with
     * counted attempts still has an unanswered inbound message (a resume that finds no work releases with the count
     * unchanged, so the next tick would select the row again), and no worker claimed it since the selection.
     *
     * @param AIConversation $idleConversation
     * @return bool
     * @throws Throwable
     */
    protected function isLostResumeStillOwed(AIConversation $idleConversation): bool
    {
        $this->loadNewestMessagesOfConversation($idleConversation);
        if ($idleConversation->lastWireMessageIsFinalAssistantText()) {
            return false;
        }
        if ($idleConversation->resumeAttempts > 0 && !$idleConversation->hasUnansweredInboundMessage()) {
            return false;
        }
        $aIConversationRunClaimsService = $this->getAIConversationRunClaimsService();
        return $idleConversation->runState === AIConversationRunState::IDLE && !$aIConversationRunClaimsService->hasLiveRunClaim($idleConversation);
    }

    /**
     * Puts the newest rows of `$conversation` on it — the last wire message is read from them, never from the context
     * tail (empty once finalize compacted the conversation to completion) nor from the whole history.
     *
     * @param AIConversation $conversation
     * @return void
     * @throws Throwable
     */
    protected function loadNewestMessagesOfConversation(AIConversation $conversation): void
    {
        $aIConversationMessagesService = $this->getAIConversationMessagesService();
        $conversation->messages = $aIConversationMessagesService->findNewestMessages($conversation, self::NEWEST_ROWS_FOR_THE_LAST_WIRE_MESSAGE);
    }

    /**
     * The account of `$conversation` (it may load it); null when it has none.
     *
     * @param AIConversation $conversation
     * @return Account|null
     */
    protected function findAccountOfConversation(AIConversation $conversation): ?Account
    {
        $account = $conversation->account ?? null;
        return $account instanceof Account ? $account : null;
    }

    /** @return AIConversationsService the conversations service the container returns (the application's class, when it replaces it) */
    protected function getAIConversationsService(): AIConversationsService
    {
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
        return $aIConversationsService;
    }

    /** @return AIConversationAgentService the agent service the container returns */
    protected function getAIConversationAgentService(): AIConversationAgentService
    {
        /** @var AIConversationAgentService $aIConversationAgentService */
        $aIConversationAgentService = DDDService::instance()->getService(AIConversationAgentService::class);
        return $aIConversationAgentService;
    }

    /** @return AIConversationOwnerReportDeliveryService the owner report delivery service the container returns */
    protected function getAIConversationOwnerReportDeliveryService(): AIConversationOwnerReportDeliveryService
    {
        /** @var AIConversationOwnerReportDeliveryService $aIConversationOwnerReportDeliveryService */
        $aIConversationOwnerReportDeliveryService = DDDService::instance()->getService(AIConversationOwnerReportDeliveryService::class);
        return $aIConversationOwnerReportDeliveryService;
    }

    /** @return AIConversationRunClaimsService the run claims service the container returns */
    protected function getAIConversationRunClaimsService(): AIConversationRunClaimsService
    {
        /** @var AIConversationRunClaimsService $aIConversationRunClaimsService */
        $aIConversationRunClaimsService = DDDService::instance()->getService(AIConversationRunClaimsService::class);
        return $aIConversationRunClaimsService;
    }

    /** @return AIConversationMessagesService the messages service the container returns */
    protected function getAIConversationMessagesService(): AIConversationMessagesService
    {
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        return $aIConversationMessagesService;
    }
}
