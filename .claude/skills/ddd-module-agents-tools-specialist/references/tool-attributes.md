# Tool attributes — every one, and what it changes at run time

**Reader:** host developer and package maintainer

Contents
- How the attributes are read
- What the tool is and does
- Disclosure, residency and wire
- Time, images, parallel calls, repeats
- Gates: entitlement, rights, admin, consent, mandate
- Readers that hold only a tool name
- Schema shaping (method and property)
- Interfaces a response or a parameter implements
- Adding an attribute (package maintainer)

## How the attributes are read

All live in `DDD\Domain\AI\Entities\MCPPackages`. They are read by reflection, never inferred from a tool's name.

- `MCPTool::createFromReflectionMethod()` reads most of them once per documentation build and carries them onto the
  `MCPTool` value object and from there onto `ToolDefinition` (`ToolDefinition::fromMcpTool()`): what the loop reads
  per turn.
- "Method or package class" below means: the attribute may sit on the package class and then covers every tool of it.
  For the ceilings (`MCPToolExecutionTimeout`, `MCPToolImageBudget`) the method's own declaration wins over the class.
  For the gates (`MCPRequiresFeatureFlags`, `MCPRequiresAnyFeatureFlag`, `MCPRequiresAccountRights`) and
  `MCPDeliversProductCapability` the method's and the class's values are united. The class's gates are read through
  its parent packages (`MCPPackage::findClassAttributesOfPackageHierarchy()`): a subclass that replaces a package keeps
  its rights and flags and may add its own; a tool's right is lifted only by a tool overlay (`AI.toolOverlays`).
- A reader that holds only a STORED call (a name) asks `MCPToolAttributeCatalog` or `MCPToolEffectCatalog`. Both are
  built once per process from the packages of every skill (`AISkill::getPackageClassesBySkillName()`): a tool bound to
  no skill is unknown to them.
- `MCPToolRequiresOwnerConfirmation` gates nothing by itself (the tool body calls the consent step), and
  `MCPDeliversProductCapability` has **no effect in the package** (rows below). `WithAIEnvelope` of `ddd-ai` is read by nothing on a tool method either —
  the executor invokes the method by reflection (call-context-and-agent-space-defaults.md).

## What the tool is and does

| Attribute | Target | At run time |
|---|---|---|
| `MCPTool` | method | Makes the method a tool. The method name is the tool name; the method docblock (without `@example`) is the description; `@example` lines become schema examples. |
| `MCPToolEffect(effect, identicalRepeatDoesNewWork, resultIsReusedWithinTurn, isOutwardAction, keepsEarlierReadsReusable)` | method only | `READ`, `WORKSPACE`, `WRITE` or `DELETE` (order of harm READ < WORKSPACE < WRITE < DELETE; `WORKSPACE` below). Declare it on every tool: without it the tool is neither a read nor a write for every stored-call reader, and `MCPToolAnnotations` stays null. Readers: the identical-write refusal (a WRITE/DELETE repeated with byte-identical arguments is refused unless `identicalRepeatDoesNewWork`), the per-turn read reuse (an identical READ later in the same owner turn gets the earlier result unless `resultIsReusedWithinTurn: false`), the fork-join's inline-write rule, the delete-retry rule (a failed DELETE whose identical call completed earlier is reported as success), the outward-action list of the audit (`isOutwardAction`), the read-reuse invalidation (`keepsEarlierReadsReusable` for a write that changes no account data), the eval read-only gate. |
| `MCPToolAnnotations` (not an attribute) | — | Derived from the effect and `MCPToolReturnsUntrustedContent` for external clients on `tools/list`: `readOnlyHint`, `destructiveHint` (a DELETE or an outward action), `idempotentHint`, `openWorldHint`. Hints only; they authorize nothing. |
| `MCPToolReturnsUntrustedContent(reason)` | method or class | Forces untrusted (READ and WORKSPACE tools are untrusted anyway, `MCPToolContentTrust`); also sets `openWorldHint`. Untrusted results are screened by the tool-result screen, carry a reading rule, and hold the turn's later writes for the owner's consent (decisions-and-guards skill, injection defence). |
| `MCPToolReturnsTrustedContent(reason)` | method or class (a subclass keeps its parent's) | The tool returns only content the platform vouches for (its own records, text it composed, the owner's own data). Without it a READ or WORKSPACE tool is untrusted. Never for third-party text the platform stored. Vendor packages: `config/app/AI/untrustedContent.php`. A response DTO implementing `MCPToolResultDeclaresContentTrust` (`isTrustedContent()`) overrides it per call; a throw counts as untrusted. |
| `MCPToolFetchesModelChosenUrl(...argumentNames)` | method | After an untrusted read of the turn, the executor refuses a URL in these arguments whose query string or fragment appears in no owner message and no tool result (`AI.untrustedContent.unseenUrlAfterUntrustedRead`: `refuse` by default, `off`). |

## The WORKSPACE effect

`MCPToolEffect::WORKSPACE`: "changes only the agent's private working area of this agent space — nothing and no one
else." A tool has this effect only when ALL four conditions hold:

1. It changes only the workspace of its agent space: files, folders, the shell session (its working directory and
   environment), background jobs running in it.
2. It changes no domain data: no database row, no store other than the workspace store, no external service, no
   conversation, memory or widget.
3. It makes nothing visible to anyone else: no message, mail or push, no file for partners or in the chat. Something
   becomes visible only when ANOTHER tool hands it out explicitly (a tool that saves a workspace file to the owner's
   drive — that tool is WRITE and an outward action).
4. Its effect stays inside that one workspace: a call on workspace A can never change workspace B.

Conditions 2 and 3 do not rest on the tool's discipline: the sandbox enforces them — no network, no credentials, none
of the agent's tools inside the shell, exactly one way out (the hand-out tool of condition 3). A tool that cannot
guarantee all four (one that writes a file AND books something, one whose shell can reach the network) is WRITE.

| Tool | Effect |
|---|---|
| a shell command, writing a file, editing a file in the workspace | WORKSPACE |
| reading a file, searching the files, viewing an image of the workspace | READ |
| saving a workspace file to the owner's drive (or anywhere the owner, a partner or the chat sees it) | WRITE, `isOutwardAction: true` |
| copying a mounted file into the workspace | READ for the source, WORKSPACE for the copy |
| writing an agent memory | WRITE |

How the package treats a WORKSPACE call — never as a write of the turn, never as a pure read:

| Reader | WORKSPACE |
|---|---|
| `isReadOnly()` / `changesState()` | both false; `changesOnlyTheWorkspace()` true, `changesNoSharedData()` true (with READ) |
| Owner confirmation, mandates, outward actions | never needed, also in a scheduled run without an operator; never outward |
| Identical-write repeat refusal | not refused (a repeated shell command is real work) |
| Write ledger of the audit, supervisor's supervised writes | not listed; the run counts show `workspaceChanges` |
| Paused-turn record | its own bucket `workspaceChanges`, printed as WORKSPACE CHANGE (never as SAVED) |
| Record change after a completed call (task-plan criteria) | no change of the record (only a widget row it wrote counts) |
| Per-turn read reuse | ends the reuse of EVERY earlier read (the safe rule: which reads read the workspace is unknown) |
| Scan for an earlier final refusal | ends it (a file the refusal missed may exist now) |
| Parallel calls of one step | never forked, also when `MCPToolParallelSafe`: one conversation shares one workspace and shell; READ calls of the step may still fork; an inline WORKSPACE call does not hold the forked children back |
| Eval read-only gate, environment-wide write lock | let through: each case and repetition works in its own workspace |
| Eval lanes | a case whose expected tools are READ / WORKSPACE only is a read case and runs in parallel |
| MCP annotations | `readOnlyHint` false, `destructiveHint` false, `idempotentHint` false |
| A per-execution write cap (a script calling tools) | counts WRITE and DELETE only |

The workspace a call works in: `AgentToolCallProvenance::$workspaceKey`, from the conversation's
`RunSettings::$workspaceKey` (inherited by delegation children). Null in live use — the tool derives its own key from
the agent space or the account. An evaluation sets `eval-<case>-r<repetition>-<runToken>` per case run and calls the
application's `AgentEvalRunHooksService::prepareWorkspaceOfCase()` / `cleanUpWorkspaceOfCase()`. The package owns no
workspace store.

## Disclosure, residency and wire

| Attribute | Target | At run time |
|---|---|---|
| `MCPToolSummary(summary)` | method | The level-1 line of the `load_tool` catalog while the tool is available but not loaded. Without it the catalog falls back to the whole docblock. Write what the tool does and when to reach for it, in one or two sentences. |
| `MCPToolAlwaysLoad(reason)` | method or class | The tool loads with its skill even in lazy tool loading — no `load_tool` call first. It stays evictable. Under eager and append-only loading a skill brings all its tools anyway. The package's memory tools (`AgentMemoriesMCPPackage`) carry it on the class. |
| `MCPToolNoEviction(reason)` | method or class | A pin: once loaded the per-turn sweep never evicts it. Keep the pinned set few and small. Residency and eviction are the context skill's (`ddd-module-agents-context-specialist`). |
| `MCPToolNewestResultIsNeverStubbed(reason)` | method | The newest result of this tool always rides whole on the wire; older ones are stubbed as usual. Declare it on the lookup whose ids a later write takes, not on the write. Read by `ArgusAIAgentConversation` through `MCPToolAttributeCatalog`. |
| `MCPToolEmbeddableWidget(...widgetTypes)` | method | Under a COMPLETED result of any effect the model is told which embeddable widget shows this data inside a `ComposedView` (only where the model is taught the kit, never for a widget the result already dictates). Widgets are the widgets skill's (`ddd-module-agents-widgets-specialist`). |

## Time, images, parallel calls, repeats

| Attribute | Target | At run time |
|---|---|---|
| `MCPToolExecutionTimeout(seconds, reason)` | method or class | The loop's wall-clock ceiling for this tool instead of `DEFAULT_SECONDS` (60); at least 1. See time-limits-and-parallel-calls.md. |
| `MCPToolImageBudget(images, reason)` | method or class | Image parts one result may carry instead of `DEFAULT_MAX_IMAGES_PER_TOOL_CALL` (4), bounded by `MAX_IMAGES_PER_TOOL_CALL_CEILING` (12). |
| `MCPToolParallelSafe(reason)` | method or class | Admits the tool to run in a child process concurrently with the other calls of the same model response. See time-limits-and-parallel-calls.md for the admission rules. |
| `MCPToolRefreshesState(reason)` | method or class | For a tool that reports changed state on identical arguments (a capture after each action): never answered from an earlier result (read reuse, failed-read and unsaved-repeat refusals), and exempt from the identical-arguments repeat guard until `MAX_REPEATED_TOOL_CALLS` (3) identical calls of the turn FAILED. The per-turn step budget still applies. |

## Gates: entitlement, rights, admin, consent, mandate

| Attribute | Target | At run time |
|---|---|---|
| `MCPRequiresFeatureFlags(...names)` | method or class | All named flags must be available on the space the call targets (else on the account). Offer side: `ToolDefinitions::filterByAvailableFeatureFlags()` drops the schema. Execution side: the executor refuses ("not available here … part of a higher plan"); no answer from the host fails closed. The host answers through `AIConversationEntitlementsService` (the entry skill's override point). |
| `MCPRequiresAnyFeatureFlag(...names)` | method or class | At least one of the named flags; composes as all(required) and any(any-of). |
| `MCPRequiresAccountRights(...names)` | method or class | Every named right must be true on the acting account (`AgentAccountRightsInterface`). Name the package's constant (`AgentAccountRights::USE_WEB`, a module's own rights class), never a host string; the host maps it to roles (`AI.accountRights`). Offer side `ToolDefinitions::filterByAccountRights()`; execution refusal names the right and says it is no plan upgrade. Flags are checked before rights. |
| `MCPRequiresAdminWithAccessToAllResources` | class only | See package-bundle-and-controller.md, "The admin audience". |
| `MCPToolRequiresOwnerConfirmation` | method | **Gates nothing by itself** — the gate exists only when the tool body calls `OwnerConsentsService::requireOwnerConsentForStep()` after its own refusals and before its write; the consent flow is the decisions-and-guards skill's (`ddd-module-agents-decisions-and-guards-specialist`). The executor reads it twice: the write-after-untrusted-read gate leaves such a tool to its own step, and `coveredByMandateActionClass` (optional) is handed to the call so a STANDING mandate of that class covers the step (`ddd-module-agents-autonomy-specialist` › mandates, "Covered consent steps"). |
| `MCPToolRequiresMandate(actionClass, bindingKey)` | method | The executor runs the tool in the agent loop only under an active mandate for the action class (refusal `REFUSAL_MANDATE_REQUIRED`); the tool's ceiling grows by `CONSENT_CHECK_SECONDS` (100) unless it is at least `LONG_CEILING_SECONDS` (200). The mandate mechanism is the autonomy skill's (`ddd-module-agents-autonomy-specialist`). |

## Readers that hold only a tool name

| Attribute | Target | Reader |
|---|---|---|
| `MCPToolOwnerFacingSubject(subject)` | method | The run report's owner-facing name of what a WRITE saved (`AgentRunReport::ownerFacingTitleForWriteTool()`); without it the words of the tool name minus the verb. Tools that save the same thing share the subject. |
| `MCPToolProposesIdentityOrClass(resultIsQuotedAsIdentityResolution)` | method | A call of it in the previous assistant turn makes the owner's short reply an identity reply for `AgentReasoningEffortDecisionsService`; with the flag the newest result is quoted to that decision. |
| `MCPDeliversProductCapability(...keys)` | method or class | **No effect in the package**: the keys are copied onto `MCPTool::$productCapabilityKeys` (and so appear in the HTTP tool documentation), but nothing in the package reads them — a host's coverage check may. Never a gate. |
| `MCPToolReceivesCurrentConversationId(reason)` | method | `ToolCall::buildExecutionArguments()` fills the argument `conversationId` with the calling conversation's id — always, over a value the call carries (only a conversation without an id keeps it) — and `ToolDefinition::toOpenAiToolSchema()` does not offer the argument to the model. For a tool that reads the conversation it is called from. |
| `MCPToolWritesAgentMemoryOnly(reason)` | method | Two readers. The fork-join runs the call inline beside forked calls of the same model response (`AgentToolCallForkJoinService::responseHasInlineWriteBesideTheFork()` does not count it as a write). `AIConversation::findMemoryDirectivesBlock()` re-reads the standing directives when the number of completed calls of such tools changes. Only for a tool whose sole writes are the agent's memory rows. |
| `MCPToolResultIsNeverStubbedByTheBudget(reason)` | method | The result is exempt from the tool-result budget while its turn is kept (`AIConversation::resolveToolResultRetention()`); its characters do not count against the budget, so it never evicts the other results of the turn; a turn that leaves the kept window is stubbed as usual. For page reads and searches a long research turn works from. |
| `MCPToolShowsNoActivityLine(reason)` | method | `AgentActivityLinesService::buildElement()` writes no activity line for the call. For a tool the owner does not need to see working. |
| `MCPToolSendsMessageToOwner(reason)` | method | A scheduled run blocks the tool: `ScheduleRunPurpose::getToolNamesBlockedByDeclaration()` adds every declaring tool to the purpose's `blockedToolNames`, so the run's report has one delivery path. Declare it on every host tool that sends the owner an e-mail or a text message. |
| `MCPToolRequiresOwnerWordsAsEvidence(reason)` | method | The call is judged on the owner's own words in the transcript, which a sub-agent never holds. A delegated run is never offered the tool (`AIConversation::findToolNamesLeftOutOfADelegatedRun()`, part of `findBlockedToolNamesOfConversationTree()`), a call of it by name there is refused finally before it executes (`AgentToolCallDispatchService::buildRefusalOfToolLeftOutOfADelegatedRun()`), and a `delegate` tool run of it is refused (`DelegationSubAgentRefusal::forToolRequiringOwnerWords()`). The package's `grantMandate` declares it; declare it on a host grant tool of the same kind. |

These six are read from the method of the class the application binds: a host that redeclares a package tool on its own
package class repeats the attribute there.

## Schema shaping (method and property)

| Attribute | Target | At run time |
|---|---|---|
| `MCPHiddenQueryOptions(...families or property names)` | method | Hides query-option families (`DtoQueryOptions::FILTERS`, `ORDER_BY`, `EXPAND`, `SELECT`) or a plain property name from THIS tool's agent schema; the REST schema and the hydrated properties stay. |
| `MCPExposedFilterProperties(...names)` | method | The tool's `filters` documents only these properties, and the executor refuses a call whose `filters` or `orderBy` names another (`id` is always accepted, in filters and in orderBy). |
| `MCPOutputOnlyProperty` | property | Omitted from the INPUT schema at every depth; the output schema keeps it. A size measure, no security gate. |
| `MCPEmptyStringIsValue` | property | An empty string on this optional property is a value (it clears the text) instead of "not passed". |

## Interfaces a response or a parameter implements

| Interface | Implemented by | At run time |
|---|---|---|
| `MCPServerProvidedInput` | a tool parameter type (`AgentToolCallProvenance`) | Left out of the schema, injected by the executor; an empty instance on the HTTP path. |
| `MCPToolResponsePlatformNoteProvider` | the response DTO | `buildPlatformNoteForModel()` rides on the wire after the result, never in the stored result the screening reads — read ONLY when the DTO also implements `LlmMarkdownRenderable` and the call did not ask `outputFormat=structured`. |
| `MCPToolResponseDictatedWidgetTypeProvider` | the response DTO | `getDictatedWidgetType()` is the widget type the note dictates without a condition; the stall check reads it. Same condition as the note: markdown rendering only. |
| `ToolCallImageStore` | the package class | Serves durable pictures again from a persisted `ToolCallImageReference`. |

## Adding an attribute (package maintainer)

1. Put the class in `Entities/MCPPackages/`, `readonly` when it only carries arguments, with a docblock that states
   its reader and an example.
2. A per-call reader: read it in `MCPTool::createFromReflectionMethod()`, carry it on `MCPTool` and
   `ToolDefinition::fromMcpTool()`. A stored-call reader: ask `MCPToolAttributeCatalog::getAttributeOfToolName()`.
   Never a list of tool names in the core.
3. An execution gate: put the rule into one value object both the offer and the executor ask (the
   `MCPToolEntitlement` pattern).
4. Add the attribute to this table and to the description of this skill.
