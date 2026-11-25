# Characterization tests: the surface snapshot

**Reader:** host developer and package maintainer

Contents: what it pins · how a host subclasses it · the two tests and their files · the regenerate switch · reading a
failure · the document · size · for the package maintainer · full class names

`AgentSurfaceSnapshotTestCase` pins what an application's agent is offered — skills, tool packages, tools, parameters,
wire schemas, purposes, HTTP MCP bundles — under the application's own container, config and bindings. A failure
names the value that changed by its path, not a file hash:

```
~ packages → AgentTaskSchedulesMCPPackage → tools → getTaskSchedules → description: "#… (880 characters)" => "#… (899 characters)"
```

The package pins its own surface with the same hook and ships it (`agent-package-surface.json` beside the class). An
application compares the package's entries with that file and keeps only where it differs (its overlays, its rights
mapping, a renamed argument, a same-path subclass), so a package release that changes one of its own tools moves both
sides and leaves the application's files alone, except where its own change meets the package's.

## How a host subclasses it

One test class in the application's suite (the suite's bootstrap boots the application kernel; the test case boots
nothing):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Domain\AI;

use DDD\Testing\Agents\Surface\AgentSurfaceSnapshotTestCase;

class AgentSurfaceSnapshotTest extends AgentSurfaceSnapshotTestCase
{
    protected function getPinnedSurfaceFile(): string
    {
        return __DIR__ . '/Fixtures/agent-surface.json';
    }

    protected function getAcceptedDifferencesFile(): ?string
    {
        return __DIR__ . '/Fixtures/agent-surface-accepted-differences.json';
    }

    protected function findPurposeNames(): array
    {
        return $this->findPurposeNamesInDirectory(dirname(__DIR__, 3) . '/config/app/AI/Purposes');
    }

    protected function findBundleClasses(): array
    {
        return [/* the application's MCPBundle classes */];
    }
}
```

| Hook | Default | What it decides |
|---|---|---|
| `getPinnedSurfaceFile()` | abstract | the file that pins the application's own entries (or its whole surface) |
| `getAcceptedDifferencesFile()` | null | the file of accepted differences to the package's surface; null = no comparison with the package, the pinned file holds everything |
| `getPackageSurfaceFile()` | the shipped file, null when the installed release ships none | the package's own surface |
| `findPurposeNames()` | none | the purposes to pin (dotted names); `findPurposeNamesInDirectory()` lists `<dir>/<group>/<name>.md` |
| `findBundleClasses()` | none | the HTTP MCP bundles to pin |
| `createSurfaceDocumentBuilder()` | the package's builder | a subclass of `AgentSurfaceDocumentBuilder` that adds or drops a field |
| `buildSurfaceDocumentToPin()` | the own entries while the package comparison runs, else all | what the pinned file holds |

## The two tests and their files

- `testThePackagesSurfaceDiffersOnlyWhereTheApplicationAcceptedIt()` — the entries the package ships (skills, packages,
  purposes) against the shipped surface. The differences (path => the application's value; `(absent)` for a package
  skill or package the application does not offer, one line per entry) must equal the accepted-differences file.
  Skipped when the application names no such file or the installed release ships no surface.
- `testTheOfferedSurfaceIsThePinnedOne()` — the application's own entries and the order of its catalog (`skillOrder`)
  against the pinned file. Without the package comparison the pinned file holds the whole surface.

Together they cover every entry of the application's surface once.

## The regenerate switch

`REGENERATE_AGENT_SURFACE_SNAPSHOT=1` (`AgentSurfaceSnapshotTestCase::REGENERATE_ENVIRONMENT_VARIABLE`) writes both
files instead of comparing; the run reports both tests as skipped. Run it after an intended change, review the diff of
the two files, commit them with the change:

```
REGENERATE_AGENT_SURFACE_SNAPSHOT=1 vendor/bin/phpunit tests/Domain/AI/AgentSurfaceSnapshotTest.php
```

After a package update the accepted-differences file changes only where the application's own change meets the
package's change; a new package skill the application does not offer shows as one `(absent)` line.

## Reading a failure

`-` pinned only, `+` now only, `~` pinned => now. A long text or a larger structure is a hash token
`#<12 hex of its SHA-1> (<n> characters)`: the token says that and how much the value changed, the source (the skill
file, the tool's DTO, the overlay) says what. A list (`toolNames`, `skillOrder`) is one value: a reordered or extended
list shows both lists, cut at `AgentSurfaceDifference::MAXIMUM_DESCRIBED_VALUE_CHARACTERS`.

## The document

`AgentSurfaceDocument` holds five parts; skills, packages, purposes and bundles are sorted by name, the catalog order
is `skillOrder`:

- `skillOrder` — the catalog's skill names as `AISkill::getAllSkillNames()` yields them (the application's own, never
  set against the package's);
- `skills → <name>` — the skill's public fields, `toolNames`, `packages`;
- `packages → <short class name>` — `packageId`, the package's fields, per tool its fields, `parameters → <name>`
  (the documented schema an MCP client reads), `inputSchemaWithoutParameters` and `wire` (the schema the loop sends:
  `description`, `requiredParameters`, one token per parameter, `rest`), and per tool two parts beside its
  documentation:
  - `declarations` — every attribute of the tool method but `#[MCPTool]` itself, short class name => its declared
    arguments as written (`ReflectionAttribute::getArguments()`, nothing instantiated: a named argument keeps its
    name, a positional one its position; `true` for an attribute without arguments; a long text a hash token; a
    second attribute of one class `<name> #2`). These are what the loop and its readers ask by tool name
    (`MCPToolAttributeCatalog`: effect, time limit, parallel admission, owner-facing subject, gates, …), and also
    `MCPRequiresAccountRights`, `MCPRequiresFeatureFlags`, `MCPHiddenQueryOptions`. A package release that changes a
    declaration of its tool moves the shipped surface and the application's side together; an application's
    same-path subclass that redeclares a tool shows as an accepted difference;
  - `overlay` — what the application's `AI.toolOverlays` entry for the tool sets: `sets` (the keys the overlay reads a
    value from: `accountRights`, `description`, `examples`, `ownerFacingSubject`, `parameters`, `summary`) and
    `ownerFacingSubject` (the one value that shows nowhere else in the entry). The texts, rights and parameters an
    overlay replaces are already the tool's fields and schema; `sets` says a changed value came from the overlay.
    The package's own surface holds no overlay (the package configures none).
  The derived answers (`AgentRunReport::ownerFacingTitleForWriteTool()`, `MCPToolEffectCatalog`, …) keep their own
  package tests; the document pins their input. The key is the short class name, so a
  same-path subclass keeps the key of the class it replaces; two classes with one short name are keyed by full name;
- `purposes → <name>` — `class` (short name) and the public fields;
- `bundles → <class>` — the bundle's fields and one token per tool of its `tools/list` documentation.

A field that holds nothing (null, false, `''`, `[]`) is left out. A new public property of `AISkill` or of a purpose
adds a value to every application's file with the release that adds it.

## Size

`AgentSurfaceDocumentBuilder::MAXIMUM_PLAIN_TEXT_CHARACTERS` (120) and `MAXIMUM_PLAIN_MAP_CHARACTERS` (240) trade
readability for size: a text or a small map up to the limit stays readable field by field, a longer one is a token.
A host with about 60 skills and 250 tools measured about 570 KB of pinned surface (about 480 KB with every parameter
as one token); the package's own surface is about 70 KB. Keep the defaults; a host that wants smaller files overrides
the constants in a builder subclass (`createSurfaceDocumentBuilder()`) — the hashes change with them, so regenerate
both files in the same commit.

`declarations` and `overlay` (since 0.5.9) add about 17 % to a host with about 230 tools; the package's own surface
went from about 68 KB to about 85 KB.

## For the package maintainer

`tests/Testing/Surface/AgentPackageSurfaceTest.php` pins the package's surface (package kernel, default config,
without the fixture application — a run with `DDD_AGENTS_TEST_WITH_APPLICATION` skips it) in
`src/Testing/Agents/Surface/agent-package-surface.json`. A change of a package tool, skill, purpose or of a public
field of `AISkill` fails it; regenerate in the same commit:

```
REGENERATE_AGENT_SURFACE_SNAPSHOT=1 php tests/Support/bin/phpunit.php tests/Testing/Surface/AgentPackageSurfaceTest.php
```

The file holds package entries only (no host value): every application diffs against it. The test hooks below
`DDD\Testing\Agents\` extend PHPUnit; the module excludes them from the container (`AgentsModule::getExcludePatterns()`,
pattern `Testing`), so a production install without PHPUnit never loads them.

## Beside it: the installation check as a test

`DDD\Testing\Agents\Installation\AgentInstallationCheckTestCase` runs `app:agent:check-installation` in the
application's suite (hooks `createConsoleApplication()`, `getFailingSeverities()` — default BLOCKING —,
`getCheckNamesNotFailingTheTest()`). The surface snapshot pins WHAT is offered; the installation check tests that the
installation is sound (bindings, rights, command names, tables). Details: `ddd-module-agents-specialist`,
references/installation-check.md.

## Full class names

| Class | Role |
|---|---|
| `DDD\Testing\Agents\Surface\AgentSurfaceSnapshotTestCase` | the abstract test case a host extends |
| `DDD\Testing\Agents\Surface\AgentSurfaceDocumentBuilder` | builds the document from the running container |
| `DDD\Testing\Agents\Surface\AgentSurfaceDocument` | the five parts |
| `DDD\Testing\Agents\Surface\AgentSurfaceDocumentComparer` | compares two document trees value by value; entry-wise helpers; no container |
| `DDD\Testing\Agents\Surface\AgentSurfaceDifference`, `DDD\Testing\Agents\Surface\AgentSurfaceDifferences` | one difference (kind, path, both values) and their set with its failure text |
| `DDD\Testing\Agents\Installation\AgentInstallationCheckTestCase` | the abstract test case that runs the installation check (entry skill) |
