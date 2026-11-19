<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\AIConversation;

/**
 * What one judged run returns: the judge's verdict, why the owner turns stopped, how many owner turns ran, what the
 * harness calls cost ({@see AgentEvalHarnessCosts}) and the driven conversation (for a stored run the conversation as
 * read back after the last turn).
 *
 * A plain object of one run; no container service.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalSimRunResult
{
    /** @var string Every owner turn the case allows ran */
    public const string STOP_REASON_MAX_TURNS = 'MAX_TURNS';

    /** @var string The simulated owner saw the goal reached, or the conversation was closed by its purpose */
    public const string STOP_REASON_DONE = 'DONE';

    /** @var string The simulated owner gave up on an agent that did not help */
    public const string STOP_REASON_GIVE_UP = 'GIVE_UP';

    /** @var AgentEvalJudgeVerdict The judge's verdict */
    protected AgentEvalJudgeVerdict $agentEvalJudgeVerdict;

    /** @var string Why the owner turns stopped: one of the `STOP_REASON_*` constants or an application's own */
    protected string $stopReason = self::STOP_REASON_MAX_TURNS;

    /** @var int The owner turns that ran */
    protected int $turns = 0;

    /** @var AIConversation The driven conversation */
    protected AIConversation $conversation;

    /** @var AgentEvalHarnessCosts|null What the judge's and the driver's calls of the run cost; null = not counted */
    protected ?AgentEvalHarnessCosts $harnessCosts = null;

    /**
     * The result of a judged run.
     *
     * @param AgentEvalJudgeVerdict $agentEvalJudgeVerdict the judge's verdict
     * @param string $stopReason why the owner turns stopped
     * @param int $turns the owner turns that ran
     * @param AIConversation $conversation the driven conversation
     * @return static
     */
    public static function forRun(AgentEvalJudgeVerdict $agentEvalJudgeVerdict, string $stopReason, int $turns, AIConversation $conversation): static
    {
        $agentEvalSimRunResult = new static();
        $agentEvalSimRunResult->agentEvalJudgeVerdict = $agentEvalJudgeVerdict;
        $agentEvalSimRunResult->stopReason = $stopReason;
        $agentEvalSimRunResult->turns = $turns;
        $agentEvalSimRunResult->conversation = $conversation;
        return $agentEvalSimRunResult;
    }

    /** What the judge's and the driver's calls of the run cost (all zero when they were not counted). */
    public function getHarnessCosts(): AgentEvalHarnessCosts
    {
        return $this->harnessCosts ?? AgentEvalHarnessCosts::newInstance();
    }

    /** Sets what the judge's and the driver's calls of the run cost. */
    public function setHarnessCosts(?AgentEvalHarnessCosts $agentEvalHarnessCosts): static
    {
        $this->harnessCosts = $agentEvalHarnessCosts;
        return $this;
    }

    /** The judge's verdict. */
    public function getAgentEvalJudgeVerdict(): AgentEvalJudgeVerdict
    {
        return $this->agentEvalJudgeVerdict;
    }

    /** Why the owner turns stopped. */
    public function getStopReason(): string
    {
        return $this->stopReason;
    }

    /** The owner turns that ran. */
    public function getTurns(): int
    {
        return $this->turns;
    }

    /** The driven conversation. */
    public function getConversation(): AIConversation
    {
        return $this->conversation;
    }
}
