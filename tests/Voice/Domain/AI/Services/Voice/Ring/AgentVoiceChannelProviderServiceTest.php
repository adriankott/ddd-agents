<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Ring;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Variants\AIConversationVariants;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallReachableChannels;
use DDD\Domain\AI\Services\Conversations\Channels\AgentVoiceChannelService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallAdmissionService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallStartsService;
use DDD\Domain\AI\Services\Voice\AgentVoiceChannelProviderService;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The voice channel's answer to the agent core's three call questions (port `AgentVoiceChannelProvider` of
 * the package): available while enabled, the channel of the live answered call, the `voiceAppRing` dimension read at
 * most once per conversation OBJECT within 120 seconds and left out when the reading fails, the memo that drops a
 * reading (a failed one as well) once it is 120 seconds old when the next reading is written, and the task texts of
 * a conversation's calls.
 */
class AgentVoiceChannelProviderServiceTest extends AgentVoiceRingStagingTestCase
{
    protected ReadingAgentVoiceCallStartsServiceDouble $callStartsService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->callStartsService = new ReadingAgentVoiceCallStartsServiceDouble();
        $this->replaceService(AgentVoiceCallStartsService::class, $this->callStartsService);
    }

    public function testTheChannelIsAvailableOnlyWhileEnabled(): void
    {
        $agentVoiceChannelProviderService = new AgentVoiceChannelProviderService();
        static::assertTrue($agentVoiceChannelProviderService->isVoiceChannelAvailable());

        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['enabled' => false]));
        static::assertFalse($agentVoiceChannelProviderService->isVoiceChannelAvailable());
    }

    public function testTheCoreAsksThePackagesProviderOnlyWhileEnabled(): void
    {
        $this->storeCallWithTask(501, 300, 'ask about the delivery');
        /** @var AgentVoiceChannelService $agentVoiceChannelService */
        $agentVoiceChannelService = DDDService::instance()->getService(AgentVoiceChannelService::class);

        static::assertContains(AgentVoiceChannelProviderService::class, $agentVoiceChannelService->getRegisteredVoiceChannelProviderClassNames());
        static::assertSame(['ask about the delivery'], $agentVoiceChannelService->findCallTaskTextsOfConversation(static::buildConversation(300))->taskTexts);

        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['enabled' => false]));
        $this->callStore->recordedCalls = [];
        static::assertNull($agentVoiceChannelService->findActiveVoiceChannelProvider());
        static::assertFalse($agentVoiceChannelService->findCallTaskTextsOfConversation(static::buildConversation(300))->hasCalls);
        static::assertSame([], $this->callStore->getRecordedMethodNames());
    }

    public function testTheLiveAnsweredCallOfAConversationNamesItsChannel(): void
    {
        $agentVoiceChannelProviderService = new ConversationStagingAgentVoiceChannelProviderServiceDouble();
        $agentVoiceChannelProviderService->conversationsById = [300 => static::buildConversation(300), 301 => static::buildConversation(301)];
        $runningCall = $this->storeRingingCall(501, time(), -100, 300);
        $runningCall->startedAt = DateTime::fromTimestamp(time() - 60);
        $this->storeRingingCall(502, time(), 60, 301);

        static::assertSame('APP', $agentVoiceChannelProviderService->findLiveAnsweredCallChannelType(300));
        static::assertNull($agentVoiceChannelProviderService->findLiveAnsweredCallChannelType(301), 'a ringing call is not live');
        static::assertNull($agentVoiceChannelProviderService->findLiveAnsweredCallChannelType(399), 'no conversation');
        static::assertSame(['findRunningCallOfConversation', 'findRunningCallOfConversation'], $this->callStore->getRecordedMethodNames());
    }

    public function testTheDimensionIsPutInOnlyWhileALoadedSkillKeysOnIt(): void
    {
        $this->callStartsService->reading = static::buildReading(true, true);
        $aIConversationVariants = new AIConversationVariants();

        static::assertFalse((new AgentVoiceChannelProviderService())->putCallReachVariantsInto(static::buildConversation(300), ['otherDimension'], $aIConversationVariants));

        static::assertSame([], $this->callStartsService->readConversations, 'not asked: not read');
        static::assertNull($aIConversationVariants->get('voiceAppRing'));
    }

    /** @param bool $appIsIncluded @param bool $appIsRingableNow */
    #[DataProvider('provideReadings')]
    public function testTheReadingDecidesTheDimension(bool $appIsIncluded, bool $appIsRingableNow, string $expectedValue): void
    {
        $this->callStartsService->reading = static::buildReading($appIsIncluded, $appIsRingableNow);
        $aIConversationVariants = new AIConversationVariants();

        static::assertTrue((new AgentVoiceChannelProviderService())->putCallReachVariantsInto(static::buildConversation(300), ['voiceAppRing'], $aIConversationVariants));

        static::assertSame($expectedValue, $aIConversationVariants->getValue('voiceAppRing'));
        static::assertTrue($aIConversationVariants->isDecided('voiceAppRing'));
    }

    /** @return array<string, array{bool, bool, string}> */
    public static function provideReadings(): array
    {
        return [
            'rings now' => [true, true, 'ringsNow'],
            'included, nobody listens' => [true, false, 'notRingableNow'],
            'not included' => [false, true, 'notIncluded'],
        ];
    }

    public function testAFailedReadingLeavesTheDimensionOutAndIsLogged(): void
    {
        $this->callStartsService->readingFailureMessage = 'admission unreadable';
        $aIConversationVariants = new AIConversationVariants();

        static::assertFalse((new AgentVoiceChannelProviderService())->putCallReachVariantsInto(static::buildConversation(300), ['voiceAppRing'], $aIConversationVariants));

        static::assertNull($aIConversationVariants->get('voiceAppRing'));
        static::assertSame(['AIConversation 300: call reach reading failed: admission unreadable'], $this->logger->findMessagesContaining('call reach reading failed'));
    }

    public function testNoReadingLeavesTheDimensionOut(): void
    {
        $aIConversationVariants = new AIConversationVariants();

        static::assertFalse((new AgentVoiceChannelProviderService())->putCallReachVariantsInto(static::buildConversation(300), ['voiceAppRing'], $aIConversationVariants));
        static::assertNull($aIConversationVariants->get('voiceAppRing'));
    }

    public function testAConversationWithoutIdOrAccountIsNotRead(): void
    {
        $this->callStartsService->reading = static::buildReading(true, true);
        $agentVoiceChannelProviderService = new AgentVoiceChannelProviderService();
        $conversationWithoutAccount = static::buildConversation(300, null);
        $conversationWithoutId = static::buildConversation(301);
        unset($conversationWithoutId->id);

        static::assertFalse($agentVoiceChannelProviderService->putCallReachVariantsInto($conversationWithoutAccount, ['voiceAppRing'], new AIConversationVariants()));
        static::assertFalse($agentVoiceChannelProviderService->putCallReachVariantsInto($conversationWithoutId, ['voiceAppRing'], new AIConversationVariants()));
        static::assertSame([], $this->callStartsService->readConversations);
    }

    public function testOneReadingServesTheSameConversationObjectAndAnotherObjectReadsAfresh(): void
    {
        $this->callStartsService->reading = static::buildReading(true, true);
        $agentVoiceChannelProviderService = new AgentVoiceChannelProviderService();
        $conversation = static::buildConversation(300);

        $agentVoiceChannelProviderService->putCallReachVariantsInto($conversation, ['voiceAppRing'], new AIConversationVariants());
        $this->callStartsService->reading = static::buildReading(true, false);
        $secondVariants = new AIConversationVariants();
        $agentVoiceChannelProviderService->putCallReachVariantsInto($conversation, ['voiceAppRing'], $secondVariants);

        static::assertCount(1, $this->callStartsService->readConversations);
        static::assertSame('ringsNow', $secondVariants->getValue('voiceAppRing'), 'the memo, not the newer reading');

        $conversationLoadedAgain = static::buildConversation(300);
        $thirdVariants = new AIConversationVariants();
        $agentVoiceChannelProviderService->putCallReachVariantsInto($conversationLoadedAgain, ['voiceAppRing'], $thirdVariants);
        static::assertCount(2, $this->callStartsService->readConversations);
        static::assertSame('notRingableNow', $thirdVariants->getValue('voiceAppRing'));
    }

    public function testTheMemoServesFor120Seconds(): void
    {
        $this->callStartsService->reading = static::buildReading(true, true);
        $agentVoiceChannelProviderService = new MemoStagingAgentVoiceChannelProviderServiceDouble();
        $conversation = static::buildConversation(300);
        $agentVoiceChannelProviderService->putCallReachVariantsInto($conversation, ['voiceAppRing'], new AIConversationVariants());

        $agentVoiceChannelProviderService->ageReadingOfConversation($conversation, 119);
        $agentVoiceChannelProviderService->putCallReachVariantsInto($conversation, ['voiceAppRing'], new AIConversationVariants());
        static::assertCount(1, $this->callStartsService->readConversations, '119 seconds old: still served');

        $agentVoiceChannelProviderService->ageReadingOfConversation($conversation, 120);
        $agentVoiceChannelProviderService->putCallReachVariantsInto($conversation, ['voiceAppRing'], new AIConversationVariants());
        static::assertCount(2, $this->callStartsService->readConversations, '120 seconds old: read again');
        static::assertSame(120, AgentVoiceChannelProviderService::VOICE_CALL_REACH_PROBE_TTL_SECONDS);
    }

    public function testAFailedReadingIsKeptInTheMemoAsWell(): void
    {
        $this->callStartsService->readingFailureMessage = 'admission unreadable';
        $agentVoiceChannelProviderService = new AgentVoiceChannelProviderService();
        $conversation = static::buildConversation(300);

        $agentVoiceChannelProviderService->putCallReachVariantsInto($conversation, ['voiceAppRing'], new AIConversationVariants());
        $this->callStartsService->readingFailureMessage = null;
        $this->callStartsService->reading = static::buildReading(true, true);

        static::assertFalse($agentVoiceChannelProviderService->putCallReachVariantsInto($conversation, ['voiceAppRing'], new AIConversationVariants()));
        static::assertCount(1, $this->callStartsService->readConversations);
    }

    public function testAReadingPastTheTtlIsDroppedAtTheNextReadingOfAnotherConversationAndAFreshOneStays(): void
    {
        $this->callStartsService->reading = static::buildReading(true, true);
        $agentVoiceChannelProviderService = new MemoStagingAgentVoiceChannelProviderServiceDouble();
        $outdatedConversation = static::buildConversation(300);
        $freshConversation = static::buildConversation(301);
        $agentVoiceChannelProviderService->putCallReachVariantsInto($outdatedConversation, ['voiceAppRing'], new AIConversationVariants());
        $agentVoiceChannelProviderService->putCallReachVariantsInto($freshConversation, ['voiceAppRing'], new AIConversationVariants());
        $agentVoiceChannelProviderService->ageReadingOfConversation($outdatedConversation, 120);
        $agentVoiceChannelProviderService->ageReadingOfConversation($freshConversation, 119);

        $this->callStartsService->reading = static::buildReading(true, false);
        $agentVoiceChannelProviderService->putCallReachVariantsInto(static::buildConversation(302), ['voiceAppRing'], new AIConversationVariants());

        static::assertFalse($agentVoiceChannelProviderService->remembersReadingOfConversation($outdatedConversation), '120 seconds old: dropped');
        static::assertTrue($agentVoiceChannelProviderService->remembersReadingOfConversation($freshConversation), '119 seconds old: kept');
        static::assertSame(2, $agentVoiceChannelProviderService->countRememberedReadings(), 'the fresh reading and the new one');
        $freshVariants = new AIConversationVariants();
        $agentVoiceChannelProviderService->putCallReachVariantsInto($freshConversation, ['voiceAppRing'], $freshVariants);
        static::assertSame('ringsNow', $freshVariants->getValue('voiceAppRing'), 'the kept reading still serves');
        static::assertCount(3, $this->callStartsService->readConversations);
    }

    public function testAnOutdatedReadingIsDroppedOnlyWhenAReadingIsWrittenNotAtAServedOne(): void
    {
        $this->callStartsService->reading = static::buildReading(true, true);
        $agentVoiceChannelProviderService = new MemoStagingAgentVoiceChannelProviderServiceDouble();
        $outdatedConversation = static::buildConversation(300);
        $servedConversation = static::buildConversation(301);
        $agentVoiceChannelProviderService->putCallReachVariantsInto($outdatedConversation, ['voiceAppRing'], new AIConversationVariants());
        $agentVoiceChannelProviderService->putCallReachVariantsInto($servedConversation, ['voiceAppRing'], new AIConversationVariants());
        $agentVoiceChannelProviderService->ageReadingOfConversation($outdatedConversation, 500);

        $agentVoiceChannelProviderService->putCallReachVariantsInto($servedConversation, ['voiceAppRing'], new AIConversationVariants());

        static::assertCount(2, $this->callStartsService->readConversations, 'served from the memo');
        static::assertTrue($agentVoiceChannelProviderService->remembersReadingOfConversation($outdatedConversation));
        static::assertSame(2, $agentVoiceChannelProviderService->countRememberedReadings());
    }

    public function testAFailedReadingIsKeptAndServedWithinTheTtlAndDroppedAfterIt(): void
    {
        $this->callStartsService->readingFailureMessage = 'admission unreadable';
        $agentVoiceChannelProviderService = new MemoStagingAgentVoiceChannelProviderServiceDouble();
        $failedConversation = static::buildConversation(300);
        $agentVoiceChannelProviderService->putCallReachVariantsInto($failedConversation, ['voiceAppRing'], new AIConversationVariants());
        $this->callStartsService->readingFailureMessage = null;
        $this->callStartsService->reading = static::buildReading(true, true);
        $agentVoiceChannelProviderService->ageReadingOfConversation($failedConversation, 119);

        $agentVoiceChannelProviderService->putCallReachVariantsInto(static::buildConversation(301), ['voiceAppRing'], new AIConversationVariants());
        static::assertTrue($agentVoiceChannelProviderService->remembersReadingOfConversation($failedConversation), '119 seconds old: kept');
        $variantsWithinTheTtl = new AIConversationVariants();
        static::assertFalse($agentVoiceChannelProviderService->putCallReachVariantsInto($failedConversation, ['voiceAppRing'], $variantsWithinTheTtl), 'the failure still serves');
        static::assertNull($variantsWithinTheTtl->get('voiceAppRing'));
        static::assertCount(2, $this->callStartsService->readConversations, 'the failed conversation is not asked again');

        $agentVoiceChannelProviderService->ageReadingOfConversation($failedConversation, 120);
        $agentVoiceChannelProviderService->putCallReachVariantsInto(static::buildConversation(302), ['voiceAppRing'], new AIConversationVariants());
        static::assertFalse($agentVoiceChannelProviderService->remembersReadingOfConversation($failedConversation), '120 seconds old: dropped');
        static::assertSame(['AIConversation 300: call reach reading failed: admission unreadable'], $this->logger->findMessagesContaining('call reach reading failed'));
    }

    public function testTheReadingOfAConversationAsksForItsOwnerAndItsSpace(): void
    {
        $this->callStartsService->answersReadingsItself = false;
        $this->callStartsService->accountsById = [static::OWNER_ACCOUNT_ID => static::buildAccount(static::OWNER_ACCOUNT_ID)];
        $admissionService = new FixedRefusalAgentVoiceCallAdmissionServiceDouble();
        $this->replaceService(AgentVoiceCallAdmissionService::class, $admissionService);
        $this->realtimeChannel->listeningByMailboxName['account-41'] = true;
        $conversationWithSpace = static::buildConversation(300);
        $agentSpace = AgentSpace::newInstance();
        $agentSpace->id = 9;
        $conversationWithSpace->agentSpaceId = 9;
        $conversationWithSpace->agentSpace = $agentSpace;

        $agentVoiceCallReachableChannels = $this->callStartsService->findReachableChannelsForConversation($conversationWithSpace);

        static::assertSame('ringsNow', $agentVoiceCallReachableChannels?->getAppRingValue());
        static::assertSame([['accountId' => 41, 'agentSpace' => $agentSpace, 'conversationId' => 300]], $admissionService->askedCallStarts);
        static::assertTrue(DBEntity::getApplyRightsRestrictions());
        static::assertNull($this->callStartsService->findReachableChannelsForConversation(static::buildConversation(301, null)), 'no account');
        static::assertNull($this->callStartsService->findReachableChannelsForConversation(static::buildConversation(302, 77)), 'the account is gone');
        static::assertSame([41, 77], $this->callStartsService->lookedUpAccountIds, 'a conversation without an account looks nothing up');
    }

    public function testTheTaskTextsOfAConversationsCalls(): void
    {
        $this->storeCallWithTask(501, 300, '  ask about the delivery ');
        $this->storeCallWithTask(502, 300, null);
        $this->storeCallWithTask(503, 300, '   ');
        $this->storeCallWithTask(504, 300, 'confirm the date');
        $this->storeCallWithTask(505, 301, 'another conversation');
        $agentVoiceChannelProviderService = new AgentVoiceChannelProviderService();

        $agentCallTaskTexts = $agentVoiceChannelProviderService->findCallTaskTextsOfConversation(static::buildConversation(300));

        static::assertTrue($agentCallTaskTexts->hasCalls);
        static::assertSame(['confirm the date', 'ask about the delivery'], $agentCallTaskTexts->taskTexts, 'in the order the store answers: newest first');
        static::assertFalse($agentVoiceChannelProviderService->findCallTaskTextsOfConversation(static::buildConversation(399))->hasCalls);
        static::assertSame([], $agentVoiceChannelProviderService->findCallTaskTextsOfConversation(static::buildConversation(399))->taskTexts);
    }

    public function testAConversationWithoutIdHasNoCallsAndTheStoreIsNotAsked(): void
    {
        $conversationWithoutId = static::buildConversation(300);
        unset($conversationWithoutId->id);

        static::assertFalse((new AgentVoiceChannelProviderService())->findCallTaskTextsOfConversation($conversationWithoutId)->hasCalls);
        static::assertSame([], $this->callStore->getRecordedMethodNames());
    }

    protected function storeCallWithTask(int $callId, int $conversationId, ?string $callTask): void
    {
        $this->storeRingingCall($callId, time(), 60, $conversationId)->callTask = $callTask;
    }

    protected static function buildReading(bool $appIsIncluded, bool $appIsRingableNow): AgentVoiceCallReachableChannels
    {
        $agentVoiceCallReachableChannels = AgentVoiceCallReachableChannels::newInstance();
        $agentVoiceCallReachableChannels->appIsIncluded = $appIsIncluded;
        $agentVoiceCallReachableChannels->appIsRingableNow = $appIsRingableNow;
        return $agentVoiceCallReachableChannels;
    }
}
