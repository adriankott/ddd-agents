<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\ClientTools;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The newest statement of one client (a browser tab, an app) that shows a conversation with the agent (`Agent.UI`):
 * its id, whether it is visible, its route and UI state, stated with what it already sends (a message post, a
 * run-state read, the answer to a client tool request). Kept in the shared cache with a short TTL
 * ({@see \DDD\Domain\AI\Services\ClientTools\AgentClientPresenceService}), never in a table. A client counts as present
 * while its statement is fresh ({@see self::isFresh()}); a client tool request goes to one of them, and the newest
 * one's UI state is the `[SIGNAL:ui]` note of the model.
 */
class AgentClientPresence extends ValueObject
{
    /** @var int A client not seen for this long counts as gone (the idle poll of the package's chat is 20 s) */
    public const int FRESH_FOR_SECONDS = 25;

    /** @var string A client id: 8 to 64 letters, digits and `-` */
    public const string CLIENT_ID_PATTERN = '/^[A-Za-z0-9\-]{8,64}$/';

    /** @var int The root conversation the client shows; 0 for an account statement */
    public int $conversationId = 0;

    /** @var int The account that read or sent */
    public int $accountId = 0;

    /** @var string The id of the client: random per mount of the agent window */
    public string $clientId;

    /** @var int When the client stated last (Unix seconds) */
    public int $lastSeenAt = 0;

    /** @var bool|null Whether the client was visible at its last statement */
    public ?bool $visible = null;

    /** @var string|null The route path the client showed at its last statement */
    public ?string $route = null;

    /** @var int|null When the user last sent something to the conversation from this client (Unix seconds) */
    public ?int $lastOwnerSendAt = null;

    /** @var string|null The UI state the client reported last ({@see AgentUIState}, normalized), as JSON */
    public ?string $uiStateJson = null;

    public static function isValidClientId(?string $clientId): bool
    {
        return $clientId !== null && preg_match(static::CLIENT_ID_PATTERN, $clientId) === 1;
    }

    /** The UI state the client reported last; null when it reported none */
    public function getUIState(): ?AgentUIState
    {
        $uiStateJson = $this->uiStateJson;
        if ($uiStateJson === null || $uiStateJson === '') {
            return null;
        }
        // setPropertiesFromObject() takes its argument by reference: it needs a variable, never an expression
        $uiStateObject = json_decode($uiStateJson);
        if (!is_object($uiStateObject)) {
            return null;
        }
        $agentUIState = new AgentUIState();
        $agentUIState->setPropertiesFromObject($uiStateObject);
        return $agentUIState->normalize();
    }

    /** Whether the client stated within {@see self::FRESH_FOR_SECONDS} */
    public function isFresh(\DateTimeInterface $now): bool
    {
        return $this->lastSeenAt > 0 && $now->getTimestamp() - $this->lastSeenAt <= static::FRESH_FOR_SECONDS;
    }

    /**
     * The form the shared cache keeps (an implementation detail, never returned across a public boundary).
     *
     * @return array<string, string|int|bool|null>
     */
    public function toCacheEntry(): array
    {
        return [
            'conversationId' => $this->conversationId,
            'accountId' => $this->accountId,
            'clientId' => $this->clientId,
            'lastSeenAt' => $this->lastSeenAt,
            'visible' => $this->visible,
            'route' => $this->route,
            'lastOwnerSendAt' => $this->lastOwnerSendAt,
            'uiStateJson' => $this->uiStateJson,
        ];
    }

    /** A presence from its cache form; null for an entry that is none */
    public static function fromCacheEntry(mixed $cacheEntry): ?static
    {
        if (!is_array($cacheEntry) || !static::isValidClientId(is_string($cacheEntry['clientId'] ?? null) ? $cacheEntry['clientId'] : null)
            || !is_int($cacheEntry['lastSeenAt'] ?? null)
        ) {
            return null;
        }
        $agentClientPresence = new static();
        $agentClientPresence->conversationId = is_int($cacheEntry['conversationId'] ?? null) ? $cacheEntry['conversationId'] : 0;
        $agentClientPresence->accountId = is_int($cacheEntry['accountId'] ?? null) ? $cacheEntry['accountId'] : 0;
        $agentClientPresence->clientId = $cacheEntry['clientId'];
        $agentClientPresence->lastSeenAt = $cacheEntry['lastSeenAt'];
        $agentClientPresence->visible = is_bool($cacheEntry['visible'] ?? null) ? $cacheEntry['visible'] : null;
        $agentClientPresence->route = is_string($cacheEntry['route'] ?? null) ? $cacheEntry['route'] : null;
        $agentClientPresence->lastOwnerSendAt = is_int($cacheEntry['lastOwnerSendAt'] ?? null) ? $cacheEntry['lastOwnerSendAt'] : null;
        $agentClientPresence->uiStateJson = is_string($cacheEntry['uiStateJson'] ?? null) ? $cacheEntry['uiStateJson'] : null;
        return $agentClientPresence;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->conversationId . '|' . $this->clientId);
    }
}
