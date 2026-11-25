<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Channels;

use DDD\Domain\AI\Services\Conversations\Channels\AgentVoiceChannelProvider;
use DDD\Domain\AI\Services\Conversations\Channels\AgentVoiceChannelService;
use DDD\Domain\AI\Services\Installation\AgentVoiceChannelInstallationCheckService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallAdmissionService;
use DDD\Domain\AI\Services\Voice\AgentVoiceChannelProviderService;
use DDD\Tests\Agents\Presentation\Api\Agents\Conversations\RecordingWarningsLoggerDouble;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use Psr\Log\LogLevel;
use ReflectionProperty;

/**
 * The order of the voice channel providers and the warning about them: an application's provider is asked first,
 * wherever the container puts it, and the package's own provider ({@see AgentVoiceChannelProviderService}) is never
 * asked while an application's is available; the package's is asked last, when no provider of the application is
 * available. Only the application's providers are counted: one beside the package's gives no log line and no finding,
 * two give one log line and one finding (subject {@see AgentVoiceChannelProvider::TAG}) that name only the
 * application's classes. A subclass of the package's provider is an application's. The full list still names every
 * registered provider.
 *
 * The package's provider is the real class; its availability is the admission's {@see
 * AgentVoiceCallAdmissionService::canVoiceRunHere()}, so the admission double writes each question to it into the log.
 * The installation check is the real service, over the voice channel service of the test.
 */
class AgentVoiceChannelProviderOrderTest extends IntegrationTestCase
{
    public const string APPLICATION_PROVIDER = 'application';

    public const string SECOND_APPLICATION_PROVIDER = 'second application';

    public const string WARNING_PREFIX = '[AgentVoiceChannel] ';

    protected VoiceChannelAvailabilityQuestionLog $availabilityQuestionLog;

    protected AvailabilityRecordingAgentVoiceCallAdmissionServiceDouble $admission;

    protected AgentVoiceChannelService $agentVoiceChannelService;

    protected RecordingWarningsLoggerDouble $logger;

    /** @var array<string, string> */
    protected array $reportedWarningsBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        static::bootTestKernel();
        $this->reportedWarningsBefore = (array)static::getReportedWarningsProperty()->getValue();
        static::getReportedWarningsProperty()->setValue(null, []);
        $this->availabilityQuestionLog = new VoiceChannelAvailabilityQuestionLog();
        $this->admission = new AvailabilityRecordingAgentVoiceCallAdmissionServiceDouble($this->availabilityQuestionLog);
        $this->replaceService(AgentVoiceCallAdmissionService::class, $this->admission);
        $this->agentVoiceChannelService = new AgentVoiceChannelService();
        $this->replaceService(AgentVoiceChannelService::class, $this->agentVoiceChannelService);
        $this->logger = new RecordingWarningsLoggerDouble();
        static::getContainer()->set('logger', $this->logger);
    }

    protected function onTearDown(): void
    {
        static::getReportedWarningsProperty()->setValue(null, $this->reportedWarningsBefore);
    }

    public function testAnApplicationsProviderIsAskedAndThePackagesIsNeverAsked(): void
    {
        $applicationProvider = $this->buildApplicationProvider();
        $this->agentVoiceChannelService->setAgentVoiceChannelProviders([new AgentVoiceChannelProviderService(), $applicationProvider]);

        static::assertSame($applicationProvider, $this->agentVoiceChannelService->findActiveVoiceChannelProvider());
        static::assertSame([static::APPLICATION_PROVIDER], $this->availabilityQuestionLog->askedProviderNames, 'the package\'s provider comes first in container order and is never asked');
    }

    public function testAnApplicationsProviderAnswersTheQuestionsOfTheCore(): void
    {
        $applicationProvider = $this->buildApplicationProvider();
        $applicationProvider->liveAnsweredCallChannelType = 'KIOSK_CALL';
        $this->agentVoiceChannelService->setAgentVoiceChannelProviders([new AgentVoiceChannelProviderService(), $applicationProvider]);

        static::assertSame('KIOSK_CALL', $this->agentVoiceChannelService->findLiveAnsweredCallChannelType(5));
        static::assertSame(1, $applicationProvider->questionCount);
        static::assertNotContains(AvailabilityRecordingAgentVoiceCallAdmissionServiceDouble::PACKAGE_PROVIDER_NAME, $this->availabilityQuestionLog->askedProviderNames);
    }

    public function testThePackagesProviderIsAskedLastWhenTheApplicationsIsUnavailable(): void
    {
        $packageProvider = new AgentVoiceChannelProviderService();
        $applicationProvider = $this->buildApplicationProvider();
        $applicationProvider->available = false;
        $secondApplicationProvider = $this->buildSecondApplicationProvider();
        $secondApplicationProvider->available = false;
        $this->agentVoiceChannelService->setAgentVoiceChannelProviders([$packageProvider, $applicationProvider, $secondApplicationProvider]);

        static::assertSame($packageProvider, $this->agentVoiceChannelService->findActiveVoiceChannelProvider());
        static::assertSame(
            [static::APPLICATION_PROVIDER, static::SECOND_APPLICATION_PROVIDER, AvailabilityRecordingAgentVoiceCallAdmissionServiceDouble::PACKAGE_PROVIDER_NAME],
            $this->availabilityQuestionLog->askedProviderNames
        );
    }

    public function testWithoutAnAvailableProviderThereIsNone(): void
    {
        $applicationProvider = $this->buildApplicationProvider();
        $applicationProvider->available = false;
        $this->admission->voiceCanRunHere = false;
        $this->agentVoiceChannelService->setAgentVoiceChannelProviders([$applicationProvider, new AgentVoiceChannelProviderService()]);

        static::assertNull($this->agentVoiceChannelService->findActiveVoiceChannelProvider());
        static::assertSame(
            [static::APPLICATION_PROVIDER, AvailabilityRecordingAgentVoiceCallAdmissionServiceDouble::PACKAGE_PROVIDER_NAME],
            $this->availabilityQuestionLog->askedProviderNames
        );
    }

    public function testThePackagesProviderAloneIsAskedAndCountedAsNone(): void
    {
        $packageProvider = new AgentVoiceChannelProviderService();
        $this->agentVoiceChannelService->setAgentVoiceChannelProviders([$packageProvider]);

        static::assertSame($packageProvider, $this->agentVoiceChannelService->findActiveVoiceChannelProvider());
        static::assertSame([], $this->agentVoiceChannelService->getRegisteredApplicationVoiceChannelProviderClassNames());
        static::assertSame(0, (new AgentVoiceChannelInstallationCheckService())->findInstallationFindings()->count());
    }

    public function testOneApplicationProviderBesideThePackagesGivesNoLogLineAndNoFinding(): void
    {
        foreach ([[true, false], [false, true]] as [$packageFirst, $applicationAvailable]) {
            $applicationProvider = $this->buildApplicationProvider();
            $applicationProvider->available = $applicationAvailable;
            $packageProvider = new AgentVoiceChannelProviderService();
            $this->agentVoiceChannelService->setAgentVoiceChannelProviders($packageFirst ? [$packageProvider, $applicationProvider] : [$applicationProvider, $packageProvider]);

            $this->agentVoiceChannelService->findActiveVoiceChannelProvider();

            static::assertSame([AvailabilityRecordingVoiceChannelProviderDouble::class], $this->agentVoiceChannelService->getRegisteredApplicationVoiceChannelProviderClassNames());
            static::assertSame(0, (new AgentVoiceChannelInstallationCheckService())->findInstallationFindings()->count());
        }
        static::assertSame([], $this->logger->findMessages(LogLevel::WARNING, static::WARNING_PREFIX));
    }

    public function testTwoApplicationProvidersGiveOneLogLineAndOneFindingNamingOnlyTheirClasses(): void
    {
        $this->agentVoiceChannelService->setAgentVoiceChannelProviders([new AgentVoiceChannelProviderService(), $this->buildApplicationProvider(), $this->buildSecondApplicationProvider()]);

        $this->agentVoiceChannelService->findActiveVoiceChannelProvider();
        $this->agentVoiceChannelService->findActiveVoiceChannelProvider();
        $findings = (new AgentVoiceChannelInstallationCheckService())->findInstallationFindings();

        $logLines = $this->logger->findMessages(LogLevel::WARNING, static::WARNING_PREFIX);
        static::assertSame(
            [static::WARNING_PREFIX . 'Several voice channel providers of the application are registered (' . AvailabilityRecordingVoiceChannelProviderDouble::class . ', ' . SecondAvailabilityRecordingVoiceChannelProviderDouble::class . '); only the first available one is asked.'],
            $logLines
        );
        static::assertSame([AgentVoiceChannelProvider::TAG], $findings->getSubjects());
        static::assertSame(1, $findings->countWarnings());
        $findingText = $findings->first()?->message ?? '';
        static::assertStringContainsString('(' . AvailabilityRecordingVoiceChannelProviderDouble::class . ', ' . SecondAvailabilityRecordingVoiceChannelProviderDouble::class . ')', $findingText);
        static::assertStringNotContainsString(AgentVoiceChannelProviderService::class, $findingText);
        static::assertStringNotContainsString(AgentVoiceChannelProviderService::class, $logLines[0]);
    }

    public function testASubclassOfThePackagesProviderCountsAsAnApplicationsProvider(): void
    {
        $subclassProvider = new ApplicationSubclassOfThePackagesVoiceChannelProviderDouble($this->availabilityQuestionLog);
        $this->agentVoiceChannelService->setAgentVoiceChannelProviders([new AgentVoiceChannelProviderService(), $subclassProvider]);

        static::assertSame($subclassProvider, $this->agentVoiceChannelService->findActiveVoiceChannelProvider());
        static::assertSame([ApplicationSubclassOfThePackagesVoiceChannelProviderDouble::PROVIDER_NAME], $this->availabilityQuestionLog->askedProviderNames);
        static::assertSame([ApplicationSubclassOfThePackagesVoiceChannelProviderDouble::class], $this->agentVoiceChannelService->getRegisteredApplicationVoiceChannelProviderClassNames());
        static::assertSame(0, (new AgentVoiceChannelInstallationCheckService())->findInstallationFindings()->count());

        $this->agentVoiceChannelService->setAgentVoiceChannelProviders([new AgentVoiceChannelProviderService(), $subclassProvider, $this->buildApplicationProvider()]);
        static::assertSame([AgentVoiceChannelProvider::TAG], (new AgentVoiceChannelInstallationCheckService())->findInstallationFindings()->getSubjects());
    }

    public function testTheFullListStillNamesEveryRegisteredProviderInContainerOrder(): void
    {
        $this->agentVoiceChannelService->setAgentVoiceChannelProviders([$this->buildApplicationProvider(), new AgentVoiceChannelProviderService(), $this->buildSecondApplicationProvider()]);

        static::assertSame(
            [AvailabilityRecordingVoiceChannelProviderDouble::class, AgentVoiceChannelProviderService::class, SecondAvailabilityRecordingVoiceChannelProviderDouble::class],
            $this->agentVoiceChannelService->getRegisteredVoiceChannelProviderClassNames()
        );
        static::assertSame(
            [AvailabilityRecordingVoiceChannelProviderDouble::class, SecondAvailabilityRecordingVoiceChannelProviderDouble::class],
            $this->agentVoiceChannelService->getRegisteredApplicationVoiceChannelProviderClassNames()
        );
    }

    protected function buildApplicationProvider(): AvailabilityRecordingVoiceChannelProviderDouble
    {
        return new AvailabilityRecordingVoiceChannelProviderDouble($this->availabilityQuestionLog, static::APPLICATION_PROVIDER);
    }

    protected function buildSecondApplicationProvider(): SecondAvailabilityRecordingVoiceChannelProviderDouble
    {
        return new SecondAvailabilityRecordingVoiceChannelProviderDouble($this->availabilityQuestionLog, static::SECOND_APPLICATION_PROVIDER);
    }

    protected static function getReportedWarningsProperty(): ReflectionProperty
    {
        return new ReflectionProperty(AgentVoiceChannelService::class, 'reportedWarnings');
    }
}
