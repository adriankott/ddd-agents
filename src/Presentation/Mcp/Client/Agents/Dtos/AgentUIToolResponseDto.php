<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Domain\AI\Entities\ClientTools\AgentScreenPicture;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolResponseImageProvider;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolResponsePlatformNoteProvider;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultImage;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultUntrustedSegments;
use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * The answer of a UI tool of `Agent.UI`: lines written by the server (trusted), the client's content wrapped as
 * untrusted, and the closing lines. The content is cut at
 * {@see self::MAX_CONTENT_CHARS}, so no screen can flood the context.
 */
class AgentUIToolResponseDto extends RestResponseDto implements LlmMarkdownRenderable, MCPToolResponsePlatformNoteProvider, MCPToolResponseImageProvider
{
    /** @var int The most characters of client content in one result */
    public const int MAX_CONTENT_CHARS = 8000;

    /** @var string The line that ends a cut content */
    public const string CONTENT_CUT_LINE = '… (cut at %d of %d characters; read one part with getCurrentScreen focus)';

    /** @var string[] Lines before the content, written by the server */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public array $lines = [];

    /** @var string|null What the client answered; wrapped as untrusted when it holds screen text */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $screenText = null;

    /** @var bool Whether the content is screen text (untrusted) or the application's own text */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public bool $contentIsUntrusted = true;

    /** @var string[] Lines after the content, written by the server */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public array $closingLines = [];

    /** @var string|null The outcome (`OK`, `NO_BROWSER`, `UNSAVED_CHANGES`, …) */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $outcome = null;

    /** @var AgentScreenPicture|null A picture of the screen (getCurrentScreen with format picture); rides as an image part, never in the text */
    protected ?AgentScreenPicture $screenPicture = null;

    public function setScreenPicture(?AgentScreenPicture $screenPicture): void
    {
        $this->screenPicture = $screenPicture;
    }

    public function getScreenPicture(): ?AgentScreenPicture
    {
        return $this->screenPicture;
    }

    /** @return ToolResultImage[] the screen picture as an inline tool picture; none without one */
    public function getResponseImages(): array
    {
        if ($this->screenPicture === null) {
            return [];
        }
        $toolResultImage = new ToolResultImage();
        $toolResultImage->base64Data = $this->screenPicture->base64Data;
        $toolResultImage->mimeType = $this->screenPicture->mimeType;
        $toolResultImage->width = $this->screenPicture->width;
        $toolResultImage->height = $this->screenPicture->height;
        $toolResultImage->byteSize = $this->screenPicture->byteSize;
        return [$toolResultImage];
    }

    /**
     * @return string
     */
    public function toLlmMarkdown(): string
    {
        $parts = $this->lines;
        $content = $this->screenText ?? '';
        if (trim($content) !== '') {
            $contentChars = mb_strlen($content);
            if ($contentChars > static::MAX_CONTENT_CHARS) {
                $content = mb_substr($content, 0, static::MAX_CONTENT_CHARS) . "\n" . sprintf(static::CONTENT_CUT_LINE, static::MAX_CONTENT_CHARS, $contentChars);
            }
            $parts[] = $this->contentIsUntrusted ? ToolResultUntrustedSegments::wrap('the user\'s screen', $content) : $content;
        }
        foreach ($this->closingLines as $closingLine) {
            $parts[] = $closingLine;
        }
        return implode("\n", $parts);
    }

    /**
     * @return string|null The reading rule when the result carries screen text
     */
    public function buildPlatformNoteForModel(): ?string
    {
        return $this->contentIsUntrusted && trim($this->screenText ?? '') !== '' ? ToolResultUntrustedSegments::READING_RULE : null;
    }
}
