<?php

declare(strict_types=1);

namespace DDD\Domain\AI\MessageHandlers;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\UsageLogs\AIBudgetBypassGrant;
use DDD\Domain\AI\Exceptions\Conversations\Memories\AgentMemoryCurationUnavailableException;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoryCurationService;
use DDD\Domain\AI\Traits\Conversations\AITraceLogTrait;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * Runs the memory curation of one conversation. A conversation whose turn is still running is put back (at most
 * {@see MAX_REQUEUE_ATTEMPTS} times), as is one whose curation model gave no answer; any other failure is logged and
 * never reaches the owner.
 */
#[AsMessageHandler(fromTransport: AgentMessengerTransports::COMPACTION)]
class AgentMemoryCurateHandler extends AIDomainMessageHandler
{
    use AITraceLogTrait;

    /** @var int How often one harvest is put back */
    public const int MAX_REQUEUE_ATTEMPTS = 5;

    public function __invoke(AgentMemoryCurateMessage $message): void
    {
        set_time_limit(900); // extraction, reconciliation and the conflict excerpt are several model calls
        DDDService::instance()->raiseMemoryLimitToAtLeast('1024M');
        $this->setAuthAccountFromMessage($message);
        $this->aiTrace('memory.curate.handler.received', ['conversationId' => $message->aiConversationId, 'requeueAttempt' => $message->requeueAttempts]);

        try {
            $conversation = AIConversation::getService()->find($message->aiConversationId, useEntityRegistrCache: false);
            if (!$conversation instanceof AIConversation) {
                return;
            }
            $aIConversationsService = AIConversation::getService();
            if ($aIConversationsService->refusesAutomationForConversation($conversation, 'memoryCurate')) {
                $this->aiTrace('memory.curate.handler.suppressed', ['conversationId' => $message->aiConversationId]);
                return;
            }
            if ($conversation->getRunState()->isNonTerminal()) {
                $this->requeue($message);
                $this->aiTrace('memory.curate.handler.deferred', ['conversationId' => $message->aiConversationId, 'attempt' => $message->requeueAttempts]);
                return;
            }
            if ($conversation->budgetBypass) {
                $aIUsageEnvelopesService = DDDService::instance()->getService(AIUsageEnvelopesService::class);
                $aIUsageEnvelopesService->grantBudgetBypassIfAnEnvelopeIsOpen(AIBudgetBypassGrant::forReason(AIBudgetBypassGrant::REASON_CONVERSATION_BYPASS));
            }
            /** @var AgentMemoryCurationService $agentMemoryCurationService */
            $agentMemoryCurationService = DDDService::instance()->getService(AgentMemoryCurationService::class);
            $agentMemoryReconcileResult = $agentMemoryCurationService->curateConversation($conversation, async: true);
            $this->aiTrace('memory.curate.handler.done', [
                'conversationId' => $message->aiConversationId,
                'written' => $agentMemoryReconcileResult->applied->count(),
                'conflicts' => $agentMemoryReconcileResult->conflicts->count(),
            ]);
        } catch (AgentMemoryCurationUnavailableException $curationUnavailable) {
            $this->requeue($message);
            $this->getLogger()->warning(sprintf(
                'AgentMemoryCurateHandler: conversation %d — the curation model gave no answer, the messages stay for a later harvest (attempt %d/%d): %s',
                $message->aiConversationId,
                $message->requeueAttempts,
                static::MAX_REQUEUE_ATTEMPTS,
                mb_substr($curationUnavailable->getMessage(), 0, 300)
            ));
        } catch (Throwable $throwable) {
            $this->aiTrace('memory.curate.handler.error', ['conversationId' => $message->aiConversationId, 'message' => $throwable->getMessage()]);
            $this->logShortException($this->getLogger(), "AgentMemoryCurateHandler: harvest failed for conversation {$message->aiConversationId}", $throwable);
            $this->logIssue($throwable, $message, "AgentMemoryCurateHandler: harvest failed for conversation {$message->aiConversationId}");
        }
    }

    /**
     * Puts the harvest back with one more attempt counted, unless it ran out of attempts.
     *
     * @param AgentMemoryCurateMessage $message
     * @return void
     */
    protected function requeue(AgentMemoryCurateMessage $message): void
    {
        if ($message->requeueAttempts >= static::MAX_REQUEUE_ATTEMPTS) {
            return;
        }
        $requeuedMessage = new AgentMemoryCurateMessage($message->aiConversationId);
        $requeuedMessage->requeueAttempts = $message->requeueAttempts + 1;
        $requeuedMessage->dispatch();
    }
}
