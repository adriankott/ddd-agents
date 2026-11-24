<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRow;
use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRowHeader;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Symfony\Commands\Agents\AgentEvalSuiteCommand;
use DDD\Domain\AI\Exceptions\AIModelCallTransportFailedException;
use DDD\Domain\AI\Exceptions\Conversations\AgentRunClaimLostException;
use DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIAgentConversation;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * A model call is sent again after a transport failure, a 5xx/429 of the router or an empty body — at most twice, with
 * backoff, the next OpenRouter host first, bypassing the caches — and never after a 4xx answer; a lost claim stops it.
 */
class ModelCallRetriesTest extends TestCase
{
    public function testATransportFailureIsRetriedWithBackoffTheNextHostAndNoCache(): void
    {
        $service = $this->buildService(['transport', 'answer']);
        $retriesJson = $service->sendWithRetries($egress = $this->buildEgress(), $this->buildConversation());

        self::assertSame([0, 1], $service->sentRotations);
        self::assertSame([false, true], $service->sentBypassingCaches);
        self::assertSame([2], $service->waitedSeconds);
        $failedAttempts = json_decode((string)$retriesJson, true);
        self::assertCount(1, $failedAttempts);
        self::assertStringContainsString('cURL error 28', $failedAttempts[0]['reason']);
        self::assertSame(['deepinfra', 'fireworks'], $failedAttempts[0]['providerOrder']);
        self::assertSame(200, $egress->lastResponseStatusCode);
    }

    public function testAFirstAnswerRecordsNoRetry(): void
    {
        $service = $this->buildService(['answer']);
        self::assertNull($service->sendWithRetries($this->buildEgress(), $this->buildConversation()));
        self::assertSame([], $service->waitedSeconds);
    }

    public function testRouterErrorsAndEmptyBodiesAreRetriedButNotAFourHundred(): void
    {
        $service = $this->buildService([503, 'empty', 'answer']);
        $service->sendWithRetries($this->buildEgress(), $this->buildConversation());
        self::assertSame([0, 1, 2], $service->sentRotations);
        self::assertSame([2, 6], $service->waitedSeconds);

        $badRequestService = $this->buildService([400, 'answer']);
        self::assertNull($badRequestService->sendWithRetries($this->buildEgress(), $this->buildConversation()));
        self::assertSame([0], $badRequestService->sentRotations, 'a 400 is an answer, never retried');
    }

    public function testARateLimitWaitsItsRetryAfterUpToTheCap(): void
    {
        $service = $this->buildService([429, 429, 'answer']);
        $service->retryAfterHeader = '9';
        $service->sendWithRetries($this->buildEgress(), $this->buildConversation());
        self::assertSame([9, 9], $service->waitedSeconds);

        $cappedService = $this->buildService([429, 'answer']);
        $cappedService->retryAfterHeader = '600';
        $cappedService->sendWithRetries($this->buildEgress(), $this->buildConversation());
        self::assertSame([20], $cappedService->waitedSeconds);
    }

    public function testTheLastTransportFailureIsThrownAfterTwoRetries(): void
    {
        $service = $this->buildService(['transport', 'transport', 'transport', 'answer']);
        try {
            $service->sendWithRetries($this->buildEgress(), $this->buildConversation());
            self::fail('the third transport failure is thrown');
        } catch (AIModelCallTransportFailedException) {
            self::assertSame([0, 1, 2], $service->sentRotations);
        }
    }

    public function testALostClaimStopsTheRetries(): void
    {
        $service = $this->buildService(['transport', 'answer']);
        $service->loseClaim = true;
        $this->expectException(AgentRunClaimLostException::class);
        try {
            $service->sendWithRetries($this->buildEgress(), $this->buildConversation());
        } finally {
            self::assertSame([0], $service->sentRotations, 'no attempt after the claim was lost');
        }
    }

    public function testTheCatalogTransportTimeoutReachesTheRequestAndItsAbsenceKeepsTheRepoTimeout(): void
    {
        $service = $this->buildService([]);
        $egress = $this->buildEgress();
        $repoTimeout = $egress->getArgusSettings()->timeout;
        $service->applyTransportTimeoutOfModel($egress);
        self::assertSame($repoTimeout, $egress->getArgusSettings()->timeout, 'no catalog value: the repo timeout stays');

        $service->resolvedModel = new AIModel();
        $service->resolvedModel->transportTimeoutSeconds = 120;
        $service->applyTransportTimeoutOfModel($egress);
        self::assertSame(120.0, $egress->getArgusSettings()->timeout);
    }

    public function testAnEvalRowThatEndedOnAModelTransportFailureIsAnInfrastructureError(): void
    {
        foreach (['cURL error 28: Operation timed out', 'cURL error 7: Failed to connect', 'cURL error 52: Empty reply from server'] as $transportError) {
            $agentEvalCaseRow = AgentEvalCaseRow::buildFailedOfCase(AgentEvalCaseRowHeader::forCase('Probe.Skill', 'probe', 'probe'), null, 'Model call transport failed: ' . $transportError);
            self::assertNotNull($agentEvalCaseRow->findInfrastructureError(AgentEvalSuiteCommand::RETRIED_INFRASTRUCTURE_ERRORS), $transportError);
        }
    }

    /** @param array<int, string|int> $attemptOutcomes */
    protected function buildService(array $attemptOutcomes): ScriptedModelCallRetriesService
    {
        $service = new ScriptedModelCallRetriesService();
        $service->attemptOutcomes = $attemptOutcomes;
        return $service;
    }

    protected function buildEgress(): ArgusAIAgentConversation
    {
        /** @var ArgusAIAgentConversation $egress */
        $egress = (new ReflectionClass(ArgusAIAgentConversation::class))->newInstanceWithoutConstructor();
        return $egress;
    }

    protected function buildConversation(): AIConversation
    {
        /** @var AIConversation $conversation */
        $conversation = (new ReflectionClass(AIConversation::class))->newInstanceWithoutConstructor();
        return $conversation;
    }
}
