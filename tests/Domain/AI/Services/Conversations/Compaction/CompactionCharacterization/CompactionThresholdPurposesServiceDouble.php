<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Compaction\CompactionCharacterization;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\Services\Conversations\Purposes\AIConversationPurposesService;
use ReflectionClass;

/**
 * The purpose registry of the compaction threshold tests: {@see getPurposeOfConversation()} (the read behind
 * `AIConversation::findPurpose()`) answers from the purposes the test gives instead of the purpose files, and records
 * the stamped purpose name of every conversation it was asked about (null for a conversation without one).
 */
class CompactionThresholdPurposesServiceDouble extends AIConversationPurposesService
{
    /** @var array<string, AIConversationPurpose> purpose name → the purpose the registry answers */
    public array $purposesByName = [];

    /** @var array<int, string|null> the stamped purpose name of every conversation asked about, in order */
    public array $requestedPurposeNames = [];

    public function getPurposeOfConversation(AIConversation $conversation): ?AIConversationPurpose
    {
        $purposeName = isset($conversation->purposeName) ? $conversation->purposeName : null;
        $this->requestedPurposeNames[] = $purposeName;
        if ($purposeName === null || $purposeName === '') {
            return null;
        }
        return $this->purposesByName[$purposeName] ?? null;
    }

    /** A purpose declaring $contextBudgetTokens (null = it declares none). */
    public static function buildPurpose(string $purposeName, ?int $contextBudgetTokens): AIConversationPurpose
    {
        /** @var AIConversationPurpose $purpose */
        $purpose = (new ReflectionClass(AIConversationPurpose::class))->newInstanceWithoutConstructor();
        $purpose->name = $purposeName;
        $purpose->contextBudgetTokens = $contextBudgetTokens;
        return $purpose;
    }
}
