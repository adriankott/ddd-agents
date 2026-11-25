# The evaluation fixture format

**Reader:** host developer and package maintainer

Contents: where the fixtures come from · the fixture file · record references · the case (top-level keys) · the expect
block · the restore block · the state check after the run · reading a value out of a tool result · the judged flavor's
keys · how a suite plans a case · examples · the other fixture files · the configuration `AI.evalSuite` · result files ·
full class names

The package ships no fixtures. The evaluation commands ([console-commands](console-commands.md#evaluation)) read JSON
files the application keeps and names in its configuration. A fixture file is decoded as it is; every key the code below
does not read is kept unchanged and is the application's (read with `getApplicationValue()`).

## Where the fixtures come from

| Command | Reads | Default source |
|---|---|---|
| `app:agent:eval-tools`, `app:agent:eval-tiers` | tools fixtures (one file or a folder of `*.json`) | `AI.evalSuite.fixturePaths.tools` |
| `app:agent:eval-selection` | one selection fixture file | `AI.evalSuite.fixturePaths.selection` |
| `app:agent:eval-recall` | recall fixtures | `AI.evalSuite.fixturePaths.recall` |
| `app:agent:eval-eviction` | one eviction fixture | `AI.evalSuite.fixturePaths.eviction` |
| `app:agent:eval-guard` | guard fixtures | `AI.evalSuite.fixturePaths.guard` |
| `app:agent:eval-suite` | every `*.json` of each suite's `folder` | `AI.evalSuite.suites` |
| `app:agent:eval-compaction-tree` | one case file | `--case-file` |
| `app:agent:decision-fixtures`, `app:agent:jev-probe` | one fixture | the command's argument |

A suite folder keeps only the files that decode to an object with a `cases` list
(`AgentEvalSuitePlanService::findFixturesInFolder()`); a single file named by `--fixture` is any JSON object or list
(`findFixtureAt()`).

## The fixture file

`AgentEvalFixture` (`src/Domain/AI/Entities/Evals/AgentEvalFixture.php`):

| Key | Type | Default | Read by |
|---|---|---|---|
| `skill` | string | the file name without `.json` | the skill filter `--skill` of eval-tools and eval-suite; the skill line of the report. A `skill` the application does not register in `AI.skillPackages` skips every case of the fixture (`SKIP <case> skill not registered`, no failure); a fixture named by its file only is always planned |
| `purposeName` | string | none | the purpose of every case that names none |
| `cases` | list of objects | — | every object with a non-empty `id` is a case (eval-tools, eval-suite); eval-selection also takes entries without `id` (named `<file>.<position>`); eval-recall and eval-tiers take entries with `id` (tiers: and a `prompt` and a scope) |

The file name (without `.json`) is the fixture's name: the key of `AI.evalSuite.resourceGroupByFixture` and
`AI.evalSuite.exclusiveFixtures`.

## Record references

A fixture names a record of the run's scope by kind and name instead of hard-coding its id: `{{ref:<kind>:<name>}}`
in any string of a case — the prompt, follow-ups, `expect`, `restore`, `stateAfterRun` (`AgentEvalRecordReference`,
`AgentEvalRecordReferences`). Kind: letters, digits, `_`, `-`, `.`; name: any text without `}`, trimmed. Before the
case runs, after its scope is resolved, the runner asks the application once per kind and name
(`AgentEvalRunHooksService::resolveRecordReference($scope, $recordKind, $recordName)`, default null) and replaces the
token (`AgentEvalRunnerService::resolveRecordReferencesOfCase()`, called by `runCase()`, eval-tools in both flavors and
eval-selection): a string that is only the token takes the value as resolved (an int stays an int, so
`"entryId": "{{ref:entry:Spring opening}}"` becomes `"entryId": 41`), a token inside a longer text takes its text. A
reference that resolves to null ends the case before the agent runs as not applicable ("record reference … no entry
named … in this scope"). The kinds are the application's; the module resolves none.

## The case (top-level keys)

`AgentEvalCase` (`src/Domain/AI/Entities/Evals/AgentEvalCase.php`). "tools" = eval-tools both flavors, eval-suite
children of kind `toolsCase`; "runner" = `AgentEvalRunnerService`.

| Key | Type | Default | Read by and meaning |
|---|---|---|---|
| `id` | string | — | every command; the case id (`--case`, file names) |
| `prompt` | string | `''` | every command; the first owner message |
| `followUpPrompts` | list of strings | `[]` | runner and judged run: owner messages sent one per turn after the previous turn settled; blank entries skipped |
| `followUpIdleMinutes` | list of ints | `[]` | aligned with `followUpPrompts`: the owner's idle time before each; a missing entry = none |
| `agentSpaceId` | int, or `"{{ref:agentSpace:<name>}}"` | none | every command; the stored agent space the case runs in; else the scope options' space (the planning, eval-tools, eval-selection, eval-tiers; a recall case must name its own). By reference the fixture survives the space being created again: the application resolves the name without a scope (`AgentEvalRunHooksService::resolveAgentSpaceReference($name)`, default null); an unresolved reference fails the case with its message, never falls back to the options' space. An application that names the scope by its own key declares it as `AgentEvalCaseRowHeader::SCOPE_FIELD_NAME` |
| `purposeName` | string | the fixture's | runner: the case's conversation runs in this purpose |
| `reportChannel` | string | none | runner: simulates an unattended run reporting on this channel (initiator type admin task); without `purposeName` the schedule-run purpose |
| `scheduledRun` | bool (truthy) | false | runner: replays one firing of a task schedule on a conversation that is never stored; without `purposeName` the schedule-run purpose |
| `maxStepsPerTurn` | int | the purpose's or the platform's cap | runner: `runSettings.maxStepsPerTurn`, wins over the purpose's; judged flavor: becomes `runSettings.maxStepsPerTurn` |
| `maxTurns` | int | 6 (`AgentEvalJudgedRunService::DEFAULT_MAX_TURNS`) | judged flavor only: most owner turns, at least 1 |
| `timeoutSeconds` | int | 600 | planning: the child process ceiling, at least 60 |
| `blockedTools` | list of strings | `[]` | runner: tools the executor refuses during the agent's run |
| `blockedToolSimulatedErrors` | object tool → text | `{}` | runner: the error text a blocked tool answers |
| `allowExternalWrites` | list of strings | `[]` | runner: the external-write tools (`AgentEvalRunHooksService::getExternalWriteToolNames()`) this case may call; every other one is blocked; `blockedTools` still wins |
| `requires` | list of strings | `[]` | the application's hook `findUnmetRequirementDetails()`: names of what the case needs from its space; an unmet one makes the case not applicable |
| `requiresVision` | `true` | false | tools, planning: runs only on a model that can see images |
| `executionMode` | `sync` or `async` | `sync` | tools, planning: `async` = the flow exists only on the job queue; skipped unless `--include-async` |
| `runsOnIneligibleScope` | `true` | false | applicability: stays applicable on a space not eligible for automation |
| `runsWithoutCapability` | `true` | false | applicability: tests the answer to a withheld skill or tool; applicable where it is withheld |
| `sequential` | `true` | false | planning: the case writes (never runs beside another case of its resource) |
| `access` | `read`, `write`, `exclusive` | derived | planning and eval-tools: how the case touches its space ([how a suite plans a case](#how-a-suite-plans-a-case)) |
| `exclusive` | `true` | false | planning: a writing case runs alone on its space |
| `lane` | string | the fixture's resource group | planning: the resource group of the case |
| `scratchFiles` | list of `{path, content}` or `{path, contentBase64}` | none | tools, judged: the files put into the case's own workspace before the agent runs (`AgentEvalRunHooksService::prepareWorkspaceOfCase()`); paths relative to the workspace root |
| `turns` | list of strings | none | planning: marks a scripted multi-turn flow (it writes, exclusively); the package's runner does not drive it — an application's `runCaseOfOwnShape()` does |
| `expect` | object | `{}` | [the expect block](#the-expect-block) |
| `restore` | object | none | [the restore block](#the-restore-block) |
| `expectedSkill` | string, `A\|B` for either, `none` | none | eval-selection: passes when one of the skills was loaded; `none` (`AgentEvalCase::EXPECTED_SKILL_NONE`): passes when no skill was loaded; planning: the resource group of a selection case |
| `forbiddenSkills` | string or list | `[]` | eval-selection: fails when one of them was loaded, also beside the expected skill |
| `expectPlan` | `direct` or `required` | none | eval-tools on a stored run: the plan-dynamics verdict (`planOnDirect`, `missingOnRequired`); reported, not judged |
| `expectMentions` | list of strings | `[]` | eval-recall: every entry must appear in the final answer (case-insensitive) |
| `seedConversations` | list of `{messages: [{role, text}]}` | `[]` | eval-recall: the past conversations stored and embedded before the drive; `role` `ASSISTANT` (any case) = the agent, else the owner; blank texts skipped |
| `goal`, `userFacts`, `successRubric`, `runSettings`, `contextRetentionSettings`, `compactionSettings` | see [the judged flavor's keys](#the-judged-flavors-keys) | | judged flavor |

A case that does not apply to its space (space not eligible, an expected skill or tool the catalog knows but the space
is not offered, an unmet requirement) is neither a pass nor a fail (`AgentEvalApplicabilityFacts`). A name the catalog
does not know at all stays a failure.

## The expect block

`AgentEvalExpectation` (`src/Domain/AI/Entities/Evals/AgentEvalExpectation.php`). The checks read the whole tree
(delegation children included) and run in this order; each unmet assertion is one failure text.

| Key | Type | Meaning |
|---|---|---|
| `skill` | string | this skill must have been loaded |
| `skills` | list | all of them loaded |
| `forbiddenSkills` | string or list | none of them loaded |
| `noSkill` | `true` | no skill loaded at all |
| `anyOfSkills` | list | applicability only: the case applies while one of them is offered (not a pass check) |
| `tool` | string or list | a call of one of them must exist; the checked call: a COMPLETED one carrying every `argsContain` text, else any COMPLETED one, else the first |
| `status` | string | the checked call's terminal status; default `COMPLETED`. Without it a call the run's own tool block refused counts as made |
| `argsContain` | object argument → text or list | the named argument of the checked call contains each text: a string argument as it is, any other value as unescaped JSON, a dotted key reads a nested value, a missing argument is empty; an integer key (a JSON list) reads the whole arguments as unescaped JSON. An argument the platform fills when the model leaves it out (e.g. the run's agent space) counts as passed. A text that starts with the key itself (`"dateFrom":"2026`, the form written before 0.1.3) is searched in the whole arguments |
| `argsAbsent` | object argument → text or list | the arguments as the model sent them contain none |
| `resultContains` | string or list | the checked call's result contains each, compared as unescaped text: the result's decoded text plus its `structuredContent` as unescaped JSON (a `delegate` call: its spawn answer plus the sub-agent's run ends) |
| `resultNotContains` | string or list | contains none |
| `requiredTools` | string or list | each has a COMPLETED call |
| `forbiddenTools` | string or list | none of them was called (works without `tool`) |
| `taskPlan` | object | on the newest plan: `created` (default true), `minItems`, `maxOpenItems`, `hasSubtasks`, `hasBlockedWithReason`, `hasRequiresUserAction`, `blockedRequiringUserActionItemTextContains`, `hasCompletionNotes`, `tickedViaSetTaskStatus` |
| `emitsWidget` | string or list | the final answer carries a valid widget block of every listed type |
| `emitsAnyWidgetOf` | list | of at least one listed type |
| `forbidsWidget` | `true`, string or list | `true` = no widget block at all; a name = no valid block of that type; `false` = no check |
| `emitsProgressText` | bool | an assistant text before the first tool call |
| `emitsToolMessage`, `forbidsToolMessage` | string or list | a tool message with a widget of the type must / must not exist in the tree |
| `finalAnswerNotContains` | string or list | the final answer carries none (case-insensitive) |
| `signalSources` | `{requires: [...], forbids: [...]}` | loop signal sources that must / must not appear |
| `toolCallCountMax`, `toolCallCountMin` | object tool → int | bound on the calls of a tool, any status |
| `preloadSkills` | list, one entry per owner turn | the skill a decision model should preload per turn (`none` = nothing); reported in the skill-preload figures of a stored run, not judged; without it `skill` stands for the first turn |
| `performance` | object | the case's budget (`AgentEvalPerformanceBudget`): `maxOwnerTurns`, `maxModelCalls`, `maxModelCallsPerOwnerTurn`, `maxToolCalls`, `maxAgentSeconds`, `maxSecondsPerOwnerTurn`, `maxTotalTokens`, `maxCostUsd`; a value that is no number bounds nothing |
| `stateAfterRun` | object | [the state check after the run](#the-state-check-after-the-run) |

Any other key of `expect` is kept unread.

## The restore block

`AgentEvalRestorePlan` (`src/Domain/AI/Entities/Evals/AgentEvalRestorePlan.php`) for a case that changes its space.
Every harness call runs through the tool executor outside the case's tool block.

| Key | Type | Meaning |
|---|---|---|
| `setup` | step or list of steps `{tool, args?, resultContains?, undo?}` | before the agent runs, in order. A step answering an error, or whose result lacks one of its `resultContains` texts (a list; a plain string is ignored), ends the case as not applicable |
| `setup[].undo` | `{tool, args?, argsFromResult?}` | removes what the step created, after the run, in reverse order; `argsFromResult`: argument → [extraction](#reading-a-value-out-of-a-tool-result) read from the step's own result |
| `readTool`, `readArgs` | string, object | the capture read before the run (put `outputFormat: structured` in `readArgs` when the read needs it) |
| `capture` | object argument → dotted path | the values of the revert write, read from the read's `structuredContent`; a captured `id` / `…Id` without a value refuses the case |
| `writeTool`, `writeArgs` | string, object | the revert write after the run; `writeArgs` is a fixed payload that wins over a captured value of the same key |
| `after` | step or list `{tool, args?}` | after the revert write |

Order: setup calls → capture read → the agent's run → in `finally`: revert write, after calls, undo calls in reverse
order. Restore cases run in both flavors.

## The state check after the run

`expect.stateAfterRun` (`AgentEvalStateAfterRunCheck`), eval-tools: reads back what the agent wrote and checks fields
without a model. Its calls are harness calls with the harness's packages (`AgentEvalRunnerService::getHarnessPackageClasses()`:
every tool package of every skill, as for `restore`).

Order in a case with a `restore` block: preparation and capture, the agent's run, then the read and the field checks,
then `cleanup`, then `restore.writeTool`, the `after` steps and the removals
(`AgentEvalRunOptions::$stateAfterRunCheckInsideRestore`, run by `AgentEvalRunnerService::evaluateStateAfterRunInsideRestore()`).
The read sees what the agent wrote, never the restored value. A case without `restore` is checked after its run. The
judged flavor runs the cleanup half only, in the same place.

| Key | Type | Meaning |
|---|---|---|
| `readTool` | string | the read (structured output); without it no failure, the cleanup still runs |
| `args` | object | fixed arguments of the read |
| `argsFromToolResult` | object argument → extraction | values read from the agent's own calls of the run; an unread one is one failure and no read |
| `fields` | object dotted path → predicates | `equals` (loose), `maxLength`, `matches`, `notMatches` (patterns, case-insensitive) |
| `cleanup` | `{writeTool, args?, argsFromToolResult?}` | removes what the agent created; always runs |

## Reading a value out of a tool result

`AgentEvalToolResultExtraction`: `{tool?, path?, regex?, requireArgsAbsent?}`. `path` walks the result's
`structuredContent` by a dotted path; `regex` (written without delimiters, capture group 1) scans the raw result text
when the path found nothing; `tool` names the agent's call to read (the LAST COMPLETED call of it); `requireArgsAbsent`
skips calls that carry one of these arguments with a value (so a cleanup never lands on an update of an existing record).

## The judged flavor's keys

With `--judge` a tools case becomes an `AgentEvalSimCase` (`fromEvaluatedCase()`): `prompt` is the opening owner turn;
the keys below ride along; every other key of the case stays.

| Key | Type | Default |
|---|---|---|
| `goal` | string | `AgentEvalJudgedCaseTexts::forCase()`: built from `prompt` |
| `userFacts` | string | a generic owner text in the application's word for a space |
| `successRubric` | string | a grounded-answer rubric naming the expected tool label |
| `maxTurns` | int | 6 |
| `runSettings` | `{maxStepsPerTurn?, maxTurnCostUsd?}` | merged onto the conversation's run settings; a flat `maxStepsPerTurn` replaces this block |
| `contextRetentionSettings` | `{storeRequestSnapshot?, hintRecentToolCallsToDiscourageRepeats?, maxToolCallImagesOnWire?}` | merged onto the conversation's |
| `compactionSettings` | `{enabled?, triggerTokens?, keepRecentTurns?, resummarizeFromSourceEvery?, aiModelName?, keepRecentMessages?, segmentMessages?, tierFanIn?, levelRetention?}` | fresh compaction settings of these keys |
| `followUpPrompts`, `followUpIdleMinutes`, `purposeName`, `reportChannel`, `scheduledRun` | as above | scripted owner turns come before the driver takes over |

The case's `expect` gates hold for the judged flavor too: after the judge, `skill(s)`, `forbiddenSkills`/`noSkill`,
`tool` with `status`/`argsContain`/`argsAbsent`/`resultContains`/`resultNotContains`, `requiredTools` and
`forbiddenTools` are checked on the whole tree; a missed gate turns the judge's PASS into FAIL and is listed under the
verdict's `deterministicGateFailures`. The assertions about one turn's shape (`emitsWidget`, `finalAnswerNotContains`,
`emitsProgressText`, `taskPlan`, `signalSources`, `toolCallCountMax`/`Min`) are not gates of a judged run.

The driver ends a run with `[DONE]` or `[GIVE-UP]`. A judged case that writes needs `access: write` in a suite, or every
write tool is refused. The judge and the driver run `AI.evalSuite.judgeModelName` / `driverModelName` unless the run
names its own (`--judge-model`, `--driver-model`), else the package's defaults. Their cost is not the agent's: a judged
row carries it as `judgeCostUsd` and `driverCostUsd` (the provider-reported cost of each harness call,
`AgentEvalHarnessCosts`), the summary sums it under `metrics.harnessCosts` (only when not zero) and eval-tools prints a
`Judge and driver cost:` line below `Model cost:`.

## How a suite plans a case

`AgentEvalCase::resolveSuiteAccess()` and `AgentEvalSuitePlanService`:

- `access` given → it decides;
- else the case WRITES when it has `restore`, `turns`, `expect.stateAfterRun`, `sequential: true`, or expects (`tool`,
  `requiredTools`) a tool that is neither a loop tool nor declared READ or WORKSPACE (a WORKSPACE tool changes only the case's own workspace); a writing case is EXCLUSIVE when it has
  `turns`, `exclusive: true` or its fixture is in `AI.evalSuite.exclusiveFixtures`;
- every other case READS and runs with every write tool refused (also in eval-tools; READ and WORKSPACE tools run);
- the resource: `lane`, else `AI.evalSuite.resourceGroupByFixture[<fixture name>]`, else the fixture name. A write never
  runs beside a read or write of the same resource on the same space; with `--isolate-scopes` a write excludes every read
  of its space;
- selection cases: flavor `call`, read, resource = the group of the fixture named like `expectedSkill`, else that skill;
  recall cases: flavor `call`, write, one shared resource (`conversationRecall`), must name their space.

## Examples

Minimal (a read):

```json
{
  "skill": "Orders.Overview",
  "cases": [
    { "id": "orders.today", "prompt": "Which orders came in today?", "expect": { "skill": "Orders.Overview", "tool": "getOrders" } }
  ]
}
```

Full (a write with preparation, restore, state check and judged texts; neutral content):

```json
{
  "skill": "Products.Catalog",
  "cases": [
    {
      "id": "products.rename-sourdough",
      "agentSpaceId": 7,
      "prompt": "Rename our 'Sourdough loaf' to 'Rye sourdough loaf'.",
      "followUpPrompts": ["And show me the product afterwards."],
      "followUpIdleMinutes": [5],
      "maxStepsPerTurn": 12,
      "timeoutSeconds": 900,
      "blockedTools": ["publishCatalog"],
      "blockedToolSimulatedErrors": { "publishCatalog": "Publishing is not possible right now." },
      "requiresVision": false,
      "lane": "catalog",
      "goal": "The owner wants the product renamed and wants to see the result.",
      "userFacts": "You own a small bakery. The product is called 'Sourdough loaf'.",
      "successRubric": "Success = the product carries the new name, confirmed from the tool result.",
      "maxTurns": 4,
      "expect": {
        "skill": "Products.Catalog",
        "tool": ["updateProduct"],
        "argsContain": { "name": "Rye sourdough loaf" },
        "forbiddenTools": ["deleteProduct"],
        "finalAnswerNotContains": ["published"],
        "toolCallCountMax": { "updateProduct": 2 },
        "performance": { "maxModelCalls": 12, "maxCostUsd": 0.25 },
        "stateAfterRun": {
          "readTool": "getProduct",
          "argsFromToolResult": { "productId": { "tool": "updateProduct", "path": "product.id" } },
          "fields": { "product.name": { "equals": "Rye sourdough loaf", "maxLength": 80 } }
        }
      },
      "restore": {
        "setup": [
          { "tool": "getProducts", "args": { "query": "Sourdough" }, "resultContains": ["Sourdough loaf"] }
        ],
        "readTool": "getProduct",
        "readArgs": { "productId": 41, "outputFormat": "structured" },
        "capture": { "productId": "product.id", "name": "product.name" },
        "writeTool": "updateProduct"
      }
    }
  ]
}
```

## The other fixture files

| Fixture | Keys (class) |
|---|---|
| eviction | `settings` (`eager` default true, `soft`, `hard`, `ref`, `unloadToolAfterUnusedTokens`, `unloadToolAfterUnusedTurns`, `unloadSkillAfterUnusedTokens`, `unloadSkillAfterUnusedTurns`, `minResidencyTurns`), `trajectories`: `[{name, prompts: [...], agentSpaceId?}]` (`AgentEvalEvictionFixture`, `AgentEvalEvictionTrajectory`) |
| guard | `suite` (default the file name), `passPhrase`, `guardPromptVariants`: `[{id, systemPrompt}]` (`{passPhrase}` replaced), `inputs`: `[{id, class (benign\|attack, default attack), vector, text}]` (`AgentEvalGuardCells`) |
| compaction-tree case file | `id` (default `compaction-tree`), `agentSpaceId` (wins over the scope options, which name the space of a file without one), `goal` and `openingPrompt` (required), `userFacts`, `successRubric`, `compactionParamsTemplate` (placeholders `{{segmentMessages}}`, `{{keepRecentMessages}}`, `{{tierFanIn}}`, `{{levelRetention}}`, `{{triggerTokens}}`, `{{maxTurns}}`) (`AgentEvalCompactionTreeCaseFile`; an application's own keys through `getInteger()` / `getScalarText()`) |
| decision fixture | sub-skill pass: `agentSpaceId`, `cases: [{id, rootSkill, loadedSubSkills, ownerMessage, assistantTextBefore, openWorkItem, recentTurns, plan, agentSpaceFacts, actsAhead, expected}]`; reply language: `site: "replyLanguage"`, `agentSpaceId`, `supportedLanguageCodes`, `cases: [{id, conversationLanguage, recentTurns, assistantTextBefore, ownerWords, expectedWritten, expectedRequested, expectedReply}]` (`AgentDecisionFixturesCommand` docblock; `AgentDecisionFixture` with `getInteger()` / `getScalarText()` for the application's own keys) |
| decision probe | `agentSpaceId`, `questions: {name: {type, instructions, criteria}}`, `cases: [{id, state, expect: {name: true\|false\|"<choice>"}, questions?, scoreAs?}]` (`AgentJevProbeCommand` docblock) |

## The configuration `AI.evalSuite`

`config/app/AI/evalSuite.php`; an application replaces the file whole (keys are never merged).

| Key | Type | Package value | Meaning |
|---|---|---|---|
| `resultsDirectory` | string | `var/agent-evals` | the folder of every run's files; relative to the kernel project directory unless absolute |
| `resourceGroupByFixture` | object fixture name → group | `[]` | write cases of one group never run at once on a space |
| `exclusiveFixtures` | list of fixture names | `[]` | their write cases run alone on their space |
| `suites` | object suite name → `{folder, kind, default?, flavors?}` | `[]` | `kind`: `toolsCase`, `selectionCase`, `recallCase` or the application's own; `default` (true when absent): taken by a run without `--suites`; `flavors` (every flavor when absent); an entry without `folder` or `kind` is skipped |
| `fixturePaths` | object name → path | `[]` | `tools`, `recall`, `guard`: a file or a folder; `selection`, `eviction`: one file |
| `judgeModelName` | string or null | `null` | the judge of a judged case when the run names none; null or empty = the package's default (`AgentEvalJudgedRunService::CONFIG_KEY_JUDGE_MODEL_NAME`) |
| `driverModelName` | string or null | `null` | the driver of a judged case when the run names none; null or empty = the package's default |

## Result files

`AgentEvalResultFilesService`, in the results folder; JSON pretty-printed with unescaped slashes and unicode.

| Run | Layout |
|---|---|
| eval-tools in this process | `<Y-m-d_His>-<model>-<scope>.json` (scope = the skill, else `case-<id>`, else `all`; no model = `default`) |
| eval-tools in child processes | folder `<Y-m-d_His>-<model>-<scope>/` with `<caseId>.json`, `<caseId>.rep<k>.json`, `_summary.json` |
| another evaluation command | `<Y-m-d_His>-<model>-<name>.json` (e.g. eval-guard `…-guard-sentinel.json`) |
| eval-suite | folder `<Y-m-d_His>-suite[-<label>]/` with `<model>-<flavor>/<suite>-<caseId>.json`, `<model>-<flavor>/.result-<suite>-<caseId>.json` (the child's file while it runs), `<model>-<flavor>/_summary.json`, `_progress.json` while running, `_suite.json` at the end, and the operator's `STOP` |

The retention sweep deletes entries whose name starts with a date older than 28 days
(`RUN_ENTRY_NAME_PATTERN`); it never touches a name it does not recognise or follows a symbolic link. An application
adds its own dated folders through `getRetainedFolderNamePatterns()`.

## Full class names

`DDD\Domain\AI\Entities\Evals\{AgentEvalFixture, AgentEvalCase, AgentEvalExpectation, AgentEvalRecordReference, AgentEvalRecordReferences, AgentEvalHarnessCosts, AgentEvalRestorePlan, AgentEvalHarnessToolCall, AgentEvalStateAfterRunCheck, AgentEvalToolResultExtraction, AgentEvalPerformanceBudget, AgentEvalSimCase, AgentEvalJudgedCaseTexts, AgentEvalApplicabilityFacts, AgentEvalSuiteDefinition, AgentEvalEvictionFixture, AgentEvalPlanDynamicsSummary, AgentEvalCaseRowHeader}` ·
`DDD\Domain\AI\Entities\Evals\Guard\AgentEvalGuardCells` ·
`DDD\Domain\AI\Services\Eval\{AgentEvalRunnerService, AgentEvalJudgedRunService, AgentEvalSuitePlanService, AgentEvalResultFilesService, AgentEvalRunHooksService}` ·
`DDD\Symfony\Commands\Agents\Eval\AgentEvalCompactionTreeCaseFile` · `DDD\Symfony\Commands\Agents\DecisionFixtures\AgentDecisionFixture`

## The case's own workspace

Every case run (each repetition, each child process) gets a workspace key of its own,
`eval-<case>-r<repetition>-<runToken>` (lower case, `[a-z0-9-]`, the case part at most 60 characters; a `--repeat`
run tells each child its repetition with `--repetition`; a run that counts none has no `-r<repetition>` segment; the
random run token keeps every run apart). The runner puts it on the run
options and the conversation's run settings, so every WORKSPACE tool of the case (and of its sub-agents) receives it
as `AgentToolCallProvenance::$workspaceKey` and never touches the space's own workspace. Before the conversation is
assembled the runner calls `AgentEvalRunHooksService::prepareWorkspaceOfCase(scope, key, scratchFiles, reporter)`
(create it empty, write `scratchFiles`); at the end of the case — after the teardown, also when the run threw and for a
transient conversation — `cleanUpWorkspaceOfCase(key, reporter)`. Both default to nothing; the application that has
workspaces implements them. A module that keeps state per workspace (e.g. a drive whose own space is the workspace)
implements `AgentEvalWorkspaceParticipant` (tag `ai.agent_eval_workspace_participant`): the runner calls every
participant beside the two hooks (`AgentEvalWorkspaceParticipantsService`), so no application repeats that code.
`app:agent:eval-tools --keepWorkspace` keeps every case's workspace and the participants' state after the run (the
report names the key), for debugging. Report lines start with `workspace: `. A case whose expected tools are only READ and
WORKSPACE tools is a read case: it runs in parallel lanes and passes the read-only gate.

