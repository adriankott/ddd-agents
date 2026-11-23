<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use Attribute;

/**
 * Marks an #[MCPTool] method that fetches a URL the model chose (a page reading, a screenshot). After the current turn
 * read untrusted content, the executor refuses such a call when the URL carries a query string or a fragment that
 * appears in no owner message and no tool result of the conversation: a page could otherwise make the model send what
 * it knows to an address of the page's choosing (the data rides in the query string). Following a link exactly as a
 * result showed it stays possible.
 *
 *   #[MCPTool, MCPToolFetchesModelChosenUrl('url', 'urls')]
 *   public function getWebPageAsMarkdown(WebPageMarkdownGetRequestDto $requestDto): ... {}
 */
#[Attribute(Attribute::TARGET_METHOD)]
readonly class MCPToolFetchesModelChosenUrl
{
    /** @var string[] The tool arguments that hold a URL or a list of URLs */
    public array $argumentNames;

    /**
     * @param string ...$argumentNames the tool arguments that hold the URL (a list of URLs is checked element by element)
     */
    public function __construct(string ...$argumentNames)
    {
        $this->argumentNames = $argumentNames !== [] ? array_values($argumentNames) : ['url'];
    }
}
