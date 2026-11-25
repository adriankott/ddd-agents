# Config map: the config files and who explains them

**Reader:** host developer

Contents: how a config file is replaced · the map · the four files of this skill · environment variables.

## How a config file is replaced

The package's config root is `config/app/` (`AgentsModule::getConfigPath()`). The framework searches the
application's config roots first, then the modules' (`Config::get()`), so a file the host puts at the same path
**replaces the package's file whole**: keys are never merged. A host that changes one key copies the whole file.
Readers that fall back per key to their class default: `AgentApplicationWording::current()`,
`AgentApplicationNames`, `AgentModelInputOptions`, `ContextRetentionSettings`, `SupervisorSettings` and `AgentDecisionModelSettings` (a site the
file does not list is off, except `AgentDecisionModelSettings::DEFAULT_ON_SITES`). The other readers take the file as
it is.

Besides the PHP files the package ships prompts (`config/app/AI/Prompts/`), purposes
(`config/app/AI/Purposes/`) and its agent skill files (`config/app/AI/Skills/`); a host's file at the same path
replaces each of them the same way. Prompts: `ddd-module-agents-context-specialist` and
`ddd-module-agents-decisions-and-guards-specialist`; purposes and skills: `ddd-module-agents-skills-and-purposes-specialist`
and `ddd-module-agents-autonomy-specialist`.

## The map

| File under `config/app/AI/` | Config key | Read by | What it sets | Default | Explained in |
|---|---|---|---|---|---|
| `agentApplicationNames.php` | `AI.agentApplicationNames` | `AgentApplicationNames` | trace line prefix, console label, further environment variable names | `[agent:trace]`, `agent`, none | this file |
| `agentApplicationWording.php` | `AI.agentApplicationWording` | `AgentApplicationWording::current()` | the host's words in the module's sentences | neutral words (`workspace`, `subscription`, empty examples) | this file |
| `agentEgress.php` | `AI.agentEgress` | `ArgusAIAgentConversation` | the HTTP transfer of the agent's model call | stall abort off, no proxy | this file |
| `agentMemory.php` | `AI.agentMemory` | `AgentMemoriesService::isMemoryStoreEnabled()`, `AgentMemoryCurationService` | the package's memory store on or off (`enabled`), the application's data with a home of its own the curation never copies (`structuredHomes`, string list) | off, none | `ddd-module-agents-context-specialist` |
| `agentModelInputOptions.php` | `AI.agentModelInputOptions` | `AgentModelInputOptions::resolveFor()` (per model call; a purpose's `modelInputOptions:` map wins per key) | what the model input leaves out to save tokens: groups of meta tools, the widget part of the base prompt, base prompt sections by name, the memory rules without a memory tool, the reachable tool schemas from the first call | everything as before (byte-identical input) | `ddd-module-agents-context-specialist` |
| `agentPersona.php` | `AI.agentPersona` | `ArgusAIAgentConversation::findAgentPersonaNames()`, `AISkillsService::getAgentPersona()` | the agent's name and the product name in the prompts and in the skill and purpose texts | `Assistant`, `this product` | this file |
| `agentToolCallChildProcess.php` | `AI.agentToolCallChildProcess` | `AgentToolCallForkJoinService` | the host's console script that starts a child process for one tool call, and the command the child runs | no console script, so every call runs inline; command `app:agent:execute-tool-call` | `ddd-module-agents-tools-specialist` |
| `contextRetention.php` | `AI.contextRetention` | `ContextRetentionSettings`, `AgentWireConfiguration::resolveDefaultPreviousReasoningCapTokens()` | skill unloading, skill loading strategy, state delivery, the reasoning carry-over cap (`previousReasoningCapTokens`, per wire API `previousReasoningCapTokensByAgentWireApi`) | skill granularity, append loading, cap 4000 on every wire API | `ddd-module-agents-context-specialist` |
| `decisionModel.php` | `AI.decisionModel` | `AgentDecisionModelSettings::current()` | the decision layer's mode and sites | mode `off` | `ddd-module-agents-decisions-and-guards-specialist` |
| `documents.php` | `AI.documents` | `AgentDocumentTextExtractionService::getSettings()`, `AgentDocumentSettings` (the drive read tools `readDriveDocument`, `viewDriveDocumentPages`) | document reading on or off, the largest document, the pages whose text is kept, the characters of one read, OCR, the cache group and lifetime of the extracted text, page images (on, per call, DPI) | on; 25 MB; 500 pages; 40000 characters; OCR on; Core's default group (set `redis`) 86400 s; page images on, 4 per call, 110 DPI | `ddd-module-agents-tools-specialist` (section Drive mounts) |
| `evalOutboundRecipients.php` | `AI.evalOutboundRecipients` | `EvalOutboundRecipientsService` | the test sinks an eval run may send to | none | `ddd-module-agents-observability-specialist` |
| `evalSuite.php` | `AI.evalSuite` | `AgentEvalResultFilesService::getResultsDirectory()`; the suite planning (`AgentEvalSuitePlanService`: `getSuiteDefinitions()`, `findFixturePath()`); the evaluation commands | the results folder of the evaluation runs; the resource group per fixture and the fixtures whose write cases run alone on their agent space; the suites a suite run plans (`suites`), the default fixture of each evaluation command (`fixturePaths`), the default judge and driver models of a judged case (`judgeModelName`, `driverModelName`) | `var/agent-evals`, no groups, no exclusive fixtures, no suites, no fixture paths, the package's judge and driver | `ddd-module-agents-observability-specialist` |
| `toolOverlays.php` | `AI.toolOverlays` | `MCPToolOverlays` (entitlement, schema, mandate summary, run report) | per tool name: `accountRights` (replaces the attributes, `[]` = none), `description`, `summary`, `parameters`, `ownerFacingSubject` | none | `ddd-module-agents-tools-specialist` |
| `agentSpaceToolArgument.php` | `AI.agentSpaceToolArgument` | `AgentSpaceToolArgument` (`MCPSchema`, `AgentSpacesService`) | the space argument's name and description on the wire | `agentSpaceId`, the DTO's description | `ddd-module-agents-tools-specialist` |
| `agentTasks.php` | `AI.agentTasks` | `AgentTaskSchedulesMCPPackage::findTaskListWidgetType()` | the widget type of the task list (`taskListWidgetType`) | none | `ddd-module-agents-autonomy-specialist` |
| `installationCheck.php` | `AI.installationCheck` | `AgentInstallationCheckService` (`app:agent:check-installation`) | checks skipped, findings accepted (unbound skills, unmapped rights, skill right gaps, missing tables) | every check runs, nothing accepted | this skill, references/installation-check.md |
| `purposeRequirements.php` | `AI.purposeRequirements` | `AIConversationPurposesService::getPurposeByName()` | feature flags a purpose requires | none | `ddd-module-agents-skills-and-purposes-specialist` |
| `skillNamespaceGroups.php` | `AI.skillNamespaceGroups` | `AISkillCatalogDocService` | the groups of the admin skill screen | the package's groups | `ddd-module-agents-skills-and-purposes-specialist` |
| `skillPackages.php` | `AI.skillPackages` | `AISkillsService`, `AISkill::getPackageClassesBySkillName()` | which MCP packages each skill brings | the package's own skills | `ddd-module-agents-skills-and-purposes-specialist` |
| `skillRequirements.php` | `AI.skillRequirements` | `AISkillsService::applySkillRequirementOverlay()` | flags, rights and billing access a skill requires | none | `ddd-module-agents-skills-and-purposes-specialist` |
| `supervisor.php` | `AI.supervisor` | `SupervisorCheckpointService` | the supervisor switch, model and efforts | off | `ddd-module-agents-autonomy-specialist` |
| `toolCallImageStore.php` | `AI.toolCallImageStore` (legacy `AI.toolCallImageSpool` read, warned once) | `AgentToolCallImageStoreService`, `AgentToolCallImageStoreSettings` | the store of tool pictures without a store of their own: L1 process cache (group, max bytes, TTL), L2 shared cache (group, TTL, extend on read), L3 object store (name, key prefix; lifecycle is the host's), the wire profile (long edge, megapixels, WebP/JPEG, quality), the retention (on, newest picture results kept, pictures per call) | on; L1 APCu 64 MB / 600 s; L2 Core's default group (APCu → skipped and warned; set `redis`) 1800 s extended on read; L3 `agentToolImages` under `agent-tool-images/` when configured (warned otherwise); 2048 px / 3.75 MP / WebP 85; retention on, 4 results, 20 pictures | `ddd-module-agents-context-specialist` (references/tool-picture-store.md) |
| `untrustedContent.php` | `AI.untrustedContent` | `MCPToolContentTrust` (the tool documentation, the tool-result screen, the reading rule of a result), `MCPToolExecutorService::holdCallAfterUntrustedRead()` | which packages and tools return trusted content beside `MCPToolReturnsTrustedContent` (`trustedPackages`, `trustedTools`), what a write after an untrusted read of the turn gets (`writeAfterUntrustedRead`: `off`, `ownerConsent` or `refuse`; an unknown value is `off` with a warning), whether a fetch of an unseen model-built URL is refused (`unseenUrlAfterUntrustedRead`: `refuse` or `off`) | no trusted extras, `off`, `refuse` | `ddd-module-agents-decisions-and-guards-specialist` |
| `usageScopesCountedElsewhere.php` | `AI.usageScopesCountedElsewhere` | `DDD\Domain\AI\Services\AIUsageEnvelopesService` (ddd-ai) | usage scopes left out of booked-cost recordings | the chat model's turn call | `ddd-module-agents-loop-specialist` |
| `webSearch.php` | `AI.webSearch` | `WebSearchConfiguration::findValue()` (`AIWebSearchService`, the web Argus repositories, `WebSearchMCPPackage`) | the web research on or off (`enabled`), a switch and an endpoint per capability (search, url-to-markdown, screenshot), cache lifetimes, timeouts, the tools' limits, the search fee per request, the reading model | off, no endpoints | `ddd-module-agents-tools-specialist` |

## The four files of this skill

### `agentApplicationNames.php`

| Key | Meaning |
|---|---|
| `traceLinePrefix` | the prefix of every trace line the module writes to the log (existing log filters of a host match it) |
| `consoleAssistantLabel` | the label in front of the agent's lines on the console (`<label>> text`) |
| `furtherEnvironmentVariableNames` | per environment variable of the module a further name the host's machines set it under, read after the module's own name: `['AGENT_TRACE' => 'SHOP_TRACE']` (the example of the package's own file) |

### `agentApplicationWording.php`

The words the module puts into its sentences for the model and the owner. Every key is listed in
`AgentApplicationWording::DECLARED_PROPERTY_NAMES` with its default on the class; a key the host leaves out, leaves
empty or sets to a non-string keeps the default. Groups:

- **The space and the billed entity:** `billingEntityName`, `agentSpaceName`, `agentSpaceContextLabel`,
  `agentSpaceContextStateKind`, `agentSpaceContextSubjectName`, `agentSpaceFactsStateName`,
  `agentSpaceOwnDataExamples`, `agentSpaceQuestionTopicExamples`. The host's base prompt writes the same
  `agentSpaceContextLabel` in front of `{%AGENT_SPACE_CONTEXT%}`. Changing `agentSpaceContextStateKind` later stops the
  reading of the notes stored under the earlier kind.
- **Owner consent and outward actions:** `ownerConsentSameTargetExamples`,
  `ownerConsentSameTargetWrittenDifferentlyExample`, `ownerConsentDifferentTargetExamples`, `outwardActionKinds`,
  `outwardActionConsentException`.
- **Runs, plans and purposes:** `workContinuedAfterOwnerMessageName`, `seededPlanConversationName`,
  `seededPlanBackgroundWorkExamples`, `purposeBoundSkillLoadAdvice`, `criterionRecordName`,
  `criterionStageToolExamples`, `runReportNotApplicableItemsSentence` (a translation key with `%items%`).
- **Decisions:** `skillPreloadFactsMeaning`, `replyLanguageFurtherCoveredSentences`, `identityOrClassProposalSubject`,
  `identityOrClassRejectedCriterion`, `identityOrClassNotRejectedCriterion`, `assistantKindDescription`.

An empty example key leaves the examples out of the sentence. Which sentence reads which key: the docblock of each
property of `AgentApplicationWording`.

### `agentEgress.php`

| Key | Meaning |
|---|---|
| `transferStallAbort` | `bytesPerSecond` and `seconds`: the transfer of a model call ends as a transport failure when fewer bytes per second arrive on average over that many seconds; `seconds` 0 switches it off |
| `liteLlmEndpoint` | the load endpoint of a proxy egress (`POST:https://…/chat/completions`) that `AGENT_EGRESS=litellm` selects per process, for tests only; null ignores the switch with a log line |

The wire API of a model is no key of this package: the model catalog of mgamadeus/ddd-ai names it
(`settings.agentWireApi` in `config/app/AI/models.php`, which a host overrides at the same path: `chatCompletions`,
`responses` for an OpenAI model, `messages` for an Anthropic model), and a conversation's run settings override it. The
agent checks the value (`AgentWireConfiguration::resolveWireApiOfCatalogValue()`); `responses` and `messages` need the
direct OpenRouter egress, so under `AGENT_EGRESS=litellm` every model speaks Chat Completions. Details:
`ddd-module-agents-context-specialist` (prompt-cache-and-stable-prefix, sections 3 and 5a).

### `agentPersona.php`

| Key | Meaning |
|---|---|
| `agentName` | the agent's name, `{%AGENT_NAME%}` in the base prompt and the character prompt |
| `productName` | the product name when the space service names none for a conversation (`{%PRODUCT_NAME%}`); `AgentSpacesService::findProductNameOfConversation()` answers per conversation |

## Environment variables

`AgentEnvironmentVariables` names the variables the module reads. Each reader reads the module's name first, then the
further name `agentApplicationNames.php` declares for it.

| Variable | Effect |
|---|---|
| `AGENT_TRACE` | `1` / `true`: the agent's trace lines are written to the log (local runs) |
| `AGENT_EGRESS` | `litellm`: this process's agent model calls go through `liteLlmEndpoint` (tests) |
| `AGENT_UNLOAD_GRANULARITY` | `skill` / `tool`: overrides `unloadGranularity` of `AI.contextRetention` |
| `AGENT_SUB_SKILL_TOOL_DISCOVERY` | the sub-skill tool discovery mode (`ddd-module-agents-skills-and-purposes-specialist`) |
