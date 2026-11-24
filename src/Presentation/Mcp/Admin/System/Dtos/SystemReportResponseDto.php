<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Admin\System\Dtos;

use DDD\Presentation\Mcp\Admin\Common\AdminToolResultLines;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolResponsePlatformNoteProvider;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultUntrustedSegments;
use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * A report of the System tools (platform health, the workers, one transport, one cron, one error group, a trend): lines in the order they are added, each block either the agent's own lines or text
 * the system logged (an error message, the output of a command, a stack trace), which can quote what a client sent
 * and is wrapped as one untrusted passage; plus the flat values a state check of the evaluation reads
 * (`fields.<name>`). The DTO composes; the line classes under System/Lines render the records.
 *
 * Example (one cron):
 *   #12 "Import Feed Items" · OK · 0 3 * * * · app:feeds:import-items
 *   Last 24 h: 1 run, 0 failed
 *   <untrusted-content source="output of cron commands">
 *   #881 · 2026-11-20 03:00 UTC · 42 s · SUCCESSFUL · "Imported 12 activities"
 *   </untrusted-content>
 */
class SystemReportResponseDto extends RestResponseDto implements LlmMarkdownRenderable, MCPToolResponsePlatformNoteProvider
{
    /** @var string[] The values a state check reads, by name (each as text; an empty text is "not set") */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public array $fields = [];

    /** @var string[] The lines of the report in order; a passage of logged text is one element, already marked untrusted */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public array $lines = [];

    /** @var bool Whether a line carries text the system logged */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public bool $carriesUntrustedText = false;

    /**
     * @param string $fieldName
     * @param string|int|bool|null $value
     * @return void
     */
    public function setField(string $fieldName, string|int|bool|null $value): void
    {
        $this->fields[$fieldName] = match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            default => (string)$value,
        };
    }

    /**
     * @param string ...$lines The agent's own lines
     * @return void
     */
    public function addLines(string ...$lines): void
    {
        array_push($this->lines, ...$lines);
    }

    /**
     * Adds lines that carry text the system logged as one untrusted passage.
     *
     * @param string $source What the passage is, e.g. "error messages logged by the system"
     * @param string[] $lines
     * @return void
     */
    public function addUntrustedLines(string $source, array $lines): void
    {
        if ($lines === []) {
            return;
        }
        $this->lines[] = AdminToolResultLines::wrapUntrustedLines($source, $lines);
        $this->carriesUntrustedText = true;
    }

    /**
     * @return string
     */
    public function toLlmMarkdown(): string
    {
        return implode("\n", $this->lines);
    }

    /**
     * @return string|null
     */
    public function buildPlatformNoteForModel(): ?string
    {
        return $this->carriesUntrustedText ? ToolResultUntrustedSegments::READING_RULE : null;
    }
}
