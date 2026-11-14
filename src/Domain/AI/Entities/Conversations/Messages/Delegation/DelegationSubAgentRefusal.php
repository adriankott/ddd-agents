<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages\Delegation;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Why the spawn does not start a sub-agent the model asked for: the failure reason the sub-agent's record stores
 * ({@see DelegationSubAgentRun::FAILURE_REASON_REFUSED}, {@see DelegationSubAgentRun::FAILURE_REASON_EARLIER_SUB_AGENT_FAILED})
 * and the corrective text the model reads as the answer of its `delegate` call. Built by
 * {@see \DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationSpawnService}; a {@see DelegationSubAgentRecord::notRun()}
 * record is written from it.
 *
 * @phpstan-consistent-constructor
 */
class DelegationSubAgentRefusal extends ValueObject
{
    /** @var string The failure reason of the sub-agent's not-run record */
    public string $failureReason = '';

    /** @var string What the model reads: why nothing was started and what to do instead */
    public string $corrective = '';

    /**
     * @param string $failureReason
     * @param string $corrective
     * @return static
     */
    public static function forFailureReasonAndCorrective(string $failureReason, string $corrective): static
    {
        $delegationSubAgentRefusal = new static();
        $delegationSubAgentRefusal->failureReason = $failureReason;
        $delegationSubAgentRefusal->corrective = $corrective;
        return $delegationSubAgentRefusal;
    }

    /**
     * The refusal of a tool run whose tool is judged on the owner's own words
     * ({@see \DDD\Domain\AI\Entities\MCPPackages\MCPToolRequiresOwnerWordsAsEvidence}): a sub-agent sees only its task,
     * never the conversation with the owner. The conversation with the owner makes that call itself before it
     * delegates; a delegating conversation that is itself a delegated run (`$parentIsDelegatedRun`) cannot make it
     * either, and is told that the main conversation with the owner makes it.
     */
    public static function forToolRequiringOwnerWords(string $toolName, bool $parentIsDelegatedRun): static
    {
        return static::forFailureReasonAndCorrective(
            DelegationSubAgentRun::FAILURE_REASON_REFUSED,
            'delegate REJECTED — `' . $toolName . '` needs the owner\'s own words as evidence, and a sub-agent sees only its '
                . 'task, never ' . ($parentIsDelegatedRun ? 'the' : 'your') . ' conversation with the owner. '
                . ($parentIsDelegatedRun
                    ? 'You are a sub-agent too: the main conversation with the owner makes this call. '
                    : 'Make this call yourself in this conversation before you delegate. ')
                . 'Nothing was started.'
        );
    }
}
