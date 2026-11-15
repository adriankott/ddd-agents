<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIAgentConversation;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Domain\AI\Services\Eval\AgentEvalJudgedRunService;
use DDD\Infrastructure\Services\DDDService;
use RuntimeException;

/**
 * The judged-run service with only the network call of a harness model call replaced: the request {@see callModel()}
 * prepares and the usage envelope open while it would be sent are recorded, and a fixed answer comes back.
 */
class AgentEvalJudgedRunServiceRecordingHarnessCompletionDouble extends AgentEvalJudgedRunService
{
    /** @var string The answer of every harness completion */
    public string $completionAnswer = 'the answer';

    /** @var string|null Thrown by the completion when set */
    public ?string $completionErrorMessage = null;

    /** @var ArgusAIAgentConversation|null The request of the last completion */
    public ?ArgusAIAgentConversation $lastCompletionRequest = null;

    /** @var string|null The scope of the envelope open while the last completion was sent */
    public ?string $envelopeScopeDuringCompletion = null;

    /** @var int|null The account of that envelope */
    public ?int $envelopeAccountIdDuringCompletion = null;

    protected function loadHarnessCompletion(ArgusAIAgentConversation $argusAIAgentConversation): string
    {
        $this->lastCompletionRequest = $argusAIAgentConversation;
        /** @var AIUsageEnvelopesService $aIUsageEnvelopesService */
        $aIUsageEnvelopesService = DDDService::instance()->getService(AIUsageEnvelopesService::class);
        $currentEnvelopeContext = $aIUsageEnvelopesService->getCurrentEnvelopeContext();
        $this->envelopeScopeDuringCompletion = $currentEnvelopeContext?->scope;
        $this->envelopeAccountIdDuringCompletion = $currentEnvelopeContext?->accountId;
        if ($this->completionErrorMessage !== null) {
            throw new RuntimeException($this->completionErrorMessage);
        }
        return $this->completionAnswer;
    }
}
