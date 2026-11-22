<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\AI\Dtos;

use DDD\Domain\AI\Entities\MCPPackages\MCPToolResponsePlatformNoteProvider;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultUntrustedSegments;
use DDD\Domain\AI\Entities\WebSearch\WebPageExtracts;
use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * Response of the `extractInformationFromWebPagesWithFastLLM` tool: one reading per page, rendered as LLM
 * markdown by the set ({@see WebPageExtracts::buildLlmMarkdown()}); how to answer is the platform note.
 */
class WebPageExtractsMcpResponseDto extends RestResponseDto implements LlmMarkdownRenderable, MCPToolResponsePlatformNoteProvider
{
    /** @var WebPageExtracts|null The readings, one per page in the order asked. */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?WebPageExtracts $extracts = null;

    /** The extraction task the pages were read for. */
    public string $extractionTask = '';

    /** Whether the note asks for the widget (false on a phone call — no screen). */
    public bool $showAnswerAsWidget = true;

    /** Characters per page reading. */
    public int $extractCharCapPerPage = 2400;

    /** The note when a URL was put back to the one a search listed ({@see \DDD\Domain\AI\Entities\WebSearch\WebPageUrlCorrection}); null otherwise. */
    public ?string $urlCorrectionNote = null;

    public function toLlmMarkdown(): string
    {
        return ($this->extracts ?? new WebPageExtracts())->buildLlmMarkdown($this->extractionTask, $this->extractCharCapPerPage);
    }

    public function buildPlatformNoteForModel(): ?string
    {
        $urlCorrectionLine = $this->urlCorrectionNote !== null && $this->urlCorrectionNote !== '' ? $this->urlCorrectionNote . "\n" : '';
        return $urlCorrectionLine . ToolResultUntrustedSegments::READING_RULE . "\n" . WebSearchHitsMcpResponseDto::buildAnswerInstruction($this->showAnswerAsWidget);
    }
}
