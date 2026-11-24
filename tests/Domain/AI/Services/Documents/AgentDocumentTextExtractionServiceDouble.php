<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Documents;

use DDD\Domain\AI\Services\Documents\AgentDocumentTextExtractionService;
use DDD\Infrastructure\Cache\Cache;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\InMemoryCacheGroupDouble;

/** The extraction with its configuration and cache group given by the test; the reading is the real one. */
class AgentDocumentTextExtractionServiceDouble extends AgentDocumentTextExtractionService
{
    /** @var array<string, mixed> The `AI.documents` node (missing keys keep their defaults) */
    public array $documentsConfigNode = [];

    public InMemoryCacheGroupDouble $cacheGroup;

    public function __construct()
    {
        $this->cacheGroup = new InMemoryCacheGroupDouble();
    }

    protected function readConfigNode(string $configKey): mixed
    {
        return $configKey === self::CONFIG_KEY ? $this->documentsConfigNode : null;
    }

    protected function resolveCacheGroup(?string $cacheGroup): ?Cache
    {
        return $this->cacheGroup;
    }
}
