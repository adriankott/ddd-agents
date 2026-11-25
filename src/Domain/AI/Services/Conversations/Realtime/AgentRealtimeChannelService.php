<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Realtime;

use DDD\Domain\AI\Entities\Conversations\Realtime\AgentRealtimeAudience;
use DDD\Domain\AI\Entities\Conversations\Realtime\AgentRealtimeListenerCredential;
use DDD\Domain\AI\Entities\Conversations\Realtime\AgentRealtimeMessage;
use DDD\Domain\AI\Entities\Conversations\Realtime\AgentRealtimeMessageDeliveries;
use DDD\Domain\AI\Entities\Conversations\Realtime\AgentRealtimeMessages;
use DDD\Domain\AI\Entities\Conversations\Realtime\AgentRealtimeMessageType;
use DDD\Domain\AI\Entities\Conversations\Realtime\AgentRealtimeMessageTypes;
use DDD\Domain\AI\Entities\Conversations\Realtime\AgentRealtimeSendResult;
use DDD\Domain\AI\Services\ClientTools\AgentClientPresenceService;
use DDD\Domain\AI\Services\ClientTools\AgentUIRealtimeMessageTypeProviderService;
use DDD\Domain\AI\Services\Conversations\AIConversationRealtimeSignalService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Cache\Apc;
use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Service\Attribute\Required;
use Throwable;

/**
 * The realtime port of the agent: typed messages from the server to the user's open clients (a client tool request of
 * `Agent.UI`, later the ring of a voice call), without a table.
 *
 * The contract:
 * - (a) {@see sendMessage()}: a message of a registered type ({@see AgentRealtimeMessageTypeProvider}) with a key, a
 *   payload of one JSON object and an expiry, to one account's open clients or to the clients of one conversation,
 *   optionally to one client of them;
 * - (b) {@see clearMessage()}: removes a message by type and key; sending the same type and key again replaces it;
 * - (c) {@see findListenerCredential()}: issues or reuses what a client needs to listen on the host's transport;
 * - (d) {@see isClientListening()}: whether a client listens — the transport's own answer when it has one, else the
 *   presence the clients state with what they already send ({@see AgentClientPresenceService}).
 *
 * Every message is also kept in a mailbox in the shared cache (`AI.agentRealtimeChannel.sharedCacheGroup`) until it
 * expires or is cleared; a client receives the open ones with its next run-state read. So a host without a transport
 * works as it is (the client finds a request at its next read, within its poll interval), and a host with one gets
 * the message at once.
 *
 * A host binds its transport by a same-path subclass (`App\Domain\AI\Services\Conversations\Realtime\
 * AgentRealtimeChannelService`) that overrides the four transport hooks: {@see publishMessageToTransport()},
 * {@see clearMessageOnTransport()}, {@see issueListenerCredential()} and {@see askTransportWhetherClientIsListening()}.
 * Each runs best effort: a throw is logged and the mailbox still holds the message.
 */
class AgentRealtimeChannelService extends Service
{
    /** @var string The configuration of the channel */
    public const string CONFIG_KEY = 'AI.agentRealtimeChannel';

    /** @var string The prefix of the mailbox keys */
    public const string MAILBOX_KEY_PREFIX = 'agent-realtime-mailbox-';

    /** @var int The most messages one mailbox keeps (the oldest leave first) */
    public const int MAX_MAILBOX_MESSAGES = 20;

    /** @var int Seconds a mailbox outlives its newest expiry */
    public const int MAILBOX_TTL_MARGIN_SECONDS = 30;

    /** @var int How long a mailbox write holds its lock */
    public const int MAILBOX_LOCK_SECONDS = 2;

    /** @var string[] The warnings already written in this process */
    protected static array $reportedWarnings = [];

    /** @var iterable<AgentRealtimeMessageTypeProvider> */
    protected iterable $agentRealtimeMessageTypeProviders = [];

    protected ?AgentRealtimeMessageTypes $registeredMessageTypes = null;

    /** @param iterable<AgentRealtimeMessageTypeProvider> $agentRealtimeMessageTypeProviders */
    #[Required]
    public function setAgentRealtimeMessageTypeProviders(
        #[AutowireIterator(AgentRealtimeMessageTypeProvider::TAG)]
        iterable $agentRealtimeMessageTypeProviders
    ): void {
        $this->agentRealtimeMessageTypeProviders = $agentRealtimeMessageTypeProviders;
        $this->registeredMessageTypes = null;
    }

    /**
     * The registered message types of every provider; a type registered twice keeps the first registration, an
     * invalid name is skipped (both logged).
     */
    public function getRegisteredMessageTypes(): AgentRealtimeMessageTypes
    {
        if ($this->registeredMessageTypes !== null) {
            return $this->registeredMessageTypes;
        }
        $agentRealtimeMessageTypes = new AgentRealtimeMessageTypes();
        foreach ($this->agentRealtimeMessageTypeProviders as $agentRealtimeMessageTypeProvider) {
            try {
                $providedMessageTypes = $agentRealtimeMessageTypeProvider->getRealtimeMessageTypes();
            } catch (Throwable $providerFailure) {
                $this->reportWarningOnce('provider-' . get_class($agentRealtimeMessageTypeProvider), 'A realtime message type provider failed: ' . $providerFailure->getMessage());
                continue;
            }
            foreach ($providedMessageTypes->getElements() as $agentRealtimeMessageType) {
                if (!AgentRealtimeMessageType::isValidName($agentRealtimeMessageType->name)) {
                    $this->reportWarningOnce('name-' . $agentRealtimeMessageType->name, "The realtime message type '{$agentRealtimeMessageType->name}' is no valid name and is skipped.");
                    continue;
                }
                if ($agentRealtimeMessageTypes->findMessageType($agentRealtimeMessageType->name) !== null) {
                    $this->reportWarningOnce('twice-' . $agentRealtimeMessageType->name, "The realtime message type '{$agentRealtimeMessageType->name}' is registered twice; the first registration is kept.");
                    continue;
                }
                $agentRealtimeMessageTypes->add($agentRealtimeMessageType);
            }
        }
        return $this->registeredMessageTypes = $agentRealtimeMessageTypes;
    }

    /**
     * (a) Sends a message: into the mailbox, then to the host's transport. A message of the same type and key in the
     * mailbox is replaced. The mailbox comes first: a transport that only rings makes the client read the mailbox at
     * once, and that read must find the message.
     *
     * @throws BadRequestException an unregistered type, an invalid key, a payload that is no JSON object or too long
     */
    public function sendMessage(AgentRealtimeMessage $agentRealtimeMessage): AgentRealtimeSendResult
    {
        $this->validateMessage($agentRealtimeMessage);
        $agentRealtimeSendResult = new AgentRealtimeSendResult();
        $agentRealtimeSendResult->keptInMailbox = $this->changeMailbox($agentRealtimeMessage->audience, $agentRealtimeMessage, null, null);
        try {
            $agentRealtimeSendResult->sentThroughTransport = $this->publishMessageToTransport($agentRealtimeMessage);
        } catch (Throwable $transportFailure) {
            $this->logTransportFailure('publish', $agentRealtimeMessage->messageType, $transportFailure);
        }
        return $agentRealtimeSendResult;
    }

    /**
     * (b) Removes a message by type and key, from the mailbox, then from the transport; a missing message is fine. The
     * mailbox comes first for the reason of {@see sendMessage()}: a client the transport tells re-reads the mailbox.
     */
    public function clearMessage(AgentRealtimeAudience $agentRealtimeAudience, string $messageType, string $messageKey): void
    {
        $this->changeMailbox($agentRealtimeAudience, null, $messageType, $messageKey);
        try {
            $this->clearMessageOnTransport($agentRealtimeAudience, $messageType, $messageKey);
        } catch (Throwable $transportFailure) {
            $this->logTransportFailure('clear', $messageType, $transportFailure);
        }
    }

    /**
     * (c) The credential a client of the account needs to listen on the host's transport; null without a transport
     * (the client then receives its messages with its run-state reads). Never throws.
     */
    public function findListenerCredential(int $accountId, ?int $rootConversationId = null): ?AgentRealtimeListenerCredential
    {
        try {
            return $this->issueListenerCredential($accountId, $rootConversationId);
        } catch (Throwable $transportFailure) {
            $this->logTransportFailure('credential', 'listener', $transportFailure);
            return null;
        }
    }

    /**
     * (d) Whether a client of the audience listens now: the transport's answer when it has one, else a fresh presence
     * statement of a client of the conversation (or, for an account audience, of the account).
     */
    public function isClientListening(AgentRealtimeAudience $agentRealtimeAudience): bool
    {
        try {
            $transportAnswer = $this->askTransportWhetherClientIsListening($agentRealtimeAudience);
        } catch (Throwable $transportFailure) {
            $this->logTransportFailure('presence', $agentRealtimeAudience->getMailboxName(), $transportFailure);
            $transportAnswer = null;
        }
        if ($transportAnswer !== null) {
            return $transportAnswer;
        }
        $agentClientPresenceService = $this->getAgentClientPresenceService();
        return $agentRealtimeAudience->conversationId !== null
            ? $agentClientPresenceService->findTargetPresence($agentRealtimeAudience->conversationId) !== null
            : $agentClientPresenceService->findNewestPresenceOfAccount($agentRealtimeAudience->accountId) !== null;
    }

    /**
     * The open messages of a mailbox (not expired), oldest first; empty without a shared cache. A conversation
     * audience reads the conversation's mailbox and the account's.
     */
    public function findOpenMessages(AgentRealtimeAudience $agentRealtimeAudience, ?string $messageType = null): AgentRealtimeMessages
    {
        $mailboxNames = [$agentRealtimeAudience->getMailboxName()];
        if ($agentRealtimeAudience->conversationId !== null) {
            $mailboxNames[] = AgentRealtimeAudience::forAccount($agentRealtimeAudience->accountId)->getMailboxName();
        }
        $agentRealtimeMessages = new AgentRealtimeMessages();
        $sharedCache = $this->getSharedCache();
        if ($sharedCache === null) {
            return $agentRealtimeMessages;
        }
        $mailboxKeys = array_map([$this, 'buildMailboxKey'], $mailboxNames);
        try {
            $mailboxesByKey = $sharedCache->getMultiple($mailboxKeys);
        } catch (Throwable) {
            return $agentRealtimeMessages;
        }
        $now = new DateTime();
        foreach ($mailboxKeys as $mailboxKey) {
            foreach ($this->readMailboxEntries($mailboxesByKey[$mailboxKey] ?? null) as $agentRealtimeMessage) {
                if (!$agentRealtimeMessage->isExpired($now) && ($messageType === null || $agentRealtimeMessage->messageType === $messageType)) {
                    $agentRealtimeMessages->add($agentRealtimeMessage);
                }
            }
        }
        return $agentRealtimeMessages;
    }

    /**
     * What a reading client receives from the mailbox: the open messages for it (for every client of the audience or
     * addressed to it), except the types delivered in a field of their own.
     *
     * @param string[] $excludedMessageTypes
     */
    public function findDeliveriesForClient(AgentRealtimeAudience $agentRealtimeAudience, ?string $clientId, array $excludedMessageTypes = []): AgentRealtimeMessageDeliveries
    {
        $agentRealtimeMessageDeliveries = new AgentRealtimeMessageDeliveries();
        $now = new DateTime();
        foreach ($this->findOpenMessages($agentRealtimeAudience)->getElements() as $agentRealtimeMessage) {
            if ($agentRealtimeMessage->isForClient($clientId) && !in_array($agentRealtimeMessage->messageType, $excludedMessageTypes, true)) {
                $agentRealtimeMessageDelivery = $agentRealtimeMessage->toDelivery($now);
                $agentRealtimeMessageDeliveries->add($agentRealtimeMessageDelivery);
            }
        }
        return $agentRealtimeMessageDeliveries;
    }

    /**
     * The cache the processes share (web requests and workers): the configured group (null = the Redis group, else
     * Core's default group). Null when it is not available or local to one process (APCu): then no mailbox, no
     * presence and no client tool answer crosses from a request to a worker; reported once per process.
     */
    public function getSharedCache(): ?Cache
    {
        $sharedCache = $this->findCandidateSharedCache();
        $sharedCacheProblem = $this->findSharedCacheProblem($sharedCache);
        if ($sharedCacheProblem !== null) {
            $this->reportWarningOnce('shared-cache', $sharedCacheProblem);
            return null;
        }
        return $sharedCache;
    }

    /**
     * Why the channel has no shared cache; null when it has one. Read by the installation check too.
     */
    public function findSharedCacheProblem(?Cache $candidateSharedCache = null): ?string
    {
        $candidateSharedCache ??= $this->findCandidateSharedCache();
        if ($candidateSharedCache === null) {
            return 'The realtime channel of the agent has no shared cache (AI.agentRealtimeChannel.sharedCacheGroup): client tool requests answer "no client".';
        }
        if ($this->isProcessLocalCache($candidateSharedCache)) {
            return 'The shared cache of the agent\'s realtime channel is local to the process (' . get_class($candidateSharedCache) . '): a request and a worker '
                . "cannot see each other's clients. Set AI.agentRealtimeChannel.sharedCacheGroup to a shared group (e.g. 'redis').";
        }
        return null;
    }

    /** The configured group, else the Redis group, else Core's default group; null when none is available */
    protected function findCandidateSharedCache(): ?Cache
    {
        $configuredCacheGroup = $this->readSettings()['sharedCacheGroup'] ?? null;
        foreach ($configuredCacheGroup !== null ? [(string)$configuredCacheGroup] : [Cache::CACHE_GROUP_REDIS, null] as $cacheGroup) {
            try {
                $sharedCache = $this->resolveCacheGroup($cacheGroup);
            } catch (Throwable) {
                $sharedCache = null;
            }
            if ($sharedCache !== null) {
                return $sharedCache;
            }
        }
        return null;
    }

    /** Whether a cache keeps its entries in this process only (APCu) */
    public function isProcessLocalCache(Cache $cache): bool
    {
        return $cache instanceof Apc;
    }

    /**
     * The transport hook of (a): hands a message to the host's transport; true when the transport took it. The
     * package has no transport. For a client tool request it still rings the deprecated per-conversation ping of
     * {@see AIConversationRealtimeSignalService::publishClientToolRequestSignal()},
     * so a host that bound its transport there keeps waking its client (which then reads the mailbox); it answers
     * false, the mailbox delivers.
     */
    protected function publishMessageToTransport(AgentRealtimeMessage $agentRealtimeMessage): bool
    {
        if ($agentRealtimeMessage->messageType === AgentUIRealtimeMessageTypeProviderService::MESSAGE_TYPE_CLIENT_TOOL_REQUEST
            && $agentRealtimeMessage->audience->conversationId !== null
        ) {
            /** @var AIConversationRealtimeSignalService $aIConversationRealtimeSignalService */
            $aIConversationRealtimeSignalService = DDDService::instance()->getService(AIConversationRealtimeSignalService::class);
            $aIConversationRealtimeSignalService->publishClientToolRequestSignal($agentRealtimeMessage->audience->conversationId);
        }
        return false;
    }

    /** The transport hook of (b): removes a message from the host's transport. The package has none. */
    protected function clearMessageOnTransport(AgentRealtimeAudience $agentRealtimeAudience, string $messageType, string $messageKey): void
    {
    }

    /** The transport hook of (c): issues or reuses a listener credential. The package has no transport: null. */
    protected function issueListenerCredential(int $accountId, ?int $rootConversationId): ?AgentRealtimeListenerCredential
    {
        return null;
    }

    /**
     * The transport hook of (d): the transport's own answer whether a client of the audience listens; null = the
     * transport cannot tell (the package's answer), then the presence statements decide.
     */
    protected function askTransportWhetherClientIsListening(AgentRealtimeAudience $agentRealtimeAudience): ?bool
    {
        return null;
    }

    /**
     * @throws BadRequestException
     */
    protected function validateMessage(AgentRealtimeMessage $agentRealtimeMessage): void
    {
        $agentRealtimeMessageType = $this->getRegisteredMessageTypes()->findMessageType($agentRealtimeMessage->messageType);
        if ($agentRealtimeMessageType === null) {
            throw new BadRequestException("The realtime message type '{$agentRealtimeMessage->messageType}' is not registered (AgentRealtimeMessageTypeProvider).");
        }
        if (!AgentRealtimeMessage::isValidMessageKey($agentRealtimeMessage->messageKey)) {
            throw new BadRequestException('A realtime message key has 1 to 128 letters, digits, ".", "_", ":" and "-".');
        }
        if (!is_object(json_decode($agentRealtimeMessage->payloadJson))) {
            throw new BadRequestException('The payload of a realtime message is one JSON object.');
        }
        if (mb_strlen($agentRealtimeMessage->payloadJson) > $agentRealtimeMessageType->maxPayloadJsonChars) {
            throw new BadRequestException("The payload of a '{$agentRealtimeMessage->messageType}' message has at most {$agentRealtimeMessageType->maxPayloadJsonChars} characters.");
        }
    }

    /**
     * Puts a message into a mailbox (replacing one of its type and key) or removes one; drops expired messages and
     * keeps the newest {@see MAX_MAILBOX_MESSAGES}. Holds the mailbox's lock where the cache has locks. False when no
     * shared cache took the write.
     */
    protected function changeMailbox(AgentRealtimeAudience $agentRealtimeAudience, ?AgentRealtimeMessage $addedMessage, ?string $removedMessageType, ?string $removedMessageKey): bool
    {
        $sharedCache = $this->getSharedCache();
        if ($sharedCache === null) {
            return false;
        }
        $mailboxKey = $this->buildMailboxKey($agentRealtimeAudience->getMailboxName());
        $lockToken = $this->acquireMailboxLock($sharedCache, $mailboxKey);
        try {
            $now = new DateTime();
            $keptMessages = [];
            foreach ($this->readMailboxEntries($sharedCache->get($mailboxKey)) as $agentRealtimeMessage) {
                $isReplacedOrRemoved = ($addedMessage !== null && $agentRealtimeMessage->uniqueKey() === $addedMessage->uniqueKey())
                    || ($agentRealtimeMessage->messageType === $removedMessageType && $agentRealtimeMessage->messageKey === $removedMessageKey);
                if (!$isReplacedOrRemoved && !$agentRealtimeMessage->isExpired($now)) {
                    $keptMessages[] = $agentRealtimeMessage;
                }
            }
            if ($addedMessage !== null) {
                $keptMessages[] = $addedMessage;
            }
            $keptMessages = array_slice($keptMessages, -static::MAX_MAILBOX_MESSAGES);
            if ($keptMessages === []) {
                $sharedCache->delete($mailboxKey);
                return true;
            }
            $newestExpiry = 0;
            $mailboxEntries = [];
            foreach ($keptMessages as $keptMessage) {
                $mailboxEntries[] = $keptMessage->toMailboxEntry();
                $newestExpiry = max($newestExpiry, $keptMessage->expiresAt->getTimestamp());
            }
            $sharedCache->set($mailboxKey, $mailboxEntries, max(1, $newestExpiry - $now->getTimestamp()) + static::MAILBOX_TTL_MARGIN_SECONDS);
            return true;
        } catch (Throwable $mailboxFailure) {
            $this->logTransportFailure('mailbox', $agentRealtimeAudience->getMailboxName(), $mailboxFailure);
            return false;
        } finally {
            if ($lockToken !== null) {
                try {
                    $sharedCache->releaseLock($mailboxKey, $lockToken);
                } catch (Throwable) {
                    // the lock expires by itself
                }
            }
        }
    }

    /** The mailbox's lock: a few short tries; null when the cache has no locks or another writer keeps it (the write goes on) */
    protected function acquireMailboxLock(Cache $sharedCache, string $mailboxKey): ?string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $lockToken = $sharedCache->acquireLock($mailboxKey, static::MAILBOX_LOCK_SECONDS);
            } catch (Throwable) {
                return null;
            }
            if ($lockToken !== null) {
                return $lockToken;
            }
            usleep(20000);
        }
        return null;
    }

    /**
     * @param mixed $mailboxValue
     * @return AgentRealtimeMessage[]
     */
    protected function readMailboxEntries(mixed $mailboxValue): array
    {
        $agentRealtimeMessages = [];
        foreach (is_array($mailboxValue) ? $mailboxValue : [] as $mailboxEntry) {
            $agentRealtimeMessage = AgentRealtimeMessage::fromMailboxEntry($mailboxEntry);
            if ($agentRealtimeMessage !== null) {
                $agentRealtimeMessages[] = $agentRealtimeMessage;
            }
        }
        return $agentRealtimeMessages;
    }

    protected function buildMailboxKey(string $mailboxName): string
    {
        return static::MAILBOX_KEY_PREFIX . $mailboxName;
    }

    /** @return array<string, mixed> The channel's configuration (`config/app/AI/agentRealtimeChannel.php`) */
    protected function readSettings(): array
    {
        $settings = Config::get(static::CONFIG_KEY);
        return is_array($settings) ? $settings : [];
    }

    /** The Core cache of a group (null = Core's default group); overridable for a test */
    protected function resolveCacheGroup(?string $cacheGroup): ?Cache
    {
        return Cache::instance($cacheGroup);
    }

    protected function getAgentClientPresenceService(): AgentClientPresenceService
    {
        /** @var AgentClientPresenceService $agentClientPresenceService */
        $agentClientPresenceService = DDDService::instance()->getService(AgentClientPresenceService::class);
        return $agentClientPresenceService;
    }

    protected function logTransportFailure(string $operation, string $subject, Throwable $transportFailure): void
    {
        DDDService::instance()->getLogger()->warning('[AgentRealtime] ' . $operation . ' failed for ' . $subject . ': ' . $transportFailure->getMessage());
    }

    protected function reportWarningOnce(string $warningKind, string $warningText): void
    {
        if (in_array($warningKind, static::$reportedWarnings, true)) {
            return;
        }
        static::$reportedWarnings[] = $warningKind;
        DDDService::instance()->getLogger()->warning('[AgentRealtime] ' . $warningText);
    }
}
