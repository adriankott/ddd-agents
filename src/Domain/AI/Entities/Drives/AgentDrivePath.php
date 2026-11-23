<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Drives;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A file argument resolved against the conversation's mounts: the mount and the path inside it (`reports/Plan.md`),
 * or a store reference (`drive/<nodeId>`), which the mount's store resolves itself. The model writes a workspace path
 * (`reports/Plan.md`, `drivespaces/world-31/Briefings/Ablauf.pdf`) or a reference, never a bare number.
 */
class AgentDrivePath extends ValueObject
{
    /** @var string The prefix of a store reference argument */
    public const string REFERENCE_PREFIX = 'drive/';

    public ?AgentDriveMount $agentDriveMount = null;

    /** @var string The path inside the mount without leading or trailing slash; '' = the mount's root */
    public string $pathInMount = '';

    /** @var string|null The store reference (`drive/<nodeId>`) when the argument was one */
    public ?string $reference = null;

    /** @var string The argument as the model wrote it */
    public string $argument = '';

    /** The workspace path of the resolved argument (prefix of the mount plus the path inside it) */
    public function getWorkspacePath(): string
    {
        if ($this->reference !== null) {
            return $this->reference;
        }
        return ($this->agentDriveMount?->getPathPrefix() ?? '') . $this->pathInMount;
    }
}
