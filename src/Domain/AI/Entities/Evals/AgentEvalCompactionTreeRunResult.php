<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\AIConversation;

/**
 * What one compaction-tree run returns: beside the verdict, the stop reason, the owner turns and the conversation of
 * every judged run, the text of the compaction tree after each owner turn and the final tree.
 *
 * A plain object of one run; no container service.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalCompactionTreeRunResult extends AgentEvalSimRunResult
{
    /** @var string[] The tree as text after each owner turn, by turn number (from 1) */
    protected array $treeSnapshots = [];

    /** @var string The tree as text after the run */
    protected string $finalTree = '';

    /**
     * The result of a compaction-tree run.
     *
     * @param AgentEvalJudgeVerdict $agentEvalJudgeVerdict the judge's verdict on the tree
     * @param string $stopReason why the owner turns stopped
     * @param int $turns the owner turns that ran
     * @param string[] $treeSnapshots the tree as text after each owner turn, by turn number (from 1)
     * @param string $finalTree the tree as text after the run
     * @param AIConversation $conversation the driven conversation
     * @return static
     */
    public static function forTreeRun(
        AgentEvalJudgeVerdict $agentEvalJudgeVerdict,
        string $stopReason,
        int $turns,
        array $treeSnapshots,
        string $finalTree,
        AIConversation $conversation
    ): static {
        $agentEvalCompactionTreeRunResult = static::forRun($agentEvalJudgeVerdict, $stopReason, $turns, $conversation);
        $agentEvalCompactionTreeRunResult->treeSnapshots = $treeSnapshots;
        $agentEvalCompactionTreeRunResult->finalTree = $finalTree;
        return $agentEvalCompactionTreeRunResult;
    }

    /**
     * The tree as text after each owner turn, by turn number (from 1).
     *
     * @return string[]
     */
    public function getTreeSnapshots(): array
    {
        return $this->treeSnapshots;
    }

    /** The tree as text after the run. */
    public function getFinalTree(): string
    {
        return $this->finalTree;
    }
}
