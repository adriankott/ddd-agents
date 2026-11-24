<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Drives;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Drives\AgentDriveFileContent;
use DDD\Domain\AI\Entities\Drives\AgentDrivePath;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\NotFoundException;

/**
 * Implemented by an {@see AgentDriveStore} whose files the agent may read (`readDriveDocument`,
 * `viewDriveDocumentPages` of the package `agent.drive`). The store reads the bytes of the file's released version
 * through its own backend or service layer — never through a URL handed to anyone — under the rights of the call, and
 * refuses what the caller may not read (a version waiting for or refused by a scan, a trashed file, a missing right).
 * A store that does not implement it keeps its files unread; the tools say so.
 */
interface AgentDriveFileContentReader
{
    /**
     * The bytes of one file of a resolved path or reference.
     *
     * @throws NotFoundException no such file, or a folder
     * @throws ForbiddenException the caller may not read it (rights, scan, trash)
     * @throws BadRequestException larger than `$maxBytes`
     */
    public function readDriveFileContent(AgentToolCallProvenance $callProvenance, AgentDrivePath $agentDrivePath, int $maxBytes): AgentDriveFileContent;
}
