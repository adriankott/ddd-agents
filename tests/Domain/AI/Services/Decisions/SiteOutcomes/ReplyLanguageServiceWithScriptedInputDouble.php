<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Decisions\SiteOutcomes;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentReplyLanguageDecisionQuestion;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentTurnStartDecision;
use DDD\Domain\AI\Services\Conversations\Agent\AgentReplyLanguageDecisionsService;
use Throwable;

/**
 * The reply-language site with its request INPUT given by the test (the directives, the locales and the anchor are the
 * row's) and its application run as it is; every call is a world line.
 */
class ReplyLanguageServiceWithScriptedInputDouble extends AgentReplyLanguageDecisionsService
{
    /** The question the site contributes this turn (null = it does not run). */
    public ?AgentReplyLanguageDecisionQuestion $scriptedInput = null;

    /** Thrown by the application instead of applying (a failure inside the turn-start sequence). */
    public ?Throwable $applyFailure = null;

    public function __construct(public DecisionSiteWorldLog $worldLog)
    {
    }

    public function buildRequestInput(AIConversation $conversation, AgentDecisionModelSettings $decisionModelSettings): ?AgentReplyLanguageDecisionQuestion
    {
        $this->worldLog->record('site build replyLanguage → ' . ($this->scriptedInput === null ? 'null' : 'input'));
        return $this->scriptedInput;
    }

    public function applyDecision(
        AIConversation $conversation,
        AgentReplyLanguageDecisionQuestion $replyLanguageQuestion,
        ?ArgusAgentTurnStartDecision $argusReplyLanguageDecision,
        ?string $decidedBy,
        AgentDecisionModelSettings $decisionModelSettings,
        ?int $elapsedMs,
        ?string $transportFailureReason,
        ?string $rawResponseExcerpt
    ): void {
        $this->worldLog->record('site apply replyLanguage batchDecidedBy=' . ($decidedBy ?? 'null') . ' primary=' . ($argusReplyLanguageDecision === null ? 'none' : 'sent') . ' failure=' . ($transportFailureReason ?? 'null'));
        if ($this->applyFailure !== null) {
            throw $this->applyFailure;
        }
        parent::applyDecision($conversation, $replyLanguageQuestion, $argusReplyLanguageDecision, $decidedBy, $decisionModelSettings, $elapsedMs, $transportFailureReason, $rawResponseExcerpt);
    }
}
