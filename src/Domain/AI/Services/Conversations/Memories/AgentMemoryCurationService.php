<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Memories;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Memories\AgentMemories;
use DDD\Domain\AI\Entities\Conversations\Memories\AgentMemory;
use DDD\Domain\AI\Entities\Conversations\Memories\Curation\AgentMemoryConflictProvenance;
use DDD\Domain\AI\Entities\Conversations\Memories\Curation\AgentMemoryOperation;
use DDD\Domain\AI\Entities\Conversations\Memories\Curation\AgentMemoryOperations;
use DDD\Domain\AI\Entities\Conversations\Memories\Curation\AgentMemoryReconcileResult;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Repo\Argus\Conversations\Memories\ArgusAgentMemoryConflictExcerpt;
use DDD\Domain\AI\Repo\Argus\Conversations\Memories\ArgusAgentMemoryExtraction;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Traits\Conversations\AITraceLogTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use DateTimeZone;
use Throwable;

/**
 * The memory curation of a conversation: the owner's new messages (the delta since the last harvest) go through the
 * extraction (candidates) and the reconciliation against the nearest stored memories (operations), and the operations
 * are applied to the store. A contradiction nobody can settle is not stored: it reaches the agent as a MEMORY_CONFLICT
 * signal, so the agent asks the owner. The delta counts as harvested afterwards, also when nothing was stored.
 *
 * Scope: a memory about the owner as a person belongs to the account, a memory about the business to the
 * conversation's space (the account level when the conversation has none).
 */
class AgentMemoryCurationService extends Service
{
    use AITraceLogTrait;

    /** @var int The nearest stored memories looked up per candidate */
    public const int NEIGHBOUR_TOP_K = 8;

    /** @var int The characters of new messages one harvest reads */
    public const int MAX_DELTA_CHARS = 2000;

    /** @var int The characters of earlier messages one harvest reads as context */
    public const int MAX_CONTEXT_CHARS = 2000;

    /** @var string The config key of the application's data with a home of its own (string[], one entry each) */
    public const string CONFIG_STRUCTURED_HOMES = 'AI.agentMemory.structuredHomes';

    /**
     * Harvests the conversation's new owner messages into the store.
     *
     * @param AIConversation $conversation
     * @param bool $async True in the worker: a raised conflict resumes the conversation so the agent asks at once
     * @return AgentMemoryReconcileResult
     */
    public function curateConversation(AIConversation $conversation, bool $async = false): AgentMemoryReconcileResult
    {
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        $curationWindow = $aIConversationMessagesService->findMemoryCurationWindow($conversation, static::MAX_DELTA_CHARS, static::MAX_CONTEXT_CHARS);
        if (!$curationWindow->hasDelta()) {
            $this->aiTrace('memory.curate.delta.empty', ['conversationId' => $conversation->id ?? null]);
            return new AgentMemoryReconcileResult();
        }
        $delta = $curationWindow->delta;
        $deltaMessageIds = $this->collectMessageIds($delta);
        $this->removeSecurityFilteredOwnerMessages($conversation, $delta);
        if ($delta->count() === 0) {
            $aIConversationMessagesService->markMessagesIngestedIntoMemory($deltaMessageIds);
            $this->aiTrace('memory.curate.delta.securityFiltered', ['conversationId' => $conversation->id ?? null]);
            return new AgentMemoryReconcileResult();
        }

        $agentReconcileResult = $this->curateAndApply(
            $delta,
            $curationWindow->earlierContext,
            (int)$conversation->accountId,
            $this->findAgentSpaceIdOfConversation($conversation),
            $this->findMemoryLanguageCode($conversation),
            $this->findStructuredHomes($conversation),
            $this->findLastMessageId($delta),
            $this->findBudgetAccount($conversation),
            $this->findBillingEntity($conversation),
            $this->findSourceTimezoneIdentifier($conversation, $delta)
        );
        $aIConversationMessagesService->markMessagesIngestedIntoMemory($deltaMessageIds);

        if ($agentReconcileResult->conflicts->count() > 0) {
            $this->injectConflictSignal($conversation, $agentReconcileResult->conflicts, $agentReconcileResult->orderedNeighbours);
            if ($async) {
                /** @var AIConversationAgentService $aIConversationAgentService */
                $aIConversationAgentService = DDDService::instance()->getService(AIConversationAgentService::class);
                $aIConversationAgentService->resumeConversationIfReady($conversation, async: true);
            }
        }
        return $agentReconcileResult;
    }

    /**
     * Extraction, reconciliation and the writes for one delta. The result carries every model call of the pass in
     * call order, also when the pass ends after an extraction without a candidate.
     *
     * @param AIConversationMessages $delta The new messages
     * @param AIConversationMessages|null $recentContext The messages before them
     * @param int $accountId
     * @param int|null $agentSpaceId The conversation's space; empty = account level
     * @param string $memoryLanguageCode
     * @param string $structuredHomes
     * @param int|null $fallbackSourceMessageId The message a memory is attributed to when its operation names none
     * @param Account|null $budgetAccount
     * @param Entity|null $billingEntity
     * @param string|null $sourceTimezoneIdentifier
     * @return AgentMemoryReconcileResult
     */
    public function curateAndApply(
        AIConversationMessages $delta,
        ?AIConversationMessages $recentContext,
        int $accountId,
        ?int $agentSpaceId,
        string $memoryLanguageCode,
        string $structuredHomes,
        ?int $fallbackSourceMessageId = null,
        ?Account $budgetAccount = null,
        ?Entity $billingEntity = null,
        ?string $sourceTimezoneIdentifier = null
    ): AgentMemoryReconcileResult {
        /** @var AgentMemoryCurationModelCallsService $agentMemoryCurationModelCallsService */
        $agentMemoryCurationModelCallsService = DDDService::instance()->getService(AgentMemoryCurationModelCallsService::class);
        $sourceTimezone = $sourceTimezoneIdentifier !== null ? new DateTimeZone($sourceTimezoneIdentifier) : null;

        $extractionResult = $agentMemoryCurationModelCallsService->extractCandidates(
            $delta,
            $recentContext,
            $memoryLanguageCode,
            $structuredHomes,
            $budgetAccount,
            $billingEntity,
            $sourceTimezone
        );
        $candidates = $extractionResult->operations->getOperationsWithContent();
        $this->aiTrace('memory.curate.extracted', ['candidates' => $this->describeOperations($candidates)]);
        $agentReconcileResult = new AgentMemoryReconcileResult();
        $agentReconcileResult->modelCalls->appendModelCalls($extractionResult->modelCalls);
        if ($candidates->count() === 0) {
            return $agentReconcileResult;
        }

        $orderedNeighbours = $this->retrieveNeighbours($candidates, $accountId, $agentSpaceId);
        $reconciliationResult = $agentMemoryCurationModelCallsService->reconcileCandidatesRoutingByResolution(
            $candidates,
            $orderedNeighbours,
            $this->buildScopeLabel($accountId, $agentSpaceId),
            $memoryLanguageCode,
            $budgetAccount,
            $billingEntity,
            $sourceTimezone
        );
        $agentReconcileResult->modelCalls->appendModelCalls($reconciliationResult->modelCalls);
        $agentReconcileResult->operations = $reconciliationResult->operations;
        $agentReconcileResult->orderedNeighbours = $orderedNeighbours;
        $this->aiTrace('memory.curate.reconciled', ['operations' => $this->describeOperations($reconciliationResult->operations)]);

        $deltaMessagesById = [];
        foreach ($delta->getElements() as $deltaMessage) {
            if (isset($deltaMessage->id)) {
                $deltaMessagesById[(int)$deltaMessage->id] = $deltaMessage;
            }
        }
        foreach ($reconciliationResult->operations->getElements() as $agentMemoryOperation) {
            if (!$agentMemoryOperation->changesStore()) {
                continue;
            }
            if ($agentMemoryOperation->requiresClarification()) {
                $agentReconcileResult->conflicts->add($agentMemoryOperation);
                continue;
            }
            $sourceMessage = $deltaMessagesById[$agentMemoryOperation->sourceMessageId ?? -1]
                ?? ($deltaMessagesById[$fallbackSourceMessageId ?? -1] ?? null);
            $this->applyOperation(
                $agentMemoryOperation,
                $orderedNeighbours,
                $accountId,
                $agentSpaceId,
                $sourceMessage !== null && isset($sourceMessage->id) ? (int)$sourceMessage->id : $fallbackSourceMessageId,
                $agentReconcileResult->applied,
                $sourceMessage?->getCreatedTime(),
                $sourceTimezoneIdentifier
            );
        }
        $this->aiTrace('memory.curate.applied', [
            'written' => $agentReconcileResult->applied->count(),
            'conflicts' => $agentReconcileResult->conflicts->count(),
        ]);
        return $agentReconcileResult;
    }

    /**
     * Applies one store-changing operation. ADD stores the content unless the scope holds it already. UPDATE and
     * INVALIDATE replace their target with the content: the successor is stored (an UPDATE keeps the target's "true
     * since"), the target is closed and points at it; a target that cannot be verified falls back to an ADD.
     *
     * @param AgentMemoryOperation $agentMemoryOperation
     * @param AgentMemories $orderedNeighbours
     * @param int $accountId
     * @param int|null $agentSpaceId
     * @param int|null $sourceMessageId
     * @param AgentMemories $applied Receives what was written
     * @param DateTime|null $sourceInstant
     * @param string|null $sourceTimezone
     * @return void
     */
    public function applyOperation(
        AgentMemoryOperation $agentMemoryOperation,
        AgentMemories $orderedNeighbours,
        int $accountId,
        ?int $agentSpaceId,
        ?int $sourceMessageId,
        AgentMemories $applied,
        ?DateTime $sourceInstant = null,
        ?string $sourceTimezone = null
    ): void {
        if ($agentMemoryOperation->operation === AgentMemoryOperation::OPERATION_ADD) {
            $this->addMemoryFromOperation($agentMemoryOperation, $accountId, $agentSpaceId, $sourceMessageId, $applied, $sourceInstant, $sourceTimezone);
            return;
        }
        if ($agentMemoryOperation->operation !== AgentMemoryOperation::OPERATION_UPDATE
            && $agentMemoryOperation->operation !== AgentMemoryOperation::OPERATION_INVALIDATE) {
            return;
        }
        $target = $this->resolveTarget($agentMemoryOperation, $orderedNeighbours, $agentSpaceId);
        if (!$target instanceof AgentMemory || !isset($target->id) || !$this->operationTargetEchoMatches($agentMemoryOperation, $target)) {
            $this->aiTrace('memory.curate.supersede.fallbackAdd', ['content' => $agentMemoryOperation->content, 'targetRef' => $agentMemoryOperation->targetMemoryRef]);
            $this->addMemoryFromOperation($agentMemoryOperation, $accountId, $agentSpaceId, $sourceMessageId, $applied, $sourceInstant, $sourceTimezone);
            return;
        }
        $agentMemoryStoreService = $this->getAgentMemoryStoreService();
        $scopeAgentSpaceId = $this->resolveScopeAgentSpaceId($agentMemoryOperation, $agentSpaceId);
        $existingSuccessor = $agentMemoryStoreService->findCurrentDuplicateOfContent($accountId, $scopeAgentSpaceId, (string)$agentMemoryOperation->content);
        if ($existingSuccessor instanceof AgentMemory && isset($existingSuccessor->id)) {
            if ((int)$existingSuccessor->id === (int)$target->id) {
                return;
            }
            $agentMemoryStoreService->closeValidityWindow((int)$target->id, (int)$existingSuccessor->id, $existingSuccessor->validFrom ?? new DateTime('now'));
            $applied->add($existingSuccessor);
            return;
        }
        $successor = $this->buildMemory($agentMemoryOperation, $accountId, $agentSpaceId, $sourceMessageId, $sourceInstant, $sourceTimezone);
        if ($agentMemoryOperation->operation === AgentMemoryOperation::OPERATION_UPDATE && isset($target->validFrom)) {
            $successor->validFrom = $target->validFrom;
        }
        $successor = $agentMemoryStoreService->persistMemory($successor);
        $agentMemoryStoreService->closeValidityWindow((int)$target->id, (int)$successor->id, $successor->validFrom ?? new DateTime('now'));
        $applied->add($successor);
    }

    /**
     * Whether the first words the model copied (`targetEcho`) match the target it named: equal, or one starts with the
     * other after normalization. No echo, or a target without content, counts as a match.
     *
     * @param AgentMemoryOperation $agentMemoryOperation
     * @param AgentMemory $target
     * @return bool
     */
    public function operationTargetEchoMatches(AgentMemoryOperation $agentMemoryOperation, AgentMemory $target): bool
    {
        $echo = $this->normalizeForEchoMatch((string)($agentMemoryOperation->targetEcho ?? ''));
        if ($echo === '') {
            return true;
        }
        $targetContent = $this->normalizeForEchoMatch($target->content);
        if ($targetContent === '') {
            return true;
        }
        return $echo === $targetContent || str_starts_with($targetContent, $echo) || str_starts_with($echo, $targetContent);
    }

    /**
     * The moment a stated `YYYY`, `YYYY-MM` or `YYYY-MM-DD` names (the first day of the period), or null for another
     * shape, an impossible date or a future one.
     *
     * @param string|null $effectiveFrom
     * @return DateTime|null
     */
    public function parseEffectiveFrom(?string $effectiveFrom): ?DateTime
    {
        if ($effectiveFrom === null) {
            return null;
        }
        $normalizedDate = match (true) {
            preg_match('/^\d{4}$/', $effectiveFrom) === 1 => $effectiveFrom . '-01-01',
            preg_match('/^\d{4}-\d{2}$/', $effectiveFrom) === 1 => $effectiveFrom . '-01',
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveFrom) === 1 => $effectiveFrom,
            default => null,
        };
        if ($normalizedDate === null) {
            return null;
        }
        $dateParts = array_map('intval', explode('-', $normalizedDate));
        if (!checkdate($dateParts[1], $dateParts[2], $dateParts[0])) {
            return null;
        }
        $parsedMoment = new DateTime($normalizedDate . ' 00:00:00');
        return $parsedMoment->getTimestamp() > (new DateTime('now'))->getTimestamp() ? null : $parsedMoment;
    }

    /**
     * The space a memory of this operation belongs to: the conversation's space for a memory about the business, none
     * for a memory about the owner as a person (account level).
     *
     * @param AgentMemoryOperation $agentMemoryOperation
     * @param int|null $agentSpaceId
     * @return int|null
     */
    public function resolveScopeAgentSpaceId(AgentMemoryOperation $agentMemoryOperation, ?int $agentSpaceId): ?int
    {
        if ($agentSpaceId === null || $agentMemoryOperation->subject === AgentMemory::SUBJECT_USER) {
            return null;
        }
        return $agentSpaceId;
    }

    /**
     * The neighbour the operation names, or null when it names none, names no neighbour, or names one of the other
     * scope (a memory of the space for an account-level operation, or the reverse).
     *
     * @param AgentMemoryOperation $agentMemoryOperation
     * @param AgentMemories $orderedNeighbours
     * @param int|null $agentSpaceId
     * @return AgentMemory|null
     */
    public function resolveTarget(AgentMemoryOperation $agentMemoryOperation, AgentMemories $orderedNeighbours, ?int $agentSpaceId): ?AgentMemory
    {
        if ($agentMemoryOperation->targetMemoryRef === null) {
            return null;
        }
        $target = array_values($orderedNeighbours->getElements())[$agentMemoryOperation->targetMemoryRef] ?? null;
        if (!$target instanceof AgentMemory) {
            return null;
        }
        $operationBindsToAgentSpace = $this->resolveScopeAgentSpaceId($agentMemoryOperation, $agentSpaceId) !== null;
        return $target->isAgentSpaceBound() === $operationBindsToAgentSpace ? $target : null;
    }

    /**
     * Stores the operation's content as a new memory unless the scope holds the same content already.
     *
     * @param AgentMemoryOperation $agentMemoryOperation
     * @param int $accountId
     * @param int|null $agentSpaceId
     * @param int|null $sourceMessageId
     * @param AgentMemories $applied
     * @param DateTime|null $sourceInstant
     * @param string|null $sourceTimezone
     * @return void
     */
    protected function addMemoryFromOperation(
        AgentMemoryOperation $agentMemoryOperation,
        int $accountId,
        ?int $agentSpaceId,
        ?int $sourceMessageId,
        AgentMemories $applied,
        ?DateTime $sourceInstant = null,
        ?string $sourceTimezone = null
    ): void {
        $agentMemoryStoreService = $this->getAgentMemoryStoreService();
        $scopeAgentSpaceId = $this->resolveScopeAgentSpaceId($agentMemoryOperation, $agentSpaceId);
        if ($agentMemoryStoreService->findCurrentDuplicateOfContent($accountId, $scopeAgentSpaceId, (string)$agentMemoryOperation->content)) {
            return;
        }
        $addedMemory = $agentMemoryStoreService->persistMemory(
            $this->buildMemory($agentMemoryOperation, $accountId, $agentSpaceId, $sourceMessageId, $sourceInstant, $sourceTimezone)
        );
        $applied->add($addedMemory);
    }

    /**
     * A new memory from an operation, bound to its scope, with the stated "true since" (now when none was stated).
     *
     * @param AgentMemoryOperation $agentMemoryOperation
     * @param int $accountId
     * @param int|null $agentSpaceId
     * @param int|null $sourceMessageId
     * @param DateTime|null $sourceInstant
     * @param string|null $sourceTimezone
     * @return AgentMemory
     */
    protected function buildMemory(
        AgentMemoryOperation $agentMemoryOperation,
        int $accountId,
        ?int $agentSpaceId,
        ?int $sourceMessageId,
        ?DateTime $sourceInstant = null,
        ?string $sourceTimezone = null
    ): AgentMemory {
        $agentMemory = AgentMemory::newInstance();
        $agentMemory->memoryType = in_array($agentMemoryOperation->memoryType, AgentMemory::MEMORY_TYPES, true)
            ? $agentMemoryOperation->memoryType
            : AgentMemory::MEMORY_TYPE_FACT;
        $agentMemory->subject = $agentMemoryOperation->subject === AgentMemory::SUBJECT_USER ? AgentMemory::SUBJECT_USER : AgentMemory::SUBJECT_BUSINESS;
        $agentMemory->content = (string)$agentMemoryOperation->content;
        $agentMemory->source = $agentMemoryOperation->source ?? AgentMemory::SOURCE_USER_STATED;
        $agentMemory->validFrom = $this->parseEffectiveFrom($agentMemoryOperation->effectiveFrom) ?? new DateTime('now');
        $agentMemory->sourceMessageId = $sourceMessageId;
        $agentMemory->sourceInstant = $sourceInstant;
        $agentMemory->sourceTimezone = $sourceTimezone;
        $agentMemory->anchorToScope($accountId, $this->resolveScopeAgentSpaceId($agentMemoryOperation, $agentSpaceId));
        return $agentMemory;
    }

    /**
     * The neighbours of all candidates: the nearest memories of the scope per candidate, plus every current directive
     * of the scope when a candidate is a directive (a new directive may replace an old one of the same attribute that is
     * not among the nearest). Each memory once, in the order found.
     *
     * @param AgentMemoryOperations $candidates
     * @param int $accountId
     * @param int|null $agentSpaceId
     * @return AgentMemories
     */
    protected function retrieveNeighbours(AgentMemoryOperations $candidates, int $accountId, ?int $agentSpaceId): AgentMemories
    {
        $agentMemoryStoreService = $this->getAgentMemoryStoreService();
        $orderedNeighbours = AgentMemories::newInstance();
        foreach ($candidates->getElements() as $candidate) {
            $similarMemories = $agentMemoryStoreService->findSimilarMemories((string)$candidate->content, $accountId, $agentSpaceId, static::NEIGHBOUR_TOP_K);
            foreach ($similarMemories->getElements() as $neighbour) {
                $orderedNeighbours->add($neighbour);
            }
        }
        if ($candidates->containsMemoryType(AgentMemory::MEMORY_TYPE_DIRECTIVE)) {
            $currentDirectives = $agentMemoryStoreService->findCurrentMemoriesForScope($accountId, $agentSpaceId, AgentMemory::MEMORY_TYPE_DIRECTIVE);
            foreach ($currentDirectives->getElements() as $directive) {
                $orderedNeighbours->add($directive);
            }
        }
        return $orderedNeighbours;
    }

    /**
     * Appends the MEMORY_CONFLICT signal: one line per contradiction with where and when the old value was stated, and
     * the instruction to ask the owner which value is right.
     *
     * @param AIConversation $conversation
     * @param AgentMemoryOperations $conflicts
     * @param AgentMemories $orderedNeighbours
     * @return void
     */
    protected function injectConflictSignal(AIConversation $conversation, AgentMemoryOperations $conflicts, AgentMemories $orderedNeighbours): void
    {
        if (!isset($conversation->id)) {
            return;
        }
        $conflictLines = [];
        foreach ($conflicts->getElements() as $conflict) {
            if (!$conflict->hasContent()) {
                continue;
            }
            $conflictLine = '- the owner now says: "' . $conflict->content . '"' . ($conflict->reason !== null ? ' (' . $conflict->reason . ')' : '');
            $target = $this->resolveTarget($conflict, $orderedNeighbours, $this->findAgentSpaceIdOfConversation($conversation));
            if ($target instanceof AgentMemory) {
                $conflictLine .= ' — ' . $this->buildConflictProvenance($conflict, $target, $conversation)->toLlmMarkdown();
            }
            $conflictLines[] = $conflictLine;
        }
        if ($conflictLines === []) {
            return;
        }
        $signalContent = 'MEMORY CONFLICT: a new statement from the owner contradicts something they told you earlier. '
            . "Do NOT assume which is right — tell the owner WHERE/WHEN they stated the earlier value and ASK which is correct; only then can it be remembered:\n"
            . implode("\n", $conflictLines);
        $signalMessage = AIConversationMessage::newInstance();
        $conversation->appendMessage(
            $signalMessage,
            AIConversationMessage::MESSAGE_SIGNAL,
            $signalContent,
            AIConversationMessage::ROLE_SYSTEM,
            AIConversationMessage::AUTHOR_AGENT,
            signalSource: AIConversationMessage::SIGNAL_SOURCE_MEMORY_CONFLICT
        );
        $signalMessage->dropCarriedLoadedCapabilities();
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        $aIConversationMessagesService->persistInboundRowIndexSafe($conversation, $signalMessage);
    }

    /**
     * Where and when the contradicted value was stated: its value and age always; the source conversation's title and
     * an excerpt of the original message when it was said in another conversation (best effort, each step may fail
     * without failing the signal).
     *
     * @param AgentMemoryOperation $conflict
     * @param AgentMemory $target
     * @param AIConversation $conversation
     * @return AgentMemoryConflictProvenance
     */
    protected function buildConflictProvenance(AgentMemoryOperation $conflict, AgentMemory $target, AIConversation $conversation): AgentMemoryConflictProvenance
    {
        // newInstance(): an application's provenance at the same path (its own wording of the age) is built directly
        $agentMemoryConflictProvenance = AgentMemoryConflictProvenance::newInstance();
        $agentMemoryConflictProvenance->contradictedMemoryContent = $target->content ?? null;
        $agentMemoryConflictProvenance->originalStatedAt = $target->validFrom ?? null;
        if (!isset($target->sourceMessageId)) {
            return $agentMemoryConflictProvenance;
        }
        $agentMemoryConflictProvenance->sourceMessageId = $target->sourceMessageId;
        try {
            $sourceMessage = AIConversationMessage::getService()->find($target->sourceMessageId);
        } catch (Throwable $sourceMessageLoadError) {
            $this->aiTrace('memory.curate.provenance.sourceMessageError', ['error' => $sourceMessageLoadError->getMessage()]);
            $sourceMessage = null;
        }
        if (!$sourceMessage instanceof AIConversationMessage || !isset($sourceMessage->aiConversationId)) {
            return $agentMemoryConflictProvenance;
        }
        $agentMemoryConflictProvenance->sourceConversationId = $sourceMessage->aiConversationId;
        if ($sourceMessage->aiConversationId === ($conversation->id ?? null)) {
            $agentMemoryConflictProvenance->fromCurrentConversation = true;
            return $agentMemoryConflictProvenance;
        }
        try {
            $sourceConversation = AIConversation::getService()->find($sourceMessage->aiConversationId);
            // only a stored summary: reading an unset one would generate it, a model call this signal never spends
            if ($sourceConversation instanceof AIConversation && isset($sourceConversation->summary)) {
                $sourceConversationTitle = $sourceConversation->summary->title ?? null;
                if ($sourceConversationTitle !== null && $sourceConversationTitle !== '') {
                    $agentMemoryConflictProvenance->sourceConversationTitle = $sourceConversationTitle;
                }
            }
        } catch (Throwable $titleLoadError) {
            $this->aiTrace('memory.curate.provenance.titleError', ['error' => $titleLoadError->getMessage()]);
        }
        try {
            $argusAgentMemoryConflictExcerpt = ArgusAgentMemoryConflictExcerpt::newInstance();
            $argusAgentMemoryConflictExcerpt->originalMessageText = $sourceMessage->message ?? null;
            $argusAgentMemoryConflictExcerpt->newContradictingStatement = $conflict->content;
            $argusAgentMemoryConflictExcerpt->contradictedMemoryContent = $target->content ?? null;
            $argusAgentMemoryConflictExcerpt->billingEntity = $this->findBillingEntity($conversation);
            $argusAgentMemoryConflictExcerpt->argusLoad(useArgusEntityCache: false, useApiACallCache: false);
            $agentMemoryConflictProvenance->curatedOriginalExcerpt = $argusAgentMemoryConflictExcerpt->curatedOriginalExcerpt;
        } catch (Throwable $excerptLoadError) {
            $this->aiTrace('memory.curate.provenance.excerptError', ['error' => $excerptLoadError->getMessage()]);
        }
        return $agentMemoryConflictProvenance;
    }

    /**
     * Removes the owner messages the screening filtered as an injection attempt: they are never harvested.
     *
     * @param AIConversation $conversation
     * @param AIConversationMessages $delta
     * @return void
     */
    protected function removeSecurityFilteredOwnerMessages(AIConversation $conversation, AIConversationMessages $delta): void
    {
        $agentWorkingState = $conversation->getAgentWorkingState();
        if ($agentWorkingState === null || $agentWorkingState->securityFilteredOwnerMessageIndexes === []) {
            return;
        }
        foreach ($delta->getElements() as $deltaMessage) {
            if ($agentWorkingState->isOwnerMessageSecurityFiltered($deltaMessage->indexInConversation ?? null)) {
                $delta->remove($deltaMessage);
            }
        }
    }

    /**
     * The application's data with a home of its own, as the extraction prompt reads it: one line per entry of
     * {@see CONFIG_STRUCTURED_HOMES} (e.g. "opening hours: the opening-hours tool"), or a note that the application
     * names none. An application may override it to depend on the conversation.
     *
     * @param AIConversation $conversation
     * @return string
     */
    protected function findStructuredHomes(AIConversation $conversation): string
    {
        $configuredStructuredHomes = Config::get(static::CONFIG_STRUCTURED_HOMES);
        if (!is_array($configuredStructuredHomes) || $configuredStructuredHomes === []) {
            return ArgusAgentMemoryExtraction::NO_STRUCTURED_HOMES;
        }
        $structuredHomeLines = [];
        foreach ($configuredStructuredHomes as $configuredStructuredHome) {
            $structuredHomeLines[] = '    • ' . trim((string)$configuredStructuredHome);
        }
        return implode("\n", $structuredHomeLines);
    }

    /**
     * The language every stored content is written in: the owner's interface language of the conversation.
     *
     * @param AIConversation $conversation
     * @return string
     */
    protected function findMemoryLanguageCode(AIConversation $conversation): string
    {
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        return $agentSpacesService->resolveUserLanguageCode($conversation);
    }

    protected function findAgentSpaceIdOfConversation(AIConversation $conversation): ?int
    {
        return isset($conversation->agentSpaceId) && $conversation->agentSpaceId ? (int)$conversation->agentSpaceId : null;
    }

    protected function findBillingEntity(AIConversation $conversation): ?Entity
    {
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        return $agentSpacesService->findBillingEntityOfConversation($conversation);
    }

    protected function findBudgetAccount(AIConversation $conversation): ?Account
    {
        $account = $conversation->account ?? null;
        return $account instanceof Account ? $account : null;
    }

    /**
     * The zone the delta's last message was read in (its request), else the conversation's zone.
     *
     * @param AIConversation $conversation
     * @param AIConversationMessages $delta
     * @return string|null
     */
    protected function findSourceTimezoneIdentifier(AIConversation $conversation, AIConversationMessages $delta): ?string
    {
        $lastDeltaMessage = null;
        foreach ($delta->getElements() as $deltaMessage) {
            $lastDeltaMessage = $deltaMessage;
        }
        // isset: an unloaded message relation is not fetched for this
        $messages = isset($conversation->messages) ? $conversation->messages : null;
        if ($lastDeltaMessage instanceof AIConversationMessage && $messages instanceof AIConversationMessages) {
            $requestTimezoneIdentifier = $messages->findRequestTimezoneIdentifierFor($lastDeltaMessage);
            if ($requestTimezoneIdentifier !== null) {
                return $requestTimezoneIdentifier;
            }
        }
        return $conversation->getTimezone()?->getName();
    }

    protected function buildScopeLabel(int $accountId, ?int $agentSpaceId): string
    {
        if ($agentSpaceId !== null) {
            return sprintf('the account %d and its space %d (a memory about the business belongs to the space, one about the owner to the account)', $accountId, $agentSpaceId);
        }
        return sprintf('the account %d (account level)', $accountId);
    }

    /**
     * @param AIConversationMessages $messages
     * @return int[]
     */
    protected function collectMessageIds(AIConversationMessages $messages): array
    {
        $messageIds = [];
        foreach ($messages->getElements() as $message) {
            if (isset($message->id)) {
                $messageIds[] = (int)$message->id;
            }
        }
        return $messageIds;
    }

    protected function findLastMessageId(AIConversationMessages $messages): ?int
    {
        $collectedMessageIds = $this->collectMessageIds($messages);
        return $collectedMessageIds === [] ? null : end($collectedMessageIds);
    }

    protected function normalizeForEchoMatch(string $content): string
    {
        $normalizedContent = mb_strtolower(trim($content));
        $normalizedContent = (string)preg_replace('/\s+/u', ' ', $normalizedContent);
        return rtrim($normalizedContent, " .…");
    }

    /**
     * @param AgentMemoryOperations $agentMemoryOperations
     * @return string[] One line per operation, for the trace
     */
    protected function describeOperations(AgentMemoryOperations $agentMemoryOperations): array
    {
        $operationLines = [];
        foreach ($agentMemoryOperations->getElements() as $agentMemoryOperation) {
            $operationLines[] = ($agentMemoryOperation->operation ?? '?') . ' ' . $agentMemoryOperation->memoryType . '/'
                . $agentMemoryOperation->subject . ' →ref' . ($agentMemoryOperation->targetMemoryRef ?? '–') . ': ' . $agentMemoryOperation->content;
        }
        return $operationLines;
    }

    protected function getAgentMemoryStoreService(): AgentMemoryStoreService
    {
        /** @var AgentMemoryStoreService $agentMemoryStoreService */
        $agentMemoryStoreService = DDDService::instance()->getService(AgentMemoryStoreService::class);
        return $agentMemoryStoreService;
    }
}
