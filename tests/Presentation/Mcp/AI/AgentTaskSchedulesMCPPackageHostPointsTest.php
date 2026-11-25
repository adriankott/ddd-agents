<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\AI;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Widgets\Syntax\WidgetBlockFieldNames;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Libs\Config;
use DDD\Presentation\Mcp\Client\Agents\AgentTaskSchedulesMCPPackage;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionMethod;

/**
 * The host points of the task tools: the task list widget (type from config, else the constant; the field the
 * application names the self-hydration key; none in a delegation child), the phone note, the scope rule of the reads
 * and the retried-call hook.
 */
class AgentTaskSchedulesMCPPackageHostPointsTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (Config::getEnv('LAZYLOAD_DEFAULT_REPO_TYPE') === null) {
            LazyLoadRepo::setDafaultRepoType(LazyLoadRepo::DB);
        }
    }

    public function testTheWidgetNoteNamesTheTypeAndTheSelfHydrationKeyField(): void
    {
        $agentSpace = new AgentSpace();
        $agentSpace->id = 42;

        $widgetNote = (new AgentTaskSchedulesMCPPackageWithTaskListWidgetDouble())->composeTaskListWidgetNoteForTest($agentSpace, new AgentToolCallProvenance());

        self::assertStringContainsString('"type":"TaskList"', $widgetNote);
        self::assertStringContainsString('"' . WidgetBlockFieldNames::getWidgetSelfHydrationKeyFieldName() . '":42', $widgetNote);
    }

    public function testNoWidgetNoteInADelegationChildOrWithoutAType(): void
    {
        $delegationChildProvenance = new AgentToolCallProvenance();
        $delegationChildProvenance->conversationId = 5;
        $delegationChildProvenance->rootConversationId = 4;

        self::assertSame('', (new AgentTaskSchedulesMCPPackageWithTaskListWidgetDouble())->composeTaskListWidgetNoteForTest(null, $delegationChildProvenance));
        self::assertNull(AgentTaskSchedulesMCPPackage::TASK_LIST_WIDGET_TYPE);
        self::assertNull((require dirname(__DIR__, 4) . '/config/app/AI/agentTasks.php')['taskListWidgetType']);
    }

    public function testThePhoneNoteReadsAFewTasksAloud(): void
    {
        $phoneNote = (new AgentTaskSchedulesMCPPackageWithTaskListWidgetDouble())->composeTaskListPhoneNoteForTest(new AgentToolCallProvenance());

        self::assertStringContainsString('at most ' . AgentTaskSchedulesMCPPackage::PHONE_LINE_TASKS_READ_ALOUD . ' tasks', $phoneNote);
        self::assertStringContainsString('Never read ids', $phoneNote);
    }

    public function testTheAccountsOwnRowsWithoutASpaceAreReadWithoutEntityRights(): void
    {
        self::assertTrue((new AgentTaskSchedulesMCPPackageWithTaskListWidgetDouble())->isScopeReadWithoutEntityRightsForTest(null, new Account()));
    }

    public function testTheAlreadyProducedNoteIsAnOverridePoint(): void
    {
        $noteMethod = new ReflectionMethod(AgentTaskSchedulesMCPPackage::class, 'composeScheduleAlreadyProducedNote');

        self::assertTrue($noteMethod->isProtected());
        self::assertSame('string', (string)$noteMethod->getReturnType());
    }

    public function testTheRetriedCallHookAnswersNullByDefault(): void
    {
        $hookMethod = new ReflectionMethod(AgentTaskSchedulesMCPPackage::class, 'findScheduleAlreadyProducedByCall');

        self::assertTrue($hookMethod->isProtected());
        self::assertSame('?' . \DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule::class, (string)$hookMethod->getReturnType());
    }
}
