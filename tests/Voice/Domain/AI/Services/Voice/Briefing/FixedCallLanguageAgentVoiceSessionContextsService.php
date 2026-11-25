<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Briefing;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Voice\AgentVoiceSettings;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceSessionContext;
use DDD\Domain\AI\Services\Voice\AgentVoiceSessionContextsService;
use RuntimeException;

/**
 * The contexts service with the stored language of a running call fixed ({@see $storedCallLanguageCode}, or a failing
 * read), and an application addition that can fail: the notes sent while a call runs and the best-effort briefing
 * can be read without a stored conversation.
 */
class FixedCallLanguageAgentVoiceSessionContextsService extends AgentVoiceSessionContextsService
{
    public ?string $storedCallLanguageCode = null;

    public bool $failsCallLanguageRead = false;

    public bool $failsApplicationAdditions = false;

    public bool $failsCharacter = false;

    /** @var AgentVoiceSettings|null Settings an application stores for the call; null keeps the base's configured default */
    public ?AgentVoiceSettings $storedVoiceSettings = null;

    public function findEffectiveVoiceSettingsOfCall(AgentVoiceCall $agentVoiceCall, ?AIConversation $conversation = null): ?AgentVoiceSettings
    {
        $configuredDefault = parent::findEffectiveVoiceSettingsOfCall($agentVoiceCall, $conversation);

        return $this->storedVoiceSettings !== null ? $configuredDefault?->buildWithOverrideApplied($this->storedVoiceSettings) : $configuredDefault;
    }

    public function findCallLanguageCodeOfVoiceCall(AgentVoiceCall $agentVoiceCall): ?string
    {
        if ($this->failsCallLanguageRead) {
            throw new RuntimeException('the conversation cannot be read');
        }

        return $this->storedCallLanguageCode;
    }

    protected function findCharacterCoreOfConversation(AIConversation $conversation): ?string
    {
        if ($this->failsCharacter) {
            throw new RuntimeException('the character prompt cannot be read');
        }

        return parent::findCharacterCoreOfConversation($conversation);
    }

    protected function applyApplicationAdditions(AgentVoiceSessionContext $voiceSessionContext, AIConversation $conversation, AgentVoiceCall $agentVoiceCall): void
    {
        if ($this->failsApplicationAdditions) {
            throw new RuntimeException('the application addition failed');
        }
    }
}
