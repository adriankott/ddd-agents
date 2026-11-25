# Catalog, binding, package ids, source roots, overlays and lint

**Reader:** host developer and package maintainer

Contents: the binding is the registry · package ids · config roots of skill and purpose files and same-path overrides · config files a host
replaces whole · overriding or replacing a package skill · skill name constants · the lint in a host's suite ·
the admin catalog and its namespace groups · what is read once per process

## The binding is the registry

`AISkill::getPackageClassesBySkillName()` reads the config `AI.skillPackages` (`AISkillsService::CONFIG_SKILL_PACKAGES`,
file `config/app/AI/skillPackages.php`): skill name → list of MCP packages. Its KEYS are the registry of skills
(`AISkill::getAllSkillNames()`), in catalog order. A skill file without a key is never offered; a key without a file
is skipped by `AISkillsService::getCatalog()` and reported by the lint. A skill without tools is bound to an empty
list.

A value names a package by class (it holds a backslash and is kept as it stands); an id (anything else, resolved
through `MCPPackageIdsService::findPackageClassForId()`) still resolves, but the naming lint refuses it: the binding
names classes, a typo then fails at load. A missing or empty binding and an id no package
names both throw `LogicException` on first use: the agent does not run on a broken binding.

The reference kernel's binding is the smallest complete one:

```php
use AgentsReference\Presentation\Mcp\Demo\DemoNotesMCPPackage;

return [
    'Demo.Notes' => [DemoNotesMCPPackage::class],
];
```

## Names: skills and package ids

| Rule | Right | Wrong |
|---|---|---|
| A skill is <Topic>.<Feature>, three parts at most, upper camel case; the topic is a functional subject, never an audience or a persona | Reputation.Reviews, Agent.Web.Search | Admin.Reviews, Assistant.Tasks, Agent.Sandbox.Documents.Pptx |
| Agent.* is the agent's own tools and organs (memory, tasks, mandates, settings, budget, web, sandbox, the user's UI); every other topic is the world it works on | Agent.Memory, Agent.UI, CloudDrive.Files | Memory.Management, Agent.Campaigns |
| The feature names what is done or read, never the topic again, never two subjects | System.Workers, System.Crons | Accounts.Accounts, System.WorkersAndCrons |
| No skill file beside a folder of the same name (the config reads the file, never the folder) | Website/Builder.md and Website/Build/*.md | Website/Build.md and Website/Build/ |
| A package id is <provider>.<topic>.<feature> in the skill's words; where provider and topic are one word it is written once | rb.challenges.campaigns, ddd.system.health, agent.memory | admin.challenges.campaigns, agent.memories |
| The provider is the host's prefix (AI.packageIdProvider), ddd for a feature of Core, or a module's word; never an audience (admin, client, public, partner, batch) and never derived from a folder | | |
| A package several skills bind keeps a feature of its own | rb.admin.finder, agent.drive | |
| The binding names classes; a rename is code and config, no alias (an old name in a stored conversation is skipped) | | |

The same rules for every package and host, with the other layers (Composer, console, config, env, images): the framework's rulebook `ddd-module-orchestrator` › references/package-cuts-and-names.md (mgamadeus/ddd).

Every MCP package names itself with its static `MCPPackage::$name` (the package's own: `agent.mandates`,
`agent.tasks`, `agent.taskRunReports`, `agent.memory`, `agent.images`, `agent.web.search`, `agent.ui`, `ddd.system.health`,
`ddd.system.issues`, `ddd.system.workers`, `ddd.system.crons`; the kernel's: `demo.notes`). `MCPPackageIdsService` finds the packages by scanning the
source roots of `AgentSourceRootsService` (the application's `src/` under the root namespace `App\` first, then every
DDD module's `src/`), below `Presentation/Mcp`, file names ending in `MCPPackage.php`, abstract classes skipped. The
first class holding an id wins; a host class that EXTENDS the package's class of the same id is the intended
replacement, any other second holder is listed by `MCPPackageIdsService::getPackageClassesByDuplicateId()`.

Trap: a package id resolves only for a package the source-root scan finds, and that scan reads the application's
`src/` under the root namespace `App\` only. A host with another root namespace (the reference kernel's is
`AgentsReference\`) binds its own packages by class; the scan and the way to widen it belong to
`ddd-module-agents-specialist` (discovery, override points).

Bind by class everywhere: a host's same-path subclass of a module package is found through the container, and the id
stays the public name in call rows and logs.

## Config roots of skill and purpose files and same-path overrides

`AISkillSourcesService` lists where skill and purpose files come from: every config root that holds an
`AI/Skills` (`AISkillSourcesService::SKILLS_FOLDER`) or `AI/Purposes` (`AISkillSourcesService::PURPOSES_FOLDER`)
folder, in the order the config reader searches them: the application's roots first, then the modules'. These
config roots are independent of the host's root namespace: skill and purpose files are found under any namespace. Reading
stays `AISkillsService::getAISkillByName()`: the first root holding the file wins. A host file at a module's path
therefore REPLACES the module's file. It is never merged: the host's file must carry every key it wants.

`AISkillSourcesService::findSkillNamesByRoot()`, `findPurposeNamesByRoot()`, `findOverriddenNames()` and
`findCollidingNames()` answer which names each root holds, which a host overrides and which collide case-insensitively.

## Config files a host replaces whole

The config reader takes the application's file instead of the module's, key by key never merged:

| Config | Package default | What a host's file must do |
|---|---|---|
| `AI.skillPackages` | binds `Agent.Mandates` → `agent.mandates`, `Agent.Tasks` → `agent.tasks`, `Agent.TaskRunReports` → `agent.taskRunReports`, `Agent.Memory` → `agent.memory`, `Agent.Images` → `agent.images` | list EVERY skill the host offers, the package's four included (`Agent.Memory` only while the memory store is on: an unavailable package, `MCPPackage::isAvailable()`, is left out of the binding and a skill bound to it alone disappears). Without `Agent.TaskRunReports` the purpose `Schedule.Run` preloads a skill that has no tools |
| `AI.skillRequirements` | empty | the gates the host puts on any skill, its own or a module's (reference [gating-by-entitlements-and-rights.md](gating-by-entitlements-and-rights.md)) |
| `AI.purposeRequirements` | empty | the flags a purpose needs before a customer may start it |
| `AI.skillNamespaceGroups` | one group, `Agent` | the groups of the admin catalog view (below) |
| `AI.agentPersona` | `Assistant`, `this product` | the names the placeholders stand for |

A template of the binding is [templates/skillPackages.php](../templates/skillPackages.php).

## Overriding or replacing a package skill

| Goal | Do |
|---|---|
| Change the text of a package skill | Copy the file to the same path below the host's `config/app/AI/Skills/` and edit it. The whole file replaces the package's; keep its keys |
| Gate a package skill | An entry in the host's `AI.skillRequirements`; never edit the file for a gate |
| Change what a package skill's tools do | A host package class at the same id: a subclass of the package's MCP package in the host's `src/Presentation/Mcp`, with the same `MCPPackage::$name` (found by id only under the scanned roots, see the trap above) |
| Drop a package skill | Leave it out of the host's `AI.skillPackages`. Check that no purpose preloads it and no root lists it |
| Rename | Not supported: the name is the file path and is stored on every message that loaded the skill |

After the change, run the lint (below). `AISkillSourcesService::findOverriddenNames()` lists every override, so a
test can pin the host's intended overrides.

## Skill name constants

`AISkill` carries constants for the names the package knows. With a file and a package: `Agent.Mandates`,
`Agent.Tasks`, `Agent.TaskRunReports`, `Agent.Memory`, `Agent.Images`, `Agent.Web.Search`, `Agent.UI`, `Agent.Voice`
(offered only while the voice channel can run), `System.Health`,
`System.Issues`, `System.Workers`, `System.Crons`. Names a host fills with its own file and package:
`Agent.Settings`, `Agent.Budget`, `Agent.Web.Images`, `Agent.Communication.Messages`,
`Agent.Communication.VoiceCalls`, `Money.CurrencyConversion`; package code reads only
`AISkill::AGENT_BUDGET` (the offer drops it under a hide-usage budget policy). A host names its own skills in a
same-path subclass of `AISkill` if it wants constants; the binding keys are the registry either way.

## The lint in a host's suite

`AISkillFilesLintService` reports what breaks a skill or purpose file at runtime without a warning:

| Method | Checks |
|---|---|
| `findSkillFileProblems()` | frontmatter present and valid YAML (after the persona step), a description, only known keys, lists of strings, sub-skills that resolve, no case-insensitive collision |
| `findPurposeFileProblems()` | frontmatter valid, a `purposeClass` that names an existing subclass of `AIConversationPurpose`, no collision |
| `findBindingProblems()` | the binding resolves, every key has a file, every bound class is an MCP package, no tool name is provided by two packages |
| `findSkillNamingProblems()` | the names of the table above: parts, case, repeated topic, `And`; no skill file beside a folder of the same name in any root |
| `findBindingNamingProblems()` | the binding names classes; every package id has the form and provider of the table above; a package one skill binds carries that skill's words; every right a skill requires is required by each package it binds (`#[MCPRequiresAccountRights]` on the class) |

A host with older names lists them (skill names, package ids) in `AI.skillLintAllowList`: their naming problems are
not reported, and an entry without a problem is reported itself, so the list only shrinks.

The package runs it over its own root. A host runs it over every root, a module's included (a module's file still
ships and loads in another application):

```php
use DDD\Domain\AI\Services\Skills\AISkillFilesLintService;
use DDD\Domain\AI\Services\Skills\AISkillSourcesService;
use DDD\Infrastructure\Services\DDDService;
use PHPUnit\Framework\TestCase;

class AgentSkillFilesTest extends TestCase
{
    public function testEverySkillPurposeAndBindingIsClean(): void
    {
        /** @var AISkillSourcesService $aISkillSourcesService */
        $aISkillSourcesService = DDDService::instance()->getService(AISkillSourcesService::class);
        /** @var AISkillFilesLintService $aISkillFilesLintService */
        $aISkillFilesLintService = DDDService::instance()->getService(AISkillFilesLintService::class);
        $problems = array_merge(
            $aISkillFilesLintService->findSkillFileProblems($aISkillSourcesService->getSkillRootDirectories()),
            $aISkillFilesLintService->findPurposeFileProblems($aISkillSourcesService->getPurposeRootDirectories()),
            $aISkillFilesLintService->findBindingProblems(),
            $aISkillFilesLintService->findSkillNamingProblems($aISkillSourcesService->getSkillRootDirectories()),
            $aISkillFilesLintService->findBindingNamingProblems()
        );
        self::assertSame([], $problems, implode("\n", $problems));
    }
}
```

The test needs a booted kernel (the binding resolves ids through the container). It does not check purpose frontmatter
keys (a misspelt purpose key is silently ignored) or sub-skill cycles.

## The admin catalog and its namespace groups

`AISkillCatalogDocService::buildSkillCatalog()` measures the catalog as a typed `AISkillCatalog`: per skill the
instruction tokens, per package and tool the tokens of the exact wire schema, totals, the purpose-bound skills split
into their purpose groups, and the general skills grouped by `AI.skillNamespaceGroups` (a skill belongs to the group
whose `skillNamePrefixes` entry is its longest prefix; no group = `Ungrouped`). `buildToolDocumentation()` documents
one tool, `buildSharedBlocks()` the two once-per-turn shared blocks. The figures come from a GPT-family tokenizer and
are indicative for other model families. Nothing here changes what the agent is offered; a host exposes it on an
admin endpoint or a console command of its own.

## What is read once per process

The binding (`AISkill::getPackageClassesBySkillName()`), the package ids, the skill and purpose requirement overlays,
the agent-relevant flag set (`AISkillsService::getAgentRelevantFeatureFlagNames()`) and each skill's resident token
estimate are memoized per process. After changing a binding, an overlay or a package class, restart the workers.
The framework also keeps read config files in a per-process tree: restart the workers after a skill or purpose file
edit as well.
