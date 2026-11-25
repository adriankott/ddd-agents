<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Channels;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Channels\AgentCallTaskTexts;
use DDD\Domain\AI\Entities\Conversations\Variants\AIConversationVariants;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Service\Attribute\Required;
use Throwable;

/**
 * What the agent core asks of the voice channel (live calls with the owner) — the interim seam of the optional voice
 * component: the channel of a live answered call, which call channels reach the owner right now, and the task texts of a
 * conversation's calls.
 *
 * Each question goes to the voice channel provider ({@see AgentVoiceChannelProvider}, registered by its tag): exactly
 * one is asked — the first in container order that is available; more than one registered provider is a WARNING of
 * `app:agent:check-installation` and logged once. Without an available provider every answer is "no voice channel".
 * An application may still put a same-path subclass under its own root namespace instead; the core resolves this
 * service only through `DDDService::instance()->getService(AgentVoiceChannelService::class)`.
 */
class AgentVoiceChannelService extends Service
{
    /** @var string[] The warnings already written in this process */
    protected static array $reportedWarnings = [];

    /** @var iterable<AgentVoiceChannelProvider> */
    protected iterable $agentVoiceChannelProviders = [];

    /** @param iterable<AgentVoiceChannelProvider> $agentVoiceChannelProviders */
    #[Required]
    public function setAgentVoiceChannelProviders(
        #[AutowireIterator(AgentVoiceChannelProvider::TAG)]
        iterable $agentVoiceChannelProviders
    ): void {
        $this->agentVoiceChannelProviders = $agentVoiceChannelProviders;
    }

    /**
     * The class names of every registered provider, in container order.
     *
     * @return string[]
     */
    public function getRegisteredVoiceChannelProviderClassNames(): array
    {
        $providerClassNames = [];
        foreach ($this->agentVoiceChannelProviders as $agentVoiceChannelProvider) {
            $providerClassNames[] = get_class($agentVoiceChannelProvider);
        }
        return $providerClassNames;
    }

    /**
     * The one provider asked: the first available in container order; null = none (the defaults answer). More than
     * one registered provider is logged once per process.
     */
    public function findActiveVoiceChannelProvider(): ?AgentVoiceChannelProvider
    {
        $registeredProviderClassNames = $this->getRegisteredVoiceChannelProviderClassNames();
        if (count($registeredProviderClassNames) > 1) {
            $this->reportWarningOnce(
                'several-providers',
                'Several voice channel providers are registered (' . implode(', ', $registeredProviderClassNames) . '); only the first available one is asked.'
            );
        }
        foreach ($this->agentVoiceChannelProviders as $agentVoiceChannelProvider) {
            try {
                if ($agentVoiceChannelProvider->isVoiceChannelAvailable()) {
                    return $agentVoiceChannelProvider;
                }
            } catch (Throwable $providerFailure) {
                $this->reportWarningOnce('availability-' . get_class($agentVoiceChannelProvider), 'A voice channel provider failed its availability check: ' . $providerFailure->getMessage());
            }
        }
        return null;
    }

    /**
     * The channel type of the open, answered call of conversation `$conversationId` (the root conversation of a
     * delegation tree), or null when no call is live. The provider's answer; without one: null.
     *
     * @param int $conversationId
     * @return string|null e.g. {@see \DDD\Domain\AI\Entities\Conversations\AIConversationOrigin} live-call channel values
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findLiveAnsweredCallChannelType(int $conversationId): ?string
    {
        $agentVoiceChannelProvider = $this->findActiveVoiceChannelProvider();
        if ($agentVoiceChannelProvider === null) {
            return null;
        }
        try {
            return $agentVoiceChannelProvider->findLiveAnsweredCallChannelType($conversationId);
        } catch (Throwable $providerFailure) {
            $this->logProviderFailure($agentVoiceChannelProvider, 'findLiveAnsweredCallChannelType', $providerFailure);
            return null;
        }
    }

    /**
     * Puts the dimensions "which call channel reaches the owner right now" into `$variants` (a copy of the rendered
     * variants, never the working state) when a loaded skill keys sections on them (`$loadedSkillDimensionNames`).
     * Returns true when `$variants` changed. The provider's answer; without one nothing changes.
     *
     * @param AIConversation $conversation
     * @param string[] $loadedSkillDimensionNames the dimension names the loaded skills' sections key on
     * @param AIConversationVariants $variants
     * @return bool
     */
    public function putCallReachVariantsInto(AIConversation $conversation, array $loadedSkillDimensionNames, AIConversationVariants $variants): bool
    {
        $agentVoiceChannelProvider = $this->findActiveVoiceChannelProvider();
        if ($agentVoiceChannelProvider === null) {
            return false;
        }
        try {
            return $agentVoiceChannelProvider->putCallReachVariantsInto($conversation, $loadedSkillDimensionNames, $variants);
        } catch (Throwable $providerFailure) {
            $this->logProviderFailure($agentVoiceChannelProvider, 'putCallReachVariantsInto', $providerFailure);
            return false;
        }
    }

    /**
     * Whether `$conversation` has calls and the task texts of those calls. The provider's answer; without one: no
     * calls.
     *
     * @param AIConversation $conversation
     * @return AgentCallTaskTexts
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findCallTaskTextsOfConversation(AIConversation $conversation): AgentCallTaskTexts
    {
        $agentVoiceChannelProvider = $this->findActiveVoiceChannelProvider();
        if ($agentVoiceChannelProvider === null) {
            return new AgentCallTaskTexts();
        }
        try {
            return $agentVoiceChannelProvider->findCallTaskTextsOfConversation($conversation);
        } catch (Throwable $providerFailure) {
            $this->logProviderFailure($agentVoiceChannelProvider, 'findCallTaskTextsOfConversation', $providerFailure);
            return new AgentCallTaskTexts();
        }
    }

    protected function logProviderFailure(AgentVoiceChannelProvider $agentVoiceChannelProvider, string $question, Throwable $providerFailure): void
    {
        DDDService::instance()->getLogger()->warning('[AgentVoiceChannel] the voice channel provider failed; the default answers', [
            'provider' => get_class($agentVoiceChannelProvider),
            'question' => $question,
            'reason' => $providerFailure->getMessage(),
        ]);
    }

    protected function reportWarningOnce(string $warningKind, string $warningText): void
    {
        if (isset(static::$reportedWarnings[$warningKind])) {
            return;
        }
        static::$reportedWarnings[$warningKind] = $warningText;
        DDDService::instance()->getLogger()->warning('[AgentVoiceChannel] ' . $warningText);
    }
}
