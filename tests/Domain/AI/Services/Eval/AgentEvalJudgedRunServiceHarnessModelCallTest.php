<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Entities\Evals\AgentEvalScope;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Exceptions\Evals\AgentEvalHarnessModelCallFailedException;
use DDD\Domain\AI\Exceptions\Evals\AgentEvalUnknownModelNameException;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Domain\AI\Services\Eval\AgentEvalJudgedRunService;
use DDD\Domain\AI\Services\Eval\AgentEvalRunHooksService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * A harness model call of the driver or the judge, without the network call: the request is a plain completion (no
 * tools) of the named model, booked under the given usage scope, inside the evaluation envelope of the scope's owner,
 * which is closed again afterwards — also when the call threw. A model name the catalog does not know is refused before
 * the envelope and the request; a load the provider refused is the failed harness call with the provider's message.
 */
class AgentEvalJudgedRunServiceHarnessModelCallTest extends IntegrationTestCase
{
    /** @var string A judge model of the catalog */
    protected const string JUDGE_MODEL_NAME = AIModel::MODEL_ANTHROPIC_CLAUDE_HAIKU_4_5;

    /** @var string A driver model of the catalog */
    protected const string DRIVER_MODEL_NAME = AIModel::MODEL_ANTHROPIC_CLAUDE_SONNET_5;

    /** @var string A model name no catalog knows (a mistyped one) */
    protected const string UNKNOWN_MODEL_NAME = 'ALIBABA.QWEN4_MAX.json';

    /** @var string A retired name the catalog resolves to its successor */
    protected const string RETIRED_MODEL_NAME = 'ANTHROPIC.CLAUDE_OPUS_4_8';

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

        $answer = $agentEvalJudgedRunService->callModel($this->buildScope(), self::JUDGE_MODEL_NAME, 'Judge this run.', AgentAIUsageScope::AI_AGENT_JUDGE_EVALUATION);

        self::assertSame('the answer', $answer);
        $completionRequest = $agentEvalJudgedRunService->lastCompletionRequest;
        self::assertNotNull($completionRequest);
        self::assertSame(self::JUDGE_MODEL_NAME, $completionRequest->aiModelNameOverride);
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

        $agentEvalJudgedRunService->callModel($this->buildScope(), self::DRIVER_MODEL_NAME, 'Write the next owner message.', AgentAIUsageScope::AI_AGENT_SIMULATE_OWNER);

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
            $agentEvalJudgedRunService->callModel($this->buildScope(), self::JUDGE_MODEL_NAME, 'Judge this run.', AgentAIUsageScope::AI_AGENT_JUDGE_EVALUATION);
            self::fail('the model call error must reach the caller');
        } catch (RuntimeException $modelCallError) {
            self::assertSame('gateway unavailable', $modelCallError->getMessage());
        }
        self::assertSame(AgentAIUsageScope::ENVELOPE_AI_AGENT_EVALUATION, $agentEvalJudgedRunService->envelopeScopeDuringCompletion);
        self::assertSame($openEnvelopeBefore, $this->getUsageEnvelopesService()->getCurrentEnvelopeId());
    }

    public function testAModelNameTheCatalogDoesNotKnowIsRefusedBeforeTheEnvelopeAndTheCall(): void
    {
        $agentEvalJudgedRunService = new AgentEvalJudgedRunServiceRecordingHarnessCompletionDouble();
        $openEnvelopeBefore = $this->getUsageEnvelopesService()->getCurrentEnvelopeId();

        try {
            $agentEvalJudgedRunService->callModel($this->buildScope(), self::UNKNOWN_MODEL_NAME, 'Judge this run.', AgentAIUsageScope::AI_AGENT_JUDGE_EVALUATION);
            self::fail('an unknown model name must be refused, never answered by the agent\'s default model');
        } catch (AgentEvalUnknownModelNameException $unknownModelName) {
            self::assertSame(self::UNKNOWN_MODEL_NAME, $unknownModelName->getUnknownModelName());
            self::assertSame(sprintf(AgentEvalJudgedRunService::UNKNOWN_MODEL_NAME_MESSAGE, self::UNKNOWN_MODEL_NAME), $unknownModelName->getMessage());
            self::assertStringContainsString('AI.models', $unknownModelName->getMessage(), 'the refusal says where the known names come from');
        }
        self::assertNull($agentEvalJudgedRunService->lastCompletionRequest, 'no completion request was built');
        self::assertNull($agentEvalJudgedRunService->envelopeScopeDuringCompletion);
        self::assertSame($openEnvelopeBefore, $this->getUsageEnvelopesService()->getCurrentEnvelopeId(), 'no envelope was opened');
        self::assertSame([], $this->hooksDouble->hookCalls, 'no prompt conversation was built');

        self::assertSame('the answer', $agentEvalJudgedRunService->callModel($this->buildScope(), self::RETIRED_MODEL_NAME, 'Judge this run.', AgentAIUsageScope::AI_AGENT_JUDGE_EVALUATION), 'a retired name resolves to its successor and passes');
        $agentEvalJudgedRunService->refuseUnknownModelName(self::JUDGE_MODEL_NAME);
    }

    public function testALoadTheProviderRefusedIsTheFailedHarnessCallWithTheProvidersMessage(): void
    {
        $agentEvalJudgedRunService = new AgentEvalJudgedRunService();
        $refusedCompletionRequest = new ArgusAIAgentConversationRefusedLoadDouble();
        $refusedCompletionRequest->aiModelNameOverride = self::JUDGE_MODEL_NAME;
        $refusedCompletionRequest->setAIUsageScope(AgentAIUsageScope::AI_AGENT_JUDGE_EVALUATION);
        $loadHarnessCompletion = new ReflectionMethod(AgentEvalJudgedRunService::class, 'loadHarnessCompletion');

        try {
            $loadHarnessCompletion->invoke($agentEvalJudgedRunService, $refusedCompletionRequest);
            self::fail('a refused load must not read as an empty answer');
        } catch (AgentEvalHarnessModelCallFailedException $harnessModelCallFailure) {
            self::assertSame('judge call failed: ' . self::JUDGE_MODEL_NAME . ': http 404 from the upstream call: No endpoints found', $harnessModelCallFailure->getMessage());
            self::assertSame(self::JUDGE_MODEL_NAME, $harnessModelCallFailure->getAiModelName());
            self::assertSame(AgentAIUsageScope::AI_AGENT_JUDGE_EVALUATION, $harnessModelCallFailure->getAiUsageScope());
            self::assertSame('http 404 from the upstream call: No endpoints found', $harnessModelCallFailure->getFailureReason());
        }

        $refusedCompletionRequest->setAIUsageScope(AgentAIUsageScope::AI_AGENT_SIMULATE_OWNER);
        try {
            $loadHarnessCompletion->invoke($agentEvalJudgedRunService, $refusedCompletionRequest);
            self::fail('a refused driver load must not read as an empty owner turn');
        } catch (AgentEvalHarnessModelCallFailedException $harnessModelCallFailure) {
            self::assertStringStartsWith('driver call failed: ' . self::JUDGE_MODEL_NAME . ': http 404', $harnessModelCallFailure->getMessage());
        }
    }
}
