<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations;

use DDD\Domain\AI\Entities\Conversations\Agent\OwnerPresence;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationOrigin;
use DDD\Domain\AI\Entities\Conversations\Messages\OwnerDevice;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\Purposes\AIConversationPurposeOfAnApplicationWithAnIntake;
use DDD\Tests\Agents\Support\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The pure questions of {@see AIConversationOrigin}, each over its input space: whether the conversation is a
 * delegation and a child below its root, whether it thinks in a frozen schedule zone, whether the owner (and a
 * customer) is present, why it is not the owner's conversation (in its check order), whether it is the platform's own
 * run or held as a phone call by its purpose, whether the presence reads the live call, and the presence itself.
 *
 * The application of the rows declares a visible intake chat, an intake held as a phone call and a hidden nightly
 * digest purpose ({@see AIConversationPurposeOfAnApplicationWithAnIntake}), and a nightly digest run of its own plus
 * a reminder without the owner ({@see AIConversationOfAnApplicationWithANightlyDigestRun}).
 */
class AIConversationOriginPredicatesTest extends UnitTestCase
{
    protected const int CONVERSATION_ID = 5301;

    protected const int ROOT_CONVERSATION_ID = 5300;

    protected const int DELEGATE_CALL_MESSAGE_ID = 6301;

    /** @var array<string, string> */
    protected array $frameworkToAppClassNamesBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->frameworkToAppClassNamesBefore = StaticRegistry::$frameworkToAppClassNames;
        StaticRegistry::$frameworkToAppClassNames[AIConversation::class] = AIConversationOfAnApplicationWithANightlyDigestRun::class;
        StaticRegistry::$frameworkToAppClassNames[AIConversationPurpose::class] = AIConversationPurposeOfAnApplicationWithAnIntake::class;
    }

    protected function tearDown(): void
    {
        StaticRegistry::$frameworkToAppClassNames = $this->frameworkToAppClassNamesBefore;
        parent::tearDown();
    }

    /** @return array<string, array{0: int|null, 1: int|null, 2: bool, 3: bool}> delegation anchor, root id, isDelegation, isDelegationChild */
    public static function provideDelegationRows(): array
    {
        return [
            'a root: no anchor, no root id' => [null, null, false, false],
            'a root that stores itself as its root' => [null, self::CONVERSATION_ID, false, false],
            'a delegation child: an anchor and a foreign root' => [self::DELEGATE_CALL_MESSAGE_ID, self::ROOT_CONVERSATION_ID, true, true],
            'an anchor without a root id: a delegation, not a child' => [self::DELEGATE_CALL_MESSAGE_ID, null, true, false],
            'a foreign root without an anchor: a child, not a delegation' => [null, self::ROOT_CONVERSATION_ID, false, true],
        ];
    }

    #[DataProvider('provideDelegationRows')]
    public function testTheDelegationAndTheChildBelowTheRoot(?int $delegatedFromMessageId, ?int $rootConversationId, bool $expectedDelegation, bool $expectedDelegationChild): void
    {
        $origin = self::buildOrigin(delegatedFromMessageId: $delegatedFromMessageId, rootConversationId: $rootConversationId);

        self::assertSame($expectedDelegation, $origin->isDelegation(), 'isDelegation');
        self::assertSame($expectedDelegationChild, $origin->isDelegationChild(), 'isDelegationChild');
    }

    public function testAnUnsavedConversationWithAStoredRootIsAChild(): void
    {
        $origin = self::buildOrigin(rootConversationId: self::ROOT_CONVERSATION_ID);
        $origin->conversationId = null;

        self::assertTrue($origin->isDelegationChild());
    }

    /** @return array<string, array{0: string|null, 1: int|null, 2: int|null, 3: int|null, 4: bool}> zone, schedule, supervised conversation, delegation anchor, isScheduleBound */
    public static function provideScheduleBoundRows(): array
    {
        return [
            'no zone: never bound' => [null, 5, self::ROOT_CONVERSATION_ID, self::DELEGATE_CALL_MESSAGE_ID, false],
            'a zone and a schedule' => ['Europe/Berlin', 5, null, null, true],
            'a zone and a supervised conversation (the tandem)' => ['Europe/Berlin', null, self::ROOT_CONVERSATION_ID, null, true],
            'a zone and a delegation anchor' => ['Europe/Berlin', null, null, self::DELEGATE_CALL_MESSAGE_ID, true],
            'a zone only (a dialog)' => ['Europe/Berlin', null, null, null, false],
        ];
    }

    #[DataProvider('provideScheduleBoundRows')]
    public function testTheFrozenScheduleZone(?string $timezone, ?int $scheduleId, ?int $supervisedConversationId, ?int $delegatedFromMessageId, bool $expectedScheduleBound): void
    {
        $origin = self::buildOrigin(delegatedFromMessageId: $delegatedFromMessageId, supervisedConversationId: $supervisedConversationId);
        $origin->timezone = $timezone;
        $origin->scheduleId = $scheduleId;

        self::assertSame($expectedScheduleBound, $origin->isScheduleBound());
    }

    /**
     * @return array<string, array{0: string|null, 1: string|null, 2: bool, 3: bool, 4: bool, 5: bool}>
     *     initiator, purpose, isUserPresentRun, runsWithCustomerPresent, isPlatformOwnRun, isHeldAsAPhoneCallByItsPurpose
     */
    public static function providePresenceQuestionRows(): array
    {
        return [
            'a dialog' => [null, null, true, true, false, false],
            'a phone call' => [AIConversation::INITIATOR_TYPE_PHONE_CALL, null, true, true, false, false],
            'an admin task' => [AIConversation::INITIATOR_TYPE_ADMIN_TASK, null, false, false, false, false],
            'a schedule' => [AIConversation::INITIATOR_TYPE_SCHEDULE, null, false, false, false, false],
            'an external agent' => [AIConversation::INITIATOR_TYPE_EXTERNAL_AGENT, null, false, false, false, false],
            'the application\'s own nightly digest run' => [AIConversationOfAnApplicationWithANightlyDigestRun::INITIATOR_TYPE_NIGHTLY_DIGEST, null, false, false, true, false],
            'the application\'s reminder without the owner: not its own run' => [AIConversationOfAnApplicationWithANightlyDigestRun::INITIATOR_TYPE_REMINDER, null, false, false, false, false],
            'a dialog under a visible purpose' => [null, AIConversationPurposeOfAnApplicationWithAnIntake::INTAKE_CHAT, true, true, false, false],
            'a dialog under the supervisor purpose: hidden' => [null, AIConversationPurpose::SUPERVISOR_CHECKPOINT, true, false, true, false],
            'a dialog under the background operation purpose: hidden' => [null, AIConversationPurpose::BACKGROUND_MECHANICAL_OPERATION, true, false, true, false],
            'a dialog under the application\'s hidden purpose' => [null, AIConversationPurposeOfAnApplicationWithAnIntake::NIGHTLY_DIGEST, true, false, true, false],
            'a dialog under the purpose held as a phone call' => [null, AIConversationPurposeOfAnApplicationWithAnIntake::INTAKE_CALL, true, true, false, true],
            'a phone call under the purpose held as a phone call: not held by the purpose' => [AIConversation::INITIATOR_TYPE_PHONE_CALL, AIConversationPurposeOfAnApplicationWithAnIntake::INTAKE_CALL, true, true, false, false],
            'a schedule under a hidden purpose' => [AIConversation::INITIATOR_TYPE_SCHEDULE, AIConversationPurpose::SUPERVISOR_CHECKPOINT, false, false, true, false],
        ];
    }

    #[DataProvider('providePresenceQuestionRows')]
    public function testWhoIsPresentAndWhoseRunItIs(?string $initiatorType, ?string $purposeName, bool $expectedUserPresent, bool $expectedCustomerPresent, bool $expectedPlatformOwnRun, bool $expectedHeldAsAPhoneCall): void
    {
        $origin = self::buildOrigin($initiatorType, $purposeName);

        self::assertSame($expectedUserPresent, $origin->isUserPresentRun(), 'isUserPresentRun');
        self::assertSame($expectedCustomerPresent, $origin->runsWithCustomerPresent(), 'runsWithCustomerPresent');
        self::assertSame($expectedPlatformOwnRun, $origin->isPlatformOwnRun(), 'isPlatformOwnRun');
        self::assertSame($expectedHeldAsAPhoneCall, $origin->isHeldAsAPhoneCallByItsPurpose(), 'isHeldAsAPhoneCallByItsPurpose');
    }

    /**
     * @return array<string, array{0: string|null, 1: string|null, 2: int|null, 3: int|null, 4: string|null}>
     *     initiator, purpose, delegation anchor, supervised conversation, the reason
     */
    public static function provideNotTheOwnersConversationRows(): array
    {
        $hiddenPurpose = AIConversationPurpose::SUPERVISOR_CHECKPOINT;
        return [
            'a dialog' => [null, null, null, null, null],
            'a dialog under a visible purpose' => [null, AIConversationPurposeOfAnApplicationWithAnIntake::INTAKE_CHAT, null, null, null],
            'a phone call' => [AIConversation::INITIATOR_TYPE_PHONE_CALL, null, null, null, null],
            'an external agent: not a run without the owner' => [AIConversation::INITIATOR_TYPE_EXTERNAL_AGENT, null, null, null, null],
            'a delegation child' => [null, null, self::DELEGATE_CALL_MESSAGE_ID, null, AIConversationOrigin::NOT_THE_OWNER_DELEGATION_CHILD],
            'a delegation child that is also a supervisor under a hidden purpose started by a schedule: the child first' => [AIConversation::INITIATOR_TYPE_SCHEDULE, $hiddenPurpose, self::DELEGATE_CALL_MESSAGE_ID, self::ROOT_CONVERSATION_ID, AIConversationOrigin::NOT_THE_OWNER_DELEGATION_CHILD],
            'a supervisor under a hidden purpose started by a schedule: the supervisor first' => [AIConversation::INITIATOR_TYPE_SCHEDULE, $hiddenPurpose, null, self::ROOT_CONVERSATION_ID, AIConversationOrigin::NOT_THE_OWNER_SUPERVISOR],
            'a hidden purpose started by a schedule: the purpose first' => [AIConversation::INITIATOR_TYPE_SCHEDULE, $hiddenPurpose, null, null, AIConversationOrigin::NOT_THE_OWNER_PLATFORM_RUN],
            'a schedule' => [AIConversation::INITIATOR_TYPE_SCHEDULE, null, null, null, AIConversationOrigin::NOT_THE_OWNER_USER_ABSENT_RUN],
            'an admin task' => [AIConversation::INITIATOR_TYPE_ADMIN_TASK, null, null, null, AIConversationOrigin::NOT_THE_OWNER_USER_ABSENT_RUN],
            'the application\'s nightly digest run' => [AIConversationOfAnApplicationWithANightlyDigestRun::INITIATOR_TYPE_NIGHTLY_DIGEST, null, null, null, AIConversationOrigin::NOT_THE_OWNER_USER_ABSENT_RUN],
            'the application\'s reminder' => [AIConversationOfAnApplicationWithANightlyDigestRun::INITIATOR_TYPE_REMINDER, null, null, null, AIConversationOrigin::NOT_THE_OWNER_USER_ABSENT_RUN],
            'an initiator the application does not declare: the owner\'s' => ['UNDECLARED_RUN', null, null, null, null],
        ];
    }

    #[DataProvider('provideNotTheOwnersConversationRows')]
    public function testWhyItIsNotTheOwnersConversationInTheCheckOrder(?string $initiatorType, ?string $purposeName, ?int $delegatedFromMessageId, ?int $supervisedConversationId, ?string $expectedReason): void
    {
        $origin = self::buildOrigin($initiatorType, $purposeName, $delegatedFromMessageId, supervisedConversationId: $supervisedConversationId);

        self::assertSame($expectedReason, $origin->getReasonItIsNotTheOwnersConversation());
    }

    /**
     * @return array<string, array{0: string|null, 1: string|null, 2: int|null, 3: string|null, 4: string|null, 5: bool, 6: OwnerPresence|null}>
     *     initiator, purpose, delegation anchor, live call channel, newest owner device kind, reads the live call, presence
     */
    public static function provideOwnerPresenceRows(): array
    {
        $phone = AIConversationOrigin::LIVE_CALL_CHANNEL_PHONE;
        $app = AIConversationOrigin::LIVE_CALL_CHANNEL_APP;
        $hidden = AIConversationPurpose::SUPERVISOR_CHECKPOINT;
        $intakeCall = AIConversationPurposeOfAnApplicationWithAnIntake::INTAKE_CALL;
        $phoneCall = AIConversation::INITIATOR_TYPE_PHONE_CALL;
        return [
            'a dialog without a call: in the chat' => [null, null, null, null, null, true, OwnerPresence::PRESENT_IN_CHAT],
            'a dialog with a phone call on it' => [null, null, null, $phone, null, true, OwnerPresence::PRESENT_ON_THE_PHONE],
            'a dialog with an in-app call on it' => [null, null, null, $app, null, true, OwnerPresence::PRESENT_IN_APP_VOICE_CALL],
            'a delegation child under a hidden purpose, its root on the phone: the child goes first' => [null, $hidden, self::DELEGATE_CALL_MESSAGE_ID, $phone, null, true, OwnerPresence::PRESENT_ON_THE_PHONE],
            'a delegation child, its root in an in-app call: none' => [null, null, self::DELEGATE_CALL_MESSAGE_ID, $app, null, true, null],
            'a delegation child without a call: none' => [null, null, self::DELEGATE_CALL_MESSAGE_ID, null, null, true, null],
            'a hidden purpose: the platform\'s run, the call not read' => [null, $hidden, null, $phone, null, false, OwnerPresence::ABSENT_PLATFORM_RUN],
            'the application\'s own run: the call not read' => [AIConversationOfAnApplicationWithANightlyDigestRun::INITIATOR_TYPE_NIGHTLY_DIGEST, null, null, $phone, null, false, OwnerPresence::ABSENT_PLATFORM_RUN],
            'a purpose held as a phone call: on the phone, the call not read' => [null, $intakeCall, null, null, null, false, OwnerPresence::PRESENT_ON_THE_PHONE],
            'that purpose started by a phone call, no live call, no device: origin unknown' => [$phoneCall, $intakeCall, null, null, null, true, OwnerPresence::ABSENT_UNKNOWN_ORIGIN],
            'a phone call, live in the app' => [$phoneCall, null, null, $app, null, true, OwnerPresence::PRESENT_IN_APP_VOICE_CALL],
            'a phone call, live on the phone' => [$phoneCall, null, null, $phone, null, true, OwnerPresence::PRESENT_ON_THE_PHONE],
            'a phone call ended, the owner wrote from a browser: in the chat' => [$phoneCall, null, null, null, OwnerDevice::KIND_WEB_BROWSER, true, OwnerPresence::PRESENT_IN_CHAT],
            'a phone call ended, the newest owner row came over the phone: origin unknown' => [$phoneCall, null, null, null, OwnerDevice::KIND_PHONE_CALL, true, OwnerPresence::ABSENT_UNKNOWN_ORIGIN],
            'a phone call ended, the device unknown: origin unknown' => [$phoneCall, null, null, null, OwnerDevice::KIND_UNKNOWN, true, OwnerPresence::ABSENT_UNKNOWN_ORIGIN],
            'an external agent, even with a live call' => [AIConversation::INITIATOR_TYPE_EXTERNAL_AGENT, null, null, $phone, null, true, OwnerPresence::WORKING_FOR_AN_EXTERNAL_AGENT],
            'a schedule' => [AIConversation::INITIATOR_TYPE_SCHEDULE, null, null, null, null, true, OwnerPresence::ABSENT_SCHEDULED_RUN],
            'an admin task' => [AIConversation::INITIATOR_TYPE_ADMIN_TASK, null, null, null, null, true, OwnerPresence::ABSENT_SCHEDULED_RUN],
            'a schedule-run purpose without an initiator, even with a live call' => [null, AIConversationPurpose::SCHEDULE_RUN, null, $phone, null, true, OwnerPresence::ABSENT_SCHEDULED_RUN],
            'the application\'s reminder: origin unknown' => [AIConversationOfAnApplicationWithANightlyDigestRun::INITIATOR_TYPE_REMINDER, null, null, $phone, null, true, OwnerPresence::ABSENT_UNKNOWN_ORIGIN],
        ];
    }

    #[DataProvider('provideOwnerPresenceRows')]
    public function testTheOwnerPresenceAndWhetherItReadsTheLiveCall(?string $initiatorType, ?string $purposeName, ?int $delegatedFromMessageId, ?string $liveCallChannelType, ?string $newestOwnerDeviceKind, bool $expectedReadsTheLiveCall, ?OwnerPresence $expectedPresence): void
    {
        $origin = self::buildOrigin($initiatorType, $purposeName, $delegatedFromMessageId);
        $newestOwnerDevice = null;
        if ($newestOwnerDeviceKind !== null) {
            $newestOwnerDevice = new OwnerDevice();
            $newestOwnerDevice->kind = $newestOwnerDeviceKind;
        }

        self::assertSame($expectedReadsTheLiveCall, $origin->readsTheLiveCallForOwnerPresence(), 'readsTheLiveCallForOwnerPresence');
        self::assertSame($expectedPresence, $origin->buildOwnerPresence($liveCallChannelType, $newestOwnerDevice));
    }

    protected static function buildOrigin(
        ?string $initiatorType = null,
        ?string $purposeName = null,
        ?int $delegatedFromMessageId = null,
        ?int $rootConversationId = null,
        ?int $supervisedConversationId = null
    ): AIConversationOrigin {
        $origin = new AIConversationOrigin();
        $origin->conversationId = self::CONVERSATION_ID;
        $origin->initiatorType = $initiatorType;
        $origin->purposeName = $purposeName;
        $origin->delegatedFromMessageId = $delegatedFromMessageId;
        $origin->rootConversationId = $rootConversationId;
        $origin->supervisedConversationId = $supervisedConversationId;
        return $origin;
    }
}
