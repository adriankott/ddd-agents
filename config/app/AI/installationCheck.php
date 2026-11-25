<?php

declare(strict_types=1);

/**
 * The installation check `app:agent:check-installation`
 * ({@see \DDD\Domain\AI\Services\Installation\AgentInstallationCheckService}), read as `Config::get('AI.installationCheck')`.
 * An application ships its own file at this path, which REPLACES this one whole.
 *
 * Every list accepts findings the host knows and keeps on purpose; an accepted name is not reported:
 * - skippedChecks: check names not run at all (`missingTables` on a host whose tables another tool checks);
 * - acceptedUnboundSkills: package skills the host does not offer (`Agent.UI` without a client that executes UI tools);
 * - acceptedUnmappedRights: package rights the mapping leaves out because the application's rights object answers them;
 * - acceptedSkillRightGaps: skill names or MCP package classes whose skill does not name the package's class-level right;
 * - acceptedMissingTables: tables of package entities the host does not create (a feature it never switches on).
 */
return [
    'skippedChecks' => [],
    'acceptedUnboundSkills' => [],
    'acceptedUnmappedRights' => [],
    'acceptedSkillRightGaps' => [],
    'acceptedMissingTables' => [],
];
