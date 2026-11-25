<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\MessageHandlers\AIConversationResumeMessage;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationNotificationsService;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Domain\AI\Services\Conversations\AIConversationDelegationBatchesService;
use DDD\Domain\AI\Services\Conversations\AIConversationRunClaimsService;
use DDD\Domain\AI\Entities\Voice\AgentVoiceConfiguration;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallsService;
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
 * The tree-wide reaper — a cron (about every five minutes) that recovers agent conversations stuck NON-TERMINAL
 * (RUNNING / AWAITING_DELEGATES) past the hung-heartbeat threshold: a crashed worker, or a dropped
 * {@see AIConversationResumeMessage}. For each stuck conversation it dispatches a fresh resume; the worker's
 * {@see AIConversationRunClaimsService::claimRun()} reclaims the row through its staleness clause
 * (`runHeartbeatAt < :hungBefore`) and drives it again. Tree-wide: a delegate whose worker died mid-loop is run again
 * too, not only parents awaiting fan-in — running it again rebuilds its loop, which then wakes its parent.
 *
 * Revival grace window: a conversation stuck for longer than `--revive-max-age` is not revived but expired to FAILED
 * ({@see AIConversationsService::expireStuckConversationsPastMaxAge()}): its task is assumed irrelevant by then, and
 * without the bound the cron would wake an unrecoverable conversation forever.
 *
 * The passes, in this order: expiry; stuck conversations; timed-out delegation batches; the stale sessions of the
 * channels ({@see closeStaleChannelSessions()}: the voice calls that lost their session worker, while the voice
 * channel is switched on; an application adds the sessions of its own channels); the settle pass over idle purposed
 * conversations whose goal is reached; settled batches whose parent never folded the result; sub-agent runs that
 * ended without their notification. A pass that fails is reported and the next one runs.
 *
 * Every resume carries the conversation's OWN account, so the worker restores the right rights context: the command
 * acts as that account around each dispatch ({@see actAsAccount()}) and skips a conversation whose account it cannot
 * resolve — it never dispatches under the account of the step before. The lookups run with the entity rights lifted.
 *
 *   php bin/console app:agent:reap
 *   php bin/console app:agent:reap --hung-after=600 --revive-max-age=3600
 *   php bin/console app:agent:reap --settle-only=<conversationId>   (settle pass for ONE purposed conversation)
 */
#[AsCommand(
    name: 'app:agent:reap',
    aliases: ['app:ai-agent:reap'],
    description: 'Reaper: wake agent conversations stuck non-terminal past the hung threshold (crashed worker / dropped resume).',
)]
class AgentReapStuckConversationsCommand extends AgentConsoleCommand
{
    use LockableTrait;

    /**
     * @var string The lock of the stale voice-call sweep: one sweep at a time, whichever run of the reaper holds it.
     * The other passes take no lock (each is safe against a second run through its own claim).
     */
    public const string STALE_VOICE_CALLS_LOCK_NAME = 'app:agent:reap:stale-voice-calls';

    /**
     * @var int A purposed conversation must have been IDLE this long before the settle pass runs its settled-turn close
     * path again — long enough that a turn's own settle (which runs the same path) had its chance.
     */
    public const int PURPOSE_SETTLE_QUIET_SECONDS = 600;

    /** @var string Seconds without a heartbeat before a non-terminal conversation counts as hung */
    public const string OPTION_HUNG_AFTER = 'hung-after';

    /** @var string The revival grace window in seconds */
    public const string OPTION_REVIVE_MAX_AGE = 'revive-max-age';

    /** @var string Runs only the settle pass, for one conversation id */
    public const string OPTION_SETTLE_ONLY = 'settle-only';

    /** @var int The resumes dispatched in the current run */
    protected int $dispatchedResumeCount = 0;

    /** @var int The delegation batches timed out in the current run */
    protected int $timedOutBatchCount = 0;

    protected function configure(): void
    {
        $this->addOption(
            self::OPTION_HUNG_AFTER,
            null,
            InputOption::VALUE_OPTIONAL,
            'Seconds without a heartbeat before a non-terminal conversation is considered hung',
            (string)AIConversationRunClaimsService::RUN_HUNG_AFTER_SECONDS
        );
        $this->addOption(
            self::OPTION_REVIVE_MAX_AGE,
            null,
            InputOption::VALUE_OPTIONAL,
            'Revival grace window in seconds: a conversation stuck longer than this is expired to FAILED instead of revived',
            (string)AIConversationsService::RUN_REVIVE_MAX_AGE_SECONDS
        );
        $this->addOption(
            self::OPTION_SETTLE_ONLY,
            null,
            InputOption::VALUE_REQUIRED,
            'Run ONLY the purposed-conversation settle pass (reconcile → complete durably → close) for this one conversation id, skipping every other sweep'
        );
    }

    /** The lookups and dispatches run in a cron without an authenticated account. */
    protected function liftsEntityRightsRestrictions(): bool
    {
        return true;
    }

    /**
     * Runs the settle pass for `--settle-only`, else every pass in order, and prints the counts of the run.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws Throwable a failure of the expiry or of the candidate reads ends the run (the passes catch their own)
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        $hungAfterSeconds = (int)$input->getOption(self::OPTION_HUNG_AFTER) ?: AIConversationRunClaimsService::RUN_HUNG_AFTER_SECONDS;
        $reviveMaxAgeSeconds = (int)$input->getOption(self::OPTION_REVIVE_MAX_AGE) ?: AIConversationsService::RUN_REVIVE_MAX_AGE_SECONDS;
        $settleOnlyConversationId = $input->getOption(self::OPTION_SETTLE_ONLY) !== null ? (int)$input->getOption(self::OPTION_SETTLE_ONLY) : null;
        $this->dispatchedResumeCount = 0;
        $this->timedOutBatchCount = 0;
        $aIConversationsService = $this->getAIConversationsService();

        if ($settleOnlyConversationId !== null) {
            // targeted settle (operations): one purposed conversation whose settled-turn close was missed — the path
            // the periodic settle pass runs, without touching anything else
            $closedCount = $this->settlePurposedConversations([$settleOnlyConversationId], $aIConversationsService, $output);
            $output->writeln("Reaper: settle-only pass for conversation {$settleOnlyConversationId} — closed={$closedCount}.");
            return Command::SUCCESS;
        }

        // stuck LONGER than the window → FAILED, not revived; runs BEFORE the stuck query, which leaves out anything
        // past the window
        $expiredConversationIds = $aIConversationsService->expireStuckConversationsPastMaxAge($reviveMaxAgeSeconds);
        foreach ($expiredConversationIds as $expiredConversationId) {
            $output->writeln("Reaper: expired conversation {$expiredConversationId} (stuck past revive-max-age) → FAILED");
        }

        $aIConversationDelegationBatchesService = $this->getAIConversationDelegationBatchesService();
        $stuckConversationIds = $aIConversationsService->findStuckConversationIds($hungAfterSeconds, $reviveMaxAgeSeconds);
        $timedOutBatchIds = $aIConversationDelegationBatchesService->findTimedOutUnfinishedBatchIds();
        $settledUnfoldedParentIds = $aIConversationDelegationBatchesService->findParentConversationIdsWithUnreadChildResults();

        $this->wakeStuckConversations($stuckConversationIds, $aIConversationsService, $output);
        $this->timeOutBatches($timedOutBatchIds, $aIConversationDelegationBatchesService, $aIConversationsService, $output);
        $this->runStaleChannelSessionsPass($aIConversationsService, $output);
        $this->runPurposedConversationsSettlePass($aIConversationsService, $output);
        $this->wakeSettledUnfoldedParents($settledUnfoldedParentIds, $aIConversationsService, $output);
        $this->notifyUnreportedSubAgentRunEnds($aIConversationDelegationBatchesService, $aIConversationsService, $output);

        $output->writeln(sprintf(
            'Reaper: dispatched %d resume(s); stuck=%d, timed-out batches=%d, settled-unfolded=%d, expired=%d.',
            $this->dispatchedResumeCount,
            count($stuckConversationIds),
            $this->timedOutBatchCount,
            count($settledUnfoldedParentIds),
            count($expiredConversationIds)
        ));
        return Command::SUCCESS;
    }

    /**
     * Closes the stale sessions of the channels: runs after the batch timeouts and before the settle pass, with the
     * entity rights lifted. A failure that escapes is reported by the caller and the next pass runs. The default: the
     * voice calls that lost their session worker ({@see closeStaleVoiceCalls()}). An application with channel sessions
     * of its own closes them in an override that calls the parent.
     *
     * @param AIConversationsService $aIConversationsService
     * @param OutputInterface $output
     * @return void
     * @throws Throwable
     */
    protected function closeStaleChannelSessions(AIConversationsService $aIConversationsService, OutputInterface $output): void
    {
        $this->closeStaleVoiceCalls($output);
    }

    /**
     * The sweep of the voice calls that lost their session worker
     * ({@see AgentVoiceCallsService::closeStaleRunningCalls()}): a call still marked running
     * {@see AgentVoiceCallsService::getRunningCallStaleAfterMinutes()} minutes after its answer is hung up at its
     * provider and ended as {@see AgentVoiceCallsService::END_REASON_REAPED_STALE}. Prints one line per closed call
     * and one line with the count.
     *
     * While the voice channel is switched off (`AI.agentVoice.enabled`) nothing is read and nothing is printed: the
     * call table is not required then. The switch alone is asked, not whether voice can run in the installation: the
     * sweep needs the call store and the provider's control request, never the session worker's library, and a call
     * left running is closed in a process without the library as well.
     *
     * One sweep at a time ({@see self::STALE_VOICE_CALLS_LOCK_NAME}): the end of a call is written without a claim,
     * so two sweeps over the same calls would write each call end twice. A run that finds the lock held says so and
     * goes on with the next pass.
     *
     * @param OutputInterface $output
     * @return void
     * @throws Throwable a sweep that fails; the calls closed before the failure stay closed, the next run takes the rest
     */
    protected function closeStaleVoiceCalls(OutputInterface $output): void
    {
        if (!AgentVoiceConfiguration::current()->enabled) {
            return;
        }
        if (!$this->lock(static::STALE_VOICE_CALLS_LOCK_NAME)) {
            $output->writeln('Reaper: stale voice-call sweep skipped (a previous run is still closing calls).');
            return;
        }
        try {
            $agentVoiceCallsService = $this->getAgentVoiceCallsService();
            $closedVoiceCalls = $agentVoiceCallsService->closeStaleRunningCalls();
            foreach ($closedVoiceCalls as $closedVoiceCall) {
                $output->writeln($this->buildClosedStaleVoiceCallLine($closedVoiceCall));
            }
            $output->writeln(sprintf(
                'Reaper: closed %d stale voice call(s) (still marked running %d minutes after their answer).',
                count($closedVoiceCalls),
                $agentVoiceCallsService->getRunningCallStaleAfterMinutes()
            ));
        } finally {
            $this->release();
        }
    }

    /**
     * The line of one call the stale voice-call sweep closed: its id, its conversation, its provider session and the
     * moment it was answered, in UTC whatever the time zone of the process.
     *
     * @param AgentVoiceCall $closedVoiceCall
     * @return string
     */
    protected function buildClosedStaleVoiceCallLine(AgentVoiceCall $closedVoiceCall): string
    {
        $answeredAt = $closedVoiceCall->startedAt ?? null;
        return sprintf(
            'Reaper: closed stale voice call %s (conversation %s, provider session %s, answered %s)',
            $closedVoiceCall->id ?? 'unknown',
            $closedVoiceCall->aiConversationId ?? 'none',
            $closedVoiceCall->providerCallId ?? 'none',
            $answeredAt !== null ? gmdate('Y-m-d\TH:i:s\Z', $answeredAt->getTimestamp()) : 'unknown'
        );
    }

    /**
     * Dispatches a fresh resume for every stuck conversation (a crashed worker, a dropped resume); the worker's claim
     * reclaims the stale row.
     *
     * @param int[] $stuckConversationIds
     * @param AIConversationsService $aIConversationsService
     * @param OutputInterface $output
     * @return void
     */
    protected function wakeStuckConversations(array $stuckConversationIds, AIConversationsService $aIConversationsService, OutputInterface $output): void
    {
        foreach ($stuckConversationIds as $stuckConversationId) {
            try {
                $stuckConversation = $aIConversationsService->find($stuckConversationId);
                if (!$this->actAsAccount($this->findAccountOfConversation($stuckConversation))) {
                    $output->writeln("<error>Reaper: conversation {$stuckConversationId} has no resolvable account — skipped (never dispatch under a foreign impersonation)</error>");
                    continue;
                }
                $this->dispatchResumeOfConversation((int)$stuckConversationId);
                $this->dispatchedResumeCount++;
                $output->writeln("Reaper: dispatched resume for stuck conversation {$stuckConversationId}");
            } catch (Throwable $reapError) {
                $output->writeln("<error>Reaper: failed to wake conversation {$stuckConversationId}: {$reapError->getMessage()}</error>");
            }
        }
    }

    /**
     * Timed-out delegation batches: force-fails their still-active members and settles the batch, then wakes the
     * parent to fold the settled result, so a barrier with a crashed or starved child stops hanging. The timeout of a
     * sub-agent run writes the run end's notification and may resume the parent through it, so it runs under the
     * parent's account.
     *
     * @param int[] $timedOutBatchIds
     * @param AIConversationDelegationBatchesService $aIConversationDelegationBatchesService
     * @param AIConversationsService $aIConversationsService
     * @param OutputInterface $output
     * @return void
     */
    protected function timeOutBatches(
        array $timedOutBatchIds,
        AIConversationDelegationBatchesService $aIConversationDelegationBatchesService,
        AIConversationsService $aIConversationsService,
        OutputInterface $output
    ): void {
        foreach ($timedOutBatchIds as $timedOutBatchId) {
            try {
                $deadlineParentConversation = $this->findParentConversationOfBatch((int)$timedOutBatchId, $aIConversationDelegationBatchesService, $aIConversationsService);
                if (!$this->actAsAccount($this->findAccountOfConversation($deadlineParentConversation))) {
                    $output->writeln("<error>Reaper: batch {$timedOutBatchId} has no parent with a resolvable account — skipped (foreign-impersonation guard)</error>");
                    continue;
                }
                $parentConversationId = $aIConversationDelegationBatchesService->timeoutBatch((int)$timedOutBatchId);
                $this->timedOutBatchCount++;
                if ($parentConversationId === null) {
                    continue;
                }
                $parentConversation = $aIConversationsService->find($parentConversationId);
                if (!$this->actAsAccount($this->findAccountOfConversation($parentConversation))) {
                    $output->writeln("<error>Reaper: parent {$parentConversationId} has no resolvable account — batch timed out, wake skipped (foreign-impersonation guard)</error>");
                    continue;
                }
                $this->dispatchResumeOfConversation($parentConversationId);
                $this->dispatchedResumeCount++;
                $output->writeln("Reaper: timed out batch {$timedOutBatchId} → woke parent {$parentConversationId}");
            } catch (Throwable $reapError) {
                $output->writeln("<error>Reaper: failed to time out batch {$timedOutBatchId}: {$reapError->getMessage()}</error>");
            }
        }
    }

    /**
     * Runs {@see closeStaleChannelSessions()}; a failure is reported and the next pass runs.
     *
     * @param AIConversationsService $aIConversationsService
     * @param OutputInterface $output
     * @return void
     */
    protected function runStaleChannelSessionsPass(AIConversationsService $aIConversationsService, OutputInterface $output): void
    {
        try {
            $this->closeStaleChannelSessions($aIConversationsService, $output);
        } catch (Throwable $reapError) {
            $output->writeln("<error>Reaper: stale channel-session sweep failed: {$reapError->getMessage()}</error>");
        }
    }

    /**
     * Purposed conversations whose goal is reached but which were never made durable or closed (the turn that reaches
     * the goal can be the last owner turn, so a failed finalize would wait for a turn that never comes): runs the
     * settled-turn path again — reconcile → complete durably → close — for the purposes the purpose class names
     * ({@see AIConversationPurpose::getPurposeNamesClosedWithoutAnOwnerTurn()}). Idempotent; bounded per run.
     *
     * @param AIConversationsService $aIConversationsService
     * @param OutputInterface $output
     * @return void
     */
    protected function runPurposedConversationsSettlePass(AIConversationsService $aIConversationsService, OutputInterface $output): void
    {
        try {
            foreach ($this->getPurposeNamesClosedWithoutAnOwnerTurn() as $purposeNameClosedWithoutAnOwnerTurn) {
                $this->settlePurposedConversations(
                    $aIConversationsService->findQuietIdlePurposedConversationIds($purposeNameClosedWithoutAnOwnerTurn, self::PURPOSE_SETTLE_QUIET_SECONDS),
                    $aIConversationsService,
                    $output
                );
            }
        } catch (Throwable $reapError) {
            $output->writeln("<error>Reaper: purposed-conversation settle sweep failed: {$reapError->getMessage()}</error>");
        }
    }

    /**
     * Settled batches whose parent never folded the result (the wake was lost between settle and dispatch): wakes the
     * parent again so it folds.
     *
     * @param int[] $settledUnfoldedParentIds
     * @param AIConversationsService $aIConversationsService
     * @param OutputInterface $output
     * @return void
     */
    protected function wakeSettledUnfoldedParents(array $settledUnfoldedParentIds, AIConversationsService $aIConversationsService, OutputInterface $output): void
    {
        foreach ($settledUnfoldedParentIds as $settledUnfoldedParentId) {
            try {
                $parentConversation = $aIConversationsService->find($settledUnfoldedParentId);
                if (!$this->actAsAccount($this->findAccountOfConversation($parentConversation))) {
                    $output->writeln("<error>Reaper: parent {$settledUnfoldedParentId} has no resolvable account — re-fire skipped (foreign-impersonation guard)</error>");
                    continue;
                }
                $this->dispatchResumeOfConversation((int)$settledUnfoldedParentId);
                $this->dispatchedResumeCount++;
                $output->writeln("Reaper: re-fired settled-but-unfolded parent {$settledUnfoldedParentId}");
            } catch (Throwable $reapError) {
                $output->writeln("<error>Reaper: failed to re-fire parent {$settledUnfoldedParentId}: {$reapError->getMessage()}</error>");
            }
        }
    }

    /**
     * Sub-agent runs that ended without their notification (a crash between the run end and its notifier): the
     * notifier runs again; its compare-and-set makes a second notification impossible.
     *
     * @param AIConversationDelegationBatchesService $aIConversationDelegationBatchesService
     * @param AIConversationsService $aIConversationsService
     * @param OutputInterface $output
     * @return void
     */
    protected function notifyUnreportedSubAgentRunEnds(
        AIConversationDelegationBatchesService $aIConversationDelegationBatchesService,
        AIConversationsService $aIConversationsService,
        OutputInterface $output
    ): void {
        foreach ($aIConversationDelegationBatchesService->findEndedUnreportedSubAgentRunDeadlineIds() as $unreportedDeadlineId) {
            try {
                $unreportedParentConversation = $this->findParentConversationOfBatch((int)$unreportedDeadlineId, $aIConversationDelegationBatchesService, $aIConversationsService);
                if (!$this->actAsAccount($this->findAccountOfConversation($unreportedParentConversation))) {
                    $output->writeln("<error>Reaper: deadline {$unreportedDeadlineId} has no parent with a resolvable account — skipped (foreign-impersonation guard)</error>");
                    continue;
                }
                $aIConversationDelegationNotificationsService = $this->getAIConversationDelegationNotificationsService();
                if ($aIConversationDelegationNotificationsService->notifyParentOfSubAgentRunEndOfDeadline((int)$unreportedDeadlineId)) {
                    $output->writeln("Reaper: wrote the lost run-end notification of deadline {$unreportedDeadlineId}");
                }
            } catch (Throwable $reapError) {
                $output->writeln("<error>Reaper: failed to notify the run end of deadline {$unreportedDeadlineId}: {$reapError->getMessage()}</error>");
            }
        }
    }

    /**
     * The settle pass over purposed conversations: each is fed through the settled-turn close path under its own
     * account. A conversation that stays open is reported as such — the path itself decides (goal not reached,
     * completion not durable yet, supervisor work pending).
     *
     * @param int[] $conversationIds
     * @param AIConversationsService $aIConversationsService
     * @param OutputInterface $output
     * @return int how many ended up closed
     */
    protected function settlePurposedConversations(array $conversationIds, AIConversationsService $aIConversationsService, OutputInterface $output): int
    {
        $aIConversationAgentService = $this->getAIConversationAgentService();
        $closedCount = 0;
        foreach ($conversationIds as $conversationId) {
            try {
                $conversation = $aIConversationsService->find($conversationId);
                if (!$conversation instanceof AIConversation) {
                    $output->writeln("<comment>Reaper: conversation {$conversationId} not found — settle skipped</comment>");
                    continue;
                }
                if (!$this->actAsAccount($this->findAccountOfConversation($conversation))) {
                    $output->writeln("<error>Reaper: conversation {$conversationId} has no resolvable account — settle skipped (foreign-impersonation guard)</error>");
                    continue;
                }
                $aIConversationAgentService->settlePurposeConversationIfGoalReached($conversation);
                if ($conversation->isClosed()) {
                    $closedCount++;
                    $output->writeln("Reaper: settled purposed conversation {$conversationId} → closed");
                } else {
                    $output->writeln("Reaper: purposed conversation {$conversationId} left open (goal not reached, completion not durable yet, or supervisor work pending)");
                }
            } catch (Throwable $settleError) {
                $output->writeln("<error>Reaper: settle of conversation {$conversationId} failed: {$settleError->getMessage()}</error>");
            }
        }
        return $closedCount;
    }

    /**
     * The purposes whose reached goal the settle pass closes without an owner turn (the application's purpose class
     * names them).
     *
     * @return string[]
     */
    protected function getPurposeNamesClosedWithoutAnOwnerTurn(): array
    {
        return AIConversationPurpose::getPurposeNamesClosedWithoutAnOwnerTurn();
    }

    /**
     * The parent conversation of a delegation batch (a sub-agent deadline is a batch row too).
     *
     * @param int $batchId
     * @param AIConversationDelegationBatchesService $aIConversationDelegationBatchesService
     * @param AIConversationsService $aIConversationsService
     * @return AIConversation|null null = no batch, no parent, or the parent is gone
     * @throws Throwable
     */
    protected function findParentConversationOfBatch(
        int $batchId,
        AIConversationDelegationBatchesService $aIConversationDelegationBatchesService,
        AIConversationsService $aIConversationsService
    ): ?AIConversation {
        $parentConversationId = $aIConversationDelegationBatchesService->find($batchId)->parentConversationId ?? null;
        $parentConversation = $parentConversationId !== null ? $aIConversationsService->find((int)$parentConversationId) : null;
        return $parentConversation instanceof AIConversation ? $parentConversation : null;
    }

    /**
     * The account of `$conversation` (it may load it); null when there is no conversation or no account.
     *
     * @param mixed $conversation what the conversations service found
     * @return Account|null
     */
    protected function findAccountOfConversation(mixed $conversation): ?Account
    {
        if (!$conversation instanceof AIConversation) {
            return null;
        }
        $account = $conversation->account ?? null;
        return $account instanceof Account ? $account : null;
    }

    /**
     * Dispatches a resume of `$conversationId`; it carries the account authenticated now.
     *
     * @param int $conversationId
     * @return void
     */
    protected function dispatchResumeOfConversation(int $conversationId): void
    {
        (new AIConversationResumeMessage($conversationId))->dispatch();
    }

    /** @return AgentVoiceCallsService the voice calls service the container returns */
    protected function getAgentVoiceCallsService(): AgentVoiceCallsService
    {
        /** @var AgentVoiceCallsService $agentVoiceCallsService */
        $agentVoiceCallsService = DDDService::instance()->getService(AgentVoiceCallsService::class);
        return $agentVoiceCallsService;
    }

    /** @return AIConversationsService the conversations service the container returns (the application's class, when it replaces it) */
    protected function getAIConversationsService(): AIConversationsService
    {
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
        return $aIConversationsService;
    }

    /** @return AIConversationDelegationBatchesService the delegation batches service the container returns */
    protected function getAIConversationDelegationBatchesService(): AIConversationDelegationBatchesService
    {
        /** @var AIConversationDelegationBatchesService $aIConversationDelegationBatchesService */
        $aIConversationDelegationBatchesService = DDDService::instance()->getService(AIConversationDelegationBatchesService::class);
        return $aIConversationDelegationBatchesService;
    }

    /** @return AIConversationAgentService the agent service the container returns */
    protected function getAIConversationAgentService(): AIConversationAgentService
    {
        /** @var AIConversationAgentService $aIConversationAgentService */
        $aIConversationAgentService = DDDService::instance()->getService(AIConversationAgentService::class);
        return $aIConversationAgentService;
    }

    /** @return AIConversationDelegationNotificationsService the run-end notifier of the sub-agents the container returns */
    protected function getAIConversationDelegationNotificationsService(): AIConversationDelegationNotificationsService
    {
        /** @var AIConversationDelegationNotificationsService $aIConversationDelegationNotificationsService */
        $aIConversationDelegationNotificationsService = DDDService::instance()->getService(AIConversationDelegationNotificationsService::class);
        return $aIConversationDelegationNotificationsService;
    }
}
