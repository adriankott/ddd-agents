<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\AI\Dtos;

use DDD\Domain\AI\Entities\WebSearch\WebPageMarkdown;
use DDD\Domain\AI\Entities\WebSearch\WebPageMarkdowns;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultUntrustedSegments;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolResponsePlatformNoteProvider;
use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * Response of the `getWebPageAsMarkdown` tool — the fetched page(s). Rendered as LLM markdown by default (the content
 * IS markdown; a JSON wrapper would only cost tokens): header line, the page text marked as untrusted, capped with an
 * explicit truncation notice ({@see WebPageMarkdown::buildLlmMarkdown()}). Only reached when at least one page was read
 * — otherwise the tool throws the instructive error.
 */
class WebPageMarkdownMcpResponseDto extends RestResponseDto implements LlmMarkdownRenderable, MCPToolResponsePlatformNoteProvider
{
    /**
     * Hard cap on the content characters handed to the model (~6k tokens): the tool result is re-billed into the
     * context EVERY turn until compaction covers it; beyond the cap an explicit truncation notice is appended
     * (never a silent cut).
     */
    public const int MAX_CONTENT_CHARS = 24000;

    /** The floor of the per-page cap when several pages share one result (~1.5k tokens each). */
    public const int MIN_CONTENT_CHARS_PER_PAGE = 6000;

    /** @var WebPageMarkdown|null The fetched page (url, title, markdown) — the single-page read. */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?WebPageMarkdown $page = null;

    /** @var WebPageMarkdowns|null The fetched pages in the order asked — the `urls` read; a failed page carries its reason. */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?WebPageMarkdowns $pages = null;

    /**
     * @var string|null The leading corrective line when this read was a repeated single-page read in the turn
     * ({@see \DDD\Presentation\Mcp\Client\AI\ToolBatchAdoptionNote}); null otherwise.
     */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $batchAdoptionNote = null;

    /**
     * @var string|null The application's line after the read ({@see \DDD\Domain\AI\Services\WebSearch\WebSearchHostService::buildNoteAfterWebPagesRead()}),
     * shown at the top of the result; null when it has none.
     */
    public ?string $noteAfterWebPagesRead = null;

    public static function of(WebPageMarkdown $page): self
    {
        $responseDto = new self();
        $responseDto->page = $page;
        return $responseDto;
    }

    public static function ofPages(WebPageMarkdowns $pages): self
    {
        $responseDto = new self();
        $responseDto->pages = $pages;
        return $responseDto;
    }

    public function toLlmMarkdown(): string
    {
        // the application's line stays in the result: a marker in it (ToolCall::RESULT_MARKER_PLATFORM_STORED) is read from there
        $noteLine = $this->noteAfterWebPagesRead !== null && $this->noteAfterWebPagesRead !== '' ? $this->noteAfterWebPagesRead . "\n\n" : '';
        return $noteLine . $this->renderPagesAsLlmMarkdown();
    }

    /**
     * The platform's own text for the model, kept out of the screened result (inside it, these lines read as
     * instructions aimed at an AI and got page reads removed by the screening): the batch note, how to read the marks,
     * and why each failed page failed with what to do about it.
     */
    public function buildPlatformNoteForModel(): ?string
    {
        $noteLines = [];
        if ($this->batchAdoptionNote !== null && $this->batchAdoptionNote !== '') {
            $noteLines[] = $this->batchAdoptionNote;
        }
        $noteLines[] = ToolResultUntrustedSegments::READING_RULE;
        foreach ($this->pages?->getElements() ?? [] as $page) {
            if (!($page->ok ?? false)) {
                $noteLines[] = $page->buildFailureExplanationForAgent();
            }
        }
        return implode("\n", $noteLines);
    }

    /** The page(s) as LLM markdown — one capped block per page, a failed page with its reason. */
    protected function renderPagesAsLlmMarkdown(): string
    {
        if ($this->pages !== null) {
            $pages = $this->pages->getElements();
            $pageCount = max(1, count($pages));
            $charsPerPage = max(self::MIN_CONTENT_CHARS_PER_PAGE, intdiv(self::MAX_CONTENT_CHARS, $pageCount));
            $renderedPages = [];
            foreach ($pages as $position => $page) {
                $renderedPages[] = '## Page ' . ($position + 1) . ' of ' . $pageCount . "\n"
                    . (($page->ok ?? false) ? $page->buildLlmMarkdown($charsPerPage) : ('Page ' . ($page->url ?? '?') . ' — NOT read (' . strtolower(str_replace('_', ' ', (string)($page->reason ?? 'unreadable'))) . ').'));
            }
            return implode("\n\n", $renderedPages);
        }
        return $this->page?->buildLlmMarkdown(self::MAX_CONTENT_CHARS) ?? '';
    }
}
