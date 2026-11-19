<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Repo\Argus\Conversations;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Models\Settings\AIModelAgenticUseCaseConfig;
use DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIAgentConversation;
use DDD\Domain\AI\Services\AIModelsService;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use ReflectionClass;

/**
 * The agent's egress sends the agentic settings (`agenticUseCase`: effort, temperature) of the model the call goes
 * to: without `--model` the model the attribute's scope pins (the live path of a browser or console turn), with
 * `--model` that model; the turn's decided effort still wins.
 */
class AgentEgressAppliesTheAgenticSettingsOfItsModelTest extends IntegrationTestCase
{
    protected AgentEgressModelsServiceWithScopeModelDouble $modelsServiceDouble;

    protected function setUp(): void
    {
        parent::setUp();
        $this->modelsServiceDouble = new AgentEgressModelsServiceWithScopeModelDouble();
        $this->modelsServiceDouble->agenticUseCasesByModelName = [
            'TEST.SCOPE_MODEL' => new AIModelAgenticUseCaseConfig('low', 0.3),
            'TEST.OVERRIDE_MODEL' => new AIModelAgenticUseCaseConfig('high'),
            AIModel::MODEL_ALIBABA_QWEN3_235B_INSTRUCT => null,
        ];
        $this->replaceService(AIModelsService::class, $this->modelsServiceDouble);
    }

    protected function buildEgress(): ArgusAIAgentConversation
    {
        /** @var ArgusAIAgentConversation $egress */
        $egress = (new ReflectionClass(ArgusAIAgentConversation::class))->newInstanceWithoutConstructor();
        return $egress;
    }

    public function testWithoutAnOverrideTheModelTheScopePinsGivesTheEffortAndTheTemperature(): void
    {
        $argusLanguageModel = $this->buildEgress()->getArgusLanguageModelInstance();

        self::assertSame('low', $argusLanguageModel?->reasoningEffort, 'the live path sends the pinned model\'s effort');
        self::assertSame(0.3, $argusLanguageModel?->temperature);
        self::assertNotEmpty($this->modelsServiceDouble->askedScopes);
    }

    public function testTheOverrideModelWinsAndTheDecidedEffortWinsOverIt(): void
    {
        $egress = $this->buildEgress();
        $egress->aiModelNameOverride = 'TEST.OVERRIDE_MODEL';
        self::assertSame('high', $egress->getArgusLanguageModelInstance()?->reasoningEffort);
        self::assertSame(0.6, $egress->getArgusLanguageModelInstance()?->temperature, 'no catalog temperature: the attribute\'s');

        $egress->reasoningEffortOverride = 'minimal';
        self::assertSame('minimal', $egress->getArgusLanguageModelInstance()?->reasoningEffort);
    }

    public function testAScopeWithoutAModelFallsBackToTheDefaultModel(): void
    {
        $this->modelsServiceDouble->scopeModelName = 'TEST.UNKNOWN';

        self::assertNull($this->buildEgress()->getArgusLanguageModelInstance()?->reasoningEffort, 'the default model has no agentic settings');
    }
}
