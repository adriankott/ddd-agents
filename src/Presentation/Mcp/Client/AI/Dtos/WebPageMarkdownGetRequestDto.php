<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\AI\Dtos;

use DDD\Domain\AI\Entities\MCPPackages\MCPOutputOnlyProperty;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * Input for the `getWebPageAsMarkdown` tool: ONE page (`url`) — or SEVERAL pages in `urls`, crawled in PARALLEL
 * server-side. A pure crawl without a model call.
 */
class WebPageMarkdownGetRequestDto extends RequestDto
{
    /** @var string|null For exactly ONE page: its full URL (https://…). Two or more pages of a step go into `urls` — one call. */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $url = null;

    /**
     * @var string[]|null ALL page URLs of one step, read IN PARALLEL server-side (max 5) — several pages of one site, or
     * one page each of several sites, in ONE call (one wait ≈ one page's latency).
     */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?array $urls = null;

    /**
     * @var bool True reads the pages again instead of the cached copy and rewrites the cache entry. Server-side only:
     * an application's own call of the tool passes it (to warm the cache); {@see MCPOutputOnlyProperty} keeps it out
     * of the model's input schema while hydration still accepts it.
     */
    #[Parameter(in: Parameter::BODY, required: false)]
    #[MCPOutputOnlyProperty]
    public bool $noCache = false;
}
