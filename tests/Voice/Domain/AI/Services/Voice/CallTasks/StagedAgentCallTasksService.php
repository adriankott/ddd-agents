<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\CallTasks;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Domain\AI\Services\Voice\AgentCallTasksService;

/**
 * The bridge with its two reads answered by the test: the fresh conversation (null = it is gone) and the agent's
 * drive ({@see RecordingAIConversationAgentService}).
 */
class StagedAgentCallTasksService extends AgentCallTasksService
{
    public ?AIConversation $freshConversation = null;

    /** @var int[] The conversation ids read fresh */
    public array $freshConversationReads = [];

    public RecordingAIConversationAgentService $recordingAgentService;

    public function __construct()
    {
        $this->recordingAgentService = new RecordingAIConversationAgentService();
    }

    protected function findFreshConversation(AIConversation $aiConversation): ?AIConversation
    {
        $this->freshConversationReads[] = (int)$aiConversation->id;

        return $this->freshConversation;
    }

    protected function getAIConversationAgentService(): AIConversationAgentService
    {
        return $this->recordingAgentService;
    }
}
