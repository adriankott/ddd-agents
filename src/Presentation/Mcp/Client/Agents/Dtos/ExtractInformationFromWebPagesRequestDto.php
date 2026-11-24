<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * Input for the `extractInformationFromWebPagesWithFastLLM` tool: known page URLs and the extraction task a fast model
 * reads them for. No payer argument: who pays comes from the call's context.
 */
class ExtractInformationFromWebPagesRequestDto extends RequestDto
{
    /** @var string[] The pages to read (max 10), all in ONE call — they are read in parallel. */
    #[Parameter(in: Parameter::BODY, required: true)]
    public array $urls = [];

    /**
     * @var string Exactly what to pull from the pages, precise enough for a model that reads without context: the
     * figures, the product variant, the year, the market (e.g. "monthly price and included storage of the Pro plan,
     * billed yearly, 2026").
     */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $extractionTask = '';
}
