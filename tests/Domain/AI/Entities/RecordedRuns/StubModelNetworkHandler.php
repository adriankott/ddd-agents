<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\RecordedRuns;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * The network behind a recording transport in a test: answers every request with one fixed JSON body and counts the
 * requests it saw. No request leaves the process.
 */
class StubModelNetworkHandler
{
    public const string ANSWER_BODY = '{"choices":[{"message":{"role":"assistant","content":"Noted."}}]}';

    /** How many requests reached the network. */
    public int $requestCount = 0;

    /**
     * @param RequestInterface $request
     * @param array<string, mixed> $options
     * @return PromiseInterface
     */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $this->requestCount++;
        return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], self::ANSWER_BODY));
    }
}
