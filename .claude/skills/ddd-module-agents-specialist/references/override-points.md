# Override points: what a host replaces, and how

**Reader:** host developer

Contents: the mechanism · folder scans and another root namespace · the base services meant to be replaced · the host
interfaces · listeners · SQL fragments · classes the module builds as the host's · controller hooks · the words of the host · rules for every override.

## The mechanism

The framework resolves a package class X (a service, an entity, an entity set, a repository) in this order:

1. a per-process cache of earlier answers;
2. the container's service class map: a definition in the host's `services.yaml` whose id is the package class and
   whose class is the host's subclass wins;
3. X with its first segment `DDD` replaced by `App` (`DDDService::APP_ROOT_NAMESPACE`), when that class exists;
4. X itself.

The module asks through this lookup (`DDDService::getService()`, `X::newInstance()`, `X::getService()`,
`DDDService::getContainerServiceClassNameForClass()`), so the host's class is what runs. A host under `App\`
overrides by a subclass at the mirrored path (`App\Domain\AI\…\X` for `DDD\Domain\AI\…\X`) with no configuration. A
host under another root namespace declares the definition of step 2 for **every** class it overrides. A service the
module builds with `new` cannot be replaced; every override point below is reached through the lookup.

**The mapped subclass must also be a public service under its own class name.** `getService()` fetches the class the
map names; a private definition is removed or inlined by the container, `getService()` then throws, and
the container lint does not catch it. Both definitions, for a host under the root namespace `Shop\`:

```yaml
services:
  DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService:
    class: Shop\Domain\AI\Services\AgentSpaces\AgentSpacesService
    public: true
  Shop\Domain\AI\Services\AgentSpaces\AgentSpacesService:
    public: true
```

The package carries no word, column or table of an application. Where an application's own fact is needed the
package asks a method whose default answers "not known", "none" or "ungated" — never an assumption about a kind of
application.

## Folder scans and another root namespace

Six folder scans are not covered by the lookup. They read the source roots of
`DDD\Domain\AI\Services\Conversations\Discovery\AgentSourceRootsService` (the application's `src/` under `App\`, then
each module's source path under `DDD\`, a file turned into a class name by PSR-4): the tool package ids
(`MCPPackageIdsService`), widgets (`ConversationWidgetClasses`), signal sources (`ConversationSignalSources`),
composed-view nodes (`ComposedViewNodeClasses`), data sources (`ComposedViewDataSourceClasses`) and entity-reference
resolvers (`EntityReferenceResolverClasses`). Skill and purpose files are not on this path: they come from the config
roots (`AISkillSourcesService`) and need no source root under any root namespace. The rules of the package-id scan
itself: `ddd-module-agents-skills-and-purposes-specialist`.

A host under another root namespace gets wrong class names from these scans until it replaces the roots. The package
keeps **one root per directory** (`AgentSourceRoots::addSourceRootOnce()`, keyed by `AgentSourceRoot::uniqueKey()`,
the directory), and `buildSourceRoots()` registers `App\` at the application's `src/` first. So the host builds a new
set with its own root **first**, then adds the package's roots; the package's `App\` root for the same directory is
then dropped:

```php
namespace Shop\Domain\AI\Services\Conversations\Discovery;

use DDD\Domain\AI\Entities\Discovery\AgentSourceRoot;
use DDD\Domain\AI\Entities\Discovery\AgentSourceRoots;
use DDD\Domain\AI\Services\Conversations\Discovery\AgentSourceRootsService as PackageAgentSourceRootsService;
use DDD\Infrastructure\Services\DDDService;

class AgentSourceRootsService extends PackageAgentSourceRootsService
{
    protected function buildSourceRoots(): AgentSourceRoots
    {
        $agentSourceRoots = AgentSourceRoots::newInstance();
        $agentSourceRoots->addSourceRootOnce(AgentSourceRoot::forDirectory('Shop', DDDService::instance()->getRootDir() . '/src', true));
        foreach (parent::buildSourceRoots()->getElements() as $packageSourceRoot) {
            $agentSourceRoots->addSourceRootOnce($packageSourceRoot);
        }
        return $agentSourceRoots;
    }
}
```

Map it with both definitions above (id `DDD\Domain\AI\Services\Conversations\Discovery\AgentSourceRootsService`). The
wrong order (adding the host root after `parent::buildSourceRoots()`) is dropped silently: the scans keep turning the
host's files into `App\…` names, and a widget registry, for example, then reports that the class does not load (it
throws under tests and logs and skips the class in production). Until the roots are replaced, the host binds its tool
packages by class.

## The base services meant to be replaced

Each is a public service with a working default. The owning topic skill explains the contract in depth.

| Base service | Default | A host overrides it to … | Explained in |
|---|---|---|---|
| `DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService` (with the entity `DDD\Domain\AI\Entities\AgentSpaces\AgentSpace`) | a space belongs to one account; facts read from the space; a start without a space is allowed | anchor the space on the host's own entity: rights, time zone, owner, language, prompt context, the tool argument that names it | [agent-space.md](agent-space.md) |
| `DDD\Domain\AI\Services\Conversations\Entitlements\AIConversationEntitlementsService` | ungated: no feature flags, no rights, billing access unknown, empty product awareness | gate skills, tools and purposes by the host's flags, rights or billing access; describe the product to the model | this file, "The host interfaces"; gating rules: `ddd-module-agents-skills-and-purposes-specialist` |
| `DDD\Domain\AI\Services\Conversations\Rights\AgentAccountRightsService` | a row is readable by its own account; a reader of every row (`isAccountReaderOfAllAgentRows()`: the framework's admin with access to all resources) reads all; no account reads nothing | add account hierarchies in `applyNonAdminAccountCondition()`; take "reads all" away from scoped admins in `isAccountReaderOfAllAgentRows()`, or per table (`isAccountReaderOfAllAgentSpaces()`, `isAccountReaderOfAllAgentMandates()`, `isAccountReaderOfAllMessageEmbeddings()`, `isAccountReaderOfAllEvaluationRows()`, `isAccountReaderOfAllTurnEventSnapshots()`) | this file, [agent-space.md](agent-space.md) |
| `DDD\Domain\AI\Services\Conversations\AgentOwnerFacingTextTranslationService` | the framework's translations of translation keys (`__()`), or the host's global `___()` when it defines one; English when a key has none | translate the report, stop reasons and schedule texts with the host's own layer (`translateKeyWithApplicationTranslations()`) | `ddd-module-agents-autonomy-specialist` |
| `DDD\Domain\AI\Services\Conversations\Environments\AgentEnvironmentNamesService` | one environment (`ENVIRONMENT_PRODUCTION`) | stamp each environment's conversations, so processes of several environments on one database drive only their own rows | this file |
| `DDD\Domain\AI\Services\Conversations\Channels\AgentVoiceChannelService` | no voice channel | connect live calls | not documented yet (a later skill) |
| `DDD\Domain\AI\Services\Conversations\Discovery\AgentSourceRootsService` | the application's `src/`, then every module's source path | replace the source roots in a test | [first-capability-end-to-end.md](first-capability-end-to-end.md) |
| `DDD\Domain\AI\Services\Conversations\Eligibility\AgentAutomationEligibilityService` | every space and account eligible | refuse automated work for an ended, suspended or erased scope | `ddd-module-agents-autonomy-specialist` |
| `DDD\Domain\AI\Services\Conversations\Schedules\ScheduleBudgetPotsService` | no budget: a zero pot that never caps | cap the spend of scheduled runs | `ddd-module-agents-autonomy-specialist` |
| `DDD\Domain\AI\Services\Conversations\Agent\AgentOwnerReportEmailsService` | no mail channel (sends nothing, answers false) | send the run report by e-mail | `ddd-module-agents-autonomy-specialist` |
| `DDD\Domain\AI\Services\Conversations\Agent\AgentBackgroundOperationsService` | no operation besides the tool run | run the host's own mechanical operations | `ddd-module-agents-autonomy-specialist` |
| `DDD\Domain\AI\Services\Mandates\AgentMandateRevocationEffectsService` | no effect | stop a standing automation when its mandate is revoked | `ddd-module-agents-autonomy-specialist` |
| `DDD\Domain\AI\Services\Conversations\Situations\AgentSituationalContextService`, `DDD\Domain\AI\Services\Conversations\Memories\AgentMemoriesService` | no situation; no memory unless the package's memory store is switched on (`AI.agentMemory.enabled`) | add the host's day context and local situation; replace the memory store with the host's own (leave the switch off) | `ddd-module-agents-context-specialist` |
| `DDD\Domain\AI\Services\WebSearch\WebSearchHostService` | the web research: no default country, the signed-in account pays, every spend allowed, no fee booked, nothing after a page read | the space's country and payer, the host's budget check and cost ledger, a hook after a page read | `ddd-module-agents-tools-specialist` |
| `DDD\Domain\AI\Services\Conversations\Agent\AgentToolResultFactsService`, `DDD\Domain\AI\Services\Conversations\Agent\AgentDecisionFactsService` | no facts | keep facts from tool results; add facts to decisions | `ddd-module-agents-tools-specialist`, `ddd-module-agents-decisions-and-guards-specialist` |
| `DDD\Domain\AI\Services\Conversations\AIConversationRealtimeSignalService` | no realtime transport, no push | push change signals to the client | `ddd-module-agents-widgets-specialist` |
| `DDD\Domain\AI\Services\Conversations\Quality\AgentConversationScorecardsService` | writes nothing | score a finished conversation | `ddd-module-agents-observability-specialist` |
| `DDD\Domain\AI\Services\Conversations\Agent\AgentEvalAccountsService`, `DDD\Domain\AI\Services\Eval\AgentEvalOutboundGuardHooksService` | no eval account, no scope owner | mark eval accounts and test sinks | `ddd-module-agents-observability-specialist` |
| `DDD\Domain\AI\Services\Eval\AgentEvalRunHooksService` | knows only the module's scope (a stored agent space), no record kinds, no host residue | the application's part of an evaluation run: scope, eligibility, harness arguments, records a fixture names by `{{ref:<kind>:<name>}}` (`resolveRecordReference()`), the teardown of what a stored conversation left — also an account-level one (`tearDownHostResidueOfConversation()`) | `ddd-module-agents-observability-specialist` |
| `DDD\Domain\AI\Services\Eval\AgentEvalSuitePlanService` | plans the module's task kinds (tools, selection, recall cases) on the agent space a case names | plan the host's own task kinds (`buildTasksOfApplicationKind()`), name the scope of a case (`assignScopeOfCaseToTask()`), add duration priors (`getDurationPriors()`) | `ddd-module-agents-observability-specialist` |
| `DDD\Domain\AI\Services\Eval\AgentEvalResultFilesService` | the results folder `AI.evalSuite.resultsDirectory` (default `var/agent-evals`); the sweep deletes the dated run entries older than 28 days | add the name patterns of the host's own dated folders (`getRetainedFolderNamePatterns()`), change the retention (`getRetentionDays()`) | `ddd-module-agents-observability-specialist` |
| `DDD\Domain\AI\Services\Eval\AgentEvalSuiteRunsService`, `DDD\Domain\AI\Services\Eval\AgentEvalSuiteCasesService` (with their entities and repositories) | stored evaluation runs and cases; an admin with access to all resources reads every row, every other account none | store the rows through the host's entity; decide who reads every row (`AgentAccountRightsService::isAccountReaderOfAllEvaluationRows()`, or `isAuthenticatedAccountReaderOfEveryRow()` of the same-path repositories) | `ddd-module-agents-observability-specialist` |
| `DDD\Domain\AI\Services\Conversations\RunReports\AIConversationRunReportService` | no decision-model cost (no usage-log id), no skill section prefix dropped | name the usage-log id of a conversation (`findUsageLogScopeIdOfConversation()`), read the cost (`findModelCostInUsageLog()`), drop the host's prefix of skill section ids (`getSkillSectionIdPrefixToDrop()`) | `ddd-module-agents-observability-specialist` |
| `DDD\Domain\AI\Services\Texts\Embeddings\AITextEmbeddingsService` | no provider (throws `TextEmbeddingNotGeneratedException`) | generate embeddings | `ddd-module-agents-context-specialist` |
| `DDD\Presentation\Mcp\Services\MCPToolExecutorService` | no admin audit; a failed tool answers the framework's client error text (with the violations of a validation failure), anything else an issue id only; no write lock | record admin tool calls with their call context (`recordAdminAudienceToolCallWithCallContext()`, the conversation id through `findConversationIdOfCallContext()`; the earlier `recordAdminAudienceToolCall()` keeps being called by its default), change the failure text (`buildToolFailureText()`), lock the environment for writes (`isEnvironmentWideWriteLockActive()`, `buildEnvironmentWideWriteLockRefusalText()`) | `ddd-module-agents-tools-specialist` |
| `DDD\Domain\AI\Services\Conversations\Agent\AgentClientContextService` | renders the client context of the owner's newest input plainly (`[SIGNAL:client-context]`, keys `page`, `page parameters`, `record`, `tab`) | name the record on screen (`describeRecordOfClientContext()`), label the keys (`getClientContextKeyLabel()`) or write the whole line (`buildClientContextTailText()`) | `ddd-module-agents-context-specialist` |
| `DDD\Domain\AI\Services\Conversations\AIConversationAgentService` | the drive appends the owner's rows and dispatches the turn | write the host's own data onto the conversation between its creation and the turn's dispatch (`beforeTurnOfDriveIsDispatched()`, with the drive request and its client context) | `ddd-module-agents-loop-specialist` |

The console printer is fetched by its interface `DDD\Domain\AI\Services\Conversations\AgentMessagePrinterInterface`, a public alias of
`DDD\Presentation\Mcp\Agent\AgentConsolePrinter`; a host aliases the id to its own printer (`ddd-module-agents-observability-specialist`).

## The host interfaces

The entitlement service answers with two interfaces the host's own classes implement. Flag and right names are
opaque strings only the host interprets; null wherever the module holds one means "ungated".

| Interface | Methods | Asked through |
|---|---|---|
| `DDD\Domain\AI\Entities\Conversations\Entitlements\AgentFeatureFlagsInterface` | `hasAllAvailableFeatureFlags()`, `hasAnyAvailableFeatureFlag()` | `findFeatureFlagsOfConversation()`, `findFeatureFlagsOfAgentSpace()`, `findFeatureFlagsOfAccount()` |
| `DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRightsInterface` | `getMissingRightNames()` | `findAccountRightsOfConversation()`, `findAccountRightsOfCurrentAccount()` |

`AIConversationEntitlement` (built by `AIConversation::getEntitlement()`) applies both axes to skills and tool
definitions. One rule a host must keep: a caller that gates a tool on a flag refuses when `findFeatureFlagsOfAccount()`
answers null, so an account-level conversation of a host that gates tools needs an answer there too. Which names a
skill or tool declares, and where (`config/app/AI/skillRequirements.php`, `config/app/AI/purposeRequirements.php`):
`ddd-module-agents-skills-and-purposes-specialist`.

## Listeners

Two interfaces collect host services by tag (autoconfigured, called in registration order):

| Interface | Called | Contract |
|---|---|---|
| `DDD\Domain\AI\Listeners\Conversations\AIConversationTurnStartListener` (`beforeTurnStarts()`) | before a turn's first model call | cheap when there is nothing to write, idempotent; returns true when it persisted a row into the conversation (the loop then reloads the rows). A throw is logged and skipped. |
| `DDD\Domain\AI\Listeners\Conversations\AIConversationSettledOwnerTurnListener` (`afterSettledOwnerTurn()`) | after an owner turn that the loop answered and released without re-firing | best effort: a throw is swallowed, the owner's answer is stored already. A listener that must not lose work runs its own recovery pass. |

The services that call them: `AIConversationTurnStartListenersService`, `AIConversationSettledOwnerTurnListenersService`.

## SQL fragments

Some statements of the package run as raw SQL over its own tables only. Where a host's table must join in, the
package asks a protected method that returns an `DDD\Domain\AI\Entities\Conversations\Scopes\AgentApplicationSqlFilter` (join, condition, bound parameters;
`AgentApplicationSqlFilter::none()` by default). Both fragments are the host's code, never user input; every identifier
in backticks so the statement runs on MariaDB and PostgreSQL.

| Method (same-path subclass) | Default | The host returns |
|---|---|---|
| `DBAIConversation::buildTestAccountsExclusionSqlFilter()` | empty: test accounts are counted | the join and condition that leave its test accounts out of the statistics (tolerant of a conversation without an account) |
| `AIConversationSchedulesService::buildAutomationStateSweepSqlFilter()` | empty: the state sweep sees only platform-paused schedules | the join and condition that select schedules whose space or account ended |

The space condition is a separate value object, `DDD\Domain\AI\Entities\AgentSpaces\AgentSpaceSqlCondition` ([agent-space.md](agent-space.md)).

## Classes the module builds as the host's

A host keeps an application-only field, constant or method in its own class at the same path; the module builds it
with `X::newInstance()` or asks the container for the class name:

- `RunSettings`, `TaskPlanItem`, `TaskPlanItems`, `AgentModelInput`, `AIConversationMetrics`;
- every Argus repository: the agent's egress `ArgusAIAgentConversation` (its endpoint, routing, cache key, the effort
  it sends) and the decision, summary, compaction, rerank and judgment repositories, always built with
  `X::newInstance()` (`ArgusRepositoriesAreNeverBuiltWithNewTest` fails on a `new Argus…()` in `src`). The egress
  sends the agentic settings (`agenticUseCase`: effort, temperature) of the model the call goes to: `--model`, else
  the model the `AGENTIC` scope pins, else the attribute's default;
- the evaluation suite's `AgentEvalSuiteTask` (the host's scope in `getScopeId()`, its own kinds),
  `AgentEvalSuitePlanOptions` (the host's own planning options) and `AgentEvalSuiteRecordSettings` (the host's key of
  the whole-scope isolation, `ISOLATE_WHOLE_SCOPE_KEY`, and its own recorded settings);
- the purpose class: `AIConversationPurpose::getPurposeClassOfTheApplication()` reads the host's same-path class for
  its purpose names and lists;
- the automation state reasons: `AgentAutomationStateReason::getReasonClassOfTheApplication()`;
- initiator types of the host's own runs: constants on the host's conversation class, which redeclares
  `OWNER_ABSENT_INITIATOR_TYPES` and `PLATFORM_OWN_RUN_INITIATOR_TYPES`; the module reads them only through
  `AIConversation::getOwnerAbsentInitiatorTypes()` and `AIConversation::getPlatformOwnRunInitiatorTypes()`. The module
  knows `INITIATOR_TYPE_SCHEDULE`, `INITIATOR_TYPE_ADMIN_TASK`, `INITIATOR_TYPE_PHONE_CALL`, `INITIATOR_TYPE_EXTERNAL_AGENT`.

## Controller hooks

`AgentConversationControllerHooksTrait` holds the protected hooks of the chat traits; each has a working default:

| Hook | Default | Override to … |
|---|---|---|
| `authorizeConversationAccess()` | open to every authenticated caller (the read rights still confine every row to its account) | gate the chat by a product flag or a user right (throw `ForbiddenException`) |
| `findAgentSpaceOfStartRequest()` | reads the request's `agentSpaceId` under the read rights; unreadable is 403, never an account-level start | name the space by the host's own id, or one fixed space per account |
| `isAdminSession()` | a validated admin acts (impersonation) | the host's admin session |
| `readOwnerDeviceFromRequest()` | the client header, else the user agent | the host's device signal |
| `afterMessagePosted()` | queues the conversation for a base-prompt warm-up, which the host's terminate subscriber builds ([host-duties.md](host-duties.md)); the start route calls no such hook | add host work after a post |
| `getRequestDtoClassOfRoutedMethod()` | the host's request DTO at the same path, else the module's | read a routed method's request into the host's subclass of the module's DTO (an extra request parameter) without redeclaring the route |
| `findConversationOfInjectedSignal()` | `findVisibleConversation()`: the request's conversation under the read rights | find the conversation of an injected signal the host's own way |
| `isRequestSnapshotStoredForStartRequest()` | the start's `storeRequestSnapshot`, honoured in an admin session only | store request snapshots (`RunSettings::$storeRequestSnapshot`) for the conversations the host's debug sessions start |

A routed trait method that needs another attribute (a quota, an envelope scope) is redeclared by the host, which
calls the trait's protected `build…Response()` body. A routed method that needs one more request parameter keeps the
route: every routed method reads its request into the class `getRequestDtoClassOfRoutedMethod()` names (hydrated and
validated as any request DTO; a class that does not extend the module's DTO is an `InternalErrorException`). The API
document of the route still names the module's DTO, so a parameter the generated client must know belongs on a
redeclared route.

## The words of the host

The sentences the module writes for the model and the owner read the host's words from
`config/app/AI/agentApplicationWording.php` (`AgentApplicationWording::current()`), the names outside a conversation
from `config/app/AI/agentApplicationNames.php`, the persona from `config/app/AI/agentPersona.php`:
[config-map.md](config-map.md). The agent's voice is the prompt file `config/app/AI/Prompts/AI/Agent/Character.md`: the
package's file is an example; a host puts its own at the same path, which replaces it whole. What the base prompt and
the meta tools leave out is `config/app/AI/agentModelInputOptions.php` (both: `ddd-module-agents-context-specialist`).

## Rules for every override

- A subclass of the conversation (`AIConversation`) repeats the class-level attributes of the module's class: PHP does
  not inherit attributes, and the framework's model generator reads the indexes and the repository from the class it
  generates (whether the generator should read a parent's attributes is a question for `mgamadeus/ddd`). Without them
  the host's model has none of the module's indexes. Copy them verbatim (the package test
  `ConversationClassAttributesDocumentedForHostSubclassesTest` keeps this list equal to the class), with
  `#[LazyLoadRepo]` naming the host's repository:

  ```php
  #[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['runState', 'runHeartbeatAt'])]
  #[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['delegationBatchId', 'runState'])]
  #[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['finalizedAt', 'lastInboundMessageAt'])]
  #[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['finalizedAt', 'finalizeCompactedAt'])]
  #[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['runState', 'agentTurnOwedAt'])]
  #[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['reportDeliveredAt', 'reportOwedAt'])]
  #[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['agentSpaceId', 'accountId', 'entryPointKey'])]
  #[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['purposeName', 'created'])]
  #[LazyLoadRepo(LazyLoadRepo::DB, repoClass: DBAIConversation::class)]
  ```
- A subclass of the message (`AIConversationMessage`) with a column of its own lists it in its context load:
  `public const array CONTEXT_LOAD_COLUMNS = [...parent::CONTEXT_LOAD_COLUMNS, 'itsColumn'];` — the messages service
  reads `getContextLoadColumns()` of the class the host uses, so the column rides every context load.
- A subclass of a filterable entity or set repeats `use QueryOptionsTrait;`: the framework looks for the trait on the
  class itself; without it the host's repository applies no filter, order or `top`.
- A request DTO whose `DtoQueryOptions` names a module class a host may derive uses
  `DtoQueryOptionsOfApplicationClassTrait`, so the host's own columns are filterable and documented.
- A class-wide property hide names the class of the serialized object: module code hides through
  `hidePropertiesOnEveryObjectOfClass()`, which registers on the module's class and on the host's.
- A database entity and its set are built with `X::newInstance()`, never `new X()`; a repository is reached through
  the service or `X::getRepoClassInstance()`, so the host's repository with its read rights runs.
- No closures in regular methods; no `private`, no `final` (the package's contract tests enforce both on its own code).
