<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Admission;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Voice\AgentVoiceAccountRights;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallRefusal;
use DDD\Domain\AI\Exceptions\AiBudgetExceededException;
use DDD\Domain\AI\Exceptions\AIScopeNotEligibleException;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\AIModelCallGateService;
use DDD\Domain\AI\Services\AIModelsService;
use DDD\Domain\AI\Services\AISessionUsageService;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\Entitlements\AIConversationEntitlementsService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallAdmissionService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallStoreService;
use DDD\Domain\AI\Services\Voice\Live\OpenAILiveVoiceProviderAdapter;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Entities\Money\MoneyAmount;
use DDD\Domain\Common\Services\AccountsService;
use DDD\Infrastructure\Services\AuthService;
use DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\CallLife\FixedConversationsAIConversationsService;
use DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\CallLife\InMemoryAgentVoiceCallStore;
use DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\CallLife\InMemoryStoreAgentVoiceCallStoreService;
use DDD\Tests\Agents\Voice\Support\AgentVoiceIntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

/**
 * Whether a call may start and go on ({@see AgentVoiceCallAdmissionService}): the order enabled → the right
 * `useVoice` → the daily cap → the budget gate, the three codes, where the rights are read from, what a running call
 * re-checks, and what a gate that cannot be asked does. Every collaborator outside the package is a double.
 */
class AgentVoiceCallAdmissionServiceTest extends AgentVoiceIntegrationTestCase
{
    protected ScriptedAIConversationEntitlementsService $entitlementsService;

    protected RecordingAIModelCallGateService $gateService;

    protected RecordingAIUsageEnvelopesService $envelopesService;

    protected RecordingAISessionUsageService $sessionUsageService;

    protected FixedAccountsService $accountsService;

    protected FixedConversationsAIConversationsService $conversationsService;

    protected ScriptedAgentSpacesService $agentSpacesService;

    protected InMemoryAgentVoiceCallStore $callStore;

    protected RecordingAIModelsService $modelsService;

    protected AgentVoiceCallAdmissionService $admissionService;

    protected bool $rightsAppliedBeforeTheTest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rightsAppliedBeforeTheTest = DBEntity::getApplyRightsRestrictions();
        DBEntity::setApplyRightsRestrictions(true);
        $this->entitlementsService = new ScriptedAIConversationEntitlementsService();
        $this->entitlementsService->rightsOfCurrentAccount = new FixedAgentAccountRights([AgentVoiceAccountRights::USE_VOICE]);
        $this->entitlementsService->rightsOfConversation = new FixedAgentAccountRights([AgentVoiceAccountRights::USE_VOICE]);
        $this->gateService = new RecordingAIModelCallGateService();
        $this->envelopesService = new RecordingAIUsageEnvelopesService();
        $this->sessionUsageService = new RecordingAISessionUsageService();
        $this->accountsService = new FixedAccountsService();
        $this->accountsService->accountsById = [5 => $this->buildAccount(5), 6 => $this->buildAccount(6)];
        $this->conversationsService = new FixedConversationsAIConversationsService();
        $this->conversationsService->conversationsById[30] = $this->buildConversation(30, 6);
        $this->agentSpacesService = new ScriptedAgentSpacesService();
        $this->callStore = new InMemoryAgentVoiceCallStore();
        $this->modelsService = new RecordingAIModelsService();
        $this->replaceService(AIModelsService::class, $this->modelsService);
        $this->replaceService(AIConversationEntitlementsService::class, $this->entitlementsService);
        $this->replaceService(AIModelCallGateService::class, $this->gateService);
        $this->replaceService(AIUsageEnvelopesService::class, $this->envelopesService);
        $this->replaceService(AISessionUsageService::class, $this->sessionUsageService);
        $this->replaceService(AccountsService::class, $this->accountsService);
        $this->replaceService(AIConversationsService::class, $this->conversationsService);
        $this->replaceService(AgentSpacesService::class, $this->agentSpacesService);
        $this->replaceService(AgentVoiceCallStoreService::class, new InMemoryStoreAgentVoiceCallStoreService($this->callStore));
        StaticRegistry::$entityServices = [];
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['enabled' => true]));
        $this->signInAccount(5);
        $this->admissionService = new AgentVoiceCallAdmissionService();
    }

    protected function onTearDown(): void
    {
        DBEntity::setApplyRightsRestrictions($this->rightsAppliedBeforeTheTest);
        parent::onTearDown();
    }

    public function testTheThreeStoredNamesOfASession(): void
    {
        static::assertSame('AI.VOICE.SESSION.LIVE', $this->admissionService->getSessionUsageScope());
        static::assertSame('ENVELOPE.AI.VOICE.SESSION', $this->admissionService->getSessionEnvelopeScope());
        static::assertSame('AI.VoiceCalls', $this->admissionService->getSessionPromptName());
    }

    public function testAnAllowedStartAsksEveryStageInOrderAndIsNull(): void
    {
        static::assertNull($this->admissionService->findRefusalOfCallStart($this->buildAccount(5), null, null));

        static::assertSame(['current'], $this->entitlementsService->askedReads);
        static::assertSame([], $this->callStore->recordedCalls);
        static::assertSame(
            ['refuseModelCallWithoutUsageScopeOrEnvelope', 'refuseModelCallForIneligibleBilledScope', 'needsCostEstimateForBudgetCheck'],
            $this->gateService->askedStageNames
        );
        static::assertSame(['open:5:ENVELOPE.AI.VOICE.SESSION', 'close:ENVELOPE.AI.VOICE.SESSION'], $this->envelopesService->recordedActions);
        $aiModelCall = $this->gateService->askedModelCalls[0];
        static::assertSame('AI.VOICE.SESSION.LIVE', $aiModelCall->aiUsageScope);
        static::assertSame(5, $aiModelCall->accountBilledForAICosts->id);
        static::assertNull($aiModelCall->entityWithinAccountBilledForAICosts);
        static::assertSame(AgentVoiceCallAdmissionService::class, $aiModelCall->argusClass);
    }

    public function testWhileDisabledNothingIsAsked(): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['enabled' => false, 'limits' => ['dailySecondsPerAccount' => 60]]));

        $this->assertRefusal(AgentVoiceCallRefusal::CODE_FEATURE_NOT_AVAILABLE, $this->admissionService->findRefusalOfCallStart($this->buildAccount(5), null, null));
        static::assertSame([], $this->entitlementsService->askedReads);
        static::assertSame([], $this->callStore->recordedCalls);
        static::assertSame([], $this->gateService->askedStageNames);
    }

    public function testWithoutTheRightTheStartIsRefusedBeforeTheCapAndTheGate(): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['enabled' => true, 'limits' => ['dailySecondsPerAccount' => 60]]));
        $this->entitlementsService->rightsOfCurrentAccount = new FixedAgentAccountRights(['useWeb']);

        $this->assertRefusal(AgentVoiceCallRefusal::CODE_FEATURE_NOT_AVAILABLE, $this->admissionService->findRefusalOfCallStart($this->buildAccount(5), null, null));
        static::assertSame([], $this->callStore->recordedCalls);
        static::assertSame([], $this->gateService->askedStageNames);
    }

    public function testRightsThatCannotBeReadRefuse(): void
    {
        $this->entitlementsService->rightsOfCurrentAccount = null;

        $this->assertRefusal(AgentVoiceCallRefusal::CODE_FEATURE_NOT_AVAILABLE, $this->admissionService->findRefusalOfCallStart($this->buildAccount(5), null, null));
        static::assertSame([], $this->gateService->askedStageNames);
    }

    public function testForTheOwnerOfAConversationTheRightsOfThatConversationAreRead(): void
    {
        static::assertNull($this->admissionService->findRefusalOfCallStart($this->buildAccount(6), null, $this->buildConversation(30, 6)));
        static::assertSame(['conversation:30'], $this->entitlementsService->askedReads);

        $this->entitlementsService->rightsOfConversation = null;
        $this->assertRefusal(AgentVoiceCallRefusal::CODE_FEATURE_NOT_AVAILABLE, $this->admissionService->findRefusalOfCallStart($this->buildAccount(6), null, $this->buildConversation(30, 6)));
    }

    public function testAnotherAccountsRightsCannotBeReadFromAnApplicationsOwnRightsObject(): void
    {
        $this->entitlementsService->rightsAnsweredByTheApplication = true;

        $this->assertRefusal(AgentVoiceCallRefusal::CODE_FEATURE_NOT_AVAILABLE, $this->admissionService->findRefusalOfCallStart($this->buildAccount(6), null, $this->buildConversation(31, 5)));
        static::assertSame(['answeredByTheApplication'], $this->entitlementsService->askedReads);
    }

    /** @param array<string, mixed> $accountRightsMapping */
    #[DataProvider('provideRoleMappings')]
    public function testOnRoleMappedRightsAnotherAccountHoldsWhatTheMappingGrants(array $accountRightsMapping, ?string $expectedCode): void
    {
        $this->declareConfigEntries('AI.accountRights', $accountRightsMapping);

        $agentVoiceCallRefusal = $this->admissionService->findRefusalOfCallStart($this->buildAccount(6), null, null);

        static::assertSame(['answeredByTheApplication'], $this->entitlementsService->askedReads);
        static::assertSame($expectedCode, $agentVoiceCallRefusal?->code);
    }

    /** @return array<string, array{array<string, mixed>, ?string}> */
    public static function provideRoleMappings(): array
    {
        return [
            'a mapping that grants useVoice to every account' => [['useVoice' => ['*'], 'useWeb' => ['*']], null],
            'a mapping that does not name useVoice' => [['useWeb' => ['*']], AgentVoiceCallRefusal::CODE_FEATURE_NOT_AVAILABLE],
        ];
    }

    #[DataProvider('provideBookedSeconds')]
    public function testTheDailyCapComparesTheSecondsBookedToday(int $bookedSeconds, ?string $expectedCode, int $expectedGateQuestions): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['enabled' => true, 'limits' => ['dailySecondsPerAccount' => 600]]));
        $this->callStore->bookedSecondsOfAccountOnDay = $bookedSeconds;

        $agentVoiceCallRefusal = $this->admissionService->findRefusalOfCallStart($this->buildAccount(5), null, null);

        static::assertSame($expectedCode, $agentVoiceCallRefusal?->code);
        static::assertSame([[5, (int)strtotime('today')]], $this->callStore->getArgumentsOf('findBookedSecondsOfAccountOnDay'));
        static::assertCount($expectedGateQuestions, $this->gateService->askedStageNames);
    }

    /** @return array<string, array{int, ?string, int}> */
    public static function provideBookedSeconds(): array
    {
        return [
            'one second under the cap' => [599, null, 3],
            'at the cap' => [600, AgentVoiceCallRefusal::CODE_DAILY_LIMIT_REACHED, 0],
            'over the cap' => [900, AgentVoiceCallRefusal::CODE_DAILY_LIMIT_REACHED, 0],
        ];
    }

    public function testWithoutACapTheBookedSecondsAreNotRead(): void
    {
        static::assertNull($this->admissionService->findRefusalOfCallStart($this->buildAccount(5), null, null));
        static::assertSame([], $this->callStore->getArgumentsOf('findBookedSecondsOfAccountOnDay'));
    }

    public function testWhereTheGateChecksABudgetTheNextSliceIsPricedAndAsked(): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['enabled' => true, 'limits' => ['admissionRecheckSeconds' => 45, 'usageBookingMinimumSeconds' => 30]]));
        $this->gateService->checksBudget = true;
        $costsOfTheSlice = new MoneyAmount(0.25);
        $this->sessionUsageService->costsOfTheSeconds = $costsOfTheSlice;

        static::assertNull($this->admissionService->findRefusalOfCallStart($this->buildAccount(5), null, null));

        static::assertSame(
            [
                'refuseModelCallWithoutUsageScopeOrEnvelope',
                'refuseModelCallForIneligibleBilledScope',
                'needsCostEstimateForBudgetCheck',
                'refuseModelCallWithoutBilledAccount',
                'loadBudgetSettingsOfBilledAccount',
                'refuseModelCallOverBudget',
            ],
            $this->gateService->askedStageNames
        );
        static::assertSame([45.0], $this->sessionUsageService->pricedSeconds);
        static::assertSame($costsOfTheSlice, $this->gateService->estimatedCosts);
    }

    public function testASliceWithoutACatalogPriceIsEstimatedAtZero(): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['enabled' => true, 'limits' => ['admissionRecheckSeconds' => 10, 'usageBookingMinimumSeconds' => 30]]));
        $this->gateService->checksBudget = true;

        $this->admissionService->findRefusalOfCallStart($this->buildAccount(5), null, null);

        static::assertSame([30.0], $this->sessionUsageService->pricedSeconds);
        static::assertEquals(new MoneyAmount(0.0), $this->gateService->estimatedCosts);
    }

    #[DataProvider('provideGateRefusals')]
    public function testEachGateRefusalHasItsCodeAndTheEnvelopeIsClosed(string $throwingStageName, Throwable $stageException, ?string $expectedCode): void
    {
        $this->gateService->checksBudget = true;
        $this->gateService->throwingStageName = $throwingStageName;
        $this->gateService->stageException = $stageException;

        $agentVoiceCallRefusal = $this->admissionService->findRefusalOfCallStart($this->buildAccount(5), null, null);

        static::assertSame($expectedCode, $agentVoiceCallRefusal?->code);
        static::assertSame(['open:5:ENVELOPE.AI.VOICE.SESSION', 'close:ENVELOPE.AI.VOICE.SESSION'], $this->envelopesService->recordedActions);
        static::assertSame($throwingStageName, $this->gateService->askedStageNames[count($this->gateService->askedStageNames) - 1]);
    }

    /** @return array<string, array{string, Throwable, ?string}> */
    public static function provideGateRefusals(): array
    {
        return [
            'the budget refuses' => ['refuseModelCallOverBudget', new AiBudgetExceededException('budget spent'), AgentVoiceCallRefusal::CODE_NO_AI_BUDGET],
            'the billed scope is not eligible' => ['refuseModelCallForIneligibleBilledScope', new AIScopeNotEligibleException('locked'), AgentVoiceCallRefusal::CODE_FEATURE_NOT_AVAILABLE],
            'the budget cannot be read: the call is let through' => ['loadBudgetSettingsOfBilledAccount', new RuntimeException('budget store down'), null],
            'the envelope rule fails: the call is let through' => ['refuseModelCallWithoutUsageScopeOrEnvelope', new RuntimeException('no envelope'), null],
        ];
    }

    public function testAnOpenEnvelopeIsUsedAndNotClosed(): void
    {
        $this->envelopesService->envelopeIsOpen = true;

        static::assertNull($this->admissionService->findRefusalOfCallStart($this->buildAccount(5), null, null));
        static::assertSame([], $this->envelopesService->recordedActions);
    }

    public function testTheConversationsAccountAndSpaceArePaidByForAStartOnIt(): void
    {
        $billingEntity = $this->buildAccount(40);
        $this->agentSpacesService->billingEntity = $billingEntity;

        static::assertNull($this->admissionService->findRefusalOfCallStart($this->buildAccount(5), null, $this->buildConversation(30, 6)));

        $aiModelCall = $this->gateService->askedModelCalls[0];
        static::assertSame(6, $aiModelCall->accountBilledForAICosts->id);
        static::assertSame($billingEntity, $aiModelCall->entityWithinAccountBilledForAICosts);
        static::assertSame([30], $this->agentSpacesService->askedConversationIds);
        static::assertSame([['accountId' => 6, 'rightsApplied' => false]], $this->accountsService->foundAccounts);
        static::assertSame(['open:6:ENVELOPE.AI.VOICE.SESSION', 'close:ENVELOPE.AI.VOICE.SESSION'], $this->envelopesService->recordedActions);
    }

    public function testAFailedBillingEntityReadNeverRefuses(): void
    {
        $this->agentSpacesService->failureMessage = 'space gone';

        static::assertNull($this->admissionService->findRefusalOfCallStart($this->buildAccount(5), null, $this->buildConversation(30, 6)));
        static::assertNull($this->gateService->askedModelCalls[0]->entityWithinAccountBilledForAICosts);
    }

    public function testAConversationWhoseAccountIsGoneBillsTheCallsAccount(): void
    {
        static::assertNull($this->admissionService->findRefusalOfCallStart($this->buildAccount(5), null, $this->buildConversation(30, 99)));
        static::assertSame(5, $this->gateService->askedModelCalls[0]->accountBilledForAICosts->id);
    }

    public function testByDefaultAStartOnAConversationIsBilledToTheConversationsAccountElseToTheCallsAccount(): void
    {
        static::assertNull($this->admissionService->findRefusalOfCallStart($this->buildAccount(5), $this->buildAgentSpace(3, 6), $this->buildConversation(30, 6)));
        static::assertNull($this->admissionService->findRefusalOfCallStart($this->buildAccount(5), $this->buildAgentSpace(3, 6), $this->buildConversation(31, 99)));

        static::assertSame([6, 5], $this->findAccountIdsTheGateWasAskedFor());
        static::assertSame(
            ['open:6:ENVELOPE.AI.VOICE.SESSION', 'close:ENVELOPE.AI.VOICE.SESSION', 'open:5:ENVELOPE.AI.VOICE.SESSION', 'close:ENVELOPE.AI.VOICE.SESSION'],
            $this->envelopesService->recordedActions
        );
    }

    public function testByDefaultAStartWithoutAConversationIsBilledToTheCallsAccountAlsoInASpaceOfAnotherOwner(): void
    {
        static::assertNull($this->admissionService->findRefusalOfCallStart($this->buildAccount(5), $this->buildAgentSpace(3, 6), null));

        static::assertSame([5], $this->findAccountIdsTheGateWasAskedFor());
        static::assertSame([], $this->accountsService->foundAccounts, 'no conversation: no account is read');
    }

    public function testAnApplicationThatBillsTheOwnerOfTheSpaceHasTheGateAskedForTheOwner(): void
    {
        $admissionService = new SpaceOwnerPaysAgentVoiceCallAdmissionService();

        static::assertNull($admissionService->findRefusalOfCallStart($this->buildAccount(5), $this->buildAgentSpace(3, 6), null));
        static::assertNull($admissionService->findRefusalOfCallStart($this->buildAccount(5), $this->buildAgentSpace(4, 8), $this->buildConversation(30, 6)));
        static::assertNull($admissionService->findRefusalOfCallStart($this->buildAccount(5), null, null));

        static::assertSame([6, 8, 5], $this->findAccountIdsTheGateWasAskedFor());
        static::assertSame(
            [
                'open:6:ENVELOPE.AI.VOICE.SESSION', 'close:ENVELOPE.AI.VOICE.SESSION',
                'open:8:ENVELOPE.AI.VOICE.SESSION', 'close:ENVELOPE.AI.VOICE.SESSION',
                'open:5:ENVELOPE.AI.VOICE.SESSION', 'close:ENVELOPE.AI.VOICE.SESSION',
            ],
            $this->envelopesService->recordedActions
        );
    }

    public function testARunningCallReChecksTheCapAndTheBudgetButNotTheRight(): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['enabled' => true, 'limits' => ['dailySecondsPerAccount' => 600]]));
        $this->entitlementsService->rightsOfCurrentAccount = null;
        $this->entitlementsService->rightsOfConversation = null;

        static::assertNull($this->admissionService->findRefusalOfRunningCall($this->buildRunningCall(5, 30)));

        static::assertSame([], $this->entitlementsService->askedReads);
        static::assertSame([30], $this->conversationsService->foundConversationIds);
        static::assertSame([[5, (int)strtotime('today')]], $this->callStore->getArgumentsOf('findBookedSecondsOfAccountOnDay'));
        static::assertSame(6, $this->gateService->askedModelCalls[0]->accountBilledForAICosts->id);
        static::assertSame([30], $this->agentSpacesService->askedConversationIds);
    }

    public function testARunningCallStillRunsWhileVoiceIsSwitchedOff(): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['enabled' => false]));

        static::assertNull($this->admissionService->findRefusalOfRunningCall($this->buildRunningCall(5, 30)));
    }

    public function testARunningCallOverTheCapOrTheBudgetIsRefused(): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['enabled' => true, 'limits' => ['dailySecondsPerAccount' => 600]]));
        $this->callStore->bookedSecondsOfAccountOnDay = 600;
        $this->assertRefusal(AgentVoiceCallRefusal::CODE_DAILY_LIMIT_REACHED, $this->admissionService->findRefusalOfRunningCall($this->buildRunningCall(5, 30)));
        // the daily limit comes before the conversation read and the model call
        static::assertSame([], $this->conversationsService->foundConversationIds);
        static::assertSame([], $this->modelsService->askedModelNames);
        static::assertSame([], $this->gateService->askedStageNames);

        $this->callStore->bookedSecondsOfAccountOnDay = 0;
        $this->gateService->checksBudget = true;
        $this->gateService->throwingStageName = 'refuseModelCallOverBudget';
        $this->gateService->stageException = new AiBudgetExceededException('budget spent');
        $this->assertRefusal(AgentVoiceCallRefusal::CODE_NO_AI_BUDGET, $this->admissionService->findRefusalOfRunningCall($this->buildRunningCall(5, 30)));
    }

    public function testARunningCallNobodyCanBeBilledForIsRefused(): void
    {
        $this->assertRefusal(AgentVoiceCallRefusal::CODE_NO_AI_BUDGET, $this->admissionService->findRefusalOfRunningCall($this->buildRunningCall(null, null)));
        $this->assertRefusal(AgentVoiceCallRefusal::CODE_NO_AI_BUDGET, $this->admissionService->findRefusalOfRunningCall($this->buildRunningCall(99, 31)));
        static::assertSame([], $this->gateService->askedStageNames);
    }

    public function testARunningCallIsPricedByTheModelOfTheProviderItRunsOn(): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith([
            'enabled' => true,
            'provider' => [
                'adapters' => ['openAiLive' => OpenAILiveVoiceProviderAdapter::class, 'secondLive' => OpenAILiveVoiceProviderAdapter::class],
                'secondLive' => ['model' => 'gpt-live-2'],
            ],
        ]));
        $callOnTheSecondProvider = $this->buildRunningCall(5, 30);
        $callOnTheSecondProvider->voiceProvider = 'secondLive';
        $callWithoutAPinnedProvider = $this->buildRunningCall(5, 30);
        $callWithoutAPinnedProvider->voiceProvider = null;

        static::assertNull($this->admissionService->findRefusalOfRunningCall($callOnTheSecondProvider));
        static::assertNull($this->admissionService->findRefusalOfRunningCall($callWithoutAPinnedProvider));
        static::assertNull($this->admissionService->findRefusalOfCallStart($this->buildAccount(5), null, null));

        static::assertSame(['gpt-live-2', 'gpt-live-1.1', 'gpt-live-1.1'], $this->modelsService->askedModelNames);
        static::assertSame(['gpt-live-2|AUDIO', 'gpt-live-1.1|AUDIO', 'gpt-live-1.1|AUDIO'], $this->modelsService->askedExternalIds);
    }

    public function testTheSessionModelIsFoundByTheProvidersModelIdWhenNoCatalogNameMatches(): void
    {
        $catalogModel = new AIModel();
        $catalogModel->name = 'OPENAI.GPT_LIVE_1_1';
        $catalogModel->type = AIModel::TYPE_AUDIO;
        $this->modelsService->modelsByExternalId['gpt-live-1.1'] = $catalogModel;

        $sessionModelCall = $this->admissionService->buildSessionModelCall($this->buildAccount(5), null);

        static::assertSame('OPENAI.GPT_LIVE_1_1', $sessionModelCall->aiModel?->name);
        static::assertSame(['gpt-live-1.1'], $this->modelsService->askedModelNames);
        static::assertSame(['gpt-live-1.1|AUDIO'], $this->modelsService->askedExternalIds);
    }

    public function testAModelCallThatEnforcesNoBudgetIsNotAskedTheBudget(): void
    {
        $this->gateService->checksBudget = true;
        $admissionService = new UnenforcedBudgetAgentVoiceCallAdmissionService();

        static::assertNull($admissionService->findRefusalOfCallStart($this->buildAccount(5), null, null));

        static::assertSame(['refuseModelCallWithoutUsageScopeOrEnvelope', 'refuseModelCallForIneligibleBilledScope'], $this->gateService->askedStageNames);
        static::assertSame([], $this->sessionUsageService->pricedSeconds);
    }

    /** @param int|null $secondsSinceTheAnswer null = never answered */
    #[DataProvider('provideCallLengths')]
    public function testARunningCallEndsAtTheLongestACallMayLast(?int $secondsSinceTheAnswer, ?string $expectedCode): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['enabled' => true, 'limits' => ['maximumCallSeconds' => 600]]));
        $agentVoiceCall = $this->buildRunningCall(5, 30);
        $agentVoiceCall->startedAt = $secondsSinceTheAnswer !== null ? DateTime::fromTimestamp(time() - $secondsSinceTheAnswer) : null;

        $agentVoiceCallRefusal = $this->admissionService->findRefusalOfRunningCall($agentVoiceCall);

        static::assertSame($expectedCode, $agentVoiceCallRefusal?->code);
    }

    /** @return array<string, array{int|null, string|null}> */
    public static function provideCallLengths(): array
    {
        return [
            'a minute before the limit' => [540, null],
            'exactly the limit' => [600, AgentVoiceCallRefusal::CODE_MAXIMUM_CALL_SECONDS_REACHED],
            'past the limit' => [900, AgentVoiceCallRefusal::CODE_MAXIMUM_CALL_SECONDS_REACHED],
            'never answered' => [null, null],
        ];
    }

    public function testTheLongestCallIsAskedBeforeEverythingElseAndEndsWithTheNeutralGoodbye(): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['enabled' => true, 'limits' => ['maximumCallSeconds' => 600, 'dailySecondsPerAccount' => 60]]));
        $this->callStore->bookedSecondsOfAccountOnDay = 60;
        $this->gateService->checksBudget = true;
        $this->gateService->throwingStageName = 'refuseModelCallOverBudget';
        $this->gateService->stageException = new AiBudgetExceededException('budget spent');
        $agentVoiceCall = $this->buildRunningCall(5, 30);
        $agentVoiceCall->startedAt = DateTime::fromTimestamp(time() - 600);

        $agentVoiceCallRefusal = $this->admissionService->findRefusalOfRunningCall($agentVoiceCall);

        static::assertSame(AgentVoiceCallRefusal::CODE_MAXIMUM_CALL_SECONDS_REACHED, $agentVoiceCallRefusal->code);
        static::assertSame(AgentVoiceCall::END_REASON_LIMIT_REACHED, $agentVoiceCallRefusal->endReason);
        static::assertFalse($agentVoiceCallRefusal->isLimitRefusal());
        static::assertSame([], $this->conversationsService->foundConversationIds);
        static::assertSame([], $this->callStore->recordedCalls);
        static::assertSame([], $this->gateService->askedStageNames);
    }

    public function testTheModelCallOfARunningCallBillsTheConversationsAccountAndSpaceOnTheCallsProvider(): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith([
            'enabled' => true,
            'provider' => [
                'adapters' => ['openAiLive' => OpenAILiveVoiceProviderAdapter::class, 'secondLive' => OpenAILiveVoiceProviderAdapter::class],
                'secondLive' => ['model' => 'gpt-live-2'],
            ],
        ]));
        $billingEntity = $this->buildAccount(40);
        $this->agentSpacesService->billingEntity = $billingEntity;
        $agentVoiceCall = $this->buildRunningCall(5, 30);
        $agentVoiceCall->voiceProvider = 'secondLive';

        $sessionModelCall = $this->admissionService->buildSessionModelCallOfRunningCall($agentVoiceCall);

        static::assertSame(6, $sessionModelCall->accountBilledForAICosts->id);
        static::assertSame($billingEntity, $sessionModelCall->entityWithinAccountBilledForAICosts);
        static::assertSame('AI.VOICE.SESSION.LIVE', $sessionModelCall->aiUsageScope);
        static::assertSame(['gpt-live-2'], $this->modelsService->askedModelNames);
        static::assertSame([30], $this->agentSpacesService->askedConversationIds);
    }

    public function testTheModelCallOfARunningCallFallsBackToTheCallsAccount(): void
    {
        static::assertSame(5, $this->admissionService->buildSessionModelCallOfRunningCall($this->buildRunningCall(5, null))->accountBilledForAICosts->id);
        static::assertSame(5, $this->admissionService->buildSessionModelCallOfRunningCall($this->buildRunningCall(5, 31))->accountBilledForAICosts->id);
        $this->conversationsService->conversationsById[32] = $this->buildConversation(32, 99);
        static::assertSame(5, $this->admissionService->buildSessionModelCallOfRunningCall($this->buildRunningCall(5, 32))->accountBilledForAICosts->id);
    }

    /** @return array<string, array{int|null, int|null}> */
    public static function provideCallsNobodyCanBeBilledFor(): array
    {
        return ['no account and no conversation' => [null, null], 'a gone account and a gone conversation' => [99, 31]];
    }

    #[DataProvider('provideCallsNobodyCanBeBilledFor')]
    public function testARunningCallNobodyCanBeBilledForHasNoModelCall(?int $accountId, ?int $conversationId): void
    {
        static::assertNull($this->admissionService->buildSessionModelCallOfRunningCall($this->buildRunningCall($accountId, $conversationId)));
        static::assertSame([], $this->modelsService->askedModelNames);
    }

    public function testAnApplicationThatChangesWhoPaysChangesTheCheckAndTheModelCall(): void
    {
        $admissionService = new OperatorPaysAgentVoiceCallAdmissionService();

        static::assertNull($admissionService->findRefusalOfRunningCall($this->buildRunningCall(5, 30)));
        static::assertSame(77, $this->gateService->askedModelCalls[0]->accountBilledForAICosts->id);
        static::assertSame(77, $admissionService->buildSessionModelCallOfRunningCall($this->buildRunningCall(5, 30))->accountBilledForAICosts->id);
    }

    public function testAModelCallThatCannotBeBuiltIsPassedOnToTheBooking(): void
    {
        $this->modelsService->failureMessage = 'model catalog unreadable';

        try {
            $this->admissionService->buildSessionModelCallOfRunningCall($this->buildRunningCall(5, 30));
            static::fail('the build failure was swallowed');
        } catch (RuntimeException $buildFailure) {
            static::assertSame('model catalog unreadable', $buildFailure->getMessage());
        }
    }

    public function testARunningCallWhoseModelCallCannotBeBuiltGoesOnAndIsLoggedOnce(): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['enabled' => true, 'limits' => ['maximumCallSeconds' => 600, 'dailySecondsPerAccount' => 600]]));
        $this->modelsService->failureMessage = 'model catalog unreadable';
        $admissionService = new GateLogRecordingAgentVoiceCallAdmissionService();
        $agentVoiceCall = $this->buildRunningCall(5, 30);
        $agentVoiceCall->startedAt = DateTime::fromTimestamp(time() - 60);

        static::assertNull($admissionService->findRefusalOfRunningCall($agentVoiceCall));

        static::assertSame([['accountId' => 5, 'errorClass' => RuntimeException::class, 'message' => 'model catalog unreadable']], $admissionService->loggedGateFailures);
        static::assertSame([[5, (int)strtotime('today')]], $this->callStore->getArgumentsOf('findBookedSecondsOfAccountOnDay'));
        static::assertSame([], $this->gateService->askedStageNames);
        static::assertSame([], $this->envelopesService->recordedActions);
    }

    public function testTheDailyLimitRefusesAlthoughTheModelCallCannotBeBuilt(): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['enabled' => true, 'limits' => ['dailySecondsPerAccount' => 600]]));
        $this->callStore->bookedSecondsOfAccountOnDay = 600;
        $this->modelsService->failureMessage = 'model catalog unreadable';
        $admissionService = new GateLogRecordingAgentVoiceCallAdmissionService();

        $this->assertRefusal(AgentVoiceCallRefusal::CODE_DAILY_LIMIT_REACHED, $admissionService->findRefusalOfRunningCall($this->buildRunningCall(5, 30)));
        static::assertSame([], $admissionService->loggedGateFailures);
        static::assertSame([], $this->modelsService->askedModelNames);
    }

    public function testTheLongestCallRefusesAlthoughTheModelCallCannotBeBuilt(): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['enabled' => true, 'limits' => ['maximumCallSeconds' => 600]]));
        $this->modelsService->failureMessage = 'model catalog unreadable';
        $admissionService = new GateLogRecordingAgentVoiceCallAdmissionService();
        $agentVoiceCall = $this->buildRunningCall(5, 30);
        $agentVoiceCall->startedAt = DateTime::fromTimestamp(time() - 600);

        static::assertSame(AgentVoiceCallRefusal::CODE_MAXIMUM_CALL_SECONDS_REACHED, $admissionService->findRefusalOfRunningCall($agentVoiceCall)?->code);
        static::assertSame([], $admissionService->loggedGateFailures);
        static::assertSame([], $this->modelsService->askedModelNames);
    }

    public function testAModelCallThatCannotBeBuiltForAStartLetsTheStartThrough(): void
    {
        $this->modelsService->failureMessage = 'model catalog unreadable';

        static::assertNull($this->admissionService->findRefusalOfCallStart($this->buildAccount(5), null, null));
        static::assertSame([], $this->gateService->askedStageNames);
        static::assertSame([], $this->envelopesService->recordedActions);
    }

    public function testARunningCallWithoutAConversationBillsTheCallsAccount(): void
    {
        static::assertNull($this->admissionService->findRefusalOfRunningCall($this->buildRunningCall(5, null)));

        static::assertSame([], $this->conversationsService->foundConversationIds);
        static::assertSame(5, $this->gateService->askedModelCalls[0]->accountBilledForAICosts->id);
        static::assertSame([], $this->agentSpacesService->askedConversationIds);
    }

    protected function assertRefusal(string $expectedCode, ?AgentVoiceCallRefusal $agentVoiceCallRefusal): void
    {
        static::assertInstanceOf(AgentVoiceCallRefusal::class, $agentVoiceCallRefusal);
        static::assertSame($expectedCode, $agentVoiceCallRefusal->code);
        static::assertSame(AgentVoiceCall::END_REASON_LIMIT_REACHED, $agentVoiceCallRefusal->endReason);
    }

    protected function buildRunningCall(?int $accountId, ?int $conversationId): AgentVoiceCall
    {
        $agentVoiceCall = AgentVoiceCall::newInstance();
        $agentVoiceCall->id = 7;
        $agentVoiceCall->accountId = $accountId;
        $agentVoiceCall->aiConversationId = $conversationId;
        $agentVoiceCall->voiceProvider = 'openAiLive';

        return $agentVoiceCall;
    }

    protected function buildConversation(int $conversationId, int $accountId): AIConversation
    {
        $conversation = AIConversation::newInstance();
        $conversation->id = $conversationId;
        $conversation->accountId = $accountId;

        return $conversation;
    }

    protected function buildAgentSpace(int $agentSpaceId, int $ownerAccountId): AgentSpace
    {
        $agentSpace = AgentSpace::newInstance();
        $agentSpace->id = $agentSpaceId;
        $agentSpace->accountId = $ownerAccountId;

        return $agentSpace;
    }

    /**
     * The billed account of each question the gate was asked, read from its first stage.
     *
     * @return int[]
     */
    protected function findAccountIdsTheGateWasAskedFor(): array
    {
        $accountIds = [];
        foreach ($this->gateService->askedStageNames as $stageIndex => $stageName) {
            if ($stageName === 'refuseModelCallWithoutUsageScopeOrEnvelope') {
                $accountIds[] = (int)$this->gateService->askedModelCalls[$stageIndex]->accountBilledForAICosts->id;
            }
        }

        return $accountIds;
    }

    protected function buildAccount(int $accountId): Account
    {
        $account = Account::newInstance();
        $account->id = $accountId;

        return $account;
    }

    protected function signInAccount(int $accountId): void
    {
        $account = $this->buildAccount($accountId);
        AuthService::instance()->setAccount($account);
    }
}
