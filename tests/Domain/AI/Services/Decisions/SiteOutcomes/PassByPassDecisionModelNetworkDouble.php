<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Decisions\SiteOutcomes;

use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;

/**
 * The decision model answering each request from its own script, in send order (the first pass, the second pass), and
 * keeping the state of every request it received.
 */
class PassByPassDecisionModelNetworkDouble extends DecisionModelNetworkDouble
{
    /** @var array<int, array<string, float|string>> per request in send order: question name → answer */
    public array $answersByPass = [];

    /** @var array<int, array<string, mixed>> the state of every request, in send order */
    public array $sentStates = [];

    /** @param array<string, mixed> $options */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $decodedBody = json_decode((string)$request->getBody(), true);
        $this->sentStates[] = is_array($decodedBody['state'] ?? null) ? $decodedBody['state'] : [];
        $this->answersByQuestion = $this->answersByPass[count($this->sentStates) - 1] ?? [];
        return parent::__invoke($request, $options);
    }
}
