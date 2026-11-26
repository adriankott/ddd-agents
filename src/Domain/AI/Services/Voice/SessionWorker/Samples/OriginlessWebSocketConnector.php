<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Voice\SessionWorker\Samples;

use Psr\Http\Message\RequestInterface;
use Ratchet\Client\Connector;

/**
 * Pawl's WebSocket client without the `Origin` header it adds to every handshake: a server talks to the provider
 * as a server, and OpenAI refuses a GPT-Live server session that carries a browser origin (HTTP 403).
 */
class OriginlessWebSocketConnector extends Connector
{
    /**
     * @param mixed $url
     * @param array $subProtocols
     * @param array $headers
     * @return RequestInterface
     */
    protected function generateRequest($url, array $subProtocols, array $headers)
    {
        return parent::generateRequest($url, $subProtocols, $headers)->withoutHeader('Origin');
    }
}
