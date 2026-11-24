<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Domain\AI\Entities\Drives\AgentDriveFiles;
use DDD\Domain\AI\Entities\Drives\AgentDriveMounts;
use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The answer of the drive tools: the files (one line each), the mounts when listed, the next page and a note. */
class AgentDriveFilesMcpResponseDto extends RestResponseDto implements LlmMarkdownRenderable
{
    /** @var string|null The listed folder's workspace path */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $path = null;

    /** @var AgentDriveFiles|null The files and folders */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?AgentDriveFiles $files = null;

    /** @var AgentDriveMounts|null The mounts (listing of `drivespaces`) */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?AgentDriveMounts $mounts = null;

    /** @var string|null Pass it as `cursor` for the next page */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $nextCursor = null;

    /** @var int|null Entries of the folder in total */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?int $totalCount = null;

    /** @var string[] Files that were not written, with the reason */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public array $refusedLines = [];

    /** @var string|null What happened, for the model */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $note = null;

    public function toLlmMarkdown(): string
    {
        $markdownLines = [];
        if ($this->note !== null && $this->note !== '') {
            $markdownLines[] = $this->note;
        }
        if ($this->mounts !== null) {
            $markdownLines[] = $this->mounts->count() > 0 ? $this->mounts->buildStateLines() : '(no drive mounted)';
        }
        if ($this->files !== null) {
            if ($this->path !== null) {
                $markdownLines[] = ($this->path === '' ? '(own drive root)' : $this->path . '/')
                    . ($this->totalCount !== null ? ' · ' . $this->totalCount . ' entries' : '');
            }
            $markdownLines[] = $this->files->toLlmMarkdown();
        }
        foreach ($this->refusedLines as $refusedLine) {
            $markdownLines[] = 'not saved: ' . $refusedLine;
        }
        if ($this->nextCursor !== null) {
            $markdownLines[] = 'more: call again with cursor "' . $this->nextCursor . '"';
        }
        return implode("\n", $markdownLines);
    }
}
