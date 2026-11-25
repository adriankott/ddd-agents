<?php

declare(strict_types=1);

/**
 * The realtime channel of the agent — read by
 * {@see \DDD\Domain\AI\Services\Conversations\Realtime\AgentRealtimeChannelService} as
 * `Config::get('AI.agentRealtimeChannel')`. An application replaces this file whole with its own
 * `config/app/AI/agentRealtimeChannel.php`; a missing key keeps its default.
 *
 * The channel carries typed messages from the server to the user's clients (a client tool request of `Agent.UI`, the
 * message types other packages register). Every message is kept in a mailbox in the shared cache until it expires or
 * is cleared, and the client's run-state read delivers it — that is the default and works without any transport. A
 * host that has a push channel (WebSocket, Firebase, Pusher, SSE …) binds it in a same-path subclass of the service
 * (`publishMessageToTransport()`, `clearMessageOnTransport()`, `issueListenerCredential()`,
 * `askTransportWhetherClientIsListening()`); the mailbox stays as the fallback.
 *
 * - `sharedCacheGroup`: the cache group every web and worker process shares (`'redis'`: Redis or Valkey; any group
 *   of Core's Cache API). Null tries `'redis'`, then Core's default group. A group local to the process (APCu) is
 *   no shared cache: then the requests, answers and presences of `Agent.UI` are off (the UI tools answer "no client")
 *   and a warning is logged once per process; `app:agent:check-installation` reports it.
 */
return [
    'sharedCacheGroup' => null,
];
