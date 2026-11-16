# Changelog

## 0.1.0-alpha.59

- The video and map tiles carry widget names: the node type `VideoCard` is `VideoWidget` (folder
  `kit/components/media/video-widget/`, `video-widget-values.ts`, preview fixtures `youtube-video-widget*` in
  `video-widget.preview-fixtures.json`), `MapPointCard` is `MapPointWidget`. Stored trees with the node type
  `VideoCard` still render (as `VideoWidget`); the old fixtures export path stays for one release.
- Test ids: `video-card…` → `video-widget…`, `map-point-card…` → `map-point-widget…`.

## 0.1.0-alpha.58

- The evaluation suite's parts ship in the module: the suite objects (`AgentEvalSuiteTask(s)`,
  `AgentEvalSuiteSchedule`, `AgentEvalCaseRow(s)`, `AgentEvalRunSummary`, `AgentEvalBaselineChoice` /
  `AgentEvalBaselineComparison`, `AgentEvalSuiteRecord`, …) with the planning service `AgentEvalSuitePlanService`,
  the result files `AgentEvalResultFilesService` (results folder `AI.evalSuite.resultsDirectory`, default
  `var/agent-evals`; baseline read; retention sweep), the run report `AIConversationRunReportService` and the command
  `app:ai-agent:run-report`. New config file `config/app/AI/evalSuite.php`.
- Two new tables: `AgentEvalSuiteRuns` and `AgentEvalSuiteCases` (entities `AgentEvalSuiteRun`, `AgentEvalSuiteCase`;
  with a table prefix e.g. `EntityAgentEvalSuiteRuns`). An admin with access to all resources reads every row, every
  other account none. A host that already has these tables keeps them: the columns are the same.
- Two new tables need their DDL in a host (schema diff): `AgentEvalSuiteRuns`, `AgentEvalSuiteCases`.

## 0.1.0-alpha.57

- The task of a sub-agent (`DelegationSubAgentRecord::$task`, `DelegateToolArguments::$task`,
  `AgentSubAgentRequest::$task`) is typed as its three concrete classes
  (`AgentDelegationAgentTask|AgentDelegationToolRunTask|AgentDelegationAwaitedRunTask`) instead of the abstract
  `AgentDelegationTask`: the API documents describe each shape (`oneOf`), so generated client models have their
  fields. `objectType` picks the class, as before; the task factories return the same union.

## 0.1.0-alpha.56

- Append-only loading: the decision model's preload on a conversation's first row is no part of the first epoch's
  base set (`LoadedCapabilities::withoutSkillsLoadedBySource()`): the turn's first model call appends it behind the
  `load_skills` call and its result of 0.1.0-alpha.55, with its `additional_tools` item and its instructions — the
  shape of the model's own load. The base prefix (prompt and base tools) is the same for every new conversation,
  whatever the preload picked. A structural or plan-item load on row 0 stays in the base set. A conversation already
  running appends such a skill once on its next call (one cache miss).

## 0.1.0-alpha.55

- Append-only loading: skills the platform loaded before the turn's first model call (a preload: decision model, plan
  item, structural) are appended behind a `load_skills` call and its result — the shape of the model's own load —
  in front of the item that carries their tools and instructions. Seen only as instructions, a preloaded skill was
  loaded again as the model's first call. Recorded in `RequestSnapshot::$appendedPreloadLoadCallSkillNames`,
  re-rendered byte for byte by the later calls of the epoch (call id `call_preload_<row index>`); the result text is
  `AIConversationMessage::LOAD_SKILLS_RESULT_LOADED`. The system-prompt swap is unchanged.
- A tool's `@example {}` of its argument set goes out as `"examples":[{}]`, no longer `[[]]`.

## 0.1.0-alpha.54

- A `load_skills` call the step held back (append-only loading: refused with the held-back text, never run) is no
  skill load of the turn: the prose answer after it is a reply, no longer nudged as a stall
  (`AIConversationTurnMessages::hasSkillOrToolLoadCall()`).

## 0.1.0-alpha.53

- "Card" names of conversation elements become "widget" names; the old exports stay as deprecated aliases for one
  release: `CanvasWidgetCard` → `CanvasLauncherWidget` (`CanvasWidgetCardProps` / `CanvasWidgetCardCanvas` →
  `CanvasLauncherWidgetProps` / `CanvasLauncherWidgetCanvas`), `ConversationErrorCard` → `ConversationErrorWidget`
  (`…Props`), `CardFooter` → `WidgetFooter`; internal: `ConversationSuggestionCard`, `ConversationIntroCard`,
  `ConversationMessageInputAttachmentPreviewCard` → `…Widget`, `EntityMentionCard` → `EntityMentionWidgetBody`.
- The canvas origin `cardClick` is `widgetClick`; `cardClick` (`LEGACY_CARD_CLICK_ORIGIN`) still opens like it.
- Test id `writing-draft-widget-card` → `writing-draft-widget`. `VideoCard`, `MapPointCard` (tiles inside a widget)
  and their test ids stay.
- The npm package of 0.1.0-alpha.52 was not published (its tag carried the renamed files without the import
  updates); this release carries 0.1.0-alpha.52's change too.

## 0.1.0-alpha.52

- The driver's and the judge's model calls of a judged run (`AgentEvalJudgedRunService::callModel()`) send the
  harness system prompt and the prompt as the one message — none of the platform's sections of an agent call (owner
  presence, skill catalog, memories, product, clock); `buildHarnessModelInput()` builds it.

## 0.1.0-alpha.51

- After a model step that only repeated `load_skills` (every call loaded nothing new), `load_skills` is held back for
  the ONE next model call; the tool choice stays the step's. Under the system-prompt swap that call sends the tools
  without `load_skills`; under append-only loading the tools stay on the wire byte for byte and a `load_skills` call is
  refused with the held-back text. The call after it offers `load_skills` again
  (`AIConversationTurnMessages::hasNewestModelStepOnlyRepeatedSkillLoads()`, trace `turn.step.skillLoadsHeldBack`).
- The widget-image renderer reads the capture read path through `fetch` like through axios: a GET without body, with
  the capture token, without the page's credentials, `debug` and `noCache`; anything else on that path fails the
  capture (`WIDGET_HYDRATION_FAILED`). A widget whose client uses `fetch` renders live.

## 0.1.0-alpha.50

- A model step that only repeated `load_skills` leaves the wire only when a newer such step exists: the NEWEST one
  stays, so the next step reads its answer ("Already loaded … call them"); with it removed the model saw nearly the
  same input and made the same call again.
- A `load_skills` name that is no skill: when it equals ONE catalog name once its dots and its case are ignored, that
  skill loads and the answer starts `Loaded <name> (you wrote "<given>").`; a call that still named a name that is no
  skill (`ToolCall::$loadSkillsCallNamedNoSkill`) is no repeated load and stays on the wire, and the `[SIGNAL:skills]`
  tail line names only skills that are loaded.
- The stall text after a prose answer that followed a skill load: when the loaded skill itself says the request takes
  no tool, the prose answer was right; otherwise call the tool. The text after an empty step is unchanged.
- The checked-stop bounce names the items whose result is text in the answer in their own group ("Write that text in
  the answer you give now — the platform closes them from it; never tick them.") and no longer under "Items you tick"
  (`TaskPlanItems::getOwedOpenAnswerTextItemTitleLines()`, `getOwedOpenItemTitleLines(..., $includesAnswerTextItems)`); its closing
  sentence then says the answer is accepted and such an item stays open when its text is missing.
- The platform's ONE_INSTRUCTION consent check of a refused call names the call: `requestedToolName` and
  `requestedToolSummary` in the payload, and the approval prompt judges consent to THAT call. An application with its
  own copy of `AI/Mandates/ApprovalCheck.md` adds the line.

## 0.1.0-alpha.49

- The judged run of an eval case moves into the package: `AgentEvalJudgedRunService` (extends
  `AgentEvalRunnerService`) — `runSim()` / `runSimWithRestore()` (a driver model plays the owner after the opening
  prompt and the case's scripted follow-ups, a judge model returns the verdict; the restore bracket around the run),
  `runCompactionTreeEval()` (a long conversation whose compaction tree is judged; a stored run reads the stored tree),
  `callModel()` (a plain completion in `ENVELOPE.AI.AGENT.EVALUATION`, booked as `AI.AGENT.SIMULATE.OWNER` or
  `AI.AGENT.JUDGE.EVALUATION`), `judgeVerdict()` (an unparseable answer is asked once more; a cut-off verdict is judged
  once more, a second cut-off is `ERROR`), the owner-visible transcript and the judge's trace
  (`AgentEvalJudgeTraceMessageLines`, the audit blocks, `AgentEvalRunCounts`). Override points with neutral defaults:
  `applyCaseFixtures()`, `buildHostEndStateTextForJudge()`, `buildTextOfHostWidgetShownToOwner()`,
  `applyHostSectionsToVerdictPrompt()`, `isHostOwnerReplyRow()` and the texts `getEmptyDriverReplyText()`,
  `getEmptyCompactionTreeDriverReplyText()`, `getDefaultCompactionTreeRubricText()`,
  `getTruncatedTwiceVerdictReasons()`; default models `getDefaultJudgeModelName()` / `getDefaultDriverModelName()`.
- Objects of the judged run: `AgentEvalSimCase`, `AgentEvalJudgeVerdict`, `AgentEvalRunCounts` (stored count key
  `toolWidgets`), `AgentEvalSimRunResult`, `AgentEvalCompactionTreeRunResult`, `AgentEvalJudgedRunOptions`,
  `AgentEvalRestoreBracket`.
- Four prompt files under `config/app/AI/Prompts/AI/Agent/Evaluation/` (`Driver.md`, `Verdict.md`,
  `CompactionTreeDriver.md`, `CompactionTreeVerdict.md`) named by `AgentAIPromptNames::APP_AI_AGENT_EVALUATION_*`; an
  application replaces a file whole.
- `AgentEvalRunnerService`: the restore bracket is split into `openRestoreBracket()` / `closeRestoreBracket()`
  (`runWithRestore()` unchanged), `assembleConversation()` is taken out of `buildConversation()`, and the follow-up
  owner message is written by `appendOwnerMessage()`, which on a stored conversation also stamps the inbound activity
  (`recordInboundActivityIfPersisted()`).
- The observability skill names the judged run (reference `building-evaluation-in-the-host.md`, § The judged run).
- The two run results (`AgentEvalSimRunResult`, `AgentEvalCompactionTreeRunResult`) are read through their getters; they
  have no array form.

## 0.1.0-alpha.48

- Tick rules: the sentence that a question to the owner is never an answer-text item stands on its own after the
  `inAnswer` passage; inserted before "mark it", it read as an order to mark the question item.

## 0.1.0-alpha.47

- The model's takeover load of a preloaded skill names the skills and their tools ("Skills loaded: <names>. Their
  tools are in your tool list — <tools>. Call the one you need next."); after it nothing new arrives on the wire, and a
  bare "loaded" made the model load again. The `[SIGNAL:skills]` line after a repeated load names the tools too.
- An open `inAnswer` leaf behind a leaf that is still open says so on its plan line
  (`TaskPlanItem::ANSWER_TEXT_AFTER_OPEN_ITEMS_MARK`: written by the answer of the turn that finished the items before
  it); the answer step after an owner question names only the items whose line says "write it in the answer of this
  turn"; the tick rules say a question to the owner is never such an item and that its answer comes after the items
  before it. A draft of part 3 was written while part 2 still waited for the owner.

## 0.1.0-alpha.46

- One evaluated run of one eval case moves into the package: `AgentEvalRunnerService` (single and follow-up turns,
  purpose, persistence, reload, restore, state check, teardown, applicability, eviction trajectory, recall seed, trace)
  with `AgentEvalRunHooksService` (13 hooks, neutral defaults), the case, scope, options, outcome, expectation,
  figures, restore plan, harness tool calls (`AgentEvalHarnessToolCalls`), extractions
  (`AgentEvalToolResultExtractions`), applicability (`AgentEvalNotApplicableReasons`) and
  `AgentEvalPreconditionNotEstablishedException`; `TaskPlanSize::fromMessagesOfConversation()`. The run applies the
  case's tool block itself for every caller; the state check lifts it for its read and cleanup; a case file's message
  trace no longer carries the conversation back-reference; the tool-message failure text says "widget".
- Turn-event subjects as constants: `AIConversationTurnEvent::SUBJECT_PLAN_RETIREMENT`, `SUBJECT_SKILL_PRELOAD_RERANK`.
- `load_skills` for a skill only the platform preloaded, asked by the model for the first time, answers "Skills
  loaded; their tools are now available." and the model takes the skill over (`source` model); "Already loaded" to
  that first own load made the model ask again and again. Repeats after that are answered as before.
- An `inAnswer` leaf that a turn's answer did not close (the turn ended on the owner's question, or an earlier leaf
  was open) records `answerTextHeldOpenAtMessageIndex`: its plan line says an earlier answer may already have given the
  text, and the model's done tick is accepted instead of a second copy. The tick rules say so.

## 0.1.0-alpha.45

- A model step that only repeated `load_skills` for skills already loaded is not replayed to the model: its call and
  result rows leave the wire (`ArgusAIAgentConversation::withoutStepsThatOnlyRepeatedSkillLoads()`; the stored rows
  stay; a prose head stays; a step with any other call stays; kept under the server-state wire), and the next step's
  tail says `[SIGNAL:skills] <skill> is loaded — its tools are in your tool list now: call the tool.` The model
  repeated its own freshest pattern instead of calling the loaded tool (21 of 1,237 runs loaded ≥ 3 times). The
  tools and the system content are unchanged.

## 0.1.0-alpha.44

- An `inAnswer` plan item is not closed by the answer of a turn that ends on the model's question to the owner (a
  completed `askOwner` with no later call but plan bookkeeping): it stays open and a later answer closes it. The
  answer that asked "go on with the drafts?" closed drafts never written. The tick rules say so.
- A plan the platform's turn-end close finishes retires like one the model's ticks finished: the owner's next message
  reopens it (keep the finished items by their keys) instead of being told to drop its items.
- The plan directive asks again for variants and sub-steps with their own result as subtasks of a part (and never
  an item or a subtask for loading a skill or another preparatory call).
- `[SIGNAL:clock]` also names this month and last month with their first and last day; the recorded-run comparison
  normalizes their month names.

## 0.1.0-alpha.43

- `inAnswer` plan items: the open line says the text belongs in this turn's answer (`TaskPlanItem::ANSWER_TEXT_MARK`);
  the answer step after a recorded owner question asks for the text of every such item; and the turn-end closer
  closes one only when no leaf before it in plan order is still open — an answer that asked the owner about an
  earlier part closed a draft it never carried.
- Owner-consent decision (`owner_explicitly_agreed_afterwards`): a wish in the same line about something the step does
  not change ("yes, go ahead — and the opening hours stay as they are") leaves the yes standing; what refuses is a yes
  that depends on something, moves to a later time, covers only part of this step, or keeps part of what the step
  changes. A yes with such a wish was read as a condition and the owner was asked again.

## 0.1.0-alpha.42

- The module ships eleven more console commands as bases an application subclasses under the same name: the chat
  (`app:ai-agent:chat`) and the full-screen chat (`app:ai-agent:chat-tui`, with the suggested `php-tui/php-tui`), the
  conversation inspector (`app:ai-agent:inspect`), the reaper (`app:ai-agent:reap`), the owed-turn recovery
  (`app:ai-conversation:deliver-owed-reports-and-resume-owed-turns`), the schedule state sweep
  (`app:ai-schedule:close-for-inactive-agent-spaces`; an application keeps an old name as an alias with
  `name: '<package name>|<old name>'`), the decision probe (`app:ai-agent:jev-probe`), the scorecards command
  (`app:ai-conversation:scorecards`), the MCP documentation and cache commands (`app:mcp:documentation`,
  `app:mcp:clear-cache`) and the node-schema export (`app:ai:composed-view:export-node-schema`).
  `MCPPackage::buildDocumentation()` takes `$noCache` like the bundle, and `clearDocumentationCache()` clears one
  package's documentation; the scorecards service selects conversations (`findConversationIdsForSelection()`).
- The skill templates of the sweep, owed-work and reaper commands are gone (the package ships the commands); the cron
  sample names the package commands.
- Removed: `ComposedViewNodeSchema::SCHEMA_FILE_RELATIVE_PATH` (an application's frontend path; nothing read it). The
  export command uses `ComposedViewNodeSchemaFilePaths::MODULE_SCHEMA_FILE_RELATIVE_PATH`.

## 0.1.0-alpha.41

- `update_task_plan` items take `inAnswer` (boolean): an item whose result is text in the answer (a draft, a summary)
  gets the delivery gate — the model's tick is not applied before the answer, and the platform closes the leaf from the
  turn's owner-visible answer (`TaskPlanItem::markResultIsTextInTheAnswer()`; model-made leaves only, never seeded
  items or containers). The tick rules and the plan directive say so.
- A container whose subtasks are all closed and none of them done takes its blocker's state: it renders `[✗ blocked: …]`
  and counts blocked, never ✓ (`TaskPlanItem::findBlockerOfClosedContainer()`); a part whose only subtask was blocked
  showed as done while nothing was produced.
- The plan directive (`AgentPlanDynamicsDecision`, and the base prompt's plan rule): one item per part the owner named;
  a subtask only for a separate result inside that part, never for loading a skill or any other preparatory call.
- `[SIGNAL:answer-step]` after an owner question recorded in this turn also names what the reads of the turn ask the
  answer to carry (the points they list, a ready widget block).

## 0.1.0-alpha.40

- `[SIGNAL:answer-step]` after an owner question recorded in this turn: first what the question's own tool result asks
  to stand in the text (a draft the question approves: every line of it), then the results of the closed plan items.
  The model wrote only the results, and the owner approved a draft that was never shown.
- Reply language of a FIRST owner message: inconclusive (the configured language applies) unless it holds two words
  written in lower case — names, brands and places with a preposition ("<Brand>, in Berlin.") were read as English
  with full confidence. A text without cased letters counts its tokens as before.

## 0.1.0-alpha.39

- A write the owner-consent check held (`OwnerConsentsService::reserveOwnerConsentForStep()` NOT_RESERVED) is marked on
  the call (`AgentToolCallProvenance::$isWriteHeldForOwnerConsent` → `ToolCall::$wasHeldForOwnerConsent`). The answer
  contract no longer lists it under "Completed so far in this turn" (its "nothing was changed" result came back
  COMPLETED); it names it under its own obligation: "Held for the owner's confirmation in this turn (nothing was
  changed): <tools>. Your final answer MUST ask the owner for that confirmation, as a question of its own".

## 0.1.0-alpha.38

- The search across conversations leaves out every sub-agent's conversation (`delegatedFromMessageId` set): a child
  of an eval run carries no eval mark of its own and answered as the owner's chat; what a sub-agent found reached its
  parent as a notification.
- The conversation list (the summary reads behind the session list) leaves out what the search leaves out: eval runs
  (unless they hold seeded history), schedule runs and conversations a schedule started.
- A tool with `MCPToolReceivesCurrentConversationId` always gets the calling conversation's id — a value the model wrote
  (`0`) no longer wins — and the agent loop's schema no longer offers the argument. A conversation without an id keeps
  the call's value.
- Checked-stop bounce: the discarded answer's content is to be written again in full, not a line saying it exists
  (`AgentCheckedStopPolicy::buildCheckedStopBounceText()`).
- Task plan update: after `Dropped: …` the result says that a dropped item's work is not delivered and that an item the
  owner did not cancel is added back and worked.
- `ContextRetentionSettingsOptionsTrait` can be reflected (a debug-mode container build failed with "Cannot access
  trait constant self::OPTION_UNLOAD_GRANULARITY directly").

## 0.1.0-alpha.37

- A sub-agent's result reaches the parent whole up to 8,000 characters (`DelegationRunEndNotification::VERDICT_MAX_LENGTH`,
  was 4,000): measured results of research sub-agents were 3,200–7,000 characters, and a cut one cost the parent a
  `check_delegations` call that read the whole result again on a grown context. Longer results are still cut with the
  pointer to `check_delegations`.
- Sub-agent directive (`SubAgentDirective.md`): a sub-agent is told that the main conversation is handed the first
  8,000 characters (about 1,000 words) of its result at once and reads the rest only with a further call — keep the
  result within that length, the findings first. Both numbers come from the constant (`{%VERDICT_MAX_LENGTH%}`,
  `{%VERDICT_MAX_WORDS%}`).

## 0.1.0-alpha.36

- `delegate` takes `parallelTasks`: ONE call starts one sub-agent per item (each item `label` and `task` +
  `skill_names` or `toolName` + `toolArguments`). The call's row carries the first item; every further item gets a
  `delegate` row of its own right after it, with its own record, number, deadline and run-end notification. Each item
  passes the model's guards on its own (a refused item fails its own row only); async, the children are admitted
  together once every row exists. A one-item list is the single form. Models emitted several `delegate` calls one
  response at a time despite the texts; an array argument is filled in one call.
- The `delegate` description, the `parallelTasks` description, the call row's answer line naming the other items and
  the per-item rejection carry the agreed wording; `Agentic.md` rule 3 says "ONE `delegate` call whose `parallelTasks`
  holds one item per sub-agent".

## 0.1.0-alpha.35

- `[SIGNAL:answer-step]` after a platform-written widget that ends the purpose: the text the last tool result asks for —
  every part it lists, in its order (the closing line alone dropped the owed sentences the result listed first).
- `[SIGNAL:answer-step]` after an owner question recorded in this turn (`askOwner`): a fourth wording — the results of
  the plan items closed in the turn, never the question again (the question was repeated as the whole answer).
- Mandate approval check: the payload names `currentWeekday` and `requestedValidUntilWeekday` on the space's clock
  (`AgentClock::formatWeekday()`); the prompt `ApprovalCheck.md` uses them for periods named by a weekday or a week and
  approves a window that matches one of two honest readings.

## 0.1.0-alpha.34

- `send_owner_report`: a `REPORT` declared before the report is refused while the task plan holds open items the model
  ticks (a plan this turn touched or a work-signal turn; once per turn), naming them. The step after an accepted
  `REPORT` has no tools, so the checked stop discarded the report written there and the report reached the chat twice.

## 0.1.0-alpha.33

- Tool-result budget: a result of a tool declaring `MCPToolResultIsNeverStubbedByTheBudget` no longer counts against the
  budget (`AIConversation::resolveToolResultRetention()`). Two page reads and a web search filled a 16k budget and the
  2k summary of the same round was stubbed; the stub asked to re-call the tool, and the model did.

## 0.1.0-alpha.32

- A structured tool result (a response DTO without markdown rendering, a bare domain object) now hands the model its
  platform note (`MCPToolResponsePlatformNoteProvider`) and its dictated widget type
  (`MCPToolResponseDictatedWidgetTypeProvider`); only a markdown-rendered result did. A search DTO, which must stay
  structured for `groupsFromToolCallId`, can name its call id in the note.

## 0.1.0-alpha.31

- `ConversationSearchResults` widget description (model-facing `.md`): the agent names the search's tool call in
  `groupsFromToolCallId` instead of copying the groups; the payload example shows what the agent writes and what the
  widget receives after the platform filled it.

## 0.1.0-alpha.30

- `ConversationSearchResults`: a block may name the search's tool call in `groupsFromToolCallId` instead of carrying
  `groups`; when the row is stored the platform writes the `groups` (and an empty `query`) of that call's stored
  structured result into the block (`ComposedViewBindingResolutionService::fillConversationSearchResultsGroupsOfRow()`,
  the hook and lookup of `$from`). The model no longer copies kilobytes of JSON (a copied block lost a bracket and was
  invalid). The search tool must return a structured result with a top-level `groups` list.

## 0.1.0-alpha.29

- `[SIGNAL:clock]` also names yesterday, tomorrow, this week and last week as dates of the space's zone (weeks from
  `AgentModelInput::FIRST_WEEKDAY_OF_THE_WEEK`, Monday; an application redeclares it): models computed "tomorrow" as the
  day after and read "last week" as the rolling seven days. The recorded-run normalization covers the new dates.
- `[SIGNAL:answer-content]`: a still-open part of the request — "a question is answered by the read that answers it, an
  order by the call that carries it out — a question is never an order to write" (it said "make its call now", read as
  an order to call the remaining write tool).
- Web: the polling spec of `AgentChat` no longer races its 150 ms answer delay under load (800 ms).
- `load_skills` and `load_tool` descriptions: "callable again at any step" is replaced by "A skill already loaded is
  never loaded again — its tools are in your tool list: call them." / "A tool already in your tool list is never loaded
  again — call it." (static text, the cached prefix stays stable across turns).
- A later repeated `load_skills` of a turn (the one followed by the step without tools) is answered with the loaded
  skills and their tools (`LoadSkillsTool::buildResultOfRepeatedCallBeforeAStepWithoutTools()`) instead of the generic
  repeat text about the work being done and the next plan item.

## 0.1.0-alpha.28

- The search across an account's conversations answers from the owner's dialogue only, and decides it INSIDE its
  three queries (message vectors, compaction nodes, keyword) instead of after them: an eval run without seeded history,
  a conversation whose own or root purpose is customer-hidden or `Schedule.Run`, and a conversation whose own or root
  initiator type is `SCHEDULE` are neither read for a distance nor ranked nor given an over-fetch place
  (`DBAIConversations::restrictToConversationsTheSearchAcrossConversationsAnswersFrom()`). New host lists:
  `AIConversationPurpose::PURPOSE_NAMES_LEFT_OUT_OF_THE_SEARCH_ACROSS_CONVERSATIONS` and
  `AIConversation::INITIATOR_TYPES_LEFT_OUT_OF_THE_SEARCH_ACROSS_CONVERSATIONS`. Before, scheduled runs were searched
  and the excluded rows took the over-fetch places of the owner's messages. Requires `mgamadeus/ddd` ^3.33.0
  (`JSON_FIELD_TEXT` in DQL on MariaDB).

## 0.1.0-alpha.27

- npm: `AgentsStateAction` carries the index signature of redux 5's `UnknownAction`, so a redux 5 / react-redux 9 host
  store that registers `WidgetHydrationReducer` stays assignable to `Store<any, UnknownAction>` (before: "Index
  signature for type 'string' is missing in type 'AgentsStateAction'" at every `<Provider store>`).

## 0.1.0-alpha.26

- `[SIGNAL:answer-step]`: a third wording for the text-only step after a platform-written widget that ends the purpose
  — the closing text the last tool result asks for, no recap of the widget and no list of completed steps; the "never
  a report" sentence is absent there (`AgentModelInput::buildAnswerStepTailHint()`, under the same condition as the
  absent answer contract, `hasPlatformWrittenWidgetThatEndsThePurposeAfterPosition()` from the owner's newest input,
  `findNewestOwnerInputPosition()`). The budget closing step keeps its own wording when both apply; every other forced
  step keeps the default wording byte for byte.
- The host's builders read fresh: the docblocks of `AgentBaseSystemPromptCacheService::resolveAgentSpaceContext()` and
  `AgentSpacesService::buildAgentSpaceContextPromptSegment()` and the skills (stable prefix, host duties 7a) state that
  a builder the inputs version makes run again reads the host's records at that moment, never an object loaded before
  the write — else the old text is cached under the new version for the TTL.
- npm: `CUSTOM_VISUALIZATION_SANDBOX_CONFIG` and `resolveCustomVisualizationFrameUrl` are exported from
  `@mgamadeus/ddd-agents/widgets`, so a host can serve the sandbox frame from another site.

## 0.1.0-alpha.25

- Task plan: a leaf that asks for a tool call AND a rendered widget closes in the completed-call pass when the tool
  made the platform write that widget while the call ran
  (`TaskPlanItemPlatformCloser::closeWhenCompletedCallAndWrittenWidgetProveDone()`, read by
  `TaskPlanItems::closeLeavesProvenByCompletedCall()`), under the turn-end conditions; the plan note on the tool result
  names it. A leaf whose widget the model writes itself, a widget the owner answers and a leaf that waits for an answer
  text to the owner stay open until the turn end as before. The completed-call closings still never read the locked
  gate tools (`TaskPlanEvidence::withoutEntitlementLocks()`): the call that just completed meets the tool gate.
- Answer contract: `AgentModelInput::completedWritesAnswerContractHint()` is null once a row the platform wrote during a
  tool call after the owner's newest input holds a widget of a type that ends the conversation's purpose
  (`hasPlatformWrittenWidgetThatEndsThePurposeAfterPosition()`, `AgentModelInput::$widgetTypesThatEndThePurpose`, set by
  `AIConversation::putDirectivesIntoContext()` from `AIConversationPurpose::getWidgetTypesThatEndThisPurpose()`; empty
  for a delegation child). A turn without that widget, a widget of another type and a widget block in the agent's own
  text keep the contract.

## 0.1.0-alpha.24

- Final refusals: `MCPToolResponse::finalRefusal()` marks a refusal the same arguments meet again (missing feature
  flags or account rights, an ineligible scope, a space the account may not use, a mandate a sub-agent or a scheduled
  run cannot obtain); it travels as `ToolResult::$refusalIsFinal` (recorded runs keep it) and
  `ToolCall::$wasRefusedAsFinal` (`isRefusedAsFinal()`; true or null, so a stored call carries the key only when true).
  The recorded-run comparison leaves `wasRefusedAsFinal` out of a replayed call while the recording does not hold it
  (`AgentRecordedRunComparison::TOOL_CALL_KEYS_ADDED_AFTER_RECORDINGS`).
- Repeat after a final refusal: every identical write after a final refusal in the same turn — the second, third and
  every later call, with a gate kind or without one — does not run; the dispatch decides it before the repeat guard
  (`AgentRepeatedToolCallCheck::findFinalRefusalOfTheSameWriteInTurn()`), answers it with the refusal the first call met
  (`buildRepeatAfterFinalRefusalCorrective()`), stores it FAILED with `wasRefusedAsDuplicate` and `wasRefusedAsFinal`,
  and the next step runs without tools. The quoted refusal is cut at a sentence end at or below
  `MAX_QUOTED_FINAL_REFUSAL_CHARS` (1000, above the longest refusal the package writes; `cutAtSentenceEnd()`). A signal
  row of any source or a completed write between the refusal and the repeat lets the repeat run. A forked call keeps
  the flag its child stored; a DELETE rewritten to "already completed" keeps no final refusal, inline and forked.
- `#[MCPToolRequiresOwnerWordsAsEvidence]`: a tool judged on the owner's own words is never offered to a delegated run
  (`AIConversation::findToolNamesLeftOutOfADelegatedRun()`, part of `findBlockedToolNamesOfConversationTree()`, which the
  offer and the append-only tool set read; the `load_tool` sub-skill tools are filtered by the same names,
  `LoadableSubSkillTools::withoutToolNames()`). A call of it by name in a delegated run is refused finally before it
  executes (`AgentToolCallDispatchService::buildRefusalOfToolLeftOutOfADelegatedRun()`; the refusal tells the sub-agent
  to finish the rest of its task and name the open step), and the fork admission does not fork it. A `delegate` tool
  run of it is refused (`DelegationSubAgentRefusal::forToolRequiringOwnerWords()`, with its own text when the
  delegating conversation is itself a delegated run). `AgentMandatesMCPPackage::grantMandate` declares it; a host that
  redeclares the grant tool repeats the attribute.
- `load_tool` and the tools a conversation is never offered: requested names that
  `findBlockedToolNamesOfConversationTree()` returns are taken out before anything is loaded or promoted — no sub-skill
  is loaded for them. A tool left out of a delegated run is answered with the dispatch's refusal text, a tool the
  purpose blocks with "Not available in this conversation: `<name>`. It cannot be loaded or called here; continue
  without it."; the other requested tools load as before.
- Task plan: the tick rules (plan header, `set_task_status` description, maintenance rules) put a stop the owner
  ordered first — the turn ends there and the open items stay open. The checked-stop bounce text names that stop: no
  item is worked, the final answer repeats what was said and names the items that stay open; otherwise the final
  answer reports every item.
- Skill catalog: `LoadSkillsTool::renderCatalogBlock()` takes the skills the same prompt build renders in its
  loaded-skills block and marks their lines `- <name> — LOADED, its tools are in your tool list — <description>`
  (`renderCatalogLineOfLoadedSkill()`), under a header that names the mark, the loaded-skills block and a skill
  announced as loaded later in the conversation (`CATALOG_HEADER`; under append-only loading a skill loaded inside the
  epoch is unmarked and announced by the appended item). Without such names the block is byte for byte as in
  alpha.23 (`CATALOG_HEADER_WITHOUT_LOADED_MARKS`). `AgentModelInput` passes the names exactly when it renders the
  loaded-skills block. Under the system-prompt swap a skill load therefore also changes the catalog lines of the
  skills it loads, inside the cached part before the loaded skills. The `load_skills` schema is unchanged.
- Message writes: `AIConversationMessagesService::openClaimFencedWrite()` locks the conversation row in every fenced
  write and compares the run token only when one is held; the parent's notification of a sub-agent run end
  (`AIConversationDelegationNotificationsService::notifyParentOfSubAgentRunEnd()`) is such a fenced write on the
  parent. A fenced write without a run claim (an inline console drive) and that notification do not fail with
  MariaDB error 1020 under `innodb_snapshot_isolation` when another session changes the conversation row after the
  write's snapshot. The inbound writers `persistInboundRowIndexSafe()` and `persistSignalWaitingForRunningTurn()` take
  the message index lock without that row lock. A continuation run with a deadline row
  (`AIConversationDelegationSpawnService::openContinuationRunOfRecord()`) locks the parent's conversation row before
  the `delegate` row (`AIConversationMessagesService::lockConversationRowOfStoredDelegateCall()`), the order of that
  notification: the deadline row's foreign key points at the parent's row, and with the `delegate` row locked first
  the continuation and the notification of the same sub-agent would end as a deadlock on both database engines.
- Repeat guard: `AIConversationToolCallHistory::countCallsWithSignature()` does not count a repeat the loop answered
  with an earlier final refusal — that call ran nothing, and counted it refused the identical call a later signal row
  lets run again.
- `update_task_plan`: an element that removes an item names it by key at any level of the call — no move rejection
  for it; a removal inside a subtree the call drops anyway is accepted and named once with that subtree; the seeded-item,
  repeated-key and "a container stays a container" rejections apply through it as before.
- npm package (`web/`): `./chat` exports `PHONE_CALL_LIFECYCLE_SIGNAL_SOURCES` and `SIGNAL_SOURCES_THAT_OWE_NO_WORK`
  (the SIGNAL sources the message list shows as a call marker and the ones that never make a failed run retryable);
  `./renderer` exports `getWidgetClickSignalSources` and `isWidgetClickSignalSource` (the package's widget click
  sources plus `AgentsEnvironment.furtherWidgetClickSignalSources`). `public-api.json` declares the new names.
- `./client/testing` adds `simulateGoogleMapsAuthFailure` and `resetGoogleMapsAuthFailureForTests`: a spec or story
  makes the `Map` node act as if Google refused the Maps key, and resets the flag afterwards. Both act on the same
  module the map component reads; the entry imports no chunk it did not import before.
- The chart palette's documentation names the tokens it reads (`--agents-chart-01` … `--agents-chart-08`). No
  behaviour change.
- `./renderer` exports `composedViewTreeToWire`: the kept tree `readComposedViewTree` returns, in the wire form the
  backend writes for the block, so an app compares its own widgets' reading with its backend's.
- `./renderer` exports `readOpenEntityActionTarget(action, currentRow?)` and its result type `OpenEntityActionTarget`
  (entity type, entity id, agent space key): the entity an `openEntity` action opens, from the backend-resolved
  `entity` of the action or, for a row-bound action, of the row it fires in. A host's own `onOpenEntity` of
  `ComposedViewRenderer` reads the action with it.
- The root entry exports `getEntityDetailBody(entityType)`: the registered entity detail body of one entity type (the
  package's or an app's), or `undefined` when none is registered.
- `./preview` exports `EntityMentionAskFlowStory`: the "Ask about this" flow of `EntityMention` (the real ask button
  beside the real composer strip of the chat input) as a ready-made story body; `maxWidth` narrows it. `public-api.json` declares the new names of this release.
- `./client/testing`: `restore()` of `installWidgetHydrationStandIns` aborts and forgets the reads started through the
  stand-ins that have not settled. Before, a read left pending (`{ __pending: true }`) kept its hydration key in
  flight past the unmount and the restore, and the next mount of the same key, also with a fresh state store, stayed
  loading.
- `./contract` exports `buildChangedMessagesRequest(messages, pageSize)`: the request a live update reads (the highest
  loaded id, the lowest index that can still change, the page size), or `null` when nothing is loaded. A binding tests
  its own endpoint filter against the requests the chat sends.
- `./chat` exports `ConversationDeepLinkedWidget` and its props: the mount of one deep-linkable widget a page opens
  (`?agentWidget=`), with `openedByPage`, the entry's feature gate and the widget error boundary.
- `./client/testing` exports `storeOpenCanvas`, `readStoredCanvas`, `clearStoredCanvas` and the type
  `StoredCanvasPointer`: the pointer a conversation keeps in `sessionStorage` for its open canvas, written, read and
  cleared as the chat does.
- The package suite covers `readMapView` (aliases, dropped and capped points, the route line) and the message reads
  of `useConversationMessagePages` (a changed-tail read held while the open's newest page loads, dropped after a reset,
  retried as the newest page after a failure). No behaviour change.

## 0.1.0-alpha.23

- Chart legend markers: `radius` and `shape` are both set, so the peer range of ApexCharts (`^3.35`) type-checks and renders round markers before and after 3.45.
- npm package (`web/`): an application builds its screens on the published entries alone, without the package's
  source files. New entry `@mgamadeus/ddd-agents/provider` (the provider without the registrations and the
  stylesheet); the root entry and `./provider` also export the prop types of every replaceable component
  (`AgentsComponents`, `AgentsTextProps`, …). The existing entries export the components and helpers an application
  composes from: `./chat` (`CanvasPane`, `ConversationMessageList`, `ConversationMessageInput`, the message rows, the
  activity ticker), `./kit` (`ModelText`, `EmbeddedConversationWidget`, `ApexChart`, `useChartPalette`,
  `useNumberLocale`, the entity-detail body contract), `./renderer` (`ConversationWidgetRenderContext`,
  `ConversationWidgetActions`, the agent-space frame, header and hooks, the canvas anchor hooks and reducers, the render
  report signal, the deep-link allow-list, `unregisterWidgetHydrator`), `./widgets` (`WidgetLabel`, `CardFooter`,
  `DonePill`, the skeleton shapes, payload validation, the concept explainer parts), `./preview`
  (`mapFixtureToDeclaredAgentSpaceKeyFieldName`) and the root entry (the definition types and folder readers of the
  entity-detail and concept-explainer registrations, `ComposedViewDataSourceFetcher`). Internals stay unexported.
- `./client/testing` adds the stand-ins a spec uses instead of mocking package modules:
  `installWidgetHydrationStandIns` (hydration answered by data, a failure or a pending read) and
  `installTestConversationWidgets` (a registry of test widgets); both return an object whose `restore()` undoes them.
- Non-code files an application reads are published under `dist/files/` and exported one subpath per file, at their
  path below `src`: the widget documentation (`*.md`), every `*.preview-fixtures.json`, the widget contracts, the node
  schema (`renderer/widget-tree/composed-node-schema.json`), the sandbox frame page, the conversation tokens
  (`chat/_conversation-tokens.scss`) and three SCSS modules; the SCSS partials they import are published beside them.
  `exports` is the one list the build copies from.
- `scripts/check-package.mjs` fails the build when an export target is missing or outside `files`, when a built entry
  is no declared subpath, or when a built entry lacks a name `public-api.json` declares for it (the new file lists the
  values and types of every code subpath; `--write-public-api` rewrites it after a deliberate change).

## 0.1.0-alpha.22

- `#[MCPExposedFilterProperties]`: the executor reads the model's raw `filters` and `orderBy` strings before the
  arguments are hydrated, right after the eligibility gate and before the mandate gate
  (`MCPToolExecutorService::refuseRawQueryOptionArgumentsOutsideExposedFilterProperties()`). A property outside the
  tool's list is answered with the tool's allowed properties on the first call, also when the entity does not know
  the property or an `orderBy` entry is empty (named `(empty entry)` in the refusal). A `filters` string that does not
  parse or validate leaves the call to hydration, which reports the error as before; a filter inside `expand` is answered
  by hydration as before. The check on the hydrated request objects stays; both build their refusal in
  `refusePropertyNamesOutsideExposedFilterProperties()`. Tools without the attribute are unchanged.

## 0.1.0-alpha.21

- Release workflow: the npm publish step logs verbosely. No code change.

## 0.1.0-alpha.20

- `delegate`: a `toolName` written with the namespace of the model's function list (`functions.<tool>`) names the
  tool after it instead of being refused as not offered (`AgentDelegationToolRunTask::readToolNameFromArgumentItem()`,
  also used for `namesBothTaskShapes` and tool-result task requests). `functions.` alone names no tool. Every name
  without the prefix is read as before.

## 0.1.0-alpha.19

- Stall classification: text of a step the loop sent with tool choice `none` (the answer step after a refused repeat,
  an owner-gate refusal, a result that ends the purpose, the step before the budget closes) is no longer judged as
  "text after a skill load" and is delivered instead of nudged and discarded; the checks of a final text still run on
  it. `AgentStepToolChoice::letsTheModelCallTools()` / `offersWorkToolsToCall()`; trace
  `turn.stall.textOfStepWithoutToolsNotJudged`. Steps sent with tools are unchanged.

## 0.1.0-alpha.18

- The module ships its plain console commands (`DDD\Symfony\Commands\Agents\`, 20 with the mark command) and the
  chat line editor: schedule fire, compact-finalized, the two event purges, maintenance (snapshot strip, capability
  rewrite, embedding repair and backfill, environment backfill), diagnosis (diagnose, validate-conversation,
  cache-diff, activity-lines, tool-response-stats, skill-token-map, context-eviction-sim, compact, search-inspect) and
  the hidden child-process command `app:ai-agent:execute-tool-call`. A command runs on a schedule only from the cron
  line the host registers (`templates/crons.sh`).
- **Behaviour change:** `agentToolCallChildProcess.commandName` defaults to the module's
  `app:ai-agent:execute-tool-call`; `commandName: null` no longer disables forking — an application that declares a
  `consoleScript` now forks with the module's command; unset `consoleScript` to stay inline.
- The schedules service answers which skip an occurrence was (`isSkipReasonOf…()`), and every skip word is produced
  through `static::`.
- Cron rows: a host that registered the former template rows (`agent-schedules-fire`,
  `agent-conversations-finalize-idle`, `agent-events-purge`) keeps them until it deactivates them; `crons.sh` keeps
  `agent-schedules-fire` (an upsert switches its command) and names the split finalize and purge rows anew.
- Developer skills: the command list, the host duties, the cron list and the child-process contract follow the
  shipped commands; the templates for firing schedules, reducing finalized conversations and purging events are
  removed (the package commands replace them).
- Test kernel: the cache key is a fingerprint of path, time and size of every watched file.

## 0.1.0-alpha.17

- Package prompt defaults: the owner's formal or informal form of address is one rule in `Agentic.md` and
  `Character.md` (recorded with a tool only when the conversation offers one); the recall passage names no
  application skill.
- `AIConversations::getSentenceOnReadingASessionInFull()`: the default says which tool reads a session and what to pass.

## 0.1.0-alpha.16

- Task plan tick rules: an item whose read or call returned nothing usable is not done, it is blocked.
- Checked-stop bounce: when the owner asked to stop after a part and check back, the answer reports that part and ends
  with the one question; the later items stay open.
- Package prompt defaults: an answer without a widget withholds nothing; no promise to remember a personal statement;
  a limit on the text is counted before it is claimed (`Agentic.md`); the example question in "Drive" is described,
  not quoted (`Character.md`).

## 0.1.0-alpha.15

- A spec of the frontend writes its deeply nested JSON text directly: `JSON.stringify` exhausted the call stack on
  the CI runner and stopped the npm release of 0.1.0-alpha.14.

## 0.1.0-alpha.14

Composer only: the npm release of this version stopped at the specs (see 0.1.0-alpha.15).

- First pre-release of the frontend `@mgamadeus/ddd-agents` (npm, dist-tag `next`) from `web/`, released in lockstep
  with the Composer package: the agent chat, the widget renderer, the composed-view kit, the conversation client and
  the image render. Built with Vite, declarations from `tsc`; the package's own specs run in the package folder.
- Release workflow: a tag `vX.Y.Z` publishes `web/` on npm by trusted publishing with provenance; CI builds and tests
  `web/` on every push.

## 0.1.0-alpha.13

- **Host work required.** The code no longer names tools of an application. Five method attributes replace the name
  lists: `MCPToolReceivesCurrentConversationId`, `MCPToolWritesAgentMemoryOnly`,
  `MCPToolResultIsNeverStubbedByTheBudget`, `MCPToolShowsNoActivityLine`, `MCPToolSendsMessageToOwner`. A host
  declares them on its tools: without the declaration the conversation id is not filled in, a memory write neither
  runs inline beside forked calls nor refreshes the standing directives within a run, a research read is stubbed by
  the budget, the memory tool shows an activity line, and a send tool stays callable inside a scheduled run.
- Removed: `AIConversation::BUDGET_EXEMPT_TOOL_RESULT_NAMES`,
  `AgentToolCallForkJoinService::INLINE_WRITES_OF_CONVERSATION_STATE_ONLY`, the `blockedToolNames` line of
  `Purposes/Schedule/Run.md`.
- `AIConversationPurpose::getToolNamesBlockedByDeclaration()`: a purpose class blocks tools by what they declare. A
  purpose that inherits `Schedule.Run` keeps the block of send tools only when its class extends `ScheduleRunPurpose`.
- The prompt files `Agentic.md` and `Character.md`, the closing line of the earlier-sessions text
  (`AIConversations::getSentenceOnReadingASessionInFull()`, overridable) and the two catalog texts of the
  `ConversationSearchResults` widget name no tool of an optional skill. A host that binds such tools states them in its
  set class and in a subclass of the widget.
- The header of the skill catalogue says that the list names loaded skills too and that a loaded skill is not loaded
  again.

## 0.1.0-alpha.12

- A `load_skills` call that names no skill is answered with the loaded skills and their callable tools by name when
  both exist.
- The first model step of a turn whose repeated `load_skills` call the repeat guard answers, while tools of loaded
  skills are callable, is answered with those tools and no longer forces the tool-less step; the next repeated
  `load_skills` of the turn gets the generic corrective and the tool-less step as before.
- The note before the forced `send_owner_report` step asks for the outcome and names NOTHING_TO_REPORT first.

## 0.1.0-alpha.11

- Console commands: the package ships its first command, `app:ai-conversation:mark-finalized`, and the groundwork
  every later command builds on: the abstract `AgentConsoleCommand` (default account, whole-run rights lift, budget
  bypass by `--ignore-budget`, run-level usage envelope, space by `--agentSpaceId` or the application's own options;
  the tear-down runs each step once, also after a failure), `AgentConsoleProgressReporter`, `AgentEvalArmOptionsTrait`
  (every arm option needs a value) and `ContextRetentionSettingsOptionsTrait`. An application replaces a command with
  a subclass that repeats `#[AsCommand]` with the same name.
- Value objects `ContextRetentionSettingsOverride` and `CompactionSettingsOverride` (`applyTo()` returns a merged copy;
  a value that is no whole number, below the knob's minimum or too large is refused with
  `SettingsOverrideValueRefusedException`), `AgentEvalArms`, the interface `AgentRecordedRunSessionInterface`.
- `AgentSpacesService::getConsoleScopeOptions()` (an `AgentSpaceConsoleScopeOptions` set, empty by default) and
  `findAgentSpaceForConsoleScopeOptionValues()` (null by default): override points for naming a space on the console.
- `AgentAIUsageScope`: `ENVELOPE_AI_CONVERSATION_SEARCH`, `ENVELOPE_AI_AGENT_EVALUATION`, `AI_AGENT_SIMULATE_OWNER`,
  `AI_AGENT_JUDGE_EVALUATION`.
- Design-decision references of the context, autonomy and decisions-and-guards skills: six reasons now argue from the
  mechanism, and where a measurement decided they name what a host measures itself.

## 0.1.0-alpha.10

- The tool-less step after a refused identical write is forced only when the loop already refused that call once in
  the turn. Before, a repeat of a write that ran in the same turn paused the tools on its first refusal.

## 0.1.0-alpha.9

- A `done` / `not_applicable` tick on a criterion leaf the record parks (`blockedByOwnerStatement()`) is no longer
  applied: a leaf the platform already parked keeps its park, any other leaf takes the record's park now
  (`TaskPlanItem::adoptRecordParkInsteadOfTick()`, `isParkedByThePlatform()`). Behaviour change of the gate-table row
  G8 "a blocked verdict": it now reaches RECORD instead of G28.

## 0.1.0-alpha.8

Documentation only; no behaviour change.

- Context skill: a compaction node's `coveredTokens` is the model-counted footprint of its raw rows (a roll-up sums its
  children's); the per-model default threshold names `AIModelsService::defaultEffectiveContextTokensForWindow()`.
- Loop skill: the three answers to a tick that repeats an item's status, when such a call fails, what the all-no-op
  breaker counts, and that a later same-status tick with a note replaces the item's run-time note.
- Docblocks of `AIConversationCompactionNode::$coveredTokens` and `AgentTaskPlanTickOutcome::isAllAlreadyInStatus()`
  corrected; a comment no longer cites source line numbers.

## 0.1.0-alpha.7

Documentation only; no source class changes.

- The developer skills carry the remaining generic facts about the agent core (43 skill files extended); descriptions
  and the source-ownership manifest are unchanged.

## 0.1.0-alpha.6

Documentation only; no source class changes.

- Developer skills: the entry skill `ddd-module-agents-specialist` (install, wiring, host duties, configuration,
  folder → skill table, templates for the host's console commands, middlewares, `messenger.yaml` and crons) and eight
  topic skills `ddd-module-agents-{loop,context,skills-and-purposes,tools,widgets,autonomy,decisions-and-guards,observability}-specialist`.
  `references/source-ownership.tsv` of the entry skill names the owning skill of every file under `src/` and `config/`.
- Contract tests `tests/Contract/DeveloperSkill*Test.php`: skill format, source ownership, every named identifier
  exists, every PHP template passes the syntax check.
- `README.md` and `AGENTS.md` describe the current state (config defaults, the package's own suite, the `ddd` floor
  `^3.27.0`).

## 0.1.0-alpha.5

- A `delegate` call that names `toolName` is a tool run, also when it carries a `task` text beside it (the text is
  not read). Before, such a call was refused and the model ran the tool itself in the main conversation. Only a call
  with neither shape is refused.

## 0.1.0-alpha.4

- The answer contract names what the final answer contains, not that it is due: a part of the owner's request that
  is still open is worked first.
- The plan directives that ask for a plan are no longer sent once the turn changed the plan; the plan-creating
  directive names sub-steps and variants as subtasks; the direct band asks for every call the request needs.
- The result text of an unchanged plan resend orders no work: the next open item, or the answer when the owner asked
  to be asked first (`TaskPlan::buildUnchangedResendResultText()`).
- A load call the repeat guard answered counts as no load for the stall check.
- A turn's first model call names the skills loaded right now (`[SIGNAL:skills]`, tail entry kind `LOADED_SKILLS`).

## 0.1.0-alpha.3

- The grant result of a standing mandate (time window or unlimited) asks the model to tell the customer in one
  sentence what it may now do on its own, until when, and that a word from them stops it.
- System prompt: a plan item is `done` only when its result exists; a load, create or publish item whose call could
  not deliver is `blocked`. A blocker is reported together with the alternative that is available.

## 0.1.0-alpha.2

- The answer contract owes the outcomes of the writes since the owner's newest input: their text or, newer than it,
  their own signal (a widget submit, an action confirmation). Before, a turn started by a widget submit listed the
  writes of the previous turn again, and the model repeated that turn's whole answer.
- The doc comment of `CustomVisualizationConversationWidget::$script` names the package's sandbox global
  `window.AGENTS_VISUALIZATION`.

## 0.1.0-alpha.1

First pre-release. Requires `mgamadeus/ddd` ^3.27.0, `mgamadeus/ddd-ai` ^3.2.1, `mgamadeus/ddd-argus` ^1.3.2,
`mgamadeus/ddd-common-money` ^1.1.1, `erusev/parsedown` ^1.7.

First version of the module: the agentic loop on top of ddd-ai.

- Conversations, messages, agent runs and turns, the task plan, purposes, delegation to sub-agents, schedules,
  mandates, compaction, the decision calls and the supervisor, with their repositories for MariaDB and PostgreSQL.
- The agent space (`AgentSpace`): the work context a conversation, a schedule and a mandate belong to. An
  application derives it and references its own entities from it.
- Conversation widgets and the composed view: block syntax, the widget registry, the kit of composed nodes and the
  names an application declares for its own blocks (`Widgets/Syntax/WidgetBlockFieldNames`).
- The MCP / JSON-RPC wire DTOs and the tool executor under `DDD\Presentation\Mcp\`.
- What an application declares instead of the module: its names outside a conversation
  (`config/app/AI/agentApplicationNames.php`), its wording towards the model
  (`config/app/AI/agentApplicationWording.php`), its purposes, skills, tools and widgets.
- `AgentsModule` with the namespaces the module owns (`AgentsModule::OWNED_NAMESPACES`).
