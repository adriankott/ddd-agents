<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Api\Agents\Conversations;

/** An application's controller that states no presence from the account-level realtime read (its override point). */
class AgentAccountRealtimeReadWithoutStampFixtureController extends AgentAccountRealtimeReadFixtureController
{
    protected function stampUIClientOfAccount(int $accountId, ?string $clientId): void
    {
    }
}
