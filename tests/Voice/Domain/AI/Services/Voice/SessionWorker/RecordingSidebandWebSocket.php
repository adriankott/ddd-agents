<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\SessionWorker;

use Ratchet\Client\WebSocket;

/**
 * The sideband socket without a connection: what the worker sends is decoded and kept, a close is counted. The
 * listeners the worker registers stay on the object ({@see countListeners()}).
 */
class RecordingSidebandWebSocket extends WebSocket
{
    /** @var array<int, array<string, mixed>> Every client event sent, decoded, in order */
    public array $sentClientEvents = [];

    public int $closeCount = 0;

    public function __construct()
    {
        // no stream: nothing of the parent's connection setup applies
    }

    public function send($msg)
    {
        $this->sentClientEvents[] = (array)json_decode((string)$msg, true);

        return true;
    }

    public function close($code = 1000, $reason = ''): void
    {
        $this->closeCount++;
    }

    /** @return string[] The `type` of every sent client event, in order */
    public function getSentClientEventTypes(): array
    {
        return array_map(
            static fn (array $sentClientEvent): string => (string)($sentClientEvent['type'] ?? ''),
            $this->sentClientEvents
        );
    }

    /** @return array<int, array<string, mixed>> The sent client events of one type, in order */
    public function findSentClientEventsOfType(string $clientEventType): array
    {
        return array_values(array_filter(
            $this->sentClientEvents,
            static fn (array $sentClientEvent): bool => ($sentClientEvent['type'] ?? null) === $clientEventType
        ));
    }

    /**
     * The client events of one type without the `event_id` the worker mints for each (random), so a test can compare
     * whole events.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findSentClientEventsOfTypeWithoutEventIds(string $clientEventType): array
    {
        return array_map(
            static fn (array $sentClientEvent): array => array_diff_key($sentClientEvent, ['event_id' => true]),
            $this->findSentClientEventsOfType($clientEventType)
        );
    }

    public function countListeners(string $eventName): int
    {
        return count($this->listeners($eventName));
    }
}
