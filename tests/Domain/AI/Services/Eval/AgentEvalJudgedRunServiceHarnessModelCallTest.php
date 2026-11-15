<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Entities\Evals\AgentEvalScope;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Domain\AI\Services\Eval\AgentEvalJudgedRunService;
use DDD\Domain\AI\Services\Eval\AgentEvalRunHooksService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use RuntimeException;

/**
 * A harness model call of the driver or the judge, without the network call: the request is a plain completion (no
 * tools) of the named model, booked under the given usage scope, inside the evaluation envelope of the scope's owner,
 * which is closed again afterwards — also when the call threw.
 */
class AgentEvalJudgedRunServiceHarnessModelCallTest extends IntegrationTestCase
{
    protected AgentEvalRunHooksServiceRecordingDouble $hooksDouble;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hooksDouble = new AgentEvalRunHooksServiceRecordingDouble();
        $this->replaceService(AgentEvalRunHooksService::class, $this->hooksDouble);
    }

    protected function buildScope(): AgentEvalScope
    {
        $ownerAccount = new Account();
        $ownerAccount->id = 5;
        return AgentEvalScope::forAgentSpace(null, $ownerAccount);
    }

    protected function getUsageEnvelopesService(): AIUsageEnvelopesService
    {
        /** @var AIUsageEnvelopesService $aIUsageEnvelopesService */
        $aIUsageEnvelopesService = DDDService::instance()->getService(AIUsageEnvelopesService::class);
        return $aIUsageEnvelopesService;
    }

    public function testTheCallIsAPlainCompletionOfTheModelBookedUnderItsScopeInsideTheEvaluationEnvelope(): void
    {
        $agentEvalJudgedRunService = new AgentEvalJudgedRunServiceRecordingHarnessCompletionDouble();
        $openEnvelopeBefore = $this->getUsageEnvelopesService()->getCurrentEnvelopeId();

        $answer = $agentEvalJudgedRunService->callModel($this->buildScope(), 'judge-model', 'Judge this run.', AgentAIUsageScope::AI_AGENT_JUDGE_EVALUATION);

        self::assertSame('the answer', $answer);
        $completionRequest = $agentEvalJudgedRunService->lastCompletionRequest;
        self::assertNotNull($completionRequest);
        self::assertSame('judge-model', $completionRequest->aiModelNameOverride);
        self::assertSame(AgentAIUsageScope::AI_AGENT_JUDGE_EVALUATION, $completionRequest->getAIUsageScope());
        self::assertSame([], $completionRequest->toolSchemas, 'no tools: a plain completion');
        self::assertSame(AgentAIUsageScope::ENVELOPE_AI_AGENT_EVALUATION, $agentEvalJudgedRunService->envelopeScopeDuringCompletion);
        self::assertSame(5, $agentEvalJudgedRunService->envelopeAccountIdDuringCompletion, 'billed to the scope\'s owner');
        self::assertSame($openEnvelopeBefore, $this->getUsageEnvelopesService()->getCurrentEnvelopeId(), 'the envelope is closed after the call');
        self::assertSame(['attachNewConversationToScope'], $this->hooksDouble->hookCalls, 'the prompt conversation is attached to the scope');
    }

    public function testTheDriversCallIsBookedAsTheSimulatedOwner(): void
    {
        $agentEvalJudgedRunService = new AgentEvalJudgedRunServiceRecordingHarnessCompletionDouble();

        $agentEvalJudgedRunService->callModel($this->buildScope(), 'driver-model', 'Write the next owner message.', AgentAIUsageScope::AI_AGENT_SIMULATE_OWNER);

        self::assertSame(AgentAIUsageScope::AI_AGENT_SIMULATE_OWNER, $agentEvalJudgedRunService->lastCompletionRequest?->getAIUsageScope());
        $modelInput = $agentEvalJudgedRunService->lastCompletionRequest?->modelInput;
        self::assertSame(AgentEvalJudgedRunService::EVAL_HARNESS_SYSTEM_PROMPT, trim((string)$modelInput?->composeSystemContent()), 'the harness system prompt alone: no identity, presence, skill catalog or memory section');
        self::assertCount(1, $modelInput->messages ?? [], 'the prompt is the one message');
        self::assertSame('Write the next owner message.', $modelInput->messages[0]->message ?? null);
    }

    public function testTheEnvelopeIsClosedWhenTheCallThrows(): void
    {
        $agentEvalJudgedRunService = new AgentEvalJudgedRunServiceRecordingHarnessCompletionDouble();
        $agentEvalJudgedRunService->completionErrorMessage = 'gateway unavailable';
        $openEnvelopeBefore = $this->getUsageEnvelopesService()->getCurrentEnvelopeId();

        try {
            $agentEvalJudgedRunService->callModel($this->buildScope(), 'judge-model', 'Judge this run.', AgentAIUsageScope::AI_AGENT_JUDGE_EVALUATION);
            self::fail('the model call error must reach the caller');
        } catch (RuntimeException $modelCallError) {
            self::assertSame('gateway unavailable', $modelCallError->getMessage());
        }
        self::assertSame(AgentAIUsageScope::ENVELOPE_AI_AGENT_EVALUATION, $agentEvalJudgedRunService->envelopeScopeDuringCompletion);
        self::assertSame($openEnvelopeBefore, $this->getUsageEnvelopesService()->getCurrentEnvelopeId());
    }
}
