<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\RunPurposes;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\Eligibility\AgentAutomationEligibilityService;
use DDD\Domain\AI\Services\Conversations\Schedules\ScheduleBudgetPotsService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\AgentServiceRecordingDrivesDouble;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\AutomationEligibilityServiceWithScriptedStateDouble;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\ConversationsServiceWithScheduleRunDouble;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\ScheduleBudgetPotsServiceWithScriptedPotDouble;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\SchedulesServiceRecordingOccurrenceWritesDouble;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;
use DDD\Domain\AI\Services\Conversations\Purposes\AIConversationPurposesService;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Mcp\Client\Agents\Dtos\TaskScheduleMcpRow;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use ReflectionProperty;

/**
 * The purpose a schedule's runs start with: stored in the schedule's settings (no column of its own), only a
 * registered purpose whose class is a schedule-run purpose, gated by the space's flags and the application's hook,
 * checked again when the schedule fires (a purpose that no longer qualifies falls back to `Schedule.Run`), shown in
 * the schedule's row and left out of the search across conversations like `Schedule.Run`.
 */
class AIConversationScheduleRunPurposeTest extends IntegrationTestCase
{
    public const string SUPPORT_UNATTENDED_FILE = "---\npurposeClass: DDD\\Domain\\AI\\Entities\\Conversations\\Purposes\\ScheduleRunPurpose\ninheritsFrom: Schedule.Run\ndescription: The unattended support run.\nstartableViaApi: false\npreferredModelName: EXAMPLE.PINNED_MODEL\nseedTaskPlanItems: [Read the open requests, Answer each request]\n---\n";

    public const string INTAKE_CHAT_FILE = "---\npurposeClass: DDD\\Domain\\AI\\Entities\\Conversations\\Purposes\\AIConversationPurpose\ndescription: Takes a new customer in.\n---\n# Intake\n";

    /** @var array<string, string> */
    protected array $frameworkToAppClassNamesBefore = [];

    /** @var array<string, mixed>|null */
    protected ?array $configTreeBefore = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->frameworkToAppClassNamesBefore = StaticRegistry::$frameworkToAppClassNames;
        StaticRegistry::$frameworkToAppClassNames[AIConversationPurpose::class] = AIConversationPurposeOfAnApplicationWithAnUnattendedSupportRun::class;
        $configTree = new ReflectionProperty(Config::class, 'configTree');
        $this->configTreeBefore = $configTree->isInitialized() ? (array)$configTree->getValue() : null;
        Config::get('AI.Purposes.Schedule.Run');
        $configValues = (array)$configTree->getValue();
        $configValues['AI']['Purposes']['Support']['Unattended'] = self::SUPPORT_UNATTENDED_FILE;
        $configValues['AI']['Purposes']['Intake']['Chat'] = self::INTAKE_CHAT_FILE;
        $configTree->setValue(null, $configValues);
        AIConversationPurposesService::forgetScheduleRunPurposeNames();
    }

    protected function onTearDown(): void
    {
        StaticRegistry::$frameworkToAppClassNames = $this->frameworkToAppClassNamesBefore;
        (new ReflectionProperty(Config::class, 'configTree'))->setValue(null, $this->configTreeBefore ?? []);
        AIConversationPurposesService::forgetScheduleRunPurposeNames();
    }

    public function testAScheduleWithoutAStoredPurposeRunsAsScheduleRunAndTheDefaultStoresNothing(): void
    {
        $schedule = new AIConversationSchedule();
        self::assertSame(AIConversationPurpose::SCHEDULE_RUN, $schedule->getRunPurposeName());

        $this->getSchedulesService()->applyRunPurposeToSchedule($schedule, AIConversationPurpose::SCHEDULE_RUN, null);
        self::assertFalse(isset($schedule->runSettings), 'the default adds no settings to a schedule without any');
        self::assertNull($schedule->buildRunSettingsForRun());
    }

    public function testOnlySchedulePurposesQualifyAndTheRefusalNamesThem(): void
    {
        $aIConversationPurposesService = $this->getPurposesService();
        self::assertSame([AIConversationPurpose::SCHEDULE_RUN, 'Support.Unattended'], $aIConversationPurposesService->findScheduleRunPurposeNames());

        $schedule = new AIConversationSchedule();
        $this->getSchedulesService()->applyRunPurposeToSchedule($schedule, 'Support.Unattended', null);
        self::assertSame('Support.Unattended', $schedule->getRunPurposeName());
        self::assertSame('Support.Unattended', $schedule->runSettings->runPurposeName, 'kept in the settings column');
        self::assertNull($schedule->buildRunSettingsForRun()?->runPurposeName, 'never copied onto a run');

        foreach (['Intake.Chat', 'Supervisor.Checkpoint', 'Unknown.Purpose'] as $refusedPurposeName) {
            try {
                $this->getSchedulesService()->applyRunPurposeToSchedule($schedule, $refusedPurposeName, null);
                self::fail("{$refusedPurposeName} must be refused");
            } catch (BadRequestException $refusal) {
                self::assertStringContainsString('Purposes a schedule may use: Schedule.Run, Support.Unattended.', $refusal->getMessage());
            }
        }
        self::assertSame('Support.Unattended', $schedule->getRunPurposeName(), 'a refusal changes nothing');

        $this->getSchedulesService()->applyRunPurposeToSchedule($schedule, null, null);
        self::assertSame(AIConversationPurpose::SCHEDULE_RUN, $schedule->getRunPurposeName());
        self::assertNull($schedule->runSettings->runPurposeName);
    }

    public function testTheApplicationsHookRefusesAPurpose(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage("The purpose 'Support.Unattended' is not allowed for this task schedule.");

        (new SchedulesServiceRefusingPurposesDouble())->applyRunPurposeToSchedule(new AIConversationSchedule(), 'Support.Unattended', new AgentSpace());
    }

    public function testAFiringChecksThePurposeAgainAndFallsBackToScheduleRun(): void
    {
        $schedule = new AIConversationSchedule();
        $schedule->setRunPurposeName('Support.Unattended');
        self::assertSame('Support.Unattended', $this->getSchedulesService()->resolveRunPurposeNameOfSchedule($schedule, null));

        $schedule->setRunPurposeName('Intake.Chat');
        self::assertSame(AIConversationPurpose::SCHEDULE_RUN, $this->getSchedulesService()->resolveRunPurposeNameOfSchedule($schedule, null), 'a stored purpose that no longer qualifies');
        self::assertSame(AIConversationPurpose::SCHEDULE_RUN, (new SchedulesServiceRefusingPurposesDouble())->resolveRunPurposeNameOfSchedule($this->buildScheduleOfSupportRun(), null));
    }

    public function testTheRowShowsAPurposeOtherThanTheDefaultAndTheSearchLeavesItsRunsOut(): void
    {
        $scheduleOfSupportRun = $this->buildScheduleOfSupportRun();
        $scheduleOfSupportRun->id = 12;
        $scheduleOfSupportRun->status = 'ACTIVE';
        $scheduleOfSupportRun->reportChannel = AIConversationSchedule::REPORT_CHANNEL_CHAT;
        $scheduleOfSupportRun->task = 'Answer the open support requests.';
        $scheduleRow = TaskScheduleMcpRow::fromSchedule($scheduleOfSupportRun);
        self::assertSame('Support.Unattended', $scheduleRow->runPurposeName);
        self::assertStringContainsString('runs as Support.Unattended', $scheduleRow->toLlmMarkdown());

        $plainSchedule = new AIConversationSchedule();
        $plainSchedule->id = 13;
        $plainSchedule->status = 'ACTIVE';
        $plainSchedule->reportChannel = AIConversationSchedule::REPORT_CHANNEL_CHAT;
        self::assertNull(TaskScheduleMcpRow::fromSchedule($plainSchedule)->runPurposeName);

        self::assertContains('Support.Unattended', AIConversationPurpose::getPurposeNamesLeftOutOfTheSearchAcrossConversations());
        self::assertNotContains('Intake.Chat', AIConversationPurpose::getPurposeNamesLeftOutOfTheSearchAcrossConversations());
    }

    public function testAFiringStartsTheRunWithTheSchedulesPurposeAndItsPinnedModel(): void
    {
        $agentService = new AgentServiceRecordingDrivesDouble();
        $this->replaceService(AgentAutomationEligibilityService::class, new AutomationEligibilityServiceWithScriptedStateDouble());
        $this->replaceService(ScheduleBudgetPotsService::class, new ScheduleBudgetPotsServiceWithScriptedPotDouble());
        $this->replaceService(AIConversationAgentService::class, $agentService);
        $this->replaceService(AIConversationsService::class, new ConversationsServiceWithScheduleRunDouble(null));
        StaticRegistry::$entityServices = [];
        $schedulesService = new SchedulesServiceRecordingOccurrenceWritesDouble();
        $schedule = $this->buildScheduleOfSupportRun();
        $schedule->id = 4431;
        $schedule->accountId = 34;
        $schedule->account = Account::newInstance();
        $schedule->account->id = 34;
        $schedule->task = 'Answer the open support requests.';
        $schedule->reportChannel = AIConversationSchedule::REPORT_CHANNEL_EMAIL;
        $schedule->schedule = '0 7 * * 1';

        $schedulesService->spawnRunConversationForClaimedSchedule($schedule);

        self::assertCount(1, $agentService->recordedDrives);
        self::assertSame('Support.Unattended', $agentService->recordedDrives[0]['purposeName']);
        self::assertSame('EXAMPLE.PINNED_MODEL', $agentService->recordedDrives[0]['aiModelName']);
        self::assertSame(AIConversation::INITIATOR_TYPE_SCHEDULE, $agentService->recordedDrives[0]['initiatorType']);
        StaticRegistry::$entityServices = [];
    }

    protected function buildScheduleOfSupportRun(): AIConversationSchedule
    {
        $schedule = new AIConversationSchedule();
        $schedule->setRunPurposeName('Support.Unattended');
        return $schedule;
    }

    protected function getSchedulesService(): AIConversationSchedulesService
    {
        /** @var AIConversationSchedulesService $aIConversationSchedulesService */
        $aIConversationSchedulesService = DDDService::instance()->getService(AIConversationSchedulesService::class);
        return $aIConversationSchedulesService;
    }

    protected function getPurposesService(): AIConversationPurposesService
    {
        /** @var AIConversationPurposesService $aIConversationPurposesService */
        $aIConversationPurposesService = DDDService::instance()->getService(AIConversationPurposesService::class);
        return $aIConversationPurposesService;
    }
}
