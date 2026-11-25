<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Channels;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Channels\AgentCallTaskTexts;
use DDD\Domain\AI\Entities\Conversations\Variants\AIConversationVariants;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The port through which a package beside ddd-agents (or the application) answers for the voice channel (live calls
 * with the owner): every service implementing it is collected by its tag
 * ({@see AgentVoiceChannelService::setAgentVoiceChannelProviders()}); the agent core keeps asking
 * {@see AgentVoiceChannelService}, which hands each question to the provider. A package ships its provider as a service
 * in a public `…\Services\` namespace; autoconfiguration tags it. No same-path subclass of the service is needed.
 *
 * One voice channel per application: the service asks exactly ONE provider — the first in container order whose
 * {@see isVoiceChannelAvailable()} answers true. Further providers are never asked; more than one registered provider
 * is a WARNING of `app:agent:check-installation` (check `voiceChannel`) and is logged once per process. Without an
 * available provider the service gives its defaults ("no voice channel"). A provider that throws is logged and the
 * question gets the default answer; the turn goes on.
 */
#[AutoconfigureTag(AgentVoiceChannelProvider::TAG)]
interface AgentVoiceChannelProvider
{
    public const string TAG = 'ai.agent_voice_channel_provider';

    /** Whether the voice channel can answer now (configured; no request to a backend). False = the service's defaults */
    public function isVoiceChannelAvailable(): bool;

    /**
     * The channel type of the open, answered call of conversation `$conversationId` (the root conversation of a
     * delegation tree), or null when no call is live.
     */
    public function findLiveAnsweredCallChannelType(int $conversationId): ?string;

    /**
     * Puts the dimensions "which call channel reaches the owner right now" into `$variants` (a copy of the rendered
     * variants, never the working state) when a loaded skill keys sections on them. Returns true when `$variants`
     * changed.
     *
     * @param string[] $loadedSkillDimensionNames the dimension names the loaded skills' sections key on
     */
    public function putCallReachVariantsInto(AIConversation $conversation, array $loadedSkillDimensionNames, AIConversationVariants $variants): bool;

    /** Whether `$conversation` has calls and the task texts of those calls (`hasCalls` false = no calls) */
    public function findCallTaskTextsOfConversation(AIConversation $conversation): AgentCallTaskTexts;
}
