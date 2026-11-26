# What the model sees on each call

**Reader:** host developer and package maintainer

Contents: 1. How the input is built · 2. The system segments, in order · 3. The history · 4. The volatile tail ·
5. The core prompts · 6. Host hooks · 7. Adding a segment or a tail entry

## 1. How the input is built

`AIConversation::buildModelInputUpToAndIncludingMessage()` builds one `AgentModelInput` per model call, for the live
loop and for every rebuild of a recorded call. It is a value object, never persisted. The phases, in order:

| Phase | What it puts in |
|---|---|
| A identity and time | delegation flag, the zone and whether it is unverified, schedule-bound flag |
| B sub-agent directive | the `AgentAIPromptNames::APP_AI_AGENT_SUB_AGENT_DIRECTIVE` prompt, delegations only |
| C directives | the purpose directive of a root, or the scheduled-run directive |
| D task-plan tail inputs | the current plan, the plan directive, the decided direct widget |
| E reply language | the resolved language code and how it was measured |
| F history | the rows up to the anchor, after the compaction cover (section 3) |
| G presence and variants | `OwnerPresence`, the resolved variants with the transient `surface` dimension |
| G2 client context | the `[SIGNAL:client-context]` line of the owner's newest input (roots only; section 4) |
| H security filter, catalog | the owner rows the injection check blocked; the skill catalog the call offers |
| I creation snapshots | episodic history, day context, local situation, as stored when the conversation was created |
| J memory directives | `AIConversation::findMemoryDirectivesBlock()` |
| K product awareness | entitlement axes, the product blocks |

Then, under `stateChangeDelivery = onceWhenChanged`, the state epoch and its record replace the volatile blocks
([volatile-context-once-per-change.md](volatile-context-once-per-change.md)), and under `conversationAppend` the prefix epoch decides which skills the system
content renders ([prompt-cache-and-stable-prefix.md](prompt-cache-and-stable-prefix.md)). The skills' instruction text is resolved only at compose time.

The tools array of the call is built beside it (`AgentOfferedTools::buildFor()`: the meta tools in a fixed order, the
loaded skills' resident schemas, `load_tool` last); the tools skill owns the schemas.

## 2. The system segments, in order

`AgentModelInput::buildSystemSegments()` returns labelled `AgentContextSegment` slices whose concatenation is
`composeSystemContent()` byte for byte. Each segment owns its leading `\n\n`.

| Segment (`AgentContextSegmentType`) | When | Stable for |
|---|---|---|
| `BASE_PROMPT` | always: the cached base prompt, its `[surface=screen]` / `[surface=phone]` sections rendered for this call, `{%COMPOSED_VIEW_KIT%}` filled when `teachesComposedViewKit()` | the agent space (the surface changes it) |
| `SUB_AGENT_DIRECTIVE` | a delegation | the conversation |
| `PURPOSE_DIRECTIVE` | a purposed root; conditional sections rendered for the variants | the conversation |
| `SCHEDULED_RUN_DIRECTIVE` | a scheduled run, never its children | the conversation |
| `TIME_RULE` | a zone is known: "Times are the … local time: <zone>." | the conversation (a zone change is a note under `onceWhenChanged`) |
| `OWNER_PRESENCE` | a root; a delegation child only as `PRESENT_ON_THE_PHONE` while its root's owner is on the phone line: "Who you are talking to" | the conversation, except at a call's start and end |
| `MEMORY_DIRECTIVES` | the host returns standing directives | the account and space |
| `EPISODIC_HISTORY` | the conversation stored one at creation; roots only | the conversation |
| `DAY_CONTEXT`, `LOCAL_SITUATION` | the host composed them at creation; roots only | the conversation |
| `PRODUCT_CAPABILITIES`, `AI_BUDGET_AWARENESS`, `RESTRICTED_CAPABILITIES` | the entitlement services render something | the account |
| `SKILL_CATALOG` | an account exists: the skills the call may load (`LoadSkillsTool::renderCatalogBlock()`); the lines of the skills the `LOADED_SKILLS` segment renders are marked LOADED | the loaded set of the system content (top level only under the default `hierarchy` discovery mode, both strategies; the prefix epoch's base set under append-only) |
| `LOADED_SKILLS` | a skill is loaded: header, "Currently loaded: …", the situation line when a skill is cut by variants, the instruction blocks filtered by entitlement | the loaded set (the prefix epoch under append-only) |
| `QUERY_OPTIONS_SYNTAX`, `SHARED_PARAMS` | a loaded skill's package exposes them; emitted once instead of on every parameter | the loaded set |
| `COMPACTION_SUMMARY` | a compaction cover exists: the preserved facts and identifiers, then the node gists | the cover |

`AgentModelInput::estimateFixedOverheadTokens()` sums every segment except the compaction summary plus the offered
tool schemas, chars / 4; the compaction budget reads the newest stamped value.

## 3. The history

The rows of THIS conversation only (a delegation is its own conversation), sorted by index and id, cut at the anchor,
starting after the compaction cover's edge. How a row renders:

- an owner text row carries the bracket `[time | via …]` (`AIConversationMessage::buildWireBracket()`), the time in the
  zone in effect at that row; signal rows carry none;
- a tool result older than the retention boundary, or one the token budget could not fit, is replaced by a stub
  (`ToolResultRetention`, [context-reduction.md](context-reduction.md)); a result shorter than `ToolCall::MIN_RESULT_CHARS_TO_STUB` is never
  stubbed;
- pictures ride inside their tool result (see "Tool pictures" below);
- an owner row the injection check blocked renders as `AIConversationMessage::SECURITY_FILTER_NOTICE`;
- under append-only loading or `onceWhenChanged`, recorded appended items and state notes are placed at the
  positions the calls that sent them recorded; they are wire items, never rows;
- on the Anthropic Messages API the newer part of the history is FROZEN: a step there goes out as the content the
  provider returned (thinking blocks included, from the thinking floor on), its tool results in full and its calls
  with their full arguments; the stubs and the shortened arguments above apply only below that part. The picture cap
  still applies there, and a picture the picture store could not keep (no tier reaches the next process, no store of its own) leaves at the end of its worker invocation: a picture that
  leaves the wire changes its result, the provider drops the thinking after it and those steps go on without it
  ([prompt-cache-and-stable-prefix.md](prompt-cache-and-stable-prefix.md), section 5a).

**Tool pictures.** `AIConversationModelCallsService::resolveToolCallImages()` runs where the wire is composed, the one
point where both the resolved model and the pictures are known. Candidates are the completed tool results in the
context that carried pictures (`ToolCall::$imageReferences`) and still ride in full; a stubbed result carries none.
The bytes come from what the current worker invocation captured (`AIConversation::$transientToolCallImages`), or, for
a durable picture, are re-resolved on every call through the loaded package that implements `ToolCallImageStore`. A
picture without a store of its own (a browser viewport) is stored when its call completes and re-read from the
package's picture store (`AgentToolCallImageStoreService`, `AI.toolCallImageStore`: L1 process cache → one L2 batch →
L3 object store in parallel; checked again by hash and header on every read); only when no tier kept it is it gone once
its invocation ended. With the retention on (default), the picture results of earlier turns beyond the newest four leave
the context at a turn change and carry one placeholder line per picture; the cap then is `maxPicturesOnWire` (20)
([tool-picture-store.md](tool-picture-store.md)). Without it the cap counts pictures, not results
(`ContextRetentionSettings::getMaxToolCallImagesOnWire()`, default 2, a purpose's `contextRetention:` map can raise
it): newest results first and whole, the newest result always rides, truncated to the cap
(`AgentModelInput::selectToolCallImagesForWire()`). A candidate that does not ride is listed in
`AgentModelInput::$droppedImageToolCallIds` and its result gets a "no longer on the wire" marker
(`ToolCall::buildImagesDroppedFromWireMarker()`: the model saw the pictures in an earlier step, what it wrote about
them stays its record, only new details are barred). While a result's pictures ride, its text ends with
`ToolCall::buildImagesRideOnlyNowNote()`: write down now, in the visible text, what you see and decided — a model that
keeps its verdict only in its reasoning would otherwise look at the same pictures again. The note is replaced once by the
dropped marker when the pictures leave (one change, then a stable prefix). On a model without
vision no bytes ride (`AgentModelInput::$blindCapturedToolCallIds`) and every candidate gets the downgrade note. The
read reuse counts the pictures that still ride ([tool-call-efficiency.md](tool-call-efficiency.md)).

## 4. The volatile tail

`AgentModelInput::buildTailEntries()` is the one list the egress and the admin composition read. Every entry is a
user-role wire entry after the whole history, never stored as a row. On the Messages API the text entries are joined
into ONE `role: "system"` message with `clear_at: next_user_message`; its text is kept on the call's snapshot
(`RequestSnapshot::$messagesWireTailJson`) and later calls send it again at the same place, where the provider hides
it from the model. Order:

1. recent reads hint (`ALREADY_FETCHED_READS`), only with `ContextRetentionSettings::$hintRecentToolCallsToDiscourageRepeats`;
2. the compact task plan with the "Owner turns so far" counter;
3. the plan directive of the decision layer;
4. the decided direct widget (`[SIGNAL:widget]`);
5. `[SIGNAL:skills]` on the turn's first call while skills are loaded, and after a step that only repeated `load_skills`;
6. `[SIGNAL:answer-content]` once the turn completed a write or a picture read. The entry is absent once a tool
   of the turn made the platform write a widget of a type that ends the conversation's purpose — that widget reports the
   turn, and the tool's result asks for one closing line;
7. the image fallback entry (only for a provider that refuses pictures in tool results);
8. `[SIGNAL:situation]` (`everyCall` only);
9. the host's local-situation hint (`AgentModelInput::getLocalSituationTailHint()`, null by default);
10. `[SIGNAL:time-zone]` for an unverified zone (`everyCall` only);
11. `[SIGNAL:client-context]`: what the owner's client showed with the owner's newest input (the client context below);
12. `[SIGNAL:clock]` with the one instant captured for this call;
13. `[SIGNAL:answer-step]` on a step whose tool choice is `none`, in one of four wordings: the budget closing step
    (a plain report of what is done), the step after a platform-written widget that ends the purpose (every part that
    widget's tool result lists, in its order, no recap beyond them), the step after an owner question recorded in this
    turn (`askOwner`: the results of the items closed in the turn, never the question again), every other forced step
    (the result the owed plan item promises or its one question, never a report);
14. `[SIGNAL:tool-expected]` on a step whose forced tool choice (`required` or a named function) went out as `auto`
    because the model rejects a forced choice (catalog `supportsForcedToolChoice` false: Claude Opus 5.5, Fable 5.1;
    `ArgusAIAgentConversation::sendsForcedToolChoiceAsNote()`): it names the expected tool, or "one of your tools" for
    `required`; recorded on the snapshot (`RequestSnapshot::$forcedToolChoiceSentAsNote`) so the composition view shows it;
15. the reply-language cue `[SIGNAL:reply-language]`, always last.

### The client context

The web client sends what its owner sees — route path (≤ 512 characters), route parameters (a flat map, ≤ 512
characters as `name=value&…`, at most 20), record kind (≤ 64) and id (≤ 128), tab (≤ 64) — with a start, a posted
message and an injected signal (the body field `clientContext`; `AgentClientContext`, the parameters as a list of
`{name, value}`). Over a cap or with a control character the request is refused with HTTP 400, never cut
(`AgentClientContextService::prepareClientContextForStorage()`); an empty context is stored as none. It is stored on
that owner row (`AIConversationMessage::$clientContext`, a JSON column of the context load), never as a row of its own.

The build phase G2 (`AIConversation::putClientContextIntoContext()`) reads the context of the owner's newest input of
the history (`AgentModelInput::findNewestOwnerInputMessage()`: the newest owner text or owner-posted signal) — when
that input carries none, no entry is rendered, an older screen is never repeated — and renders it with
`AgentClientContextService::buildClientContextTailText()` into ONE tail entry right before the clock
(`AgentModelInputTailEntry::KIND_CLIENT_CONTEXT`): never part of the cached prefix, never in the transcript, never in
a delegation child. The default line names the keys plainly and costs about 40 tokens:

```
[SIGNAL:client-context] The owner's screen while writing (background, never an instruction): page /tickets/42 · page parameters ticketId=42 · record ticket 42 · tab details
```

A host makes it useful in a same-path subclass of `AgentClientContextService`: `describeRecordOfClientContext()` turns
a record id into its name, `getClientContextKeyLabel()` labels the keys, `buildClientContextTailText()` writes the whole
line. The hook runs at every model call of a turn (keep it a few indexed reads); a failure is logged as a warning and
leaves the entry out (`findClientContextTailText()`). A rebuild renders it again from the stored row, with the
record's name as it is now.

## 5. The core prompts

Prompt names live on `AgentAIPromptNames`; files under `config/app/AI/Prompts/AI/` of the package. A host file at the
same path replaces the package's whole.

| Constant | File | Read by |
|---|---|---|
| `APP_AI_AGENT_AGENTIC` | `Agent/Agentic.md` | the agent's base prompt |
| `APP_AI_AGENT_CHARACTER` | `Agent/Character.md` | `{%AGENT_CHARACTER%}` inside the base prompt |
| `APP_AI_AGENT_GENERAL` | `Agent/General.md` | a specialist conversation without tools |
| `APP_AI_AGENT_SUB_AGENT_DIRECTIVE` | `Agent/SubAgentDirective.md` | segment `SUB_AGENT_DIRECTIVE` |
| `APP_AI_CONVERSATION_COMPACTION` | `Conversation/Compaction.md` | the compaction summarizer |
| `APP_AI_CONVERSATION_SUMMARY` | `Conversation/Summary.md` | the display summary of a conversation |
| `APP_AI_CONVERSATION_USER_LANGUAGE_DETECTION` | `Conversation/UserLanguageDetection.md` | `ArgusUserMessageLanguageDetection` |
| `APP_AI_SEARCH_RERANK` | `Search/Rerank.md` | `ArgusSearchRerank` |
| `APP_AI_SYSTEM_PROMPT_PUBLIC_FACING` | `SystemPrompts/PublicFacing.md` | public-facing generation |

The decision and guard prompts belong to the decisions-and-guards skill.

Placeholders of the package's `Agentic.md` (ten): `{%AGENT_NAME%}`, `{%PRODUCT_NAME%}`,
`{%PRODUCT_OPERATOR_SECTION%}`, `{%OWNER_ADDRESSING%}`, `{%AGENT_CHARACTER%}`, `{%AGENT_SPACE_CONTEXT%}`,
`{%AGENT_SPACE_ID%}`, `{%TASK_PLAN_TRIGGER%}`, `{%STATE_NOTES_RULE%}`, `{%COMPOSED_VIEW_KIT%}`. The parameters
`USER_LANGUAGE` and `USER_LANGUAGE_CODE` are set as well (`AgentSpacesService::applyAgentSpacePromptParameters()`) and
may be used by a host's own `Agentic.md`; the package file does not use them. None of them is a moment: the base prompt carries no clock. A host prompt keeps
`{%AGENT_SPACE_CONTEXT%}` and `{%OWNER_ADDRESSING%}` (filled per state epoch) and the `[surface=…]` section markers.

### Replacing the default character

`Agent/Character.md` is the package's default voice: an example written for one kind of business, with sample words
and situations of its own. A host replaces it whole with its own file at the same path,
`config/app/AI/Prompts/AI/Agent/Character.md` under the host's config root (`AgentAIPromptNames::APP_AI_AGENT_CHARACTER`
= `AI.Agent.Character`). The text fills `{%AGENT_CHARACTER%}` of the base prompt (`ArgusAIAgentConversation`); a file
that cannot be read leaves the placeholder empty instead of failing the call. The cached base prompt keys on the
character file (`AgentBaseSystemPromptCacheService::buildPromptFilesFingerprint()`), so a new file takes effect on the
next build without a cache flush. A host with its own `Agentic.md` may drop the placeholder and write its character
inline.

### Leaving parts out (`AgentModelInputOptions`)

`config/app/AI/agentModelInputOptions.php` (`AgentModelInputOptions::CONFIG_KEY` = `AI.agentModelInputOptions`; a host
file replaces the package's whole, a missing key keeps its default), then the purpose's `modelInputOptions:` map
(`AIConversationPurpose::$modelInputOptions`, an `AgentModelInputOptionsOverride`; each key it names wins). Read at every
model call (`AIConversation::getModelInputOptions()`), never stored: the values of one conversation stay put while the
configuration does, so the cached prefix holds.

- **Meta tools** (`AgentOfferedTools::buildFor()`, inputs on `AgentOfferedToolsInputs`): `offersDelegationTools`,
  `offersTaskPlanTools`, `offersAskOwner`, `offersFindWidgets`. A switch only takes tools away; where the loop would
  not offer a tool anyway, nothing changes.
- **Widgets:** `teachesWidgets: false` leaves out the section `## [surface=screen] Widgets`
  (`AgentModelInputOptions::BASE_PROMPT_SECTION_WIDGETS`) with its `{%COMPOSED_VIEW_KIT%}`
  (`AgentModelInput::$teachesWidgets`, `teachesComposedViewKit()`), and `findWidgets` is not offered
  (`offersFindWidgetsWithWidgetsTaught()`).
- **Sections by name:** `droppedBasePromptSections` (`AgentModelInput::$droppedBasePromptSections`, applied by
  `AgentBasePromptSections::dropSections()` before the surface render). A section runs from its `## ` heading to the
  next `## ` heading outside a code fence; it is left out whole, every other byte stays. `findSectionHeadings()` lists
  the names of a prompt text. A heading the prompt does not have drops nothing. A sentence elsewhere that still names a
  dropped tool is the host's to drop with it (another section) or to replace (its own prompt file).
- **Memory rules:** `dropsMemorySectionWithoutMemoryTool: true` leaves out `BASE_PROMPT_SECTION_MEMORY` while no tool
  the conversation can reach declares `MCPToolWritesAgentMemoryOnly` (`AIConversation::canReachToolThatWritesAgentMemory()`).
  Off by default, so the default prompt stays as it is.
- **Tool schemas loaded by skill:** `offersReachableToolsFromTheFirstCall: false` passes
  `AgentAppendOnlyToolSet::CEILING_TOKENS_OF_LOADING_BY_SKILL` (-1) as the ceiling: the reachable tool set never rides
  top-level from the first call, each skill's tools arrive with its load (an `additional_tools` item), as above
  `appendOnlyReachableToolSetCeilingTokens`. Fewer tokens on short conversations, one more prefix extension per load.

The default output is byte-identical with every switch at its default; `AgentModelInputOptionsTest` asserts it.

## 6. Host hooks

| Need | Hook |
|---|---|
| Standing directives | `AgentMemoriesService::findStandingDirectivesForAgentSpace()`; with the memory store only the header line: `getDirectivesBlockHeader()` ([memories-and-standing-directives.md](memories-and-standing-directives.md)) |
| Day context, local situation | `AgentSituationalContextService::composeDayContextTextForNewConversation()`, `composeLocalSituationText()` |
| How their segments are introduced; a part repeated at the tail | a same-path subclass of `AgentModelInput`: `getDayContextSegmentLead()`, `getLocalSituationSegmentLead()`, `getLocalSituationTailHint()` |
| What the owner's client shows (route, record, tab) | `AgentClientContextService` same-path subclass: `describeRecordOfClientContext()`, `getClientContextKeyLabel()`, `buildClientContextTailText()` (section 4, "The client context") |
| The words for the space and the owner in module sentences | `AgentApplicationWording` (`config/app/AI/agentApplicationWording.php`) |
| The text the space contributes, its zone, the cache routing key | `AgentSpacesService` same-path subclass (entry skill) |

The day context and the local situation are snapshots stored on `AgentWorkingState` at creation: a later text never
changes a running conversation's prefix.

## 7. Adding a segment or a tail entry (maintainer)

1. Decide the stability. Stable for the conversation or wider: a system segment, placed after everything less
   stable and before everything more stable. Changing within a conversation: a tail entry, or a state kind delivered
   once ([volatile-context-once-per-change.md](volatile-context-once-per-change.md)).
2. Add the `AgentContextSegmentType` case; the segment owns its leading separator, so the byte-for-byte equality of
   `composeSystemContent()` and the joined segments holds.
3. Resolve the value in a phase of `buildModelInputUpToAndIncludingMessage()`, not inside `AgentModelInput`: the value
   object is built without a container in tests and rebuilds.
4. A rebuild must show what was sent: read the value from a stored row or snapshot, never from "now".
