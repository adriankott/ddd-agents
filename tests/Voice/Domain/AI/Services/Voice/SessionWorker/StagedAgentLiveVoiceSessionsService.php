<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\SessionWorker;

use DDD\Domain\AI\Entities\Conversations\Agent\Activity\AgentActivityTurnFeed;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Services\AISessionUsageService;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoriesService;
use DDD\Domain\AI\Services\Conversations\TraceEvents\AIConversationTraceEventsService;
use DDD\Domain\AI\Services\Voice\AgentCallTasksService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallAdmissionService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallsService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallStoreService;
use DDD\Domain\AI\Services\Voice\AgentVoiceSessionContextsService;
use DDD\Domain\AI\Services\Voice\SessionWorker\AgentLiveVoiceSessionsService;
use DDD\Domain\AI\Services\Voice\Providers\RealtimeVoiceProviderAdapter;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\Message;

/**
 * The session worker without a socket, a database or a clock of its own: every collaborator it reaches through a
 * `get…()` method or an override point answers from a recording double held in a public field, the voice log is kept
 * in memory, and the tests reach the worker's state through {@see getWorkerState()} / {@see setWorkerState()} and its
 * protected steps through {@see runWorkerStep()}.
 *
 * The worker reads the call's conversation through its override point {@see getAIConversationsService()}, answered
 * by the test case's {@see FixedAIConversationsService}. The billed account and the session's model call come from the
 * admission ({@see RecordingAgentVoiceCallAdmissionService}), which reads them through the container's services.
 */
class StagedAgentLiveVoiceSessionsService extends AgentLiveVoiceSessionsService
{
    public RecordingVoiceProviderAdapter $voiceProviderAdapter;

    public RecordingAgentVoiceCallsService $agentVoiceCallsService;

    public RecordingAgentVoiceCallStoreService $agentVoiceCallStoreService;

    public RecordingAgentVoiceCallAdmissionService $agentVoiceCallAdmissionService;

    public RecordingAgentCallTasksService $agentCallTasksService;

    public RecordingAIConversationMessagesService $aIConversationMessagesService;

    public RecordingAIConversationTraceEventsService $aIConversationTraceEventsService;

    public RecordingAgentMemoriesService $agentMemoriesService;

    public RecordingAISessionUsageService $aISessionUsageService;

    public RecordingAIUsageEnvelopesService $aIUsageEnvelopesService;

    public RecordingAgentVoiceSessionContextsService $agentVoiceSessionContextsService;

    public ?AIConversationsService $aIConversationsService = null;

    /** @var string[] The voice log, line by line, without the time stamp */
    public array $voiceLogLines = [];

    public ?AgentActivityTurnFeed $activityTurnFeed = null;

    /** @var array<int, array{rootConversationId: int, taskMessageIndex: int}> */
    public array $activityTurnFeedReads = [];

    /** @var int[] */
    public array $activityReaderStampedForRootConversationIds = [];

    /** @var string|null The end reason `endVoiceCall` left for this call; null = none */
    public ?string $requestedCallEndAfterGoodbye = null;

    public int $requestedCallEndClears = 0;

    public function __construct()
    {
        parent::__construct();
        $this->voiceProviderAdapter = new RecordingVoiceProviderAdapter();
        $this->agentVoiceCallsService = new RecordingAgentVoiceCallsService();
        $this->agentVoiceCallStoreService = new RecordingAgentVoiceCallStoreService();
        $this->agentVoiceCallAdmissionService = new RecordingAgentVoiceCallAdmissionService();
        $this->agentCallTasksService = new RecordingAgentCallTasksService();
        $this->aIConversationMessagesService = new RecordingAIConversationMessagesService();
        $this->aIConversationTraceEventsService = new RecordingAIConversationTraceEventsService();
        $this->agentMemoriesService = new RecordingAgentMemoriesService();
        $this->aISessionUsageService = new RecordingAISessionUsageService();
        $this->aIUsageEnvelopesService = new RecordingAIUsageEnvelopesService();
        $this->agentVoiceSessionContextsService = new RecordingAgentVoiceSessionContextsService();
    }

    public function getWorkerState(string $fieldName): mixed
    {
        return $this->{$fieldName};
    }

    public function setWorkerState(string $fieldName, mixed $fieldValue): void
    {
        $this->{$fieldName} = $fieldValue;
    }

    /** Runs one protected step of the worker, as the event loop or another step would. */
    public function runWorkerStep(string $methodName, mixed ...$methodArguments): mixed
    {
        return $this->{$methodName}(...$methodArguments);
    }

    /**
     * One provider event as the socket delivers it: a text frame through the public callback the socket calls
     * ({@see applyReceivedSidebandMessage()}), which needs the attached call and its socket.
     *
     * @param array<string, mixed> $sessionEvent
     */
    public function receiveSessionEvent(AgentVoiceCall $agentVoiceCall, RecordingSidebandWebSocket $sidebandWebSocket, array $sessionEvent): void
    {
        $this->attachedAgentVoiceCall = $agentVoiceCall;
        $this->sidebandWebSocketConnection = $sidebandWebSocket;
        $sidebandMessage = new Message();
        $sidebandMessage->addFrame(new Frame((string)json_encode($sessionEvent)));
        $this->applyReceivedSidebandMessage($sidebandMessage);
    }

    /** One watcher tick for the call on the socket, as the 2-second timer runs it. */
    public function runWatcherTickOn(AgentVoiceCall $agentVoiceCall, RecordingSidebandWebSocket $sidebandWebSocket): void
    {
        $this->attachedAgentVoiceCall = $agentVoiceCall;
        $this->sidebandWebSocketConnection = $sidebandWebSocket;
        $this->runWatcherTickOfAttachedCall();
    }

    /** One fast check for the call on the socket, as the 0.5-second timer runs it. */
    public function runFastCheckOn(AgentVoiceCall $agentVoiceCall, RecordingSidebandWebSocket $sidebandWebSocket): void
    {
        $this->attachedAgentVoiceCall = $agentVoiceCall;
        $this->sidebandWebSocketConnection = $sidebandWebSocket;
        $this->runGoodbyeAndTaskOutcomeCheckOfAttachedCall();
    }

    /** @return string[] The voice log lines that contain `$lineText` */
    public function findVoiceLogLinesContaining(string $lineText): array
    {
        return array_values(array_filter(
            $this->voiceLogLines,
            static fn (string $voiceLogLine): bool => str_contains($voiceLogLine, $lineText)
        ));
    }

    protected function appendToVoiceLog(string $voiceLogLine): void
    {
        $this->voiceLogLines[] = $voiceLogLine;
    }

    protected function findActivityTurnFeed(int $rootConversationId, int $taskMessageIndex): ?AgentActivityTurnFeed
    {
        $this->activityTurnFeedReads[] = ['rootConversationId' => $rootConversationId, 'taskMessageIndex' => $taskMessageIndex];

        return $this->activityTurnFeed;
    }

    protected function markActivityReaderPresent(int $rootConversationId): void
    {
        $this->activityReaderStampedForRootConversationIds[] = $rootConversationId;
    }

    protected function findRequestedCallEndAfterGoodbye(AgentVoiceCall $agentVoiceCall): ?string
    {
        return $this->requestedCallEndAfterGoodbye;
    }

    protected function clearRequestedCallEndAfterGoodbye(AgentVoiceCall $agentVoiceCall): void
    {
        $this->requestedCallEndClears++;
        $this->requestedCallEndAfterGoodbye = null;
    }

    protected function voiceSessionContextsService(): AgentVoiceSessionContextsService
    {
        return $this->agentVoiceSessionContextsService;
    }

    protected function getAIConversationsService(): AIConversationsService
    {
        return $this->aIConversationsService ?? parent::getAIConversationsService();
    }

    protected function getAgentVoiceCallsService(): AgentVoiceCallsService
    {
        return $this->agentVoiceCallsService;
    }

    protected function getAgentVoiceCallStoreService(): AgentVoiceCallStoreService
    {
        return $this->agentVoiceCallStoreService;
    }

    protected function getAgentVoiceCallAdmissionService(): AgentVoiceCallAdmissionService
    {
        return $this->agentVoiceCallAdmissionService;
    }

    protected function getAgentCallTasksService(): AgentCallTasksService
    {
        return $this->agentCallTasksService;
    }

    protected function getAIConversationMessagesService(): AIConversationMessagesService
    {
        return $this->aIConversationMessagesService;
    }

    protected function getAIConversationTraceEventsService(): AIConversationTraceEventsService
    {
        return $this->aIConversationTraceEventsService;
    }

    protected function getAgentMemoriesService(): AgentMemoriesService
    {
        return $this->agentMemoriesService;
    }

    protected function getAISessionUsageService(): AISessionUsageService
    {
        return $this->aISessionUsageService;
    }

    protected function getAIUsageEnvelopesService(): AIUsageEnvelopesService
    {
        return $this->aIUsageEnvelopesService;
    }

    protected function getVoiceProviderAdapterOfCall(AgentVoiceCall $agentVoiceCall): RealtimeVoiceProviderAdapter
    {
        return $this->voiceProviderAdapter;
    }
}
