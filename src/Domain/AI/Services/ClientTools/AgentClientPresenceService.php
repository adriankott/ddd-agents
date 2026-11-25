<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\ClientTools;

use DDD\Domain\AI\Entities\ClientTools\AgentClientPresence;
use DDD\Domain\AI\Entities\ClientTools\AgentClientPresences;
use DDD\Domain\AI\Entities\ClientTools\AgentUIState;
use DDD\Domain\AI\Services\Conversations\Realtime\AgentRealtimeChannelService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Throwable;

/**
 * The presence of the user's clients (`Agent.UI`) without a table: a client states its id, visibility, route and UI
 * state with what it already sends (a message post, a run-state read, the answer to a client tool request); the
 * newest statements of the clients of a conversation, and the newest statement per account, live in the shared cache
 * of the realtime channel ({@see AgentRealtimeChannelService::getSharedCache()}) for {@see PRESENCE_TTL_SECONDS}.
 *
 * A client that shows no conversation states only that it is there, with its account-level realtime read
 * ({@see recordClientStatementWithoutConversation()}). That statement has a key of its own per account, so it never
 * replaces what a client that shows a conversation stated; {@see findNewestPresenceOfAccount()} reads both.
 *
 * A client tool request goes to the client {@see findTargetPresence()} picks; no fresh statement = no client. Without
 * a shared cache nothing is stated and every read answers "no client".
 */
class AgentClientPresenceService extends Service
{
    /** @var string The prefix of a conversation's statements */
    public const string CONVERSATION_KEY_PREFIX = 'agent-client-presence-conversation-';

    /** @var string The prefix of an account's newest statement of a client that shows a conversation */
    public const string ACCOUNT_KEY_PREFIX = 'agent-client-presence-account-';

    /** @var string The prefix of an account's newest statement of a client that shows no conversation */
    public const string ACCOUNT_WITHOUT_CONVERSATION_KEY_PREFIX = 'agent-client-presence-without-conversation-';

    /** @var int How long statements are kept after the last one */
    public const int PRESENCE_TTL_SECONDS = 900;

    /** @var int A statement is written again only after this many seconds, unless visibility, route or UI state changed */
    public const int STATEMENT_THROTTLE_SECONDS = 10;

    /** @var int The most clients of one conversation that are kept (the longest unseen leave first) */
    public const int MAX_CLIENTS_PER_CONVERSATION = 5;

    /**
     * Records one statement of a client: the first one, a changed visibility, route or UI state, a send of the user,
     * or one after the throttle is written; anything else only refreshes nothing. A value that is no client id is
     * ignored; the UI state is normalized ({@see AgentUIState::normalize()}), never refused. Never throws.
     *
     * @param int $conversationId the root conversation the client shows
     * @param int $accountId the account that read or sent
     * @param AgentUIState|null $agentUIState what the client reported; null keeps the stated one
     * @param bool $isOwnerSend whether the statement comes with a send of the user
     */
    public function recordClientStatement(int $conversationId, int $accountId, ?string $clientId, ?AgentUIState $agentUIState, bool $isOwnerSend = false): void
    {
        if ($conversationId <= 0 || !AgentClientPresence::isValidClientId($clientId)) {
            return;
        }
        $sharedCache = $this->getSharedCache();
        if ($sharedCache === null) {
            return;
        }
        try {
            $now = (new DateTime())->getTimestamp();
            $normalizedUIState = $agentUIState?->normalize();
            $uiStateJson = $normalizedUIState !== null ? static::encodeUIState($normalizedUIState) : null;
            $agentClientPresences = $this->findPresencesOfConversation($conversationId);
            $agentClientPresence = $agentClientPresences->getByUniqueKey(AgentClientPresence::uniqueKeyStatic($conversationId . '|' . $clientId));
            $visible = $normalizedUIState !== null ? $normalizedUIState->visible : $agentClientPresence?->visible;
            $route = $normalizedUIState !== null ? $normalizedUIState->routePath : $agentClientPresence?->route;
            $isChanged = $agentClientPresence === null || $agentClientPresence->visible !== $visible || $agentClientPresence->route !== $route
                || ($uiStateJson !== null && $agentClientPresence->uiStateJson !== $uiStateJson);
            if (!$isChanged && !$isOwnerSend && $now - $agentClientPresence->lastSeenAt < static::STATEMENT_THROTTLE_SECONDS) {
                return;
            }
            if ($agentClientPresence === null) {
                $agentClientPresence = new AgentClientPresence();
                $agentClientPresence->conversationId = $conversationId;
                $agentClientPresence->clientId = (string)$clientId;
            }
            $agentClientPresence->accountId = $accountId;
            $agentClientPresence->lastSeenAt = $now;
            $agentClientPresence->visible = $visible;
            $agentClientPresence->route = $route;
            if ($uiStateJson !== null) {
                $agentClientPresence->uiStateJson = $uiStateJson;
            }
            if ($isOwnerSend) {
                $agentClientPresence->lastOwnerSendAt = $now;
            }
            $cacheEntries = [$agentClientPresence->toCacheEntry()];
            foreach ($agentClientPresences->getElements() as $otherClientPresence) {
                if ($otherClientPresence->clientId !== $agentClientPresence->clientId && count($cacheEntries) < static::MAX_CLIENTS_PER_CONVERSATION) {
                    $cacheEntries[] = $otherClientPresence->toCacheEntry();
                }
            }
            $sharedCache->setMultiple([
                static::CONVERSATION_KEY_PREFIX . $conversationId => $cacheEntries,
                static::ACCOUNT_KEY_PREFIX . $accountId => $agentClientPresence->toCacheEntry(),
            ], static::PRESENCE_TTL_SECONDS);
        } catch (Throwable $presenceFailure) {
            DDDService::instance()->getLogger()->warning('[AgentUI] client statement skipped', ['conversationId' => $conversationId, 'reason' => $presenceFailure->getMessage()]);
        }
    }

    /**
     * Records that a client of the account is there although it shows no conversation: the statement of the
     * account-level realtime read. It carries the client id and the time, nothing else, under a key of its own per
     * account ({@see ACCOUNT_WITHOUT_CONVERSATION_KEY_PREFIX}); the statements of the clients that show a conversation
     * stay as they are. Written when the account has no such statement yet, or its newest one is at least
     * {@see STATEMENT_THROTTLE_SECONDS} old, whichever client stated it: a read in between costs one cache read and no
     * write, however many clients repeat the read (only reads that arrive in the same instant can both write). A
     * client that repeats the read at least every {@see AgentClientPresence::FRESH_FOR_SECONDS} keeps the statement
     * fresh. A value that is no client id is ignored. Never throws.
     *
     * @param int $accountId the signed-in account that read
     * @param string|null $clientId the id the reading client states for itself
     */
    public function recordClientStatementWithoutConversation(int $accountId, ?string $clientId): void
    {
        if ($accountId <= 0 || !AgentClientPresence::isValidClientId($clientId)) {
            return;
        }
        $sharedCache = $this->getSharedCache();
        if ($sharedCache === null) {
            return;
        }
        try {
            $now = (new DateTime())->getTimestamp();
            $statementCacheKey = static::ACCOUNT_WITHOUT_CONVERSATION_KEY_PREFIX . $accountId;
            $newestStatedPresence = AgentClientPresence::fromCacheEntry($this->readSharedCacheValue($statementCacheKey));
            if ($newestStatedPresence !== null && $now - $newestStatedPresence->lastSeenAt < static::STATEMENT_THROTTLE_SECONDS) {
                return;
            }
            $agentClientPresence = new AgentClientPresence();
            $agentClientPresence->accountId = $accountId;
            $agentClientPresence->clientId = (string)$clientId;
            $agentClientPresence->lastSeenAt = $now;
            $sharedCache->set($statementCacheKey, $agentClientPresence->toCacheEntry(), static::PRESENCE_TTL_SECONDS);
        } catch (Throwable $presenceFailure) {
            DDDService::instance()->getLogger()->warning('[AgentUI] client statement skipped', ['accountId' => $accountId, 'reason' => $presenceFailure->getMessage()]);
        }
    }

    /** The stored form of a normalized UI state: one JSON object, the same for the same state */
    public static function encodeUIState(AgentUIState $agentUIState): string
    {
        return (string)json_encode($agentUIState->toObject(false), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** The statements of the clients of a conversation, the newest first (fresh or not); empty without a shared cache */
    public function findPresencesOfConversation(int $conversationId): AgentClientPresences
    {
        $agentClientPresences = new AgentClientPresences();
        $sharedCacheValue = $this->readSharedCacheValue(static::CONVERSATION_KEY_PREFIX . $conversationId);
        $statements = [];
        foreach (is_array($sharedCacheValue) ? $sharedCacheValue : [] as $cacheEntry) {
            $agentClientPresence = AgentClientPresence::fromCacheEntry($cacheEntry);
            if ($agentClientPresence !== null) {
                $statements[] = $agentClientPresence;
            }
        }
        usort($statements, [static::class, 'compareNewestFirst']);
        foreach ($statements as $agentClientPresence) {
            $agentClientPresences->add($agentClientPresence);
        }
        return $agentClientPresences;
    }

    /** The client a client tool request of the conversation goes to ({@see AgentClientPresences::pickTargetPresence()}); null when none is fresh */
    public function findTargetPresence(int $conversationId): ?AgentClientPresence
    {
        return $this->findPresencesOfConversation($conversationId)->filterFresh(new DateTime())->pickTargetPresence();
    }

    /**
     * A fresh statement of a client of the account ({@see AgentClientPresence::isFresh()}): the newest one of a client
     * that shows a conversation, else the newest one of a client that shows none
     * ({@see recordClientStatementWithoutConversation()}; its `conversationId` is 0); null when neither is fresh.
     */
    public function findNewestPresenceOfAccount(int $accountId): ?AgentClientPresence
    {
        $now = new DateTime();
        foreach ([static::ACCOUNT_KEY_PREFIX, static::ACCOUNT_WITHOUT_CONVERSATION_KEY_PREFIX] as $accountKeyPrefix) {
            $agentClientPresence = AgentClientPresence::fromCacheEntry($this->readSharedCacheValue($accountKeyPrefix . $accountId));
            if ($agentClientPresence !== null && $agentClientPresence->isFresh($now)) {
                return $agentClientPresence;
            }
        }
        return null;
    }

    public static function compareNewestFirst(AgentClientPresence $firstPresence, AgentClientPresence $secondPresence): int
    {
        return $secondPresence->lastSeenAt <=> $firstPresence->lastSeenAt;
    }

    protected function readSharedCacheValue(string $cacheKey): mixed
    {
        $sharedCache = $this->getSharedCache();
        if ($sharedCache === null) {
            return null;
        }
        try {
            $sharedCacheValue = $sharedCache->get($cacheKey);
        } catch (Throwable) {
            return null;
        }
        // Core's cache answers a miss with false: a miss is null here
        return $sharedCacheValue === false ? null : $sharedCacheValue;
    }

    protected function getSharedCache(): ?Cache
    {
        /** @var AgentRealtimeChannelService $agentRealtimeChannelService */
        $agentRealtimeChannelService = DDDService::instance()->getService(AgentRealtimeChannelService::class);
        return $agentRealtimeChannelService->getSharedCache();
    }
}
