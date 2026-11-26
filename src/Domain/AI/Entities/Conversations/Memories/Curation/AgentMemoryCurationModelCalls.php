<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Memories\Curation;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The model calls of a memory curation, in the order they were made. Keyed by object
 * ({@see AgentMemoryCurationModelCall::uniqueKey()}): two attempts of one call with identical content are both kept.
 *
 * @property AgentMemoryCurationModelCall[] $elements
 * @method AgentMemoryCurationModelCall|null first()
 * @method AgentMemoryCurationModelCall[] getElements()
 */
class AgentMemoryCurationModelCalls extends ObjectSet
{
    /**
     * Appends the calls of `$agentMemoryCurationModelCalls` after the calls of this set, in their order.
     *
     * @param AgentMemoryCurationModelCalls $agentMemoryCurationModelCalls
     * @return void
     */
    public function appendModelCalls(AgentMemoryCurationModelCalls $agentMemoryCurationModelCalls): void
    {
        foreach ($agentMemoryCurationModelCalls->getElements() as $agentMemoryCurationModelCall) {
            $this->add($agentMemoryCurationModelCall);
        }
    }

    /**
     * The calls of one stage (an {@see AgentMemoryCurationModelCall} STAGE_* constant), in call order.
     *
     * @param string $stage
     * @return AgentMemoryCurationModelCalls
     */
    public function getModelCallsOfStage(string $stage): AgentMemoryCurationModelCalls
    {
        $modelCallsOfStage = new AgentMemoryCurationModelCalls();
        foreach ($this->getElements() as $agentMemoryCurationModelCall) {
            if ($agentMemoryCurationModelCall->stage === $stage) {
                $modelCallsOfStage->add($agentMemoryCurationModelCall);
            }
        }
        return $modelCallsOfStage;
    }

    /**
     * The input tokens of all calls (a call without a reported count adds nothing).
     *
     * @return int
     */
    public function sumInputTokens(): int
    {
        $inputTokens = 0;
        foreach ($this->getElements() as $agentMemoryCurationModelCall) {
            $inputTokens += $agentMemoryCurationModelCall->inputTokens ?? 0;
        }
        return $inputTokens;
    }

    /**
     * The output tokens of all calls (a call without a reported count adds nothing).
     *
     * @return int
     */
    public function sumOutputTokens(): int
    {
        $outputTokens = 0;
        foreach ($this->getElements() as $agentMemoryCurationModelCall) {
            $outputTokens += $agentMemoryCurationModelCall->outputTokens ?? 0;
        }
        return $outputTokens;
    }
}
