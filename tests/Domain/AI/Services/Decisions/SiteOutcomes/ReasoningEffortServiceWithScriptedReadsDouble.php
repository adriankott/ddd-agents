<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Decisions\SiteOutcomes;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentReasoningEffortCatalogEfforts;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentReasoningEffortDecision;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentReasoningEffortDecisionQuestion;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentTurnStartDecision;
use DDD\Domain\AI\Services\Conversations\Agent\AgentReasoningEffortDecisionsService;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationPurposeRunsService;
use RuntimeException;

/**
 * The reasoning-effort site with the three reads behind every effort answer given by the test — the model catalog's
 * configured effort and ceiling, the purpose's base effort and its minimum — and everything else run as it is. Its
 * request input is the test's for the turn start unless {@see $runsThePackagesRequestInput}.
 */
class ReasoningEffortServiceWithScriptedReadsDouble extends AgentReasoningEffortDecisionsService
{
    /** The question the site contributes this turn (null = it does not run). */
    public ?AgentReasoningEffortDecisionQuestion $scriptedInput = null;

    public bool $runsThePackagesRequestInput = false;

    /** The catalog's configured agentic effort of the conversation's model (null = the model declares none). */
    public ?string $catalogConfiguredEffort = AgentDecisionModelSettings::REASONING_EFFORT_LOW;

    public string $catalogCeilingEffort = AgentReasoningEffortDecision::DEFAULT_CEILING;

    /** The purpose's declared base effort, null = none. */
    public ?string $purposeBaseEffort = null;

    /** The purpose's declared minimum, null = none. */
    public ?string $purposeMinimumEffort = null;

    /** The purpose questions of the wrapper (null = the container's service). */
    public ?PurposeRunsServiceWithScriptedAnswersDouble $purposeRunsServiceDouble = null;

    /** True = the wrapper's purpose read throws (its fallback to the conversation's own limits). */
    public bool $purposeReadThrows = false;

    public function __construct(public ?DecisionSiteWorldLog $worldLog = null)
    {
    }

    public function buildReasoningEffortRequestInput(AIConversation $conversation, AgentDecisionModelSettings $decisionModelSettings): ?AgentReasoningEffortDecisionQuestion
    {
        if ($this->runsThePackagesRequestInput) {
            return parent::buildReasoningEffortRequestInput($conversation, $decisionModelSettings);
        }
        $this->worldLog?->record('site build reasoningEffort → ' . ($this->scriptedInput === null ? 'null' : 'input askDecisionModel=' . json_encode($this->scriptedInput->askDecisionModel)));
        return $this->scriptedInput;
    }

    public function applyReasoningEffortDecision(
        AIConversation $conversation,
        AgentReasoningEffortDecisionQuestion $reasoningEffortQuestion,
        ?ArgusAgentTurnStartDecision $argusDecision,
        ?string $requestDecidedBy,
        AgentDecisionModelSettings $decisionModelSettings,
        ?string $runToken,
        ?int $elapsedMs,
        ?string $transportFailureReason
    ): void {
        $this->worldLog?->record('site apply reasoningEffort batchDecidedBy=' . ($requestDecidedBy ?? 'null') . ' primary=' . ($argusDecision === null ? 'none' : 'sent') . ' failure=' . ($transportFailureReason ?? 'null'));
        parent::applyReasoningEffortDecision($conversation, $reasoningEffortQuestion, $argusDecision, $requestDecidedBy, $decisionModelSettings, $runToken, $elapsedMs, $transportFailureReason);
    }

    protected function purposeRunsService(): AIConversationPurposeRunsService
    {
        if ($this->purposeReadThrows) {
            throw new RuntimeException('the purpose cannot be read');
        }
        return $this->purposeRunsServiceDouble ?? parent::purposeRunsService();
    }

    protected function resolveConfiguredAndCeilingReasoningEffort(AIConversation $conversation): AgentReasoningEffortCatalogEfforts
    {
        return AgentReasoningEffortCatalogEfforts::forConfiguredAndCeiling($this->catalogConfiguredEffort, $this->catalogCeilingEffort);
    }

    protected function resolvePurposeBaseReasoningEffort(AIConversation $conversation): ?string
    {
        return $this->purposeBaseEffort;
    }

    protected function resolvePurposeMinReasoningEffort(AIConversation $conversation): ?string
    {
        return $this->purposeMinimumEffort;
    }
}
