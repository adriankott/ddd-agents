<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Ring;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Voice\AgentVoiceChannelProviderService;

/**
 * The channel provider with its memo of call reach readings open to the test: the code reads `time()` directly, so a
 * reading is made old by moving the time it was taken at back, never by waiting.
 */
class MemoStagingAgentVoiceChannelProviderServiceDouble extends AgentVoiceChannelProviderService
{
    /** Moves the time the reading of `$conversation` (this object) was taken at `$ageSeconds` into the past */
    public function ageReadingOfConversation(AIConversation $conversation, int $ageSeconds): void
    {
        if (!$this->remembersReadingOfConversation($conversation)) {
            return;
        }
        $this->voiceCallReachProbeMemoByConversationKey[static::buildMemoKeyOf($conversation)][0] = time() - $ageSeconds;
    }

    /** Whether the memo holds a reading (a failed one included) of `$conversation` (this object) */
    public function remembersReadingOfConversation(AIConversation $conversation): bool
    {
        return array_key_exists(static::buildMemoKeyOf($conversation), $this->voiceCallReachProbeMemoByConversationKey);
    }

    public function countRememberedReadings(): int
    {
        return count($this->voiceCallReachProbeMemoByConversationKey);
    }

    protected static function buildMemoKeyOf(AIConversation $conversation): string
    {
        return spl_object_id($conversation) . ':' . (int)$conversation->id;
    }
}
