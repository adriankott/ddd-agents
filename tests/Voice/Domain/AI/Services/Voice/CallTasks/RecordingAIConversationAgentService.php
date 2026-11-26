<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\CallTasks;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AgentClientContext;
use DDD\Domain\AI\Entities\Conversations\Subjects\AIConversationSubject;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\CompactionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\OwnerDevice;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettingsOverride;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Domain\Common\Entities\Accounts\Account;

/**
 * The agent's drive without a turn: every `driveConversation()` is recorded with the arguments the bridge passes, and
 * answers the conversation the test prepared (its messages are what the bridge reads the task index from).
 */
class RecordingAIConversationAgentService extends AIConversationAgentService
{
    /** @var array<int, array<string, mixed>> */
    public array $driveRequests = [];

    public ?AIConversation $drivenConversation = null;

    public function driveConversation(
        string $scope,
        ?int $aiConversationId,
        ?AgentSpace $agentSpace,
        string $message,
        ?string $aiModelName = null,
        ?bool $budgetBypass = null,
        ?int $truncateFromIndex = null,
        bool $async = false,
        ?CompactionSettings $compactionSettings = null,
        ?ContextRetentionSettings $contextRetentionSettings = null,
        ?string $startSignalText = null,
        ?string $startSignalSource = null,
        ?string $initiatorType = null,
        ?int $maxStepsPerTurn = null,
        ?int $scheduleId = null,
        ?RunSettings $runSettings = null,
        ?string $purposeName = null,
        ?string $reportChannel = null,
        ?Account $runAsAccount = null,
        ?bool $supportOverride = null,
        ?RunSettingsOverride $runSettingsOverride = null,
        ?OwnerDevice $ownerDevice = null,
        bool $messageWrittenByPlatform = false,
        ?string $entryPointKey = null,
        ?AgentClientContext $clientContext = null,
        ?AIConversationSubject $aIConversationSubject = null
    ): AIConversation {
        $this->driveRequests[] = [
            'scope' => $scope,
            'aiConversationId' => $aiConversationId,
            'agentSpace' => $agentSpace,
            'message' => $message,
            'async' => $async,
            'startSignalText' => $startSignalText,
            'startSignalSource' => $startSignalSource,
            'initiatorType' => $initiatorType,
            'runAsAccount' => $runAsAccount,
        ];

        if ($this->drivenConversation === null) {
            // its messages set in memory: an unset one would lazy-load from the database
            $this->drivenConversation = AIConversation::newInstance();
            $this->drivenConversation->messages = new AIConversationMessages();
        }

        return $this->drivenConversation;
    }
}
