<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\Scorecards;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/** A scorecard transport that only counts: it reports the scripted number of queued messages and sends nothing. */
class ScorecardTransportWithQueuedCountDouble implements TransportInterface, MessageCountAwareInterface
{
    public function __construct(public int $queuedMessageCount)
    {
    }

    public function getMessageCount(): int
    {
        return $this->queuedMessageCount;
    }

    public function get(): iterable
    {
        return [];
    }

    public function ack(Envelope $envelope): void
    {
    }

    public function reject(Envelope $envelope): void
    {
    }

    public function send(Envelope $envelope): Envelope
    {
        return $envelope;
    }
}
