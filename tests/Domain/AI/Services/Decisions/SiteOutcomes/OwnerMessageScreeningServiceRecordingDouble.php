<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Decisions\SiteOutcomes;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentOwnerMessageInjectionDecision;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationOwnerMessageScreeningService;

/**
 * The owner-message injection screen run as it is (its "already checked" scan of the card log included), every call a
 * world line. {@see $skipsTheCheck} makes the site contribute no child.
 */
class OwnerMessageScreeningServiceRecordingDouble extends AIConversationOwnerMessageScreeningService
{
    public bool $skipsTheCheck = false;

    public function __construct(public DecisionSiteWorldLog $worldLog)
    {
    }

    public function findOwnerMessageForInjectionCheck(AIConversation $conversation, AgentDecisionModelSettings $decisionModelSettings): ?AIConversationMessage
    {
        $ownerMessage = $this->skipsTheCheck ? null : parent::findOwnerMessageForInjectionCheck($conversation, $decisionModelSettings);
        $this->worldLog->record('site find ownerMessageInjection → ' . ($ownerMessage === null ? 'null' : '@' . $ownerMessage->indexInConversation));
        return $ownerMessage;
    }

    public function buildInjectionCheckRequest(AIConversation $conversation, AIConversationMessage $ownerMessage): ArgusAgentOwnerMessageInjectionDecision
    {
        $this->worldLog->record('site build ownerMessageInjection request @' . $ownerMessage->indexInConversation);
        return parent::buildInjectionCheckRequest($conversation, $ownerMessage);
    }

    public function applyDecision(
        AIConversation $conversation,
        AIConversationMessage $ownerMessage,
        ?ArgusAgentOwnerMessageInjectionDecision $argusOwnerMessageInjectionDecision,
        AgentDecisionModelSettings $decisionModelSettings,
        ?int $elapsedMs,
        bool $requestRefused = false
    ): void {
        $this->worldLog->record('site apply ownerMessageInjection child=' . ($argusOwnerMessageInjectionDecision === null ? 'none' : 'sent'));
        parent::applyDecision($conversation, $ownerMessage, $argusOwnerMessageInjectionDecision, $decisionModelSettings, $elapsedMs, $requestRefused);
    }
}
