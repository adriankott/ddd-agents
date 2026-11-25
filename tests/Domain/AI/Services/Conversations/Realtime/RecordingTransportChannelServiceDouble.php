<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Realtime;

use DDD\Domain\AI\Entities\Conversations\Realtime\AgentRealtimeAudience;
use DDD\Domain\AI\Entities\Conversations\Realtime\AgentRealtimeListenerCredential;
use DDD\Domain\AI\Entities\Conversations\Realtime\AgentRealtimeMessage;
use DDD\Domain\AI\Services\ClientTools\AgentUIRealtimeMessageTypeProviderService;
use DDD\Domain\AI\Services\Conversations\Realtime\AgentRealtimeChannelService;
use DDD\Infrastructure\Cache\Cache;

/**
 * The realtime port bound to a fake transport, as a host binds its own in a same-path subclass: it records what it
 * publishes and clears, issues one credential per account and answers presence from its own connections. Without a
 * transport (`$hasTransport` false) it behaves like the package default (mailbox only).
 */
class RecordingTransportChannelServiceDouble extends AgentRealtimeChannelService
{
    public bool $hasTransport = true;

    public bool $transportFails = false;

    /** @var AgentRealtimeMessage[] */
    public array $publishedMessages = [];

    /** @var string[] type|key of every cleared message */
    public array $clearedMessages = [];

    /** @var array<string, bool> mailbox name => whether a client listens on the transport */
    public array $listeningByMailboxName = [];

    public ?Cache $sharedCacheDouble = null;

    public function __construct(?Cache $sharedCacheDouble = null)
    {
        $this->sharedCacheDouble = $sharedCacheDouble;
        $this->setAgentRealtimeMessageTypeProviders([new AgentUIRealtimeMessageTypeProviderService(), new DemoRealtimeMessageTypeProviderDouble()]);
    }

    protected function publishMessageToTransport(AgentRealtimeMessage $agentRealtimeMessage): bool
    {
        if (!$this->hasTransport) {
            return false;
        }
        if ($this->transportFails) {
            throw new \RuntimeException('transport down');
        }
        $this->publishedMessages[] = $agentRealtimeMessage;
        return true;
    }

    protected function clearMessageOnTransport(AgentRealtimeAudience $agentRealtimeAudience, string $messageType, string $messageKey): void
    {
        if ($this->hasTransport) {
            $this->clearedMessages[] = $messageType . '|' . $messageKey;
        }
    }

    protected function issueListenerCredential(int $accountId, ?int $rootConversationId): ?AgentRealtimeListenerCredential
    {
        if (!$this->hasTransport) {
            return null;
        }
        $agentRealtimeListenerCredential = new AgentRealtimeListenerCredential();
        $agentRealtimeListenerCredential->transportName = 'fake';
        $agentRealtimeListenerCredential->credential = 'token-of-' . $accountId;
        $agentRealtimeListenerCredential->listenPath = 'accounts/' . $accountId;
        return $agentRealtimeListenerCredential;
    }

    protected function askTransportWhetherClientIsListening(AgentRealtimeAudience $agentRealtimeAudience): ?bool
    {
        return $this->hasTransport ? ($this->listeningByMailboxName[$agentRealtimeAudience->getMailboxName()] ?? false) : null;
    }

    protected function resolveCacheGroup(?string $cacheGroup): ?Cache
    {
        return $this->sharedCacheDouble;
    }

    protected function readSettings(): array
    {
        return ['sharedCacheGroup' => 'shared'];
    }
}
