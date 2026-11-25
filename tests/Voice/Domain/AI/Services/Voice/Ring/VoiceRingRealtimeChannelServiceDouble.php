<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Ring;

use DDD\Domain\AI\Entities\Conversations\Realtime\AgentRealtimeAudience;
use DDD\Domain\AI\Entities\Conversations\Realtime\AgentRealtimeMessage;
use DDD\Domain\AI\Entities\Conversations\Realtime\AgentRealtimeSendResult;
use DDD\Domain\AI\Services\ClientTools\AgentUIRealtimeMessageTypeProviderService;
use DDD\Domain\AI\Services\Voice\AgentVoiceRealtimeMessageTypeProviderService;
use DDD\Infrastructure\Cache\Cache;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Realtime\RecordingTransportChannelServiceDouble;
use RuntimeException;

/**
 * The package's realtime port bound to its fake transport ({@see RecordingTransportChannelServiceDouble}) with
 * the voice channel's ring type registered, and with one operation made to throw on demand: the listener read, the
 * send, the clear, or the shared cache read.
 */
class VoiceRingRealtimeChannelServiceDouble extends RecordingTransportChannelServiceDouble
{
    public const string OPERATION_LISTENER_READ = 'listenerRead';
    public const string OPERATION_SEND = 'send';
    public const string OPERATION_CLEAR = 'clear';

    /** @var string[] The operations that throw */
    public array $failingOperations = [];

    /** @var AgentRealtimeAudience[] Every audience the listener read was asked for */
    public array $listenerReadAudiences = [];

    /** @var array<int, array{audience: string, messageType: string, messageKey: string}> Every clear the voice channel asked */
    public array $askedClears = [];

    /** @var bool When false the port has no shared cache (the mailbox and the shared entries are gone) */
    public bool $hasSharedCache = true;

    public function __construct(?Cache $sharedCacheDouble = null)
    {
        parent::__construct($sharedCacheDouble);
        $this->setAgentRealtimeMessageTypeProviders([new AgentUIRealtimeMessageTypeProviderService(), new AgentVoiceRealtimeMessageTypeProviderService()]);
    }

    public function isClientListening(AgentRealtimeAudience $agentRealtimeAudience): bool
    {
        $this->listenerReadAudiences[] = $agentRealtimeAudience;
        if (in_array(static::OPERATION_LISTENER_READ, $this->failingOperations, true)) {
            throw new RuntimeException('listener read failed');
        }
        return parent::isClientListening($agentRealtimeAudience);
    }

    public function sendMessage(AgentRealtimeMessage $agentRealtimeMessage): AgentRealtimeSendResult
    {
        if (in_array(static::OPERATION_SEND, $this->failingOperations, true)) {
            throw new RuntimeException('send failed');
        }
        return parent::sendMessage($agentRealtimeMessage);
    }

    public function clearMessage(AgentRealtimeAudience $agentRealtimeAudience, string $messageType, string $messageKey): void
    {
        $this->askedClears[] = ['audience' => $agentRealtimeAudience->getMailboxName(), 'messageType' => $messageType, 'messageKey' => $messageKey];
        if (in_array(static::OPERATION_CLEAR, $this->failingOperations, true)) {
            throw new RuntimeException('clear failed');
        }
        parent::clearMessage($agentRealtimeAudience, $messageType, $messageKey);
    }

    protected function resolveCacheGroup(?string $cacheGroup): ?Cache
    {
        return $this->hasSharedCache ? parent::resolveCacheGroup($cacheGroup) : null;
    }
}
