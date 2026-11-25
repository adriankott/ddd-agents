<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\MessageHandlers\Voice\SessionWorker;

use DDD\Domain\AI\MessageHandlers\Voice\SessionWorker\VoiceLiveSessionAttachHandler;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallStoreService;
use DDD\Domain\AI\Services\Voice\SessionWorker\AgentLiveVoiceSessionsService;
use Psr\Log\LoggerInterface;

/** The attach handler with its store, its session worker and its logger answered by recording doubles. */
class StagedVoiceLiveSessionAttachHandler extends VoiceLiveSessionAttachHandler
{
    public RecordingCallLookupStoreService $callLookupStoreService;

    public RecordingAttachingSessionsService $attachingSessionsService;

    public RecordingAttachLogger $attachLogger;

    public function __construct()
    {
        parent::__construct();
        $this->callLookupStoreService = new RecordingCallLookupStoreService();
        $this->attachingSessionsService = new RecordingAttachingSessionsService();
        $this->attachLogger = new RecordingAttachLogger();
    }

    public function getLogger(): LoggerInterface
    {
        return $this->attachLogger;
    }

    protected function getAgentVoiceCallStoreService(): AgentVoiceCallStoreService
    {
        return $this->callLookupStoreService;
    }

    protected function getAgentLiveVoiceSessionsService(): AgentLiveVoiceSessionsService
    {
        return $this->attachingSessionsService;
    }
}
