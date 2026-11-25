<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Ring;

use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/** The message bus, keeping every dispatched message with its stamps instead of sending it; can throw on dispatch. */
class RecordingMessageBusDouble implements MessageBusInterface
{
    /** @var array<int, array{message: object, stamps: array<int, object>}> */
    public array $dispatchedMessages = [];

    public ?string $dispatchFailureMessage = null;

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        if ($this->dispatchFailureMessage !== null) {
            throw new RuntimeException($this->dispatchFailureMessage);
        }
        $this->dispatchedMessages[] = ['message' => $message, 'stamps' => $stamps];
        return new Envelope($message, $stamps);
    }
}
