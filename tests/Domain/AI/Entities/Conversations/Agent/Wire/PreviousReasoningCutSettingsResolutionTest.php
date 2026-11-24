<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Wire;

use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentWireConfiguration;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettingsOverride;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Models\Settings\AILanguageModelSetting;
use DDD\Domain\AI\Entities\Models\Settings\AIModelAgenticUseCaseConfig;
use DDD\Domain\AI\Entities\Models\Settings\AIModelPreviousReasoningCarryOver;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Libs\Config;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * Each number of the reasoning cut is resolved on its own: the conversation's row, else the model's catalog value
 * (`agenticUseCase.previousReasoning`), else `AI.contextRetention` per wire API, else the flat value, else the built-in
 * default.
 */
class PreviousReasoningCutSettingsResolutionTest extends UnitTestCase
{
    /** @var array<string, mixed> */
    protected array $configValuesBefore = [];

    protected function tearDown(): void
    {
        (new ReflectionProperty(Config::class, 'configTree'))->setValue(null, $this->configValuesBefore);
        parent::tearDown();
    }

    /** @param array<string, mixed> $contextRetention */
    protected function declareContextRetention(array $contextRetention): void
    {
        $configTree = new ReflectionProperty(Config::class, 'configTree');
        $this->configValuesBefore = $configTree->isInitialized() ? (array)$configTree->getValue() : [];
        $configValues = $this->configValuesBefore;
        [$topKey, $subKey] = explode('.', ContextRetentionSettings::CONFIG_KEY);
        $configValues[$topKey][$subKey] = $contextRetention;
        $configTree->setValue(null, $configValues);
    }

    public function testEachNumberFallsBackOnItsOwn(): void
    {
        $this->declareContextRetention([
            'previousReasoningCapTokens' => 5000,
            'previousReasoningKeptTokensAtCut' => 100,
            AgentWireConfiguration::CONFIG_KEY_PREVIOUS_REASONING_KEPT_TOKENS_AT_CUT_BY_AGENT_WIRE_API => [AIModel::AGENT_WIRE_API_RESPONSES => 800],
            'previousReasoningKeptStepsAtCut' => 3,
            'previousReasoningHeadroomTokensAfterCut' => 250,
        ]);
        $runSettings = RunSettings::newInstance();
        $runSettings->previousReasoningHeadroomTokensAfterCut = 2000;
        $aiModel = static::buildResponsesModel(new AIModelPreviousReasoningCarryOver(AIModelPreviousReasoningCarryOver::CARRY_OVER_CAPPED, 1000, null, 4, 600));

        $agentWireConfiguration = AgentWireConfiguration::resolveFor($aiModel, $runSettings, null, true);

        self::assertSame(RunSettings::PREVIOUS_REASONING_CARRY_OVER_CAPPED, $agentWireConfiguration->previousReasoningCarryOver);
        self::assertSame(1000, $agentWireConfiguration->previousReasoningCapTokens, 'the model before the config');
        self::assertSame(800, $agentWireConfiguration->previousReasoningKeptTokensAtCut, 'the wire API entry before the flat value');
        self::assertSame(4, $agentWireConfiguration->previousReasoningKeptStepsAtCut, 'the model before the config');
        self::assertSame(2000, $agentWireConfiguration->previousReasoningHeadroomTokensAfterCut, 'the row before the model');
    }

    public function testWithoutAnySourceTheBuiltInDefaultsKeepTheOldRule(): void
    {
        $this->declareContextRetention([]);

        $agentWireConfiguration = AgentWireConfiguration::resolveFor(static::buildResponsesModel(null), null, null, true);

        self::assertSame(AgentWireConfiguration::DEFAULT_PREVIOUS_REASONING_CAP_TOKENS, $agentWireConfiguration->previousReasoningCapTokens);
        self::assertSame(0, $agentWireConfiguration->previousReasoningKeptTokensAtCut);
        self::assertSame(2, $agentWireConfiguration->previousReasoningKeptStepsAtCut);
        self::assertSame(0, $agentWireConfiguration->previousReasoningHeadroomTokensAfterCut);
    }

    public function testTheShippedConfigurationKeepsTheOldRule(): void
    {
        $contextRetention = require dirname(__DIR__, 7) . '/config/app/AI/contextRetention.php';

        self::assertSame(0, $contextRetention['previousReasoningKeptTokensAtCut']);
        self::assertSame(2, $contextRetention['previousReasoningKeptStepsAtCut']);
        self::assertSame(0, $contextRetention['previousReasoningHeadroomTokensAfterCut']);
    }

    public function testTheOverrideSetsAndClearsTheRows(): void
    {
        $runSettings = RunSettings::newInstance();
        $runSettings->applyOverride(RunSettingsOverride::fromValues(previousReasoningKeptTokensAtCut: 0, previousReasoningKeptStepsAtCut: 3, previousReasoningHeadroomTokensAfterCut: 1500), 7, new DateTime());

        self::assertSame(0, $runSettings->previousReasoningKeptTokensAtCut, '0 is a value of the min, not inherit');
        self::assertSame(3, $runSettings->previousReasoningKeptStepsAtCut);
        self::assertSame(1500, $runSettings->previousReasoningHeadroomTokensAfterCut);
        self::assertSame(7, $runSettings->previousReasoningHeadroomTokensAfterCutSetByAccountId);

        $runSettings->applyOverride(RunSettingsOverride::fromValues(previousReasoningKeptTokensAtCut: -1, previousReasoningKeptStepsAtCut: -1, previousReasoningHeadroomTokensAfterCut: -1), 7, new DateTime());
        self::assertNull($runSettings->previousReasoningKeptTokensAtCut);
        self::assertNull($runSettings->previousReasoningKeptStepsAtCut);
        self::assertNull($runSettings->previousReasoningHeadroomTokensAfterCut);

        $this->expectException(BadRequestException::class);
        $runSettings->applyOverride(RunSettingsOverride::fromValues(previousReasoningKeptStepsAtCut: 0), 7, new DateTime());
    }

    protected static function buildResponsesModel(?AIModelPreviousReasoningCarryOver $previousReasoning): AIModel
    {
        /** @var AIModel $aiModel */
        $aiModel = (new ReflectionClass(AIModel::class))->newInstanceWithoutConstructor();
        /** @var AILanguageModelSetting $aiLanguageModelSetting */
        $aiLanguageModelSetting = (new ReflectionClass(AILanguageModelSetting::class))->newInstanceWithoutConstructor();
        $aiLanguageModelSetting->agentWireApi = AIModel::AGENT_WIRE_API_RESPONSES;
        $aiModel->settings = $aiLanguageModelSetting;
        $aiModel->vendor = AIModel::VENDOR_OPENAI;
        $aiModel->agenticUseCase = new AIModelAgenticUseCaseConfig(previousReasoning: $previousReasoning);
        return $aiModel;
    }
}
