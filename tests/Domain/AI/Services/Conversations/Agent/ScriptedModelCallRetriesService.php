<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Exceptions\AIModelCallTransportFailedException;
use DDD\Domain\AI\Exceptions\Conversations\AgentRunClaimLostException;
use DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIAgentConversation;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationModelCallsService;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use ReflectionProperty;

/**
 * The model call service with a scripted egress: each attempt plays the next outcome (`transport`, a status code, `empty`
 * or `answer`), waits are recorded instead of slept, and the claim can be scripted to be lost before a retry.
 */
class ScriptedModelCallRetriesService extends AIConversationModelCallsService
{
    /** @var array<int, string|int> The outcome of each attempt, in order */
    public array $attemptOutcomes = [];

    /** @var int[] The provider order rotation each attempt was sent with */
    public array $sentRotations = [];

    /** @var bool[] Whether each attempt bypassed the caches */
    public array $sentBypassingCaches = [];

    /** @var int[] The waits before the retries */
    public array $waitedSeconds = [];

    /** @var bool Whether the claim is lost at the next claim check */
    public bool $loseClaim = false;

    /** @var string|null The Retry-After header the 429 answers carry */
    public ?string $retryAfterHeader = null;

    /** @var AIModel|null The model the egress resolves */
    public ?AIModel $resolvedModel = null;

    protected function findAgentModelOfEgress(ArgusAIAgentConversation $argusAIAgentConversation): ?AIModel
    {
        return $this->resolvedModel;
    }

    public function sendWithRetries(ArgusAIAgentConversation $argusAIAgentConversation, AIConversation $conversation): ?string
    {
        return $this->sendModelCallWithRetries($argusAIAgentConversation, $conversation, null);
    }

    protected function sendModelCallThroughEgress(ArgusAIAgentConversation $argusAIAgentConversation, bool $bypassesCaches): void
    {
        $this->sentRotations[] = $argusAIAgentConversation->providerOrderRotation;
        $this->sentBypassingCaches[] = $bypassesCaches;
        $outcome = array_shift($this->attemptOutcomes) ?? 'answer';
        $argusAIAgentConversation->lastRequestPayload = ['body' => ['provider' => ['order' => ['deepinfra', 'fireworks']]]];
        if ($outcome === 'transport') {
            throw new AIModelCallTransportFailedException(new ConnectException('cURL error 28: Operation timed out', new Request('POST', 'https://openrouter.ai')));
        }
        if ($outcome === 'empty') {
            $argusAIAgentConversation->lastRawResponse = null;
            return;
        }
        if (is_int($outcome)) {
            $argusAIAgentConversation->lastResponseStatusCode = $outcome;
            $argusAIAgentConversation->lastRawResponse = (object)['error' => (object)['code' => $outcome, 'message' => 'router']];
            $headers = new ReflectionProperty($argusAIAgentConversation, 'httpResponseHeaders');
            $headers->setValue($argusAIAgentConversation, $this->retryAfterHeader !== null ? ['Retry-After' => [$this->retryAfterHeader]] : []);
            return;
        }
        $argusAIAgentConversation->lastResponseStatusCode = 200;
        $argusAIAgentConversation->lastRawResponse = (object)['choices' => []];
    }

    protected function keepRunClaimWhileWaiting(AIConversation $conversation): void
    {
        if ($this->loseClaim) {
            throw new AgentRunClaimLostException('claim lost');
        }
    }

    protected function waitBeforeModelCallRetry(int $waitSeconds): void
    {
        $this->waitedSeconds[] = $waitSeconds;
    }
}
