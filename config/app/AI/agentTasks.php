<?php

declare(strict_types=1);

/**
 * The task tools (`Agent.Tasks`, AgentTaskSchedulesMCPPackage).
 *
 * taskListWidgetType: the widget type that shows the task list on screen; the agent writes it with the space's
 * self-hydration key as its only field. null = the package constant AgentTaskSchedulesMCPPackage::TASK_LIST_WIDGET_TYPE
 * (none by default): getTaskSchedules then names no widget.
 */
return [
    'taskListWidgetType' => null,
];
