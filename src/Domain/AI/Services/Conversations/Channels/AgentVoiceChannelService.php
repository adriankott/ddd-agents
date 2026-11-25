<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Channels;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Channels\AgentCallTaskTexts;
use DDD\Domain\AI\Entities\Conversations\Variants\AIConversationVariants;
use DDD\Domain\AI\Services\Voice\AgentVoiceChannelProviderService;
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
 * one is asked. An application's own providers are asked first, in container order; the package's own provider
 * ({@see AgentVoiceChannelProviderService}) is the fallback and is asked last, so an application's provider wins
 * while it is available ({@see findActiveVoiceChannelProvider()}, the one place that orders them). The package's
 * provider is not counted: only two or more providers of an application are a WARNING of
 * `app:agent:check-installation` and logged once ({@see getRegisteredApplicationVoiceChannelProviderClassNames()},
 * the one place that counts them). Without an available provider every answer is "no voice channel".
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
     * The class names of the registered providers that are an application's: every registered provider but the
     * package's own ({@see isPackageVoiceChannelProvider()}), in container order. The one place that counts the
     * providers: two or more of these are what the installation check and the log line of this service report.
     *
     * @return string[]
     */
    public function getRegisteredApplicationVoiceChannelProviderClassNames(): array
    {
        $applicationProviderClassNames = [];
        foreach ($this->agentVoiceChannelProviders as $agentVoiceChannelProvider) {
            if (!$this->isPackageVoiceChannelProvider($agentVoiceChannelProvider)) {
                $applicationProviderClassNames[] = get_class($agentVoiceChannelProvider);
            }
        }
        return $applicationProviderClassNames;
    }

    /**
     * The one provider asked; null = none (the defaults answer). The one place that orders the providers: an
     * application's providers are asked first, in container order, and the package's own provider last, so an
     * application's provider wins while it is available and the package's answers when the application has none or
     * none of its own is available. Two or more providers of an application are logged once per process; the
     * package's provider beside one of an application is not.
     */
    public function findActiveVoiceChannelProvider(): ?AgentVoiceChannelProvider
    {
        $applicationProviderClassNames = $this->getRegisteredApplicationVoiceChannelProviderClassNames();
        if (count($applicationProviderClassNames) > 1) {
            $this->reportWarningOnce(
                'several-providers',
                'Several voice channel providers of the application are registered (' . implode(', ', $applicationProviderClassNames) . '); only the first available one is asked.'
            );
        }
        return $this->findFirstAvailableVoiceChannelProvider(false) ?? $this->findFirstAvailableVoiceChannelProvider(true);
    }

    /**
     * Whether `$agentVoiceChannelProvider` is the package's own provider: an object of exactly the class
     * {@see AgentVoiceChannelProviderService}. Recognised by the class, never by a name or a tag an application could
     * give its own provider. A subclass of it is an application's provider (the application's class is the one that
     * answers, also when the container holds the package's class beside it).
     */
    protected function isPackageVoiceChannelProvider(AgentVoiceChannelProvider $agentVoiceChannelProvider): bool
    {
        return get_class($agentVoiceChannelProvider) === AgentVoiceChannelProviderService::class;
    }

    /**
     * The first available provider in container order among the application's providers (`$amongPackageProviders`
     * false) or among the package's own (true). A provider whose availability check throws is passed over and logged
     * once per process.
     */
    protected function findFirstAvailableVoiceChannelProvider(bool $amongPackageProviders): ?AgentVoiceChannelProvider
    {
        foreach ($this->agentVoiceChannelProviders as $agentVoiceChannelProvider) {
            if ($this->isPackageVoiceChannelProvider($agentVoiceChannelProvider) !== $amongPackageProviders) {
                continue;
            }
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
