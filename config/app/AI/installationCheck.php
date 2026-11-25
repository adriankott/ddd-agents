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
 * - acceptedSkillOnlyRights: skill names whose `requiredAccountRights` name a right no tool of their packages requires
 *   (the right is meant to hide the offer only);
 * - acceptedMissingTables: tables of package entities the host does not create (a feature it never switches on; a
 *   feature a package declares as switched off or unbound is not required anyway).
 *
 * renamesStateFile: the path of the application's `.ddd-renames.json` (absolute, or relative to the project directory)
 * for `pendingRenames`; null = the first one in the project directory or a parent directory up to the repository root.
 *
 * databaseConnectTimeoutSeconds: the connect timeout of the check's own probe connection (`missingTables`, and the
 * command's read of its default account, which is skipped when the probe gets no answer); at least 1. The probe copies
 * the default connection's parameters and adds the timeout as a driver option of its own: the application's connection
 * is left as it is.
 */
return [
    'skippedChecks' => [],
    'acceptedUnboundSkills' => [],
    'acceptedUnmappedRights' => [],
    'acceptedSkillRightGaps' => [],
    'acceptedSkillOnlyRights' => [],
    'acceptedMissingTables' => [],
    'renamesStateFile' => null,
    'databaseConnectTimeoutSeconds' => 5,
];
