<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\AI;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Presentation\Mcp\Client\Agents\AgentTaskSchedulesMCPPackage;

/** The task package of a host with a task list widget, its host points opened for the test */
class AgentTaskSchedulesMCPPackageWithTaskListWidgetDouble extends AgentTaskSchedulesMCPPackage
{
    public const ?string TASK_LIST_WIDGET_TYPE = 'TaskList';

    public function composeTaskListWidgetNoteForTest(?AgentSpace $agentSpace, AgentToolCallProvenance $callProvenance): string
    {
        return $this->composeTaskListWidgetNote($agentSpace, $callProvenance);
    }

    public function composeTaskListPhoneNoteForTest(AgentToolCallProvenance $callProvenance): string
    {
        return $this->composeTaskListPhoneNote($callProvenance);
    }

    public function isScopeReadWithoutEntityRightsForTest(?AgentSpace $agentSpace, Account $actingAccount): bool
    {
        return $this->isScopeReadWithoutEntityRights($agentSpace, $actingAccount);
    }
}
