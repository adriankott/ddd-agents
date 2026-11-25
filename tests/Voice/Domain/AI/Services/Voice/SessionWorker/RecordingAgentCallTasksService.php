<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\SessionWorker;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Voice\Calls\AgentCallTaskOutcome;
use DDD\Domain\AI\Entities\Voice\Calls\AgentCallTaskStart;
use DDD\Domain\AI\Services\Voice\AgentCallTasksService;

/**
 * The bridge to the agent as the session worker uses it: a task start takes the next scripted answer, an outcome read
 * the next scripted outcome (none left = still working); both are kept.
 */
class RecordingAgentCallTasksService extends AgentCallTasksService
{
    /** @var AgentCallTaskStart[] The scripted answers of {@see startAgentTaskOnTranscript()}, in order */
    public array $taskStartAnswers = [];

    /** @var array<int, int|null> The conversation id of every task start */
    public array $taskStartConversationIds = [];

    /** @var AgentCallTaskOutcome[] The scripted outcomes, in order */
    public array $taskOutcomeAnswers = [];

    /** @var array<int, array{conversationId: int|null, taskAfterMessageIndex: int}> */
    public array $taskOutcomeReads = [];

    /** @var array<int, int|null> The conversation id of every account check */
    public array $authAccountEnsuredForConversationIds = [];

    public function startAgentTaskOnTranscript(AIConversation $aiConversation): AgentCallTaskStart
    {
        $this->taskStartConversationIds[] = $aiConversation->id;

        return array_shift($this->taskStartAnswers) ?? AgentCallTaskStart::forError('no_scripted_task_start');
    }

    public function findAgentTaskOutcome(AIConversation $aiConversation, int $taskAfterMessageIndex): AgentCallTaskOutcome
    {
        $this->taskOutcomeReads[] = ['conversationId' => $aiConversation->id, 'taskAfterMessageIndex' => $taskAfterMessageIndex];

        return array_shift($this->taskOutcomeAnswers) ?? AgentCallTaskOutcome::forWorking();
    }

    public function ensureAuthAccountForConversation(AIConversation $aiConversation): void
    {
        $this->authAccountEnsuredForConversationIds[] = $aiConversation->id;
    }
}
