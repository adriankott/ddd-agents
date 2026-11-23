<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * What the workspace holds that the drive does not have yet (unsaved changes). A workspace without a drive reports
 * `isDriveWorkspace` false and no changes.
 */
class AgentSandboxDriveStatus extends ValueObject
{
    /** @var bool The workspace is backed by a drive */
    public bool $isDriveWorkspace = false;

    /** @var int The number of changes not committed to the drive */
    public int $pendingChangeCount = 0;

    /** @var AgentSandboxDriveChanges The changes, as far as the host lists them */
    public AgentSandboxDriveChanges $changes;

    /** @var DateTime|null The last commit to the drive */
    public ?DateTime $lastCommittedAt = null;

    public function __construct()
    {
        $this->changes = new AgentSandboxDriveChanges();
    }
}
