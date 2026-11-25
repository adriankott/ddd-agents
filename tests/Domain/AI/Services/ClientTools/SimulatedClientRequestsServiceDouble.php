<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\ClientTools;

use DDD\Domain\AI\Services\ClientTools\AgentClientPresenceService;
use DDD\Domain\AI\Services\ClientTools\AgentClientToolRequestsService;
use DDD\Domain\AI\Services\ClientTools\AgentUIClientToolRequestTypeProviderService;
use DDD\Domain\AI\Services\Conversations\Realtime\AgentRealtimeChannelService;
use DDD\Domain\AI\Services\Eval\AgentScriptedUIClientService;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Realtime\RecordingTransportChannelServiceDouble;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Realtime\SharedCacheWithCountersDouble;
use Throwable;

/**
 * The client tool requests over a shared cache in memory, without a database: between two reads of the answer key a
 * simulated client of the conversation reads its delivery (as its run-state read would) and answers it — or does not.
 */
class SimulatedClientRequestsServiceDouble extends AgentClientToolRequestsService
{
    public SharedCacheWithCountersDouble $sharedCache;

    public RecordingTransportChannelServiceDouble $channel;

    public SharedCachePresenceServiceDouble $presenceService;

    public bool $mayUseUI = true;

    /** @var callable|null (SimulatedClientRequestsServiceDouble) => void, called between two reads of the answer key */
    public $simulatedClient = null;

    public int $pauses = 0;

    public ?Throwable $clientFailure = null;

    /** @param SharedCacheWithCountersDouble|null $sharedCache the shared cache; null: one with an atomic counter */
    public function __construct(?SharedCacheWithCountersDouble $sharedCache = null)
    {
        $this->sharedCache = $sharedCache ?? new SharedCacheWithCountersDouble();
        $this->channel = new RecordingTransportChannelServiceDouble($this->sharedCache);
        $this->channel->hasTransport = false;
        $this->presenceService = new SharedCachePresenceServiceDouble($this->sharedCache);
        $this->setAgentClientToolRequestTypeProviders([new AgentUIClientToolRequestTypeProviderService()]);
    }

    protected function pauseBetweenReads(): void
    {
        $this->pauses++;
        if ($this->simulatedClient !== null) {
            try {
                ($this->simulatedClient)($this);
            } catch (Throwable $clientFailure) {
                $this->clientFailure = $clientFailure;
            }
        }
        usleep(1000);
    }

    protected function touchRunHeartbeat(int $conversationId): void
    {
    }

    protected function isCurrentAccountAllowedToUseUI(): bool
    {
        return $this->mayUseUI;
    }

    protected function getAgentRealtimeChannelService(): AgentRealtimeChannelService
    {
        return $this->channel;
    }

    protected function getAgentClientPresenceService(): AgentClientPresenceService
    {
        return $this->presenceService;
    }

    protected function getAgentScriptedUIClientService(): AgentScriptedUIClientService
    {
        return new NoScriptedScreenUIClientServiceDouble();
    }
}
