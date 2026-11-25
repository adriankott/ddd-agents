<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Live\Provider;

use ArrayObject;
use DDD\Domain\AI\Services\Voice\Live\OpenAILiveVoiceProviderAdapter;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * The GPT-Live adapter with its HTTP client replaced ({@see buildHttpClient()}): every request goes to a Guzzle
 * MockHandler that answers the queued responses (or throws the queued transport failures) and records what was sent;
 * nothing reaches the network. The voice log lines are recorded instead of written to the logger.
 */
class RecordingOpenAILiveVoiceProviderAdapter extends OpenAILiveVoiceProviderAdapter
{
    /** @var array<int, ResponseInterface|Throwable> What the provider answers, in order */
    public array $queuedAnswers = [];

    /** @var int[] The timeout of every HTTP client the adapter built, in order */
    public array $builtClientTimeoutSeconds = [];

    /** @var string[] The voice log lines the adapter wrote */
    public array $voiceLogLines = [];

    /** @var ArrayObject<int, array{request: RequestInterface, options: array<string, mixed>}> The requests sent */
    public ArrayObject $sentRequests;

    public function __construct()
    {
        $this->sentRequests = new ArrayObject();
    }

    /** @return RequestInterface The n-th request sent (0 = the first) */
    public function getSentRequest(int $requestIndex): RequestInterface
    {
        return $this->sentRequests[$requestIndex]['request'];
    }

    /** @return array<string, mixed> The decoded JSON body of the n-th request sent */
    public function getSentRequestJsonBody(int $requestIndex): array
    {
        return (array)json_decode((string)$this->getSentRequest($requestIndex)->getBody(), true);
    }

    protected function buildHttpClient(int $timeoutSeconds): Client
    {
        $this->builtClientTimeoutSeconds[] = $timeoutSeconds;
        $handlerStack = HandlerStack::create(new MockHandler($this->queuedAnswers));
        $this->queuedAnswers = [];
        $handlerStack->push(Middleware::history($this->sentRequests));

        return new Client(['handler' => $handlerStack, 'timeout' => $timeoutSeconds]);
    }

    protected function appendToVoiceLog(string $voiceLogLine): void
    {
        $this->voiceLogLines[] = $voiceLogLine;
    }
}
