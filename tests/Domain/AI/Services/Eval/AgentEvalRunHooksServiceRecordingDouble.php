<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Evals\AgentEvalScope;
use DDD\Domain\AI\Interfaces\AIConversationProgressReporterInterface;
use DDD\Domain\AI\Services\Eval\AgentEvalRunHooksService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\AuthService;
use RuntimeException;

/**
 * The hooks seam of a runner test: the external-write tools, the arguments the platform fills and the stored space of
 * the harness calls are set by the test; every call the runner makes to a hook is recorded, nothing is read from or
 * written to a database.
 */
class AgentEvalRunHooksServiceRecordingDouble extends AgentEvalRunHooksService
{
    /** @var string[] What {@see getExternalWriteToolNames()} answers */
    public array $externalWriteToolNames = [];

    /** @var array<string, mixed> What {@see getHarnessToolCallArguments()} answers */
    public array $harnessToolCallArguments = [];

    /** @var AgentSpace|null What {@see findOrStoreAgentSpaceForHarnessToolCalls()} answers */
    public ?AgentSpace $harnessAgentSpace = null;

    /** @var string|null What {@see findIneligibleScopeReason()} answers */
    public ?string $ineligibleScopeReason = null;

    /** @var string|null Thrown by {@see findIneligibleScopeReason()} when set */
    public ?string $ineligibleScopeErrorMessage = null;

    /** @var string[] The hook calls in their order, by method name */
    public array $hookCalls = [];

    /** @var int[] The authenticated account id while {@see findIneligibleScopeReason()} ran (0 = none) */
    public array $authenticatedAccountIdsDuringEligibilityRead = [];

    /** @var int[][] The tree ids each host teardown received */
    public array $conversationIdsOfTreePerHostTeardown = [];

    /** @var string[] The lines the host teardown reports */
    public array $hostTeardownLines = [];

    public function getExternalWriteToolNames(): array
    {
        $this->hookCalls[] = 'getExternalWriteToolNames';
        return $this->externalWriteToolNames;
    }

    public function getHarnessToolCallArguments(AgentEvalScope $agentEvalScope): array
    {
        $this->hookCalls[] = 'getHarnessToolCallArguments';
        return $this->harnessToolCallArguments;
    }

    public function findOrStoreAgentSpaceForHarnessToolCalls(AgentEvalScope $agentEvalScope): ?AgentSpace
    {
        $this->hookCalls[] = 'findOrStoreAgentSpaceForHarnessToolCalls';
        return $this->harnessAgentSpace;
    }

    public function attachNewConversationToScope(AIConversation $conversation, AgentEvalScope $agentEvalScope): void
    {
        $this->hookCalls[] = 'attachNewConversationToScope';
    }

    public function releaseHostHoldsAfterHarnessCalls(
        AgentEvalScope $agentEvalScope,
        DateTime $harnessCallsStartedAt,
        AIConversationProgressReporterInterface $progressReporter
    ): array {
        $this->hookCalls[] = 'releaseHostHoldsAfterHarnessCalls';
        $progressReporter->reportProgress('restore release: nothing held');
        return [];
    }

    public function tearDownHostResidueOfConversation(
        AIConversation $conversation,
        array $conversationIdsOfTree,
        AIConversationProgressReporterInterface $progressReporter
    ): array {
        $this->hookCalls[] = 'tearDownHostResidueOfConversation';
        $this->conversationIdsOfTreePerHostTeardown[] = $conversationIdsOfTree;
        foreach ($this->hostTeardownLines as $hostTeardownLine) {
            $progressReporter->reportProgress('restore teardown: ' . $hostTeardownLine);
        }
        return $this->hostTeardownLines;
    }

    public function findIneligibleScopeReason(AgentEvalScope $agentEvalScope): ?string
    {
        $this->hookCalls[] = 'findIneligibleScopeReason';
        $this->authenticatedAccountIdsDuringEligibilityRead[] = (int)(AuthService::instance()->getAccount()->id ?? 0);
        if ($this->ineligibleScopeErrorMessage !== null) {
            throw new RuntimeException($this->ineligibleScopeErrorMessage);
        }
        return $this->ineligibleScopeReason;
    }
}
