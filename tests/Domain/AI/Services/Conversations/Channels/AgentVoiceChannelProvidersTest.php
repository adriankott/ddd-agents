<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Channels;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Variants\AIConversationVariants;
use DDD\Domain\AI\Services\Conversations\Channels\AgentVoiceChannelProvider;
use DDD\Domain\AI\Services\Conversations\Channels\AgentVoiceChannelService;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The voice channel seam as a tagged provider port: without a provider the service gives "no voice channel"; a
 * registered, available provider answers every question; exactly one provider is asked (the first available in
 * container order); a failing provider gives the default answer; more than one registered provider is a WARNING of
 * `app:agent:check-installation`.
 */
class AgentVoiceChannelProvidersTest extends UnitTestCase
{
    public function testWithoutAProviderTheServiceGivesTodaysAnswers(): void
    {
        $agentVoiceChannelService = new AgentVoiceChannelService();
        $agentVoiceChannelService->setAgentVoiceChannelProviders([]);
        $conversation = new AIConversation();

        self::assertNull($agentVoiceChannelService->findActiveVoiceChannelProvider());
        self::assertNull($agentVoiceChannelService->findLiveAnsweredCallChannelType(5));
        self::assertFalse($agentVoiceChannelService->putCallReachVariantsInto($conversation, ['callReach'], new AIConversationVariants()));
        self::assertFalse($agentVoiceChannelService->findCallTaskTextsOfConversation($conversation)->hasCalls);
    }

    public function testARegisteredProviderAnswersEveryQuestion(): void
    {
        $provider = new ScriptedVoiceChannelProviderDouble();
        $provider->liveAnsweredCallChannelType = 'PHONE_CALL';
        $provider->taskTexts = ['Confirm the order'];
        $agentVoiceChannelService = new AgentVoiceChannelService();
        $agentVoiceChannelService->setAgentVoiceChannelProviders([$provider]);
        $conversation = new AIConversation();

        self::assertSame('PHONE_CALL', $agentVoiceChannelService->findLiveAnsweredCallChannelType(5));
        self::assertTrue($agentVoiceChannelService->putCallReachVariantsInto($conversation, ['callReach'], new AIConversationVariants()));
        $agentCallTaskTexts = $agentVoiceChannelService->findCallTaskTextsOfConversation($conversation);
        self::assertTrue($agentCallTaskTexts->hasCalls);
        self::assertSame(['Confirm the order'], $agentCallTaskTexts->taskTexts);
    }

    public function testAnUnavailableProviderIsNotAskedAndAFailingOneGivesTheDefault(): void
    {
        $provider = new ScriptedVoiceChannelProviderDouble();
        $provider->liveAnsweredCallChannelType = 'PHONE_CALL';
        $provider->available = false;
        $agentVoiceChannelService = new AgentVoiceChannelService();
        $agentVoiceChannelService->setAgentVoiceChannelProviders([$provider]);

        self::assertNull($agentVoiceChannelService->findLiveAnsweredCallChannelType(5));
        self::assertSame(0, $provider->questionCount);

        $provider->available = true;
        $provider->fails = true;
        self::assertNull($agentVoiceChannelService->findLiveAnsweredCallChannelType(5));
        self::assertFalse($agentVoiceChannelService->findCallTaskTextsOfConversation(new AIConversation())->hasCalls);
    }

    public function testExactlyOneProviderIsAskedTheFirstAvailableInContainerOrder(): void
    {
        $unavailableProvider = new ScriptedVoiceChannelProviderDouble();
        $unavailableProvider->available = false;
        $firstAvailableProvider = new SecondScriptedVoiceChannelProviderDouble();
        $firstAvailableProvider->liveAnsweredCallChannelType = 'IN_APP_CALL';
        $laterProvider = new ScriptedVoiceChannelProviderDouble();
        $laterProvider->liveAnsweredCallChannelType = 'PHONE_CALL';
        $agentVoiceChannelService = new AgentVoiceChannelService();
        $agentVoiceChannelService->setAgentVoiceChannelProviders([$unavailableProvider, $firstAvailableProvider, $laterProvider]);

        self::assertSame('IN_APP_CALL', $agentVoiceChannelService->findLiveAnsweredCallChannelType(5));
        self::assertSame(0, $laterProvider->questionCount, 'a later provider is never asked, not even when the first answers null');
        $firstAvailableProvider->liveAnsweredCallChannelType = null;
        self::assertNull($agentVoiceChannelService->findLiveAnsweredCallChannelType(5));
        self::assertSame(0, $laterProvider->questionCount);
    }

    public function testMoreThanOneRegisteredProviderIsAnInstallationWarning(): void
    {
        $agentVoiceChannelService = new AgentVoiceChannelService();
        $agentVoiceChannelInstallationCheck = new AgentVoiceChannelInstallationCheckWithProvidersDouble($agentVoiceChannelService);

        $agentVoiceChannelService->setAgentVoiceChannelProviders([new ScriptedVoiceChannelProviderDouble()]);
        self::assertSame(0, $agentVoiceChannelInstallationCheck->findInstallationFindings()->count());

        $agentVoiceChannelService->setAgentVoiceChannelProviders([new ScriptedVoiceChannelProviderDouble(), new SecondScriptedVoiceChannelProviderDouble()]);
        $findings = $agentVoiceChannelInstallationCheck->findInstallationFindings();
        self::assertSame([AgentVoiceChannelProvider::TAG], $findings->getSubjects());
        self::assertSame(0, $findings->countBlockingFindings());
    }

    public function testThePortIsATaggedInterface(): void
    {
        $tagAttributes = (new ReflectionClass(AgentVoiceChannelProvider::class))->getAttributes(AutoconfigureTag::class);
        self::assertCount(1, $tagAttributes);
        self::assertSame(AgentVoiceChannelProvider::TAG, $tagAttributes[0]->getArguments()[0]);
    }
}
