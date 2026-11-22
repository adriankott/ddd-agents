<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\AI\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * Input for the `searchTheWeb` tool: one query — or up to `AI.webSearch.hits.maxQueriesPerCall` independent queries in `queries`, run in
 * parallel server-side — with optional filters, and optionally the reading of the best result pages by a fast model
 * for an extraction task. The search engine is a server concern; the model supplies the queries and the filters.
 */
class SearchTheWebRequestDto extends RequestDto
{
    /** @var string|null For exactly ONE search: what to look for, as you would type it into a search engine. Two or more searches of a step go into `queries` — one call. */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $query = null;

    /**
     * @var string[]|null ALL independent searches of one step (max 5), run IN PARALLEL server-side — one call, one
     * wait. Each a short search-engine query for ONE sub-question (e.g. one per product compared, one per aspect).
     */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?array $queries = null;

    /** @var string|null `fast` (default) or `web` — `web` searches deeper at five times the price; use it only when a `fast` search came back thin. */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $mode = null;

    /** @var string|null Only pages from the last `hour`, `day`, `week`, `month` or `year` — for news and events only; omit it for specifications, tests, prices and facts, whose best sources are often older. */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $recency = null;

    /** @var string[]|null Only these sites (e.g. `example.com`); a leading `-` excludes a site (`-pinterest.com`). Max 20. */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?array $domains = null;

    /** @var string|null Two-letter country whose web is searched (`DE`, `AT`, `US`); defaults to the country of the conversation's space, when the application knows one. */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $countryCode = null;

    /** @var int|null Pages per query, 1–20 (default 8). */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $maxHits = null;

    /**
     * @var bool True: a fast model also reads the best result pages (up to `maxPages`) and returns only what
     * `extractionTask` asks for, with verbatim quotes. Use it when the question has several aspects and the excerpts are
     * thin; for one or two known pages call `extractInformationFromWebPagesWithFastLLM` instead.
     */
    #[Parameter(in: Parameter::BODY, required: false)]
    public bool $extractInformationFromResultPagesWithFastLLM = false;

    /** @var string|null Required with `extractInformationFromResultPagesWithFastLLM`: exactly what to pull from the pages (the figures, the variant, the year). */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $extractionTask = null;

    /** @var int|null How many result pages the fast model reads, 1–10 (default 8). */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $maxPages = null;
}
