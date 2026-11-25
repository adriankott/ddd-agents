# Gating skills and purposes by entitlements and rights

**Reader:** host developer

Contents: the axes · where the answers come from · skill level, package level, tool level · the overlays · offer
is not authorization · what the model is told · purposes · traps

## The axes

| Axis | Declared on the skill | Answered by the host through | Fails |
|---|---|---|---|
| feature flags of the agent space (the plan) | `requiredFeatureFlags` (ALL), `anyOfRequiredFeatureFlags` (at least one) | `AIConversationEntitlementsService::findFeatureFlagsOfConversation()` → `AgentFeatureFlagsInterface` | an upgrade the owner can buy |
| rights of the acting account | `requiredAccountRights` | `AIConversationEntitlementsService::findAccountRightsOfConversation()` → `AgentAccountRightsInterface` | a grant by the account's administrator |
| billing access of the account | `requiresAccountBillingAccess` | `AIConversationEntitlementsService::findAccountBillingAccessOfConversation()` | nothing: the capability is not mentioned at all |
| the budget policy | — (`Agent.Budget` only) | `AIConversationEntitlementsService::isExactAIBudgetReportable()` | the skill is not offered |
| the model can see | `requiresVisionCapabilities` | `AIModel::$hasVisionCapabilities` | a load refusal on a blind model |
| purpose and initiator | `onlyUnderPurposeNames`, `notUnderPurposeNames`, `onlyOnInitiatorTypes` | the conversation | not offered, load refused |

The predicate of the flag axis is `AISkill::isIncludedInFeatureFlags()`: all required flags AND, when declared, one of
the any-of flags. Flag and right names are opaque strings: only the host interprets them.

## Where the answers come from

The defaults of `AIConversationEntitlementsService`: no flags (null, ungated), billing unknown (null), budget
reportable, and the account's rights by roles: `AgentRoleMappedAccountRights` reads the config `AI.accountRights`
(right name → role names, `'*'` = every account); a right the mapping does not name is not held. Shipped mapping: the
operator rights (`operatePlatform`, `manageTexts`, `useSandbox`, `useBrowser`) to `Role::SUPERADMIN`, the everyday
rights (`scheduleAgentTasks`, `useAgentMemory`, `useWeb`, `useUI`, `useDrive`) to every account. A package declares its
rights as constants (`AgentAccountRights` here, the drive's rights class in ddd-clouddrive), carries them on its package class
(`#[MCPRequiresAccountRights]`) and names them in its skill's `requiredAccountRights`; a host maps them to its roles
(its own `config/app/AI/accountRights.php`, replaced whole) or answers them with its own `AgentAccountRightsInterface`
(`findAccountRightsOfConversation()`, `findAccountRightsOfCurrentAccount()`). Null on an axis means that axis does not restrict.

**Composed rights.** The package checks a host's own rights object composed with the mapping
(`AgentComposedAccountRights`, built by `AIConversationEntitlementsService::findComposedAccountRightsOfConversation()` /
`findComposedAccountRightsOfCurrentAccount()`): per right name, a host object that implements
`AgentAccountRightsAnsweringInterface` and answers the name decides alone (held or not); otherwise the right is held
when the host object holds it OR the mapping holds it. So a host object that knows only its own right names leaves the
package rights to `AI.accountRights`. A mapping entry is a role list (`'*'` = every account) or a delegation
`['right' => '<host right>']`, which asks the host object for that right. A right the host withholds is left out of
its own mapping or answered through the interface. `AgentDeclaredAccountRightsService::getDeclaredAccountRightNames()`
lists every right the installed packages declare (their `*AccountRights::RIGHTS` lists and the rights on their
packages and tools) — what a host's guard test and `app:agent:check-installation` hold the mapping against. A host gates by putting a
same-path subclass of the service under its root namespace; how is the entry skill's (`ddd-module-agents-specialist`,
override points). Consequences: without that subclass every flag gate is inert, a right gate follows the role mapping
(a host right the mapping does not name is not held), and `requiresAccountBillingAccess` drops a skill only when the host answers false, not when it answers null.

## Skill level, package level, tool level

- Flags and rights hide the skill everywhere: offer, `load_skills`, preloads, delegation seeds. Billing access and the
  budget policy filter the offer and the offer-based preloads only, never a purpose preload or a delegation seed.
- A tool gated tighter than its skill carries `MCPRequiresFeatureFlags`, `MCPRequiresAnyFeatureFlag` or
  `MCPRequiresAccountRights` on the method, or on the package class for all its tools (method and class are united,
  `MCPToolEntitlement`). The skill stays loaded, the tool leaves the wire and the tools line, and the executor refuses
  it on every path. The attributes belong to `ddd-module-agents-tools-specialist`.
- Rule of thumb: the skill carries the one gate every tool of it needs; a tool carries only a gate of its own (a
  write, a metered action). `Agent.Tasks` names no right: its reads are for every account, its writing tools require
  `scheduleAgentTasks` each.
- A host changes a tool's right by tool NAME in `config/app/AI/toolOverlays.php` (`'accountRights' => [...]`, `[]` =
  no right); the overlay replaces what the method and class attributes say (tools skill, tool overlays).

## The overlays

A module's skill knows no host flag. `AI.skillRequirements` (`AISkillsService::CONFIG_SKILL_REQUIREMENTS`) adds gates
without editing a file:

```php
use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRights;

return [
    'Agent.Mandates' => [
        'requiredFeatureFlags' => ['AGENT.MANDATES'],
        'requiredAccountRights' => [AgentAccountRights::SCHEDULE_AGENT_TASKS],
    ],
];
```

`AISkillsService::applySkillRequirementOverlay()` UNITES the overlay with the frontmatter (frontmatter values first,
so the frontmatter's first any-of flag stays the upgrade offer; duplicates dropped; the bool keys OR-ed). The overlay
can add a gate, never remove one. Keys: `requiredFeatureFlags`, `anyOfRequiredFeatureFlags`, `requiredAccountRights`,
`requiresAccountBillingAccess`. Purposes have `AI.purposeRequirements` with `requiredFeatureFlags` only. The flag name
above is a placeholder of the host; a right is always a package's constant, never a host string.

## Rights: who declares, who maps

| Rule | How |
|---|---|
| A package declares the right its tools need, as a constant of its rights class | `AgentAccountRights::USE_WEB`; the sandbox's `useSandbox`, the browser's `useBrowser` in their own packages |
| The package class carries the right; the skill names the same right | `#[MCPRequiresAccountRights(AgentAccountRights::USE_WEB)]` on the package, `requiredAccountRights: [useWeb]` in the skill |
| A host maps rights to its roles; no role name in a package or a skill | `AI.accountRights` (`operatePlatform => [Role::SUPERADMIN]`) or its own `AgentAccountRightsInterface` |
| An unmapped right is not held | a platform skill stays closed until the host opens it |
| Platform skills (`System.*`, `Translations.*`) are for superadmins | the right plus `#[MCPRequiresAdminWithAccessToAllResources]` on the package: they read across every account |
| A host adds a gate to a package's tool by a same-path subclass of the package | it repeats the package's rights attribute (the attributes of the parent class are not read on the subclass) |

The naming rules of the whole ecosystem: the framework's rulebook `ddd-module-orchestrator` › references/package-cuts-and-names.md (mgamadeus/ddd).

## Offer is not authorization

`requiredAccountRights` on a skill is an OFFER filter only. The executor receives package classes, not the skill that
exposed a tool, and a tool can belong to several skills, so the skill cannot be the hard gate. Every right a skill
declares must also be declared with `MCPRequiresAccountRights` on the packages it binds; otherwise the offer
disappears while the MCP route and a console command of the host still run the tool. Write a host test that compares
both; the package does not ship it. Every tool runs with the rights of the authenticated account in any case.

## What the model is told

| Situation | Text | From |
|---|---|---|
| a requested skill is not in the plan | "NOT loaded (not included in this customer's plan): … (requires …)" plus "offer the unlocking upgrade" | `AISkillLoadRefusals::renderCorrectiveSentences()`, `AISkill::describeFeatureFlagRequirement()` |
| a requested skill lacks a right | "NOT loaded (this user account lacks the required permission): … (right: …)" | same |
| a loaded skill has locked tools | one sentence per axis in its tools line | `AISkill::getInstructionBlock()` |
| what the plan includes, what an upgrade unlocks, what this user may not use | the product awareness segments | the host's `AIConversationEntitlementsService::buildProductAwareness()` (`AgentProductAwareness`) |

The package parses `shortDescription` and `salesTriggers` and offers helpers (`AISkill::getFeatureFlagNamesAnUpgradeUnlocks()`,
`AISkill::describeMissingFeatureFlags()`, `AISkills::buildRestrictedCapabilitiesMarkdown()`,
`AISkillsService::getAgentRelevantFeatureFlagNames()`), but renders no awareness itself: a host that wants upgrade
offers builds the segment in its `buildProductAwareness()`. Without it, a locked skill is simply absent.

A plan gate that names a tool no permitted skill provides is detected by
`AISkills::getToolNamesNoPermittedSkillProvides()`: such a gate counts as resolved instead of blocking the plan.

## Purposes

`requiredFeatureFlags` of a purpose (plus `AI.purposeRequirements`) is checked for a CUSTOMER start only
(`AIConversationPurposeRunsService::findStartablePurposeForAgentSpace()`); an admin start is never gated, and a space
whose flags the host cannot answer is not gated. Preloaded skills of a purpose pass the flag and right filter
(`AIConversation::findPermittedSkillNames()`): a purpose cannot smuggle a locked skill.

## Traps

- A misspelt gate key is no gate. Run the lint (reference [catalog-binding-overrides-and-lint.md](catalog-binding-overrides-and-lint.md)).
- An unknown right name must count as missing: that is the contract of
  `AgentAccountRightsInterface::getMissingRightNames()`, which the host's implementation must keep. Whether an
  unknown flag name is available is the answer of the host's `AgentFeatureFlagsInterface` implementation.
- A count limit (how many of something a plan allows) is not a gate: enforce it in the service that writes.
- A gating change reaches running workers only after a restart (the overlay is read once per process).
