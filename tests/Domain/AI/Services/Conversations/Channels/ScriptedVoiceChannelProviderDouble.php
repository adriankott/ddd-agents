<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Channels;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Channels\AgentCallTaskTexts;
use DDD\Domain\AI\Entities\Conversations\Variants\AIConversationVariants;
use DDD\Domain\AI\Services\Conversations\Channels\AgentVoiceChannelProvider;

/** A voice channel provider as a package beside ddd-agents ships one: scripted answers, every question counted. */
class ScriptedVoiceChannelProviderDouble implements AgentVoiceChannelProvider
{
    public bool $available = true;

    public bool $fails = false;

    public ?string $liveAnsweredCallChannelType = null;

    /** @var string[] */
    public array $taskTexts = [];

    public int $questionCount = 0;

    public function isVoiceChannelAvailable(): bool
    {
        return $this->available;
    }

    public function findLiveAnsweredCallChannelType(int $conversationId): ?string
    {
        $this->answerOrFail();
        return $this->liveAnsweredCallChannelType;
    }

    public function putCallReachVariantsInto(AIConversation $conversation, array $loadedSkillDimensionNames, AIConversationVariants $variants): bool
    {
        $this->answerOrFail();
        return in_array('callReach', $loadedSkillDimensionNames, true);
    }

    public function findCallTaskTextsOfConversation(AIConversation $conversation): AgentCallTaskTexts
    {
        $this->answerOrFail();
        $agentCallTaskTexts = new AgentCallTaskTexts();
        $agentCallTaskTexts->hasCalls = $this->taskTexts !== [];
        $agentCallTaskTexts->taskTexts = $this->taskTexts;
        return $agentCallTaskTexts;
    }

    protected function answerOrFail(): void
    {
        $this->questionCount++;
        if ($this->fails) {
            throw new \RuntimeException('voice backend down');
        }
    }
}
