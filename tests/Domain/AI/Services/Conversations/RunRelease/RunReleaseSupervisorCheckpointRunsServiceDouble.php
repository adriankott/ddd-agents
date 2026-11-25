<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\RunRelease;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Supervisor\SupervisorCheckpointTrigger;
use DDD\Domain\AI\Services\Conversations\Agent\AgentModelCallRunnerInterface;
use DDD\Domain\AI\Services\Conversations\Agent\SupervisorCheckpointRunsService;
use DDD\Domain\AI\Services\Conversations\SupervisorCheckpointService;
use Throwable;

/**
 * The supervisor of the release stage. The tandem's turn, the settled-turn checkpoint dispatch and the re-fire of a
 * waiting intervention are recorded and answer or throw as scripted; the tandem's reads of its supervised conversation
 * and of a still owed beat are scripted too. The re-fire of the tandem itself ({@see resumeTandemIfABeatIsStillOwed()})
 * runs for real and reaches the container's agent service. Steers are never materialized.
 */
class RunReleaseSupervisorCheckpointRunsServiceDouble extends SupervisorCheckpointRunsService
{
    public RunReleaseEventLog $eventLog;

    /** The answer of the settled-turn checkpoint dispatch. */
    public bool $checkpointDispatchedAnswer = false;

    /** Thrown by the settled-turn checkpoint dispatch. */
    public ?Throwable $checkpointDispatchFailure = null;

    /** Thrown by the re-fire of a waiting intervention. */
    public ?Throwable $interventionReFireFailure = null;

    /** The answer of the re-fire of a waiting intervention (true: one waited and was resumed). */
    public bool $interventionWaitsAnswer = false;

    /** The supervised conversation the tandem reads (null: none found). */
    public ?AIConversation $supervisedConversation = null;

    /** Thrown by the tandem's read of its supervised conversation. */
    public ?Throwable $supervisedConversationLoadFailure = null;

    /** Whether a beat is still owed after the tandem's release. */
    public bool $beatStillOwedAnswer = false;

    /** Thrown by the tandem's "a beat is still owed" read. */
    public ?Throwable $beatStillOwedFailure = null;

    public function __construct(RunReleaseEventLog $eventLog)
    {
        // The parent service is container-managed and holds no constructor state.
        $this->eventLog = $eventLog;
    }

    public function materializeUnconsumedSupervisorSteers(AIConversation $conversation): void
    {
    }

    public function runSupervisorCheckpointTurn(AgentRun $agentRun, AgentModelCallRunnerInterface $agentModelCallRunner, AIConversation $conversation, ?string $runToken): void
    {
        $this->eventLog->record('runSupervisorCheckpointTurn');
    }

    public function dispatchSupervisorCheckpointIfDue(AIConversation $conversation): bool
    {
        if ($this->checkpointDispatchFailure !== null) {
            $this->eventLog->record('dispatchSupervisorCheckpointIfDue threw');
            throw $this->checkpointDispatchFailure;
        }
        $this->eventLog->record('dispatchSupervisorCheckpointIfDue answer=' . ($this->checkpointDispatchedAnswer ? 'yes' : 'no'));
        return $this->checkpointDispatchedAnswer;
    }

    public function resumeSupervisedConversationIfAnInterventionWaits(AIConversation $conversation): bool
    {
        if ($this->interventionReFireFailure !== null) {
            $this->eventLog->record('resumeSupervisedConversationIfAnInterventionWaits threw');
            throw $this->interventionReFireFailure;
        }
        $this->eventLog->record('resumeSupervisedConversationIfAnInterventionWaits answer=' . ($this->interventionWaitsAnswer ? 'yes' : 'no'));
        return $this->interventionWaitsAnswer;
    }

    public function loadSupervisedConversationOf(AIConversation $supervisorConversation): ?AIConversation
    {
        if ($this->supervisedConversationLoadFailure !== null) {
            $this->eventLog->record('loadSupervisedConversationOf threw');
            throw $this->supervisedConversationLoadFailure;
        }
        return $this->supervisedConversation;
    }

    public function nextSupervisorBeatOwed(AIConversation $supervisorConversation, AIConversation $supervisedConversation, SupervisorCheckpointService $supervisorCheckpointService): ?SupervisorCheckpointTrigger
    {
        if ($this->beatStillOwedFailure !== null) {
            $this->eventLog->record('nextSupervisorBeatOwed threw');
            throw $this->beatStillOwedFailure;
        }
        $this->eventLog->record('nextSupervisorBeatOwed answer=' . ($this->beatStillOwedAnswer ? 'owed' : 'none'));
        return $this->beatStillOwedAnswer ? SupervisorCheckpointTrigger::create('settled', 'settled beat: 1 owner row(s)', -1, 0) : null;
    }
}
