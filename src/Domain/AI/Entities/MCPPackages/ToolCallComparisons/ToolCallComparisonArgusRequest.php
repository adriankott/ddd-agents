<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One Argus request of a compared case as two runs compare it: its endpoint and its body — a JSON body decoded, so its
 * id-named fields are normalised as ids ({@see ToolCallComparisonIdPlaceholders::normalizeStructure()}). Pure.
 *
 * @phpstan-consistent-constructor
 */
class ToolCallComparisonArgusRequest extends ValueObject
{
    public string $endpoint = '';

    /** The raw body as sent */
    public string $requestBody = '';

    public static function forRequest(string $endpoint, string $requestBody): static
    {
        $toolCallComparisonArgusRequest = static::newInstance();
        $toolCallComparisonArgusRequest->endpoint = $endpoint;
        $toolCallComparisonArgusRequest->requestBody = $requestBody;
        return $toolCallComparisonArgusRequest;
    }

    /**
     * The stored document of the request: `endpoint`, then `body` (the decoded JSON body, else the raw body).
     *
     * @return array
     */
    public function toArray(): array
    {
        $decodedRequestBody = json_decode($this->requestBody, true);
        return [
            'endpoint' => $this->endpoint,
            'body' => is_array($decodedRequestBody) ? $decodedRequestBody : $this->requestBody,
        ];
    }
}
