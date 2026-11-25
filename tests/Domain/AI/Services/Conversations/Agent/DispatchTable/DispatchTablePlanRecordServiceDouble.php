<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\DispatchTable;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanEvidence;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AgentMetaToolCallAnswer;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AgentToolCallWorkRequest;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationTaskPlanRecordService;
use Throwable;

/**
 * The plan application of `update_task_plan` and `set_task_status`, recorded instead of applied: the call is recorded,
 * marked COMPLETED and answered with a fixed text; on request the tick answer asks for the step without tools, or the
 * application throws after recording a plan outcome on the call (the application decided before the throw).
 */
class DispatchTablePlanRecordServiceDouble extends AIConversationTaskPlanRecordService
{
    public const string UPDATE_TASK_PLAN_ANSWER = 'fixed update_task_plan answer';

    public const string SET_TASK_STATUS_ANSWER = 'fixed set_task_status answer';

    /** @var array<int, array{method: string, arguments: array<string, mixed>}> */
    public array $calls = [];

    /** Whether the answer of `set_task_status` asks for the step without tools */
    public bool $setTaskStatusAsksForToolLessStep = false;

    /** Thrown by the application instead of an answer */
    public ?Throwable $throwOnApply = null;

    /** The plan outcome the application records on the call before it throws; null = none */
    public ?string $taskPlanCallOutcomeBeforeThrow = null;

    public function __construct()
    {
    }

    public function applyUpdateTaskPlanCall(AIConversation $conversation, ToolCall $toolCall, AgentToolCallWorkRequest $taskPlanUpdateRequest): string
    {
        $this->calls[] = ['method' => 'applyUpdateTaskPlanCall', 'arguments' => $taskPlanUpdateRequest->toolArguments];
        $this->throwWhenAsked($toolCall);
        $toolCall->markCompleted();
        return self::UPDATE_TASK_PLAN_ANSWER;
    }

    public function applySetTaskStatusCall(
        AIConversation $conversation,
        ToolCall $toolCall,
        AgentToolCallWorkRequest $taskStatusUpdateRequest,
        TaskPlanEvidence $taskPlanEvidence
    ): AgentMetaToolCallAnswer {
        $this->calls[] = ['method' => 'applySetTaskStatusCall', 'arguments' => $taskStatusUpdateRequest->toolArguments];
        $this->throwWhenAsked($toolCall);
        $toolCall->markCompleted();
        $setTaskStatusAnswer = AgentMetaToolCallAnswer::completed(self::SET_TASK_STATUS_ANSWER);
        return $this->setTaskStatusAsksForToolLessStep ? $setTaskStatusAnswer->withToolLessAnswerStep() : $setTaskStatusAnswer;
    }

    protected function throwWhenAsked(ToolCall $toolCall): void
    {
        if ($this->throwOnApply === null) {
            return;
        }
        if ($this->taskPlanCallOutcomeBeforeThrow !== null) {
            $toolCall->taskPlanCallOutcome = $this->taskPlanCallOutcomeBeforeThrow;
        }
        throw $this->throwOnApply;
    }
}
