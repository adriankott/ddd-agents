<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\OwnerDevice;
use DDD\Domain\AI\Interfaces\AIConversationProgressReporterInterface;

/**
 * What a caller sets for one evaluation run of one case, instead of properties on the runner service: the arms of the
 * run, whether its conversations are stored, the simulated owner device, the model, the read-only switch, whether a
 * recall run keeps the conversations it seeded, and where the run's report lines go.
 *
 * It is also the run's record of what it created, kept here so that a caller's `finally` reaches it when the run
 * threw: the conversation the runner built last ({@see takeBuiltConversation()}, what the teardown cleans up after),
 * the ids of the conversations a recall run seeded, and the tool block the runner applied for the case. One options
 * object per case: the record of one case must never be torn down with another's.
 *
 * The options are the run's progress reporter as well: a hook that takes a reporter gets the options, which hand
 * every line on to {@see $progressReporter} or drop it.
 *
 * A plain object of one run; no container service.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalRunOptions implements AIConversationProgressReporterInterface
{
    /** @var AgentEvalArms|null The arms of the run; null = no arm chosen (the platform defaults) */
    public ?AgentEvalArms $agentEvalArms = null;

    /**
     * @var bool Store the evaluation conversation and its messages. A stored conversation is what the decision layer,
     * the turn events and the run's teardown need; a transient run never reaches them.
     */
    public bool $persistConversations = false;

    /** @var OwnerDevice|null The device every owner message of the run carries; null = none */
    public ?OwnerDevice $simulatedOwnerDevice = null;

    /** @var string|null The model the agent runs with; null = the conversation's default */
    public ?string $aiModelName = null;

    /** @var bool true = every tool that is not a declared read is refused during the agent's run */
    public bool $everyWriteToolBlocked = false;

    /** @var bool true = a recall run leaves the conversations it seeded stored (they are deleted otherwise) */
    public bool $keepSeededConversations = false;

    /** @var AIConversationProgressReporterInterface|null Takes the run's report lines; null = the lines are dropped */
    public ?AIConversationProgressReporterInterface $progressReporter = null;

    /** @var int|null The repetition of the case this run is (1 = the first); null when the caller does not count them */
    public ?int $repetition = null;

    /**
     * @var string|null The case's own workspace of this run (`eval-<case>-r<repetition>-<runToken>`, without `-r<repetition>` when the repetition is not counted), set when the case
     * starts ({@see \DDD\Domain\AI\Services\Eval\AgentEvalRunnerService::prepareWorkspaceOfCase()}) and cleared
     * after its clean-up; the conversation carries it in its run settings
     */
    public ?string $workspaceKey = null;

    /** @var bool Keep the case's workspace after the run (`--keepWorkspace`, for debugging): no clean-up, the key is reported */
    public bool $keepsWorkspace = false;

    /** @var string[] The tools the runner blocked for the agent's run of the case (set when the block is applied) */
    public array $blockedToolNames = [];

    /** @var string[] The error text a blocked tool answers, by tool name (set with the block) */
    public array $blockedToolSimulatedErrors = [];

    /**
     * @var AgentEvalStateAfterRunCheck|null The state check (`expect.stateAfterRun`) a restore bracket runs after the
     * agent's run and BEFORE its revert write (`restore.writeTool`), so the read sees what the agent wrote; taken when
     * it runs. null = none.
     */
    public ?AgentEvalStateAfterRunCheck $stateAfterRunCheckInsideRestore = null;

    /** @var string[]|null The failures of that check once it ran (empty = the state matched); null = it did not run */
    public ?array $stateAfterRunFailuresInsideRestore = null;

    /** @var AIConversation|null The conversation the runner built last for this run; taken by the teardown */
    protected ?AIConversation $builtConversation = null;

    /** @var int[] The ids of the conversations a recall run stored as its seed, in the order they were stored */
    protected array $seededConversationIds = [];

    /**
     * The options of a run with `$agentEvalArms`.
     *
     * @param AgentEvalArms|null $agentEvalArms null = no arm chosen
     * @return static
     */
    public static function forArms(?AgentEvalArms $agentEvalArms = null): static
    {
        $agentEvalRunOptions = new static();
        $agentEvalRunOptions->agentEvalArms = $agentEvalArms;
        return $agentEvalRunOptions;
    }

    /** The arms of the run; arms without a choice when none were set. */
    public function getAgentEvalArms(): AgentEvalArms
    {
        if ($this->agentEvalArms === null) {
            $this->agentEvalArms = AgentEvalArms::newInstance();
        }
        return $this->agentEvalArms;
    }

    /** Hands one report line to the reporter; drops it when the run has none. */
    public function reportProgress(string $progressLine): void
    {
        $this->progressReporter?->reportProgress($progressLine);
    }

    /** Records the conversation the runner just built for this run (it replaces an earlier one). */
    public function recordBuiltConversation(AIConversation $conversation): void
    {
        $this->builtConversation = $conversation;
    }

    /** The conversation the runner built last, handed over once; null when none was built since the last take. */
    public function takeBuiltConversation(): ?AIConversation
    {
        $builtConversation = $this->builtConversation;
        $this->builtConversation = null;
        return $builtConversation;
    }

    /** Records the id of a conversation a recall run stored as its seed. */
    public function recordSeededConversationId(int $seededConversationId): void
    {
        $this->seededConversationIds[] = $seededConversationId;
    }

    /** Forgets the seeded ids recorded so far: a recall run starts its own list. */
    public function forgetSeededConversationIds(): void
    {
        $this->seededConversationIds = [];
    }

    /**
     * The ids of the conversations a recall run stored as its seed, also when the run threw after storing them.
     *
     * @return int[]
     */
    public function getSeededConversationIds(): array
    {
        return $this->seededConversationIds;
    }
}
