<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\WebSearch\Fixtures;

use DDD\Domain\AI\Services\WebSearch\AIWebSearchService;
use DDD\Infrastructure\Http\PublicUrlGuard;

/** The research service with every host public except the ones named `private.test` (no resolver is asked). */
class PublicHostsAIWebSearchService extends AIWebSearchService
{
    protected function classifyHostMemoized(string $url): string
    {
        return str_contains($url, 'private.test') ? PublicUrlGuard::HOST_PRIVATE : PublicUrlGuard::HOST_PUBLIC;
    }
}
