<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Decisions\SiteOutcomes;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCallInjectionScreeningVerdict;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;

/**
 * The messages service of the tool-result screen: the verdict is applied to the in-memory call and logged as
 * `result tool call {injectionScreening, injectionProbability, result (first 120 characters)}`. No row is touched.
 */
class ToolCallVerdictRecordingMessagesServiceDouble extends AIConversationMessagesService
{
    public function __construct(public DecisionSiteWorldLog $worldLog)
    {
    }

    public function persistToolCallVerdictWhileScreeningPending(AIConversationMessage $callMessage, ToolCallInjectionScreeningVerdict $injectionScreeningVerdict): bool
    {
        $injectionScreeningVerdict->applyTo($callMessage->toolCall);
        $this->worldLog->record('result tool call ' . json_encode([
            'injectionScreening' => $callMessage->toolCall->injectionScreening ?? null,
            'injectionProbability' => $callMessage->toolCall->injectionProbability ?? null,
            'result' => mb_substr((string)($callMessage->toolCall->result ?? ''), 0, 120),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return true;
    }
}
