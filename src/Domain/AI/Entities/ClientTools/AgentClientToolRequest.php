<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\ClientTools;

use DDD\Domain\AI\Services\ClientTools\AgentClientToolRequestsService;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * A request of a tool to the user's client (a browser tab, an app), the channel of `Agent.UI`: a tool sends it through
 * the realtime port and waits ({@see AgentClientToolRequestsService::requestAndWait()}); the client receives it through
 * the host's transport or with its run-state read, executes it once and posts the answer; the waiting tool reads the
 * answer from the shared cache. Nothing of it is stored in a table: the request lives in the realtime mailbox until
 * it is answered or expires, the answer in the shared cache until the tool read it.
 *
 * The types are open: the package's own are the TYPE_* constants, a host or package registers more through
 * {@see \DDD\Domain\AI\Services\ClientTools\AgentClientToolRequestTypeProvider}.
 */
class AgentClientToolRequest extends ValueObject
{
    /** @var string Read the screen as Markdown, or capture it as a picture */
    public const string TYPE_READ_SCREEN = 'READ_SCREEN';

    /** @var string List the navigation targets */
    public const string TYPE_LIST_TARGETS = 'LIST_TARGETS';

    /** @var string Navigate to a target */
    public const string TYPE_OPEN_TARGET = 'OPEN_TARGET';

    /** @var string Mark elements on the screen */
    public const string TYPE_HIGHLIGHT = 'HIGHLIGHT';

    /** @var string Set the view mode of the agent window (off unless the host switches it on) */
    public const string TYPE_SET_WINDOW_MODE = 'SET_WINDOW_MODE';

    /** @var string A typed action on the open screen: set filters, sort, open a row, switch a tab, press a declared button (off unless the host switches it on) */
    public const string TYPE_ACT_ON_SCREEN = 'ACT_ON_SCREEN';

    /** @var string[] The package's own types (the registered set is open: {@see AgentClientToolRequestsService::getRegisteredRequestTypes()}) */
    public const array TYPES = [self::TYPE_READ_SCREEN, self::TYPE_LIST_TARGETS, self::TYPE_OPEN_TARGET, self::TYPE_HIGHLIGHT, self::TYPE_SET_WINDOW_MODE, self::TYPE_ACT_ON_SCREEN];

    /** @var string The outcome of a request the client carried out */
    public const string OUTCOME_OK = 'OK';

    /** @var string The outcome of a request whose tool stopped waiting */
    public const string OUTCOME_NO_ANSWER = 'NO_ANSWER';

    /** @var int The longest arguments JSON a tool may send */
    public const int MAX_ARGUMENTS_JSON_CHARS = 2048;

    /** @var int The longest answer text the client may post */
    public const int MAX_ANSWER_TEXT_CHARS = 24000;

    /** @var int The longest details JSON the client may post */
    public const int MAX_ANSWER_DETAILS_JSON_CHARS = 1024;

    /** @var int The longest outcome code */
    public const int MAX_OUTCOME_CHARS = 48;

    /** @var int The smallest request id (ids are random, below 2^53 so a browser reads them exactly) */
    public const int MIN_REQUEST_ID = 1_000_000_000_000;

    /** @var int The largest request id */
    public const int MAX_REQUEST_ID = 9_007_199_254_740_991;

    /** @var int The id of the request: random, unique enough for its short life (the answer route takes it) */
    public int $id;

    /** @var int The root conversation the calling tool runs in (the owner's thread) */
    public int $conversationId;

    /** @var int The account the tool runs for (the owner of the conversation) */
    public int $accountId;

    /** @var string|null The provider's id of the tool call */
    public ?string $toolCallId = null;

    /** @var string What the client is to do (a registered type) */
    public string $requestType;

    /** @var string The arguments as one JSON object, at most {@see self::MAX_ARGUMENTS_JSON_CHARS} characters */
    public string $argumentsJson = '{}';

    /** @var string The client that is to execute it (a client id) */
    public string $targetClientId;

    /** @var DateTime Until when the tool waits */
    public DateTime $expiresAt;

    /** A fresh request id: random in [{@see MIN_REQUEST_ID}, {@see MAX_REQUEST_ID}] */
    public static function generateRequestId(): int
    {
        return random_int(static::MIN_REQUEST_ID, static::MAX_REQUEST_ID);
    }

    /** Whole seconds until the tool stops waiting; 0 once it has */
    public function getSecondsLeft(\DateTimeInterface $now): int
    {
        return max(0, $this->expiresAt->getTimestamp() - $now->getTimestamp());
    }

    /**
     * One argument as sent: a string, an int, a bool, a list of strings, or null when the request carries none.
     *
     * @return string|int|bool|string[]|null
     */
    public function getArgument(string $argumentName): string|int|bool|array|null
    {
        $arguments = json_decode($this->argumentsJson, true);
        $argument = is_array($arguments) ? ($arguments[$argumentName] ?? null) : null;
        if (is_array($argument)) {
            return array_values(array_map('strval', array_filter($argument, 'is_scalar')));
        }
        return is_string($argument) || is_int($argument) || is_bool($argument) ? $argument : null;
    }

    /** What the client receives */
    public function toDelivery(\DateTimeInterface $now): AgentClientToolRequestDelivery
    {
        $delivery = new AgentClientToolRequestDelivery();
        $delivery->id = $this->id;
        $delivery->requestType = $this->requestType;
        $delivery->argumentsJson = $this->argumentsJson;
        $delivery->secondsLeft = $this->getSecondsLeft($now);
        return $delivery;
    }

    /**
     * The record the answer route checks against (an implementation detail of the shared cache).
     *
     * @return array<string, string|int|null>
     */
    public function toCacheEntry(): array
    {
        return [
            'id' => $this->id,
            'conversationId' => $this->conversationId,
            'accountId' => $this->accountId,
            'toolCallId' => $this->toolCallId,
            'requestType' => $this->requestType,
            'argumentsJson' => $this->argumentsJson,
            'targetClientId' => $this->targetClientId,
            'expiresAt' => $this->expiresAt->getTimestamp(),
        ];
    }

    /** A request from its cache record; null for a value that is none */
    public static function fromCacheEntry(mixed $cacheEntry): ?static
    {
        if (!is_array($cacheEntry) || !is_int($cacheEntry['id'] ?? null) || !is_int($cacheEntry['conversationId'] ?? null)
            || !is_int($cacheEntry['accountId'] ?? null) || !is_string($cacheEntry['requestType'] ?? null)
            || !is_string($cacheEntry['targetClientId'] ?? null) || !is_int($cacheEntry['expiresAt'] ?? null)
        ) {
            return null;
        }
        $agentClientToolRequest = new static();
        $agentClientToolRequest->id = $cacheEntry['id'];
        $agentClientToolRequest->conversationId = $cacheEntry['conversationId'];
        $agentClientToolRequest->accountId = $cacheEntry['accountId'];
        $agentClientToolRequest->toolCallId = is_string($cacheEntry['toolCallId'] ?? null) ? $cacheEntry['toolCallId'] : null;
        $agentClientToolRequest->requestType = $cacheEntry['requestType'];
        $agentClientToolRequest->argumentsJson = is_string($cacheEntry['argumentsJson'] ?? null) ? $cacheEntry['argumentsJson'] : '{}';
        $agentClientToolRequest->targetClientId = $cacheEntry['targetClientId'];
        $agentClientToolRequest->expiresAt = DateTime::fromTimestamp($cacheEntry['expiresAt']);
        return $agentClientToolRequest;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic((string)($this->id ?? spl_object_id($this)));
    }
}
