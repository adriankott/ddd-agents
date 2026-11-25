# The installation check (`app:agent:check-installation`)

**Reader:** host developer and package maintainer

Contents: what it reads · the checks · output and exit code · accepting a finding · adding a check from a package ·
adapting a check

`php bin/console app:agent:check-installation [--format=json]` reads how the application installed the agent packages
and reports what fails at runtime (**BLOCKING**) or needs a look (**WARNING**). It writes nothing. Run it after every
`composer update` (after `vendor/bin/ddd-renames`) and as a CI step; exit 1 means a BLOCKING finding.

Classes: `AgentInstallationCheckService` (the checks), `AgentInstallationCheckInterface` (the port for added checks),
`AgentInstallationFinding` / `AgentInstallationFindings` (the findings), `AgentPackageRename` / `AgentPackageRenames`
(the rename maps), `AgentCheckInstallationCommand`.

## What it reads

Configuration (`AI.skillPackages`, `AI.accountRights`, `AI.toolOverlays`, `AI.installationCheck`, the keys of the rename
maps), the source roots of the installed packages (`AgentSourceRootsService`: every root but the application's), the
skill folders of every config root, the console's command list, the default connection's table list, the PATH, the
application's `.ddd-renames.json` and each package's `renames.json`.

## The checks

| Check | Severity | Reports |
|---|---|---|
| `skillBindings` | BLOCKING | `AI.skillPackages` is missing or empty; an entry names a class that does not exist, a class that is no MCP package, or an id no installed MCP package has (the binding throws when the agent reads it) |
| `unboundPackageSkills` | WARNING | a skill file a package ships that the binding does not name: the agent never offers it |
| `rightsHeldByNobody` | WARNING | a right an installed package declares (`AgentDeclaredAccountRightsService`) that `AI.accountRights` maps to no role, no `'*'` and no `['right' => …]` delegation: no account holds it unless the application's rights object holds it |
| `skillRights` | WARNING | a bound package requires a right on the class (`MCPRequiresAccountRights`) that its skill does not name in `requiredAccountRights` (file or `skillRequirements.php`): the skill is offered to accounts that may call none of its tools |
| `toolOverlays` | WARNING | an entry of `AI.toolOverlays` with a key the overlay does not read, or for a tool name no package of the binding has |
| `missingTables` | BLOCKING | the table of a package entity (or of the application's subclass of one) the schema lacks; the database not reachable is one WARNING. Not required: tables of `Base.Repo.database.excludedTables`, `acceptedMissingTables`, the memory table while memory is off or a host store answers it |
| `renamedConfigKeys` | WARNING | a config key a package renamed (`renames.json`, kind `configKey`) or removed (`AgentInstallationCheckService::REMOVED_CONFIG_KEYS_BY_VERSION`) that the application still sets |
| `consoleCommands` | WARNING | a name or alias of a package command (`AsCommand` below a package root's `Symfony/Commands`) the console does not resolve: a cron line or script that runs it fails |
| `skillLint` | WARNING (BLOCKING for a skill file of the application that does not parse) | the skill-file lint and the naming lint of `AISkillFilesLintService` over every skill root |
| `pendingRenames` | WARNING | renames of an installed package newer than the version `.ddd-renames.json` records for it and not newer than the installed version: run `vendor/bin/ddd-renames`, then `--apply` (a fresh installation: `--mark-applied`) |
| `toolImageStore` (added check) | WARNING | the picture store's object store (`AI.toolCallImageStore.objectStore`) is on but not configured |
| `documentTools` (added check) | WARNING | the drive's document tools are bound and document reading is on, but `pdftotext`, `pdfinfo`, `pdftoppm` (page images, OCR) or `tesseract` (OCR) is not on the PATH |

A check that throws becomes one WARNING finding ("The check could not run: …", first line of the reason); the other
checks run.

## Output and exit code

Text: a table (severity, check, subject, finding, hint) and a summary line. JSON (`--format=json`):

```json
{
    "ok": false,
    "blocking": 1,
    "warnings": 2,
    "findings": [
        {"checkName": "skillBindings", "severity": "BLOCKING", "subject": "Demo.Reports", "message": "…", "hint": "…"}
    ]
}
```

Exit `0` without a BLOCKING finding, `1` with one, `2` for an unknown `--format`.

## Accepting a finding (`config/app/AI/installationCheck.php`, `AI.installationCheck`)

The application's file replaces the package's whole. Every list holds names the host keeps on purpose:

| Key | Holds |
|---|---|
| `skippedChecks` | check names not run at all (built-in or added) |
| `acceptedUnboundSkills` | package skills the host does not offer |
| `acceptedUnmappedRights` | package rights the mapping leaves out because the application's rights object answers them |
| `acceptedSkillRightGaps` | skill names or MCP package classes exempt from `skillRights` |
| `acceptedMissingTables` | tables of package entities the host does not create |

## Adding a check from a package

A package (or the application) implements `AgentInstallationCheckInterface` in a service of a public `…\Services\`
namespace; autoconfiguration tags it (`AgentInstallationCheckInterface::TAG`) and the check runs after the built-in
ones, in container order.

```php
class DriveStoreInstallationCheckService extends Service implements AgentInstallationCheckInterface
{
    public function getInstallationCheckName(): string
    {
        return 'driveStore';
    }

    public function findInstallationFindings(): AgentInstallationFindings
    {
        $agentInstallationFindings = AgentInstallationFindings::newInstance();
        if (!$this->isDriveStoreConfigured()) {
            $agentInstallationFindings->addFinding('driveStore', AgentInstallationFinding::SEVERITY_WARNING, 'drives', 'No drive store is configured.', 'Configure one in config/app/…');
        }
        return $agentInstallationFindings;
    }
}
```

Rules: read only, answer within seconds, name no secret or host in a message. A package that renames anything ships
the rename in its `renames.json` (Core's format, `vendor/bin/ddd-renames validate`): `renamedConfigKeys` and
`pendingRenames` read it without code. A config key removed without a successor goes into
`REMOVED_CONFIG_KEYS_BY_VERSION` of the release that removed it.

## Adapting a check

A same-path subclass of `AgentInstallationCheckService` overrides one check's protected method (`add…Findings()`, listed
in `BUILT_IN_CHECK_METHOD_NAMES`) or a helper (`isApplicationPath()`, `findPackageSourceRoots()`,
`findLiveTableNames()`); `skippedChecks` switches a check off without code.
