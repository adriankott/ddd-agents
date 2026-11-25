<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Decisions\SiteOutcomes;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionLayerStatus;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentReasoningEffortDecision;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentReasoningEffortFloor;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use ReflectionMethod;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentTurnStartDecision;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The reasoning-effort MATRIX: the four places that answer "which effort", each read for one named variant of the
 * conversation:
 * - E1 `sent` — the effort the model call is sent with (`AgentReasoningEffortDecisionsService::
 *   resolveDecidedReasoningEffortForCurrentTurn()`; null = the catalog value, nothing is sent);
 * - E2 `floor` — the active effort the decision model is told (`resolveFloorReasoningEffortForCurrentTurn()`), with
 *   its source;
 * - E3 `fallback` / `answer` — the effective effort the site stamps on a transport fallback and on a parsable answer
 *   that raises nothing (`applyReasoningEffortDecision()`), and whether the stamp is applied;
 * - E4 `baseline` — the control panel's baseline (`AgentDecisionLayerStatus::forConversation()` over
 *   `buildReasoningEffortLimitsForConversation()`), with its source.
 *
 * E3 is read by calling the site's application directly, so its delegation / autonomous / kill-switch values are what
 * the application WOULD stamp — the turn start never builds the site's input for those conversations (null rows = the
 * site is `off` for the conversation and never applies). The world reads behind every place are the variant's: the
 * model catalog's configured effort and ceiling (`low` / `high` by default), the purpose's base effort and minimum
 * (none by default). One rule: E4 is E1 without a turn stamp and without the plan-item minimum.
 */
class ReasoningEffortMatrixTest extends DecisionSiteOutcomeTestCase
{
    /** @var array<string, mixed> the default variant: catalog `low` / `high`, site `on`, a root conversation, nothing declared */
    protected const array DEFAULT_VARIANT = [
        'siteMode' => 'on',
        'origin' => 'root',
        'stamp' => 'none',
        'pin' => null,
        'killSwitch' => false,
        'fixedEffort' => null,
        'catalogEffort' => 'low',
        'ceilingEffort' => 'high',
        'purposeBaseEffort' => null,
        'purposeMinimumEffort' => null,
        'planItemMinimumEffort' => null,
    ];

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function effortVariants(): array
    {
        return [
            'model default (catalog low), site on, root, no stamp' => [[]],
            'model override with a medium catalog effort, site on' => [['catalogEffort' => 'medium']],
            'model override with a medium catalog effort, site shadow' => [['catalogEffort' => 'medium', 'siteMode' => 'shadow']],
            'model without a catalog effort, site on' => [['catalogEffort' => null]],
            'model without a catalog effort, site off' => [['catalogEffort' => null, 'siteMode' => 'off']],
            'site off' => [['siteMode' => 'off']],
            'site shadow, no pin' => [['siteMode' => 'shadow']],
            'kill switch on the row' => [['killSwitch' => true]],
            'applied stamp of the current owner turn (high)' => [['stamp' => 'applied']],
            'stale stamp of an earlier owner turn (high)' => [['stamp' => 'stale']],
            'shadow stamp of the current owner turn (high, not applied)' => [['stamp' => 'shadow']],
            'row pin medium, site on' => [['pin' => 'medium']],
            'row pin medium, site shadow' => [['pin' => 'medium', 'siteMode' => 'shadow']],
            'row pin high under a medium ceiling' => [['pin' => 'high', 'ceilingEffort' => 'medium']],
            'fixed effort medium (eval arm / supervisor run)' => [['fixedEffort' => 'medium']],
            'fixed effort high under a medium ceiling' => [['fixedEffort' => 'high', 'ceilingEffort' => 'medium']],
            'fixed effort low beside a high pin and an applied stamp' => [['fixedEffort' => 'low', 'pin' => 'high', 'stamp' => 'applied']],
            'purpose base medium, site on' => [['purposeBaseEffort' => 'medium']],
            'purpose base medium, site shadow' => [['purposeBaseEffort' => 'medium', 'siteMode' => 'shadow']],
            'purpose base medium, delegation' => [['purposeBaseEffort' => 'medium', 'origin' => 'delegation']],
            'purpose base medium, autonomous run' => [['purposeBaseEffort' => 'medium', 'origin' => 'autonomous']],
            'purpose base medium with a purpose minimum low, site shadow' => [['purposeBaseEffort' => 'medium', 'purposeMinimumEffort' => 'low', 'siteMode' => 'shadow']],
            'purpose base low, site on' => [['purposeBaseEffort' => 'low']],
            'purpose minimum medium, root' => [['purposeMinimumEffort' => 'medium']],
            'purpose minimum medium, delegation' => [['purposeMinimumEffort' => 'medium', 'origin' => 'delegation']],
            'purpose minimum medium, site shadow' => [['purposeMinimumEffort' => 'medium', 'siteMode' => 'shadow']],
            'plan-item minimum high, root' => [['planItemMinimumEffort' => 'high']],
            'plan-item minimum high, autonomous run' => [['planItemMinimumEffort' => 'high', 'origin' => 'autonomous']],
            'plan-item minimum high, site off' => [['planItemMinimumEffort' => 'high', 'siteMode' => 'off']],
            'delegation, nothing declared' => [['origin' => 'delegation']],
            'autonomous run, nothing declared' => [['origin' => 'autonomous']],
            'delegation with a row pin high' => [['origin' => 'delegation', 'pin' => 'high']],
            'purpose base medium, site off' => [['purposeBaseEffort' => 'medium', 'siteMode' => 'off']],
            'row pin medium, site off' => [['pin' => 'medium', 'siteMode' => 'off']],
            'purpose minimum high under a medium ceiling' => [['purposeMinimumEffort' => 'high', 'ceilingEffort' => 'medium']],
        ];
    }

    /** @param array<string, mixed> $variantOverrides */
    #[DataProvider('effortVariants')]
    public function testTheEffortOfEveryCopy(array $variantOverrides): void
    {
        $rowName = (string)$this->dataName();
        $variant = $variantOverrides + self::DEFAULT_VARIANT;
        $this->useDecisionModelSettings(['mode' => 'on', 'sites' => [AgentDecisionModelSettings::SITE_REASONING_EFFORT => $variant['siteMode']]]);
        $decisionModelSettings = AgentDecisionModelSettings::current();
        $worksAutonomously = $variant['origin'] === 'autonomous';

        // E1 — the effort the model call is sent with
        $sentEffort = $this->effortService($variant)->resolveDecidedReasoningEffortForCurrentTurn($this->conversationFor($variant), $worksAutonomously);
        // E2 — the active effort the decision model is told
        $effortServiceDouble = $this->effortService($variant);
        $floorReflection = new ReflectionMethod($effortServiceDouble, 'resolveFloorReasoningEffortForCurrentTurn');
        /** @var AgentReasoningEffortFloor $agentReasoningEffortFloor */
        $agentReasoningEffortFloor = $floorReflection->invoke($effortServiceDouble, $this->conversationFor($variant), $decisionModelSettings);
        [$floorEffort, $floorSource, $ceilingEffort] = [$agentReasoningEffortFloor->effort, $agentReasoningEffortFloor->source, $agentReasoningEffortFloor->ceilingEffort];
        // E3 — the stamped effective effort on a fallback and on a parsable answer that raises nothing
        $fallbackStamp = $this->stampAfterTheTurnStart($variant, $floorEffort, $floorSource, AgentReasoningEffortDecision::FALLBACK_TRANSPORT);
        $answerStamp = $this->stampAfterTheTurnStart($variant, $floorEffort, $floorSource, null);
        // E4 — the control panel's baseline, through the wrapper its controllers call (the autonomy read from the purpose)
        $baselineConversation = $this->conversationFor($variant);
        $baselineEffortService = $this->effortService($variant);
        $baselineEffortService->purposeRunsServiceDouble = new PurposeRunsServiceWithScriptedAnswersDouble();
        $baselineEffortService->purposeRunsServiceDouble->purposeWorksAutonomously = $worksAutonomously;
        $agentDecisionLayerStatus = AgentDecisionLayerStatus::forConversation(
            $baselineConversation,
            $decisionModelSettings,
            $baselineEffortService->buildReasoningEffortLimitsForConversation($baselineConversation, $decisionModelSettings)
        );

        $actualCells = [
            'E1 sent: ' . json_encode($sentEffort),
            'E2 floor: ' . $floorEffort . ' (' . $floorSource . ') ceiling ' . $ceilingEffort,
            'E3 fallback: ' . json_encode($fallbackStamp?->effectiveEffort) . ' applied=' . json_encode($fallbackStamp?->isApplied()) . ' decidedBy=' . json_encode($fallbackStamp?->decidedBy),
            'E3 answer: ' . json_encode($answerStamp?->effectiveEffort) . ' applied=' . json_encode($answerStamp?->isApplied()) . ' decidedBy=' . json_encode($answerStamp?->decidedBy),
            'E4 baseline: ' . json_encode($agentDecisionLayerStatus->reasoningEffortBaseline) . ' (' . $agentDecisionLayerStatus->reasoningEffortBaselineSource . ')',
        ];
        self::assertSame(ReasoningEffortMatrixCells::CELLS[$rowName] ?? ['<not pinned>'], $actualCells, $rowName);
    }

    /**
     * The wrapper `buildReasoningEffortLimitsForConversation()` a control panel calls: whether the conversation works
     * autonomously comes from its purpose (a delegation works without an owner turn either way), the world reads are
     * the loop's; an unreadable purpose leaves only the limits the conversation itself carries.
     */
    public function testTheControllersWrapperReadsTheAutonomyFromThePurposeAndFallsBackToTheConversationsOwnLimits(): void
    {
        $this->useDecisionModelSettings(['mode' => 'on', 'sites' => [AgentDecisionModelSettings::SITE_REASONING_EFFORT => 'on']]);
        $decisionModelSettings = AgentDecisionModelSettings::current();
        $variant = ['catalogEffort' => 'medium', 'ceilingEffort' => 'medium', 'purposeBaseEffort' => 'low', 'purposeMinimumEffort' => 'low', 'fixedEffort' => 'high'] + self::DEFAULT_VARIANT;
        $cells = [];
        foreach (['root, purpose not autonomous' => [false, 'root', false], 'root, autonomous purpose' => [true, 'root', false], 'delegation' => [false, 'delegation', false], 'purpose unreadable' => [true, 'root', true]] as $caseName => [$worksAutonomously, $origin, $purposeReadThrows]) {
            $effortService = $this->effortService(['origin' => $origin] + $variant);
            $effortService->purposeRunsServiceDouble = new PurposeRunsServiceWithScriptedAnswersDouble();
            $effortService->purposeRunsServiceDouble->purposeWorksAutonomously = $worksAutonomously;
            $effortService->purposeReadThrows = $purposeReadThrows;
            $agentReasoningEffortLimits = $effortService->buildReasoningEffortLimitsForConversation($this->conversationFor(['origin' => $origin] + $variant), $decisionModelSettings);
            $cells[] = $caseName . ': ' . json_encode([
                'configured' => $agentReasoningEffortLimits->configuredEffort,
                'ceiling' => $agentReasoningEffortLimits->ceilingEffort,
                'purposeBase' => $agentReasoningEffortLimits->purposeBaseEffort,
                'purposeMinimum' => $agentReasoningEffortLimits->purposeMinimum,
                'fixed' => $agentReasoningEffortLimits->fixedEffort,
                'siteMode' => $agentReasoningEffortLimits->siteMode,
                'worksWithoutAnOwnerTurn' => $agentReasoningEffortLimits->worksWithoutAnOwnerTurn,
            ]);
        }
        self::assertSame([
            'root, purpose not autonomous: {"configured":"medium","ceiling":"medium","purposeBase":"low","purposeMinimum":"low","fixed":"high","siteMode":"on","worksWithoutAnOwnerTurn":false}',
            'root, autonomous purpose: {"configured":"medium","ceiling":"medium","purposeBase":"low","purposeMinimum":"low","fixed":"high","siteMode":"on","worksWithoutAnOwnerTurn":true}',
            'delegation: {"configured":"medium","ceiling":"medium","purposeBase":"low","purposeMinimum":"low","fixed":"high","siteMode":"on","worksWithoutAnOwnerTurn":true}',
            'purpose unreadable: {"configured":null,"ceiling":"high","purposeBase":null,"purposeMinimum":null,"fixed":"high","siteMode":"on","worksWithoutAnOwnerTurn":false}',
        ], $cells);
    }

    protected function taskPlanWithOneItem(string $text, string $minimumReasoningEffort): TaskPlan
    {
        $taskPlanItem = new TaskPlanItem();
        $taskPlanItem->key = '1';
        $taskPlanItem->text = $text;
        $taskPlanItem->status = TaskPlanItem::STATUS_OPEN;
        $taskPlanItem->minReasoningEffort = $minimumReasoningEffort;
        $taskPlanItems = new TaskPlanItems();
        $taskPlanItems->add($taskPlanItem);
        $taskPlan = new TaskPlan();
        $taskPlan->items = $taskPlanItems;
        return $taskPlan;
    }

    protected function useDecisionModelSettings(array $configured): void
    {
        AgentDecisionModelSettings::useForCurrentProcess(AgentDecisionModelSettings::fromConfigured($configured));
    }

    /** @param array<string, mixed> $variant */
    protected function effortService(array $variant): ReasoningEffortServiceWithScriptedReadsDouble
    {
        $reasoningEffortServiceDouble = new ReasoningEffortServiceWithScriptedReadsDouble($this->worldLog);
        $reasoningEffortServiceDouble->catalogConfiguredEffort = $variant['catalogEffort'];
        $reasoningEffortServiceDouble->catalogCeilingEffort = $variant['ceilingEffort'];
        $reasoningEffortServiceDouble->purposeBaseEffort = $variant['purposeBaseEffort'];
        $reasoningEffortServiceDouble->purposeMinimumEffort = $variant['purposeMinimumEffort'];
        return $reasoningEffortServiceDouble;
    }

    /** @param array<string, mixed> $variant */
    protected function conversationFor(array $variant): AIConversation
    {
        $agentWorkingState = new AgentWorkingState();
        if ($variant['stamp'] !== 'none') {
            $reasoningEffortDecision = new AgentReasoningEffortDecision();
            $reasoningEffortDecision->ownerMessageIndex = $variant['stamp'] === 'stale' ? 0 : 2;
            $reasoningEffortDecision->decidedBy = $variant['stamp'] === 'shadow' ? AgentReasoningEffortDecision::DECIDED_BY_SHADOW : AgentReasoningEffortDecision::DECIDED_BY_DECISION_MODEL;
            $reasoningEffortDecision->effectiveEffort = AgentDecisionModelSettings::REASONING_EFFORT_HIGH;
            $agentWorkingState->reasoningEffortDecision = $reasoningEffortDecision;
        }
        if ($variant['planItemMinimumEffort'] !== null) {
            $agentWorkingState->taskPlan = $this->taskPlanWithOneItem('Write the handover', $variant['planItemMinimumEffort']);
        }
        $conversation = $this->ownerTurnConversation($agentWorkingState);
        if ($variant['origin'] === 'delegation') {
            $conversation->delegatedFromMessageId = 770002;
        }
        if ($variant['pin'] !== null || $variant['killSwitch'] || $variant['fixedEffort'] !== null) {
            $runSettings = new RunSettings();
            $runSettings->decisionModelReasoningEffort = $variant['pin'];
            $runSettings->decisionModelOff = $variant['killSwitch'] ? true : null;
            $runSettings->fixedReasoningEffort = $variant['fixedEffort'];
            $conversation->runSettings = $runSettings;
        }
        return $conversation;
    }

    /**
     * The stamp the turn-start site leaves for this variant: a transport fallback (`$requestDecidedBy`) or a parsable
     * answer whose four probabilities raise nothing. Null when the site mode is `off` (the site is never applied then).
     *
     * @param array<string, mixed> $variant
     */
    protected function stampAfterTheTurnStart(array $variant, string $floorEffort, string $floorSource, ?string $requestDecidedBy): ?AgentReasoningEffortDecision
    {
        $conversation = $this->conversationFor($variant);
        $siteMode = AgentDecisionModelSettings::current()->siteModeForConversation(AgentDecisionModelSettings::SITE_REASONING_EFFORT, $conversation);
        if ($siteMode === AgentDecisionModelSettings::MODE_OFF) {
            return null;
        }
        $reasoningEffortInput = $this->reasoningEffortQuestion(true, $floorEffort);
        $reasoningEffortInput->mode = $siteMode;
        $reasoningEffortInput->floorSource = $floorSource;
        $argusTurnStartDecision = null;
        if ($requestDecidedBy === null) {
            $argusTurnStartDecision = new ArgusAgentTurnStartDecision();
            $argusTurnStartDecision->fillReasoningEffortQuestions($reasoningEffortInput);
            $argusTurnStartDecision->disputesWhatTheAssistantClaimsProbability = 0.1;
            $argusTurnStartDecision->rejectsIdentityOrClassProbability = 0.1;
            $argusTurnStartDecision->asksForADifferentCourseProbability = 0.1;
        }
        $this->effortService($variant)->applyReasoningEffortDecision($conversation, $reasoningEffortInput, $argusTurnStartDecision, $requestDecidedBy, AgentDecisionModelSettings::current(), null, null, null);
        return $conversation->getAgentWorkingState()?->reasoningEffortDecision;
    }
}
