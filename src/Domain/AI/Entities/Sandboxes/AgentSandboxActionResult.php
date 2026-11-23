<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The answer to an action on a workspace: what ran, and the audit entry written for it.
 */
class AgentSandboxActionResult extends ValueObject
{
    /** @var string The action ({@see AgentSandboxActionLogEntry::ACTIONS}) */
    public string $action = '';

    /** @var string The workspace acted on */
    public string $workspaceKey = '';

    /** @var string SUCCEEDED (a refusal or a failure answers with an error instead) */
    public string $outcome = AgentSandboxActionLogEntry::OUTCOME_SUCCEEDED;

    /** @var AgentSandboxOffloadResult|null What an offload committed */
    public ?AgentSandboxOffloadResult $offloadResult = null;

    /** @var int|null The audit entry's id; null when the audit table is missing (the entry went to the error log) */
    public ?int $actionLogEntryId = null;
}
