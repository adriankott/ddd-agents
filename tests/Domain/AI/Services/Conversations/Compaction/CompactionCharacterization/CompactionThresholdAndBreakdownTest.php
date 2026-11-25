<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Compaction\CompactionCharacterization;

use DDD\Domain\AI\Entities\Conversations\Agent\Context\CompactionThresholdBreakdown;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Compaction\CompactionModelLimits;
use DDD\Domain\AI\Entities\Conversations\Messages\CompactionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\CompactionSettingsOverride;
use DDD\Domain\AI\Services\AIModelsService;
use DDD\Domain\AI\Services\Conversations\AIConversationCompactionService;
use DDD\Domain\AI\Services\Conversations\Purposes\AIConversationPurposesService;
use DDD\Infrastructure\Services\DDDService;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The compaction threshold of a conversation and its admin breakdown:
 * {@see AIConversation::findCompactionModelLimits()} (the model's `effectiveContextTokens` under 80 % of its window,
 * then under the 100k ceiling; 100k without a model value), {@see AIConversationCompactionService::clampContextBudgetToModelWindow()}
 * (80 % of the window only, no ceiling), {@see AIConversation::shouldConsiderCompaction()} and
 * {@see AIConversation::buildCompactionThresholdBreakdown()} (the source, the fields, the catalog and registry reads, the
 * serialized bytes). The 80 % rule itself (truncation, no wall without a positive window) is
 * {@see \DDD\Tests\Agents\Domain\AI\Entities\Conversations\Compaction\CompactionContextRotWallTest}'s.
 *
 * The model catalog and the purpose registry are doubles in the test container; the conversation is transient and
 * in-memory. No database.
 */
class CompactionThresholdAndBreakdownTest extends CompactionContainerTestCase
{
    protected const string MODEL_SMALL_WINDOW = 'TEST.SMALL_WINDOW';        // effective 30k, window 32k → 80 % = 25 600
    protected const string MODEL_HUGE_WINDOW = 'TEST.HUGE_WINDOW';          // effective 128k, window 1M → 128k, ceiling 100k
    protected const string MODEL_WITHOUT_WINDOW = 'TEST.WITHOUT_WINDOW';    // effective 64k, no window
    protected const string MODEL_WITHOUT_EFFECTIVE = 'TEST.WITHOUT_EFFECTIVE'; // no effective, window 200k
    protected const string MODEL_ZERO_EFFECTIVE = 'TEST.ZERO_EFFECTIVE';    // effective 0, window 200k
    protected const string MODEL_NOT_IN_CATALOG = 'TEST.NOT_IN_CATALOG';

    protected const string PURPOSE_WITH_BUDGET = 'Test.WithBudget';         // contextBudgetTokens 100 000
    protected const string PURPOSE_WITHOUT_BUDGET = 'Test.WithoutBudget';   // contextBudgetTokens null
    protected const string PURPOSE_ZERO_BUDGET = 'Test.ZeroBudget';         // contextBudgetTokens 0
    protected const string PURPOSE_NOT_IN_REGISTRY = 'Test.NotInRegistry';

    protected CompactionThresholdModelsServiceDouble $modelsServiceDouble;

    protected CompactionThresholdPurposesServiceDouble $purposesServiceDouble;

    protected function setUp(): void
    {
        parent::setUp();
        $this->modelsServiceDouble = new CompactionThresholdModelsServiceDouble();
        foreach ([
            CompactionThresholdModelsServiceDouble::buildModel(self::MODEL_SMALL_WINDOW, 30000, 32000),
            CompactionThresholdModelsServiceDouble::buildModel(self::MODEL_HUGE_WINDOW, 128000, 1000000),
            CompactionThresholdModelsServiceDouble::buildModel(self::MODEL_WITHOUT_WINDOW, 64000, null),
            CompactionThresholdModelsServiceDouble::buildModel(self::MODEL_WITHOUT_EFFECTIVE, null, 200000),
            CompactionThresholdModelsServiceDouble::buildModel(self::MODEL_ZERO_EFFECTIVE, 0, 200000),
        ] as $aiModel) {
            $this->modelsServiceDouble->modelsByName[(string)$aiModel->name] = $aiModel;
        }
        $this->replaceService(AIModelsService::class, $this->modelsServiceDouble);

        $this->purposesServiceDouble = new CompactionThresholdPurposesServiceDouble();
        $this->purposesServiceDouble->purposesByName = [
            self::PURPOSE_WITH_BUDGET => CompactionThresholdPurposesServiceDouble::buildPurpose(self::PURPOSE_WITH_BUDGET, 100000),
            self::PURPOSE_WITHOUT_BUDGET => CompactionThresholdPurposesServiceDouble::buildPurpose(self::PURPOSE_WITHOUT_BUDGET, null),
            self::PURPOSE_ZERO_BUDGET => CompactionThresholdPurposesServiceDouble::buildPurpose(self::PURPOSE_ZERO_BUDGET, 0),
        ];
        $this->replaceService(AIConversationPurposesService::class, $this->purposesServiceDouble);
    }

    protected function compactionService(): AIConversationCompactionService
    {
        /** @var AIConversationCompactionService $aIConversationCompactionService */
        $aIConversationCompactionService = DDDService::instance()->getService(AIConversationCompactionService::class);
        return $aIConversationCompactionService;
    }

    /** The doubles are what the container answers. */
    protected function assertTheContainerAnswersTheDoubles(): void
    {
        self::assertSame($this->modelsServiceDouble, DDDService::instance()->getService(AIModelsService::class));
        self::assertSame($this->purposesServiceDouble, DDDService::instance()->getService(AIConversationPurposesService::class));
    }

    /**
     * A transient conversation with the given model override, purpose, trigger override and newest footprint. The node
     * set is always set (empty or `$compactionNodeCount` nodes), so nothing is lazy-loaded.
     */
    protected function conversation(
        ?string $modelName,
        ?string $purposeName,
        ?int $triggerTokens,
        ?int $latestInputTokens,
        int $compactionNodeCount = 0,
        ?bool $compactionEnabled = null
    ): AIConversation {
        $conversation = self::fixtureConversation();
        if ($modelName !== null) {
            $conversation->aiModelNameOverride = $modelName;
        }
        if ($purposeName !== null) {
            $conversation->purposeName = $purposeName;
        }
        if ($triggerTokens !== null || $compactionEnabled !== null) {
            $compactionSettings = new CompactionSettings();
            $compactionSettings->triggerTokens = $triggerTokens;
            $compactionSettings->enabled = $compactionEnabled;
            $conversation->compactionSettings = $compactionSettings;
        }
        $messages = [self::fixtureAssistantMessage(0, null)];
        if ($latestInputTokens !== null) {
            $messages[] = self::fixtureAssistantMessage(1, $latestInputTokens);
        }
        $conversation->messages = self::fixtureMessageSet($messages);
        $nodes = [];
        for ($nodeIndex = 0; $nodeIndex < $compactionNodeCount; $nodeIndex++) {
            $nodes[] = self::fixtureNode(0, $nodeIndex * 10, $nodeIndex * 10 + 9);
        }
        $conversation->compactionNodes = self::fixtureNodeSet($nodes);
        return $conversation;
    }

    /** @return array<string, mixed> every public field of the breakdown, in declaration order */
    protected static function breakdownFields(CompactionThresholdBreakdown $breakdown): array
    {
        return [
            'thresholdTokens' => $breakdown->thresholdTokens,
            'source' => $breakdown->source,
            'latestContextInputTokens' => $breakdown->latestContextInputTokens,
            'isDue' => $breakdown->isDue,
            'modelName' => $breakdown->modelName,
            'modelEffectiveContextTokens' => $breakdown->modelEffectiveContextTokens,
            'conversationTriggerTokens' => $breakdown->conversationTriggerTokens,
            'defaultFallbackTokens' => $breakdown->defaultFallbackTokens,
            'purposeName' => $breakdown->purposeName,
            'purposeContextBudgetTokens' => $breakdown->purposeContextBudgetTokens,
            'compactionNodeCount' => $breakdown->compactionNodeCount,
        ];
    }

    // ---------------------------------------------------------------------------------------------------------------
    // The model limits — AIConversation::findCompactionModelLimits() and the service's clampContextBudgetToModelWindow()
    // ---------------------------------------------------------------------------------------------------------------

    public function testTheFallbackAndCeilingIsOneHundredThousandOnBothConstants(): void
    {
        self::assertSame(100000, CompactionModelLimits::DEFAULT_EFFECTIVE_CONTEXT_TOKENS);
        self::assertSame(100000, AIConversationCompactionService::DEFAULT_EFFECTIVE_CONTEXT_TOKENS);
    }

    /**
     * Columns: model override → the three fields of the limits (window, the model's own value, the threshold) and the
     * catalog reads.
     *
     * @return array<string, array{0: string|null, 1: int|null, 2: int|null, 3: int, 4: string[]}>
     */
    public static function modelLimitsCases(): array
    {
        return [
            'no model override → 100k, no catalog read' => [null, null, null, 100000, []],
            // an EMPTY override is "no model": never a catalog read
            'empty model name → 100k, no catalog read' => ['', null, null, 100000, []],
            'model not in the catalog → 100k' => [self::MODEL_NOT_IN_CATALOG, null, null, 100000, [self::MODEL_NOT_IN_CATALOG]],
            'model without effectiveContextTokens → 100k, its window kept' => [self::MODEL_WITHOUT_EFFECTIVE, 200000, null, 100000, [self::MODEL_WITHOUT_EFFECTIVE]],
            'model with effectiveContextTokens 0 → 100k' => [self::MODEL_ZERO_EFFECTIVE, 200000, null, 100000, [self::MODEL_ZERO_EFFECTIVE]],
            '80 % of a 32k window caps 30k at 25 600' => [self::MODEL_SMALL_WINDOW, 32000, 25600, 25600, [self::MODEL_SMALL_WINDOW]],
            // the model's own value is shown without the ceiling; the threshold carries it
            '128k under a 1M window: own value 128k, threshold capped at 100k' => [self::MODEL_HUGE_WINDOW, 1000000, 128000, 100000, [self::MODEL_HUGE_WINDOW]],
            'no window → the model value under the ceiling' => [self::MODEL_WITHOUT_WINDOW, null, 64000, 64000, [self::MODEL_WITHOUT_WINDOW]],
        ];
    }

    /** @param string[] $expectedCatalogReads */
    #[DataProvider('modelLimitsCases')]
    public function testTheModelLimitsOfTheConversation(
        ?string $modelName,
        ?int $expectedContextWindowTokens,
        ?int $expectedModelEffectiveContextTokens,
        int $expectedThreshold,
        array $expectedCatalogReads
    ): void {
        $conversation = $this->conversation($modelName, null, null, null);

        $compactionModelLimits = $conversation->findCompactionModelLimits();

        self::assertSame($expectedContextWindowTokens, $compactionModelLimits->contextWindowTokens);
        self::assertSame($expectedModelEffectiveContextTokens, $compactionModelLimits->modelEffectiveContextTokens);
        self::assertSame($expectedThreshold, $compactionModelLimits->effectiveContextTokens);
        self::assertSame($expectedCatalogReads, $this->modelsServiceDouble->requestedModelNames, 'the catalog is read exactly for the override name');
        $this->assertTheContainerAnswersTheDoubles();
    }

    /** @return array<string, array{0: int, 1: string|null, 2: int, 3: string[]}> */
    public static function contextBudgetClampCases(): array
    {
        return [
            'no model → the budget as declared' => [100000, null, 100000, []],
            'empty model name → the budget as declared, no catalog read' => [100000, '', 100000, []],
            'model not in the catalog → the budget as declared' => [100000, self::MODEL_NOT_IN_CATALOG, 100000, [self::MODEL_NOT_IN_CATALOG]],
            '80 % of a 32k window' => [100000, self::MODEL_SMALL_WINDOW, 25600, [self::MODEL_SMALL_WINDOW]],
            'a budget under the 80 % passes' => [10000, self::MODEL_SMALL_WINDOW, 10000, [self::MODEL_SMALL_WINDOW]],
            // no 100k ceiling for a purpose budget: 150k stays 150k under a 1M window
            'no ceiling for a purpose budget' => [150000, self::MODEL_HUGE_WINDOW, 150000, [self::MODEL_HUGE_WINDOW]],
            'no window → the budget as declared' => [100000, self::MODEL_WITHOUT_WINDOW, 100000, [self::MODEL_WITHOUT_WINDOW]],
            // the clamp reads only the window: a model without effectiveContextTokens still clamps (200k × 0.8 = 160k)
            'the clamp ignores effectiveContextTokens' => [300000, self::MODEL_WITHOUT_EFFECTIVE, 160000, [self::MODEL_WITHOUT_EFFECTIVE]],
            // a non-positive budget is not rejected here; the callers filter it
            'a zero budget passes through' => [0, self::MODEL_SMALL_WINDOW, 0, [self::MODEL_SMALL_WINDOW]],
        ];
    }

    /** @param string[] $expectedCatalogReads */
    #[DataProvider('contextBudgetClampCases')]
    public function testThePurposeBudgetClampsToEightyPercentOfTheWindowOnly(int $contextBudgetTokens, ?string $modelName, int $expectedBudget, array $expectedCatalogReads): void
    {
        self::assertSame($expectedBudget, $this->compactionService()->clampContextBudgetToModelWindow($contextBudgetTokens, $modelName));
        self::assertSame($expectedCatalogReads, $this->modelsServiceDouble->requestedModelNames, 'the catalog is read exactly for the model name');
        $this->assertTheContainerAnswersTheDoubles();
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Whether compaction is due — AIConversation::shouldConsiderCompaction() and getEffectiveCompactionThreshold()
    // ---------------------------------------------------------------------------------------------------------------

    public function testAThresholdOfZeroIsNeverDue(): void
    {
        $conversation = $this->conversation(null, null, null, 5);

        self::assertSame(0, $conversation->getEffectiveCompactionThreshold(0));
        self::assertFalse($conversation->shouldConsiderCompaction(0), 'a footprint of 5 reaches a threshold of 0, and is not due');
        self::assertTrue($conversation->shouldConsiderCompaction(5));
        self::assertFalse($conversation->shouldConsiderCompaction(6));
    }

    public function testNoMeasuredFootprintIsNeverDue(): void
    {
        $conversation = $this->conversation(null, null, 1, null);

        self::assertNull($conversation->getLatestContextInputTokens());
        self::assertFalse($conversation->shouldConsiderCompaction(1));
    }

    // ---------------------------------------------------------------------------------------------------------------
    // The admin breakdown — AIConversation::buildCompactionThresholdBreakdown()
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * The fields of the plain fallback breakdown with $overrides applied.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected static function expectedFields(array $overrides): array
    {
        return array_merge([
            'thresholdTokens' => 100000,
            'source' => CompactionThresholdBreakdown::SOURCE_DEFAULT_FALLBACK,
            'latestContextInputTokens' => null,
            'isDue' => false,
            'modelName' => null,
            'modelEffectiveContextTokens' => null,
            'conversationTriggerTokens' => null,
            'defaultFallbackTokens' => 100000,
            'purposeName' => null,
            'purposeContextBudgetTokens' => null,
            'compactionNodeCount' => 0,
        ], $overrides);
    }

    /**
     * The breakdown per source × model with / without a window × an override equal / unequal to the clamped purpose
     * budget. Columns: model override, purpose, trigger override, newest footprint → the expected fields, the catalog
     * reads in order, the purpose names the registry was asked about.
     *
     * @return array<string, array{0: string|null, 1: string|null, 2: int|null, 3: int|null, 4: array<string, mixed>, 5: string[], 6: array<int, string|null>}>
     */
    public static function breakdownCases(): array
    {
        return [
            // ── defaultFallback ──
            // the purpose registry is asked even without a purpose name
            'defaultFallback: nothing set' => [null, null, null, null, self::expectedFields([]), [], [null]],
            'defaultFallback: footprint below 100k' => [null, null, null, 99999, self::expectedFields(['latestContextInputTokens' => 99999]), [], [null]],
            'defaultFallback: footprint at 100k is due (>=)' => [null, null, null, 100000, self::expectedFields(['latestContextInputTokens' => 100000, 'isDue' => true]), [], [null]],
            // the catalog is read once per breakdown
            'defaultFallback: model without effectiveContextTokens keeps its name' => [
                self::MODEL_WITHOUT_EFFECTIVE, null, null, 1000,
                self::expectedFields(['latestContextInputTokens' => 1000, 'modelName' => self::MODEL_WITHOUT_EFFECTIVE]),
                [self::MODEL_WITHOUT_EFFECTIVE], [null],
            ],
            'defaultFallback: model not in the catalog keeps its name' => [
                self::MODEL_NOT_IN_CATALOG, null, null, 1000,
                self::expectedFields(['latestContextInputTokens' => 1000, 'modelName' => self::MODEL_NOT_IN_CATALOG]),
                [self::MODEL_NOT_IN_CATALOG], [null],
            ],
            // an empty override is reported as modelName '' (not null) while it counts as "no model"
            'defaultFallback: empty model name is reported as an empty string' => [
                '', null, null, 1000, self::expectedFields(['latestContextInputTokens' => 1000, 'modelName' => '']), [], [null],
            ],
            // ── modelEffective ──
            'modelEffective: 80 % of a 32k window' => [
                self::MODEL_SMALL_WINDOW, null, null, 25600,
                self::expectedFields(['thresholdTokens' => 25600, 'source' => CompactionThresholdBreakdown::SOURCE_MODEL_EFFECTIVE, 'latestContextInputTokens' => 25600, 'isDue' => true, 'modelName' => self::MODEL_SMALL_WINDOW, 'modelEffectiveContextTokens' => 25600]),
                [self::MODEL_SMALL_WINDOW], [null],
            ],
            // the breakdown's modelEffectiveContextTokens is the 80 %-capped value WITHOUT the 100k ceiling, while
            // thresholdTokens carries the ceiling: 128 000 is shown next to a 100 000 threshold
            'modelEffective: a huge window shows 128k next to the 100k threshold' => [
                self::MODEL_HUGE_WINDOW, null, null, 110000,
                self::expectedFields(['source' => CompactionThresholdBreakdown::SOURCE_MODEL_EFFECTIVE, 'latestContextInputTokens' => 110000, 'isDue' => true, 'modelName' => self::MODEL_HUGE_WINDOW, 'modelEffectiveContextTokens' => 128000]),
                [self::MODEL_HUGE_WINDOW], [null],
            ],
            'modelEffective: no window shows the raw effective value' => [
                self::MODEL_WITHOUT_WINDOW, null, null, null,
                self::expectedFields(['thresholdTokens' => 64000, 'source' => CompactionThresholdBreakdown::SOURCE_MODEL_EFFECTIVE, 'modelName' => self::MODEL_WITHOUT_WINDOW, 'modelEffectiveContextTokens' => 64000]),
                [self::MODEL_WITHOUT_WINDOW], [null],
            ],
            // ── conversationOverride ──
            'conversationOverride: trigger under the model threshold' => [
                self::MODEL_SMALL_WINDOW, null, 20000, 20000,
                self::expectedFields(['thresholdTokens' => 20000, 'source' => CompactionThresholdBreakdown::SOURCE_CONVERSATION_OVERRIDE, 'latestContextInputTokens' => 20000, 'isDue' => true, 'modelName' => self::MODEL_SMALL_WINDOW, 'modelEffectiveContextTokens' => 25600, 'conversationTriggerTokens' => 20000]),
                [self::MODEL_SMALL_WINDOW], [null],
            ],
            // the override may exceed the 100k ceiling
            'conversationOverride: above the ceiling, no model' => [
                null, null, 150000, 149999,
                self::expectedFields(['thresholdTokens' => 150000, 'source' => CompactionThresholdBreakdown::SOURCE_CONVERSATION_OVERRIDE, 'latestContextInputTokens' => 149999, 'conversationTriggerTokens' => 150000]),
                [], [null],
            ],
            // an override without a purpose's stamp that is not the clamped budget either
            'conversationOverride: override unequal to the clamped purpose budget' => [
                self::MODEL_SMALL_WINDOW, self::PURPOSE_WITH_BUDGET, 100000, 30000,
                self::expectedFields(['thresholdTokens' => 100000, 'source' => CompactionThresholdBreakdown::SOURCE_CONVERSATION_OVERRIDE, 'latestContextInputTokens' => 30000, 'modelName' => self::MODEL_SMALL_WINDOW, 'modelEffectiveContextTokens' => 25600, 'conversationTriggerTokens' => 100000, 'purposeName' => self::PURPOSE_WITH_BUDGET, 'purposeContextBudgetTokens' => 25600]),
                [self::MODEL_SMALL_WINDOW], [self::PURPOSE_WITH_BUDGET],
            ],
            // ── purposeContextBudget ──
            // one catalog read serves the threshold, the purpose clamp and the model value
            'purposeContextBudget: override equal to the budget under a 1M window' => [
                self::MODEL_HUGE_WINDOW, self::PURPOSE_WITH_BUDGET, 100000, 100000,
                self::expectedFields(['thresholdTokens' => 100000, 'source' => CompactionThresholdBreakdown::SOURCE_PURPOSE_CONTEXT_BUDGET, 'latestContextInputTokens' => 100000, 'isDue' => true, 'modelName' => self::MODEL_HUGE_WINDOW, 'modelEffectiveContextTokens' => 128000, 'conversationTriggerTokens' => 100000, 'purposeName' => self::PURPOSE_WITH_BUDGET, 'purposeContextBudgetTokens' => 100000]),
                [self::MODEL_HUGE_WINDOW], [self::PURPOSE_WITH_BUDGET],
            ],
            'purposeContextBudget: override equal to the clamped budget under a 32k window' => [
                self::MODEL_SMALL_WINDOW, self::PURPOSE_WITH_BUDGET, 25600, 1000,
                self::expectedFields(['thresholdTokens' => 25600, 'source' => CompactionThresholdBreakdown::SOURCE_PURPOSE_CONTEXT_BUDGET, 'latestContextInputTokens' => 1000, 'modelName' => self::MODEL_SMALL_WINDOW, 'modelEffectiveContextTokens' => 25600, 'conversationTriggerTokens' => 25600, 'purposeName' => self::PURPOSE_WITH_BUDGET, 'purposeContextBudgetTokens' => 25600]),
                [self::MODEL_SMALL_WINDOW], [self::PURPOSE_WITH_BUDGET],
            ],
            'purposeContextBudget: no model, no window to clamp to' => [
                null, self::PURPOSE_WITH_BUDGET, 100000, null,
                self::expectedFields(['thresholdTokens' => 100000, 'source' => CompactionThresholdBreakdown::SOURCE_PURPOSE_CONTEXT_BUDGET, 'conversationTriggerTokens' => 100000, 'purposeName' => self::PURPOSE_WITH_BUDGET, 'purposeContextBudgetTokens' => 100000]),
                [], [self::PURPOSE_WITH_BUDGET],
            ],
            'purposeContextBudget: a model without a window' => [
                self::MODEL_WITHOUT_WINDOW, self::PURPOSE_WITH_BUDGET, 100000, null,
                self::expectedFields(['thresholdTokens' => 100000, 'source' => CompactionThresholdBreakdown::SOURCE_PURPOSE_CONTEXT_BUDGET, 'modelName' => self::MODEL_WITHOUT_WINDOW, 'modelEffectiveContextTokens' => 64000, 'conversationTriggerTokens' => 100000, 'purposeName' => self::PURPOSE_WITH_BUDGET, 'purposeContextBudgetTokens' => 100000]),
                [self::MODEL_WITHOUT_WINDOW], [self::PURPOSE_WITH_BUDGET],
            ],
            // ── a purpose without an override: the budget is shown, the source stays the model / the fallback ──
            'purpose budget without an override does not change the source' => [
                self::MODEL_SMALL_WINDOW, self::PURPOSE_WITH_BUDGET, null, null,
                self::expectedFields(['thresholdTokens' => 25600, 'source' => CompactionThresholdBreakdown::SOURCE_MODEL_EFFECTIVE, 'modelName' => self::MODEL_SMALL_WINDOW, 'modelEffectiveContextTokens' => 25600, 'purposeName' => self::PURPOSE_WITH_BUDGET, 'purposeContextBudgetTokens' => 25600]),
                [self::MODEL_SMALL_WINDOW], [self::PURPOSE_WITH_BUDGET],
            ],
            'purpose without a budget' => [
                null, self::PURPOSE_WITHOUT_BUDGET, null, null, self::expectedFields(['purposeName' => self::PURPOSE_WITHOUT_BUDGET]), [], [self::PURPOSE_WITHOUT_BUDGET],
            ],
            'purpose with a budget of 0 counts as none' => [
                null, self::PURPOSE_ZERO_BUDGET, null, null, self::expectedFields(['purposeName' => self::PURPOSE_ZERO_BUDGET]), [], [self::PURPOSE_ZERO_BUDGET],
            ],
            // the purpose NAME is the stamped field, reported even when the registry knows no such purpose
            'purpose name not in the registry is still reported' => [
                null, self::PURPOSE_NOT_IN_REGISTRY, null, null, self::expectedFields(['purposeName' => self::PURPOSE_NOT_IN_REGISTRY]), [], [self::PURPOSE_NOT_IN_REGISTRY],
            ],
            // ── a trigger of 0 or below is no override ──
            'trigger 0 is no override and is not reported' => [
                self::MODEL_SMALL_WINDOW, null, 0, null,
                self::expectedFields(['thresholdTokens' => 25600, 'source' => CompactionThresholdBreakdown::SOURCE_MODEL_EFFECTIVE, 'modelName' => self::MODEL_SMALL_WINDOW, 'modelEffectiveContextTokens' => 25600]),
                [self::MODEL_SMALL_WINDOW], [null],
            ],
            'a negative trigger is no override and is not reported' => [
                null, null, -5, 100000,
                self::expectedFields(['latestContextInputTokens' => 100000, 'isDue' => true]),
                [], [null],
            ],
        ];
    }

    public function testABudgetAPurposeStampedStaysThePurposesUnderAnotherModelsWindow(): void
    {
        $conversation = $this->conversation(self::MODEL_SMALL_WINDOW, self::PURPOSE_WITH_BUDGET, 100000, 30000);
        $conversation->compactionSettings->setTriggerTokensOfPurpose(100000);

        $breakdown = $conversation->buildCompactionThresholdBreakdown();

        self::assertSame(CompactionThresholdBreakdown::SOURCE_PURPOSE_CONTEXT_BUDGET, $breakdown->source);
        self::assertSame(100000, $breakdown->thresholdTokens, 'the applied threshold is the stamped trigger, unchanged');
        self::assertSame(25600, $breakdown->purposeContextBudgetTokens, 'the budget under the current model');
        self::assertFalse($breakdown->isDue);
    }

    public function testATriggerWrittenOverThePurposesStampIsAnOverrideEvenWhenItEqualsTheClampedBudget(): void
    {
        $conversation = $this->conversation(self::MODEL_SMALL_WINDOW, self::PURPOSE_WITH_BUDGET, 100000, null);
        $conversation->compactionSettings->setTriggerTokensOfPurpose(100000);
        $conversation->compactionSettings->triggerTokens = 25600;

        self::assertSame(CompactionThresholdBreakdown::SOURCE_CONVERSATION_OVERRIDE, $conversation->buildCompactionThresholdBreakdown()->source);
    }

    public function testThePurposesStampIsStoredButNeverOnTheApi(): void
    {
        $compactionSettings = new CompactionSettings();
        $compactionSettings->setTriggerTokensOfPurpose(100000);

        self::assertStringNotContainsString('triggerTokensStampedByPurpose', (string)json_encode($compactionSettings->toObject()));
        $restored = new CompactionSettings();
        $restored->mapFromRepository(json_decode((string)json_encode($compactionSettings->mapToRepository()), true));
        self::assertTrue($restored->isTriggerStampedByPurpose());
    }

    public function testAnOperatorsTriggerClearsThePurposesStamp(): void
    {
        $compactionSettings = new CompactionSettings();
        $compactionSettings->setTriggerTokensOfPurpose(100000);
        $overriddenSettings = CompactionSettingsOverride::fromRawValues('100000')->applyTo($compactionSettings);

        self::assertNull($overriddenSettings->isTriggerStampedByPurpose());
        self::assertTrue($compactionSettings->isTriggerStampedByPurpose(), 'the settings passed in are not changed');
    }

    public function testTheSettingsAPurposeBuildsCarryItsStamp(): void
    {
        $purpose = $this->purposesServiceDouble->purposesByName[self::PURPOSE_WITH_BUDGET];

        self::assertTrue($purpose->buildCompactionSettingsForContextBudget(25600)->isTriggerStampedByPurpose());
    }

    /**
     * @param array<string, mixed> $expectedFields
     * @param string[] $expectedCatalogReads
     * @param array<int, string|null> $expectedPurposeQuestions
     */
    #[DataProvider('breakdownCases')]
    public function testTheBreakdownFieldsPerSource(
        ?string $modelName,
        ?string $purposeName,
        ?int $triggerTokens,
        ?int $latestInputTokens,
        array $expectedFields,
        array $expectedCatalogReads,
        array $expectedPurposeQuestions
    ): void {
        $conversation = $this->conversation($modelName, $purposeName, $triggerTokens, $latestInputTokens);

        $breakdown = $conversation->buildCompactionThresholdBreakdown();

        self::assertSame($expectedFields, self::breakdownFields($breakdown));
        self::assertSame($expectedCatalogReads, $this->modelsServiceDouble->requestedModelNames, 'the catalog is read once per breakdown');
        self::assertSame($expectedPurposeQuestions, $this->purposesServiceDouble->requestedPurposeNames, 'the purpose registry is asked once per breakdown');
        $this->assertTheContainerAnswersTheDoubles();
        // the breakdown's isDue and thresholdTokens are the conversation's own answers for its model threshold
        $resolvedModelThreshold = $conversation->findCompactionModelLimits()->effectiveContextTokens;
        self::assertSame($conversation->shouldConsiderCompaction($resolvedModelThreshold), $breakdown->isDue);
        self::assertSame($conversation->getEffectiveCompactionThreshold($resolvedModelThreshold), $breakdown->thresholdTokens);
    }

    public function testADisabledConversationIsNeverDueButStillShowsItsThreshold(): void
    {
        $conversation = $this->conversation(self::MODEL_SMALL_WINDOW, null, null, 999999, compactionEnabled: false);

        $breakdown = $conversation->buildCompactionThresholdBreakdown();

        self::assertFalse($breakdown->isDue, 'enabled=false wins over any footprint');
        self::assertSame(25600, $breakdown->thresholdTokens);
        self::assertSame(CompactionThresholdBreakdown::SOURCE_MODEL_EFFECTIVE, $breakdown->source);
        self::assertSame(999999, $breakdown->latestContextInputTokens);
        self::assertSame([self::MODEL_SMALL_WINDOW], $this->modelsServiceDouble->requestedModelNames);
        self::assertSame([null], $this->purposesServiceDouble->requestedPurposeNames);
    }

    public function testAnEnabledFlagSetExplicitlyIsDue(): void
    {
        $conversation = $this->conversation(self::MODEL_SMALL_WINDOW, null, null, 25600, compactionEnabled: true);

        self::assertTrue($conversation->buildCompactionThresholdBreakdown()->isDue);
    }

    public function testTheFootprintIsTheNewestMeasuredRowByIndex(): void
    {
        $conversation = $this->conversation(null, null, null, null);
        // index order, not insertion order; the newest row WITH a positive inputTokens wins (null and 0 are skipped)
        $conversation->messages = self::fixtureMessageSet([
            self::fixtureAssistantMessage(4, 0),
            self::fixtureAssistantMessage(0, 40000),
            self::fixtureAssistantMessage(2, 12000),
            self::fixtureAssistantMessage(3, null),
        ]);

        $breakdown = $conversation->buildCompactionThresholdBreakdown();

        self::assertSame(12000, $breakdown->latestContextInputTokens);
        self::assertFalse($breakdown->isDue);
        self::assertSame([], $this->modelsServiceDouble->requestedModelNames);
        self::assertSame([null], $this->purposesServiceDouble->requestedPurposeNames);
    }

    /**
     * The count reads the LOADED set (`?->` on the lazy relation would load the whole tree when the property is unset;
     * not exercised here, it would query the database). A node set set to null counts 0; a loaded set counts its
     * elements, whatever subset it holds.
     */
    public function testTheNodeCountReadsTheLoadedSet(): void
    {
        $conversationWithThreeNodes = $this->conversation(null, null, null, null, compactionNodeCount: 3);
        self::assertSame(3, $conversationWithThreeNodes->buildCompactionThresholdBreakdown()->compactionNodeCount);

        $conversationWithNullNodes = $this->conversation(null, null, null, null);
        $conversationWithNullNodes->compactionNodes = null;
        self::assertSame(0, $conversationWithNullNodes->buildCompactionThresholdBreakdown()->compactionNodeCount);

        self::assertSame([null, null], $this->purposesServiceDouble->requestedPurposeNames, 'the purpose registry is asked once per breakdown');
        self::assertSame([], $this->modelsServiceDouble->requestedModelNames);
    }

    /** The bytes an admin endpoint serializes for the breakdown, for one breakdown with every field and one plain fallback. */
    public function testTheSerializedBreakdownBytes(): void
    {
        $purposeBreakdown = $this->conversation(self::MODEL_HUGE_WINDOW, self::PURPOSE_WITH_BUDGET, 100000, 64000, compactionNodeCount: 2)->buildCompactionThresholdBreakdown();
        $fallbackBreakdown = $this->conversation(null, null, null, null)->buildCompactionThresholdBreakdown();

        self::assertSame(
            '{"thresholdTokens":100000,"source":"purposeContextBudget","latestContextInputTokens":64000,"isDue":false,'
            . '"modelName":"TEST.HUGE_WINDOW","modelEffectiveContextTokens":128000,"conversationTriggerTokens":100000,'
            . '"defaultFallbackTokens":100000,"purposeName":"Test.WithBudget","purposeContextBudgetTokens":100000,"compactionNodeCount":2,'
            . '"objectType":"DDD\\\\Domain\\\\AI\\\\Entities\\\\Conversations\\\\Agent\\\\Context\\\\CompactionThresholdBreakdown"}',
            json_encode($purposeBreakdown->toObject())
        );
        self::assertSame(
            // the serializer drops the null fields (absent, not null) and appends the objectType
            '{"thresholdTokens":100000,"source":"defaultFallback","isDue":false,"defaultFallbackTokens":100000,"compactionNodeCount":0,'
            . '"objectType":"DDD\\\\Domain\\\\AI\\\\Entities\\\\Conversations\\\\Agent\\\\Context\\\\CompactionThresholdBreakdown"}',
            json_encode($fallbackBreakdown->toObject())
        );
        self::assertSame([self::MODEL_HUGE_WINDOW], $this->modelsServiceDouble->requestedModelNames);
        self::assertSame([self::PURPOSE_WITH_BUDGET, null], $this->purposesServiceDouble->requestedPurposeNames);
    }
}
