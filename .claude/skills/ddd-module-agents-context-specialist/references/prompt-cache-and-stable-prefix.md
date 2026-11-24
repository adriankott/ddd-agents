# Prompt cache and stable prefix

**Reader:** host developer and package maintainer

Contents: 1. The rule · 2. Breakpoints and the routing key · 3. Wire API and strategy · 4. Append-only loading and
prefix epochs · 5. Reasoning carry-over · 5a. The Messages API: thinking blocks and the frozen part · 6. The cached base prompt · 7. What breaks the cache · 8. Host checklist

## 1. The rule

A provider caches the longest byte-identical prefix of a request in the order tools, system content, history. The
first changed byte invalidates everything after it. The package therefore keeps three things fixed for as long as it
can: the top-level `tools`, the system content, and every history row once it was sent (the volatile tail comes after
it and is the only part that changes per call).

## 2. Breakpoints and the routing key

Explicit cache markers and the routing key are sent only for a model whose catalog prompt cache mode is
`AIModel::PROMPT_CACHE_MODE_EXPLICIT_BREAKPOINTS`, and only over the direct OpenRouter egress
(`ArgusAIAgentConversation::usesExplicitPromptCacheBreakpoints()`). For every other model the system content goes as
one text and no marker and no routing key are sent; the catalog belongs to `mgamadeus/ddd-ai`.

With explicit markers (`ArgusAIAgentConversation::buildModelContext()`):

| Wire API and loading mode | Markers in the system content | Markers in the history |
|---|---|---|
| Responses, `conversationAppend` (the package default) | 2: after the base prompt, at the end | the newest stable non-assistant item (user, tool or developer) with text, plus the item the previous call marked |
| Responses, `systemPromptSwap` | 2: after the base prompt, at the end | the same two |
| Chat Completions, `systemPromptSwap` | 3: after the base prompt, before the loaded skills, at the end | the newest stable message before the tail that carries text |

`buildSystemContentPartsWithPromptCacheBreakpoints()` splits the system content into those parts;
`addPromptCacheBreakpointToLastStableWireMessage()` and `addPromptCacheBreakpointToPreviousCallsWireMessage()` set the
history markers. A Responses request reads the cache only at markers it carries, hence the re-marked item of the
previous call; the slot before the loaded skills goes to it under the provider's cap of four.

**Anthropic `cache_control`.** A model whose catalog mode is `AIModel::PROMPT_CACHE_MODE_ANTHROPIC_CACHE_CONTROL` (the
Claude models) over the direct OpenRouter egress (`ArgusAIAgentConversation::usesAnthropicCacheControl()`) gets its
markers as `cache_control: {type: "ephemeral"}` on those text parts
(`convertPromptCacheBreakpointsToAnthropicCacheControl()`, at most four, Anthropic's limit). On Chat Completions (so
`systemPromptSwap`) they sit as in the Chat Completions row above: three in the system content, one on the newest
stable message. On the Messages API under `conversationAppend` the loaded-skills segment is fixed, so the marker
before it is not needed: two in the system content (after the base prompt, at the end) and two in the history — the
newest stable message and the one the previous call marked
(`addPromptCacheBreakpointToPreviousCallsMessagesWireMessage()`; Anthropic looks an entry up only about 20 blocks back
from a marker, and one step with many parallel calls adds more blocks than that). Without markers a Claude
model caches nothing. The writes are booked at the default lifetime (5 min, 1.25x the input rate); the usage maps
OpenRouter's `prompt_tokens_details.cached_tokens` / `cache_write_tokens`. Anthropic caches tools before the system
content, so under the swap a skill load (new top-level tools) writes the whole prefix again; reordering the system
segments cannot keep the cache across a load. A model with `automaticPrefix` (Kimi K3.1 Pro) gets no markers.

The routing key (`AgentWireRequestParameters::$promptCacheKey`, sent as `prompt_cache_key`) is sent only together
with explicit markers (`ArgusAIAgentConversation::resolvePromptCacheKey()`). Its value comes from
`AgentSpacesService::findPromptCacheRoutingKeyOfConversation()`: one per agent space, the account's for a
conversation without a space.

## 3. Wire API and strategy

`AgentWireConfiguration::resolveFor()` decides, once per run, from the model, the run settings and the stamped retention
settings:

- **Wire API.** `RunSettings::$agentWireApi` if set, else the catalog's value, else `chatCompletions`. The catalog
  (`AIModel::getAgentWireApi()` in mgamadeus/ddd-ai) returns the value as written and checks nothing; the agent matches
  it against the forms it speaks (`AgentWireConfiguration::CATALOG_WIRE_APIS`: `chatCompletions`, `responses`,
  `messages`; `resolveWireApiOfCatalogValue()`), and a value it does not speak is Chat Completions with one warning
  per model and process — a catalog default never makes a model unusable. `responses` is allowed for an OpenAI model
  and `messages` (the Anthropic Messages API) for an Anthropic model, both over the direct OpenRouter egress only; a
  catalog value the egress cannot serve (the LiteLLM proxy) is Chat Completions as well.
  `RunSettings::AGENT_WIRE_API_RESPONSES_SERVER_STATE` is a test arm (OpenAI keeps the state,
  `AgentResponsesServerStateChain`) and never a catalog value.
- **Skill-loading strategy.** `systemPromptSwap` (a load changes the catalog lines of the loaded skills in the
  SKILL_CATALOG segment, the LOADED_SKILLS segment and the top-level tools) or `conversationAppend` (Responses and
  Messages, `RunSettings::isAppendOnlyCapableAgentWireApi()`).
- **Rejections** carry a reason instead of a silent switch: `REJECTION_CONVERSATION_APPEND_NEEDS_RESPONSES`,
  `REJECTION_RESPONSES_NEEDS_OPENAI_MODEL`, `REJECTION_RESPONSES_NEEDS_DIRECT_OPENROUTER`,
  `REJECTION_MESSAGES_NEEDS_ANTHROPIC_MODEL`, `REJECTION_MESSAGES_NEEDS_DIRECT_OPENROUTER`,
  `REJECTION_SERVER_STATE_NEEDS_CONVERSATION_APPEND`, `REJECTION_SERVER_STATE_WITH_STATE_NOTES`. They apply to a
  value the conversation's row names; a catalog value falls back as described above.

All API bodies are composed from one API-neutral `AgentWireRequestParameters`: `AgentChatCompletionsRequestComposer`,
`AgentResponsesRequestComposer` (`AgentResponsesInputItems` maps the same messages: system → one `developer`
message, `function_call` / `function_call_output`, `input_image`, the appended `additional_tools` items) or
`AgentMessagesRequestComposer` (section 5a).

The strategy is stamped at creation (`ContextRetentionSettings::buildForNewConversation()`): the global default
`conversationAppend` becomes `systemPromptSwap` when the new conversation's model speaks Chat Completions.

- A strategy the start request names (`RunSettings::$requestedSkillLoadingStrategy`) is kept, and refused on Chat
  Completions: the start answers a bad request with `REJECTION_CONVERSATION_APPEND_NEEDS_RESPONSES`.
- A purpose's wire profile (its `contextRetention:` choices and run settings) that the model cannot run is NOT
  refused: the purposed start drops it (`AIConversationPurposeRunsService`), starts with the purpose's other knobs
  (`AIConversationPurpose::buildContextRetentionSettingsForConversationWithoutAgentWireProfile()`) and the wire-API
  default — the swap on Chat Completions. The developer sees it in the trace line `purpose.agentWireProfile.fellBack`
  (purpose, model, reason) and in `AIConversationPurposeStartSettings::$agentWireProfileRejectionReason`.
- A stored conversation without a stamped strategy or delivery is stamped at its first run start
  (`AIConversationsService::stampCreationSettingsOfUnstampedConversation()`): one that already made a model call gets
  `systemPromptSwap` and `everyCall` (the shape its history already has), one without a model call its stored value or
  the current default for its wire API. A later change of the default never moves a running conversation.
- A model picked mid-conversation that speaks only Chat Completions moves a `conversationAppend` conversation that
  already made a model call to `systemPromptSwap` at the next run start
  (`AIConversationsService::switchToSystemPromptSwapForChatCompletionsModel()`, trace
  `conversation.skillLoadingStrategy.switchedForChatCompletionsModel`) — the one change of the stamp, and one-way: the
  swap runs on both wire APIs. A strategy named at the creation of a Chat Completions conversation is still refused.
- The console chat stamps a new conversation from the model it runs on (`--model`, `--tier`;
  `AgentConsoleChatScopeTrait::$modelNameOfNewConversation`), not from the agentic default.

**Where the combination is checked.** At creation (a bad request, nothing stored); at every run start
(`AIConversationAgentService` fails the run with an error row and the owner report of a service error); when an
override of the run settings is applied (`AIConversationsService::applyAdminRunSettingsOverride()`, a bad request); and
in a model call made outside a run (`AIConversationModelCallsService::performModelCall()` throws
`AIModelCallFailedException`).

**The Messages answer.** `AgentMessagesResponse` reads it (`type: "message"`): the text, the `tool_use` blocks in the
Chat Completions shape, the content blocks verbatim (stored for the replay), the usage (the booked input is the
uncached remainder plus the tokens read from and written to the cache; `output_tokens_details.thinking_tokens`) and
`input_transformations` — the thinking blocks the provider dropped from the REQUEST. An answer without text and
without a call (a `refusal` stop, a cut at `max_tokens` inside the thinking) fails the call with the outcome
`messagesAnswerUnusable`. The readers live in this package: ddd-ai's language-model trait asks three hooks
(`isAnswerObjectWithoutEnvelope()`, `extractAnswerText()`, `extractAnswerUsage()`) which
`ArgusAIAgentConversation` overrides for this answer shape. A Messages body carries no service tier, so none is noted
for the call and no tier multiplier enters its booking.

**The Responses answer.** `AgentResponsesResponse` reads the answer: the calls in the Chat Completions shape, the
reasoning items and the usage including the tokens written to the cache (`AgentResponsesUsage`). An answer the provider refused or cut off (status
incomplete) that carries neither text nor a call fails the call on the provider-error path with the outcome
`responsesAnswerUnusable` (the error row, the report, the failed run), never as an empty answer the loop would treat
as a stall.

## 4. Append-only loading and prefix epochs

Under `conversationAppend` (`AIConversation::buildPrefixEpoch()` → `AgentPrefixEpoch`):

- **Epoch start**: index 0, or the first raw row after the compaction cover's edge, or the state epoch's start when
  that is later. Its **base set** is the capability set at that start; the system content renders the base set with
  the variants of the epoch's first call; the top-level `tools` are the meta tools plus the base set's tools.
- **Each call** (`AgentAppendOnlyCall`) re-renders, at the recorded positions, what earlier calls of the epoch appended
  (read from `RequestSnapshot::$appendedSkillNames`, `$appendedToolNames`, `$appendedSituationChange`, anchored at
  `$appendedAfterMessageIndex`) and appends what is new: skills loaded since (their instructions as a `developer`
  message), joining tools (an `additional_tools` item) and a `# Situation` entry for a variant change.
- **A preload looks like the model's own load.** Skills the platform loaded before the turn's first model call
  (decision model, plan item, structural — any `LoadedCapability::$source` but `model`) are appended behind a
  `load_skills` call and its result (`call_preload_<row index>`, `AIConversationMessage::LOAD_SKILLS_RESULT_LOADED`),
  recorded in `RequestSnapshot::$appendedPreloadLoadCallSkillNames` and re-rendered at the same position. Shown only as
  instructions, the preloaded skill was loaded again by the model as its first call; its own load it never repeats.
  The decision model's preload on row 0 is no part of the first epoch's base set
  (`LoadedCapabilities::withoutSkillsLoadedBySource()`): call 0 appends it in that shape too, and the base prefix (prompt
  + base tools) is the same for every new conversation, whatever the preload picked. A structural or plan-item load on
  row 0 stays in the base set (the same per purpose, cached across conversations).
- **Conditional meta tools** (`AgentAppendOnlyCall::CONDITIONAL_META_TOOL_NAMES`) join once where their condition
  first holds and stay; a cold epoch carries them top-level (`AgentPrefixEpoch::$carriedConditionalMetaToolNames`).
- **Reachable tool set top-level.** When the estimated schemas of every tool the purpose may reach fit
  `appendOnlyReachableToolSetCeilingTokens` (55000), `AgentAppendOnlyToolSet::TOP_LEVEL_REACHABLE_TOOLS` offers them
  all from the epoch start; a skill load then appends text only, and a call of a tool whose skill is not loaded is
  refused with "load the skill first". Above the ceiling each load appends an `additional_tools` item.
- **Situation epochs.** With `rebuildEpochOnSituationChange = true` the system content carries the situation-dependent
  sections; a situation change (`AgentPrefixEpoch::isStartedAnewBySituationOf()`) starts a new epoch whose system
  content carries every skill loaded so far, at the cost of one cache miss after the base prompt. With `false` a
  change appends a `# Situation` entry instead.
- **Nothing unloads** inside an epoch; a step that narrows its offer (`AgentStepToolRestriction`) keeps the whole list
  on the wire and the dispatch refuses a held-back tool; a step left without any tool sends `tool_choice: none`, which
  keeps the cached prefix. The package never sends allowed_tools: that list renders into the prompt.
- **Base set before the first raw row** is read from the stored chain
  (`AIConversationMessagesService::findLoadedCapabilitiesBeforeIndex()`), memoized per run and start index.
- **No repeats.** A recorded appended item never repeats a skill or a tool the epoch's base set or top-level tools
  already carry (possible when a compaction edge falls between a load row and the call that recorded it).
- **A discarded step keeps its record.** When a step's response is discarded (a stall, a bounce, a retry, a
  budget-closing stall, the injection re-drive), the signal row that carries its usage carries the call's slim
  snapshot too (`RequestSnapshot::buildRecordForRowOfDiscardedResponse()`, no debug payload), so later calls place its
  appended items where it sent them.

The appended items are wire items recorded on request snapshots, never rows: the loop's newest-row checks and the
compaction summarizer never see them.

## 5. Reasoning carry-over

`AgentWireConfiguration::$previousReasoningCarryOver` (resolved from `RunSettings`): `all`, `currentTurn`, `capped`,
`off`. Order: the conversation's row, else the model's catalog value (`AIModel::$previousReasoningCarryOver` in
mgamadeus/ddd-ai, host-overridable in `config/app/AI/models.php`; applied on the Responses API and on Chat Completions
over the direct OpenRouter egress only, where `reasoning_details` reach the provider), else the per-API default:
`capped` on Responses and on Messages, `off` on Chat Completions; always `off` on the server-state arm. Kimi K3.1 Pro sets
`currentTurn`: it keeps its verdicts (what it saw on pictures) in its reasoning, so without its own items it re-read the
same pictures until the repeat guard stopped it.

**Only the producing model gets its items back.** `RequestSnapshot::holdsWireReasoningItemsFor($wireApi, $modelName)`
compares the stored `RequestSnapshot::$modelName` with the model of the current call — a conversation can switch models
mid-way (`aiModelNameOverride`). Items stored without a model name go back only on the Responses API (encrypted items
are model-bound and a refused item repeats the call without carried items); on Chat Completions they are dropped. The
`capped` cut sums only the items the call would send. Whether a different host of the same model (OpenRouter order
rotation on a retry) accepts carried items is not documented by OpenRouter; plain `reasoning.text` items are expected to
pass, and a refusal naming `CARRIED_REASONING_REJECTION_ERROR_TEXTS` repeats the call without them. Under `capped`,
`AgentPreviousReasoningCut` keeps the previous steps' reasoning items until their sum exceeds
`previousReasoningCapTokens` (4000), then drops all but the newest `KEPT_NEWEST_STEPS_AT_A_CUT` (2) steps' items in one
block and records the cut index (`RequestSnapshot::$previousReasoningCutFromMessageIndex`): a sawtooth with a stable
prefix between two crossings. A step's reasoning size is the provider's count (`RequestSnapshot::$wireReasoningTokens`),
else an estimate from the items' JSON (`AgentPreviousReasoningCut::ESTIMATED_REASONING_ITEMS_JSON_OVERHEAD_BYTES`,
1,600 bytes, plus `ESTIMATED_REASONING_ITEMS_JSON_BYTES_PER_TOKEN`, 13 bytes per token);
`RequestSnapshot::$previousReasoningSentTokens` is the carried size of the call. A provider error naming one of `AgentWireConfiguration::CARRIED_REASONING_REJECTION_ERROR_TEXTS`
makes the call retry without carried items (`withoutPreviousReasoningCarryOver()`).

**Why the cap is 4000.** The cut keeps the two newest steps, so the cap must sit well above two steps' reasoning, or
every further step moves the cut and the cached prefix ends at the first dropped item. At medium effort a planning step
reasons for 300–900 tokens: with a cap of 1000 (the shipped value before 0.1.3) the cut moved on 8 of 11 call pairs of
one planning turn, each a partial miss back to the moved item. At 4000 the two kept steps restart the block at about
0.6–1.8k, and a cut is followed by several steps with a stable prefix; only steps above 2,000 reasoning tokens each
cut on every call. Measured on a Responses wire with explicit breakpoints over a six-turn conversation (13–16 calls,
two runs each):

| Carry-over | Cached share | Seconds per model call |
|---|---|---|
| `capped`, 1000 | 95.5 % / 94.7 % | 2.05 / 1.88 |
| `capped`, 4000 | 95.8 % / 94.4 % | 2.06 / 2.54 |
| `all` | 95.9 % / 96.0 % | 1.89 / 1.95 |

`all` drops nothing and has the best cached share, but carries every step's items for the whole conversation (the
input grows with it); `capped` at 4000 bounds the carried block. The latency differences are inside the run-to-run
noise: a call's duration follows its output tokens (about 1.35 s plus 0.49 s per 100 output tokens), not its uncached
input, so the cap is a cache and cost lever, not a latency one. A host that wants `all` sets it per conversation
(`RunSettings::$previousReasoningCarryOver`, a purpose's `runSettings`); a host that wants another cap sets
`previousReasoningCapTokens`.

**A cap per wire API.** `previousReasoningCapTokensByAgentWireApi` (`AI.contextRetention`, keyed by the wire API:
`responses`, `messages`) gives one wire API its own cap; a wire API without an entry takes `previousReasoningCapTokens`,
a conversation's `RunSettings::$previousReasoningCapTokens` wins over both. The package ships no entry (every wire API at
4000). The reason to set one: on the Messages API a cut drops thinking blocks from the cached history, so the next call
writes that history to the cache again, while the blocks below the cap cost little to keep — in a measured
conversation the carried thinking stayed under 3,000 tokens after nine calls, so a cut there costs far more than it
saves. On the Responses API the cut is what bounds the input and stays wanted.

## 5a. The Messages API: thinking blocks and the frozen part

On the Anthropic Messages API a thinking block is signed and bound to the prefix that produced it: the system prompt,
the tool set and every message before the block. A request that changed anything before a block loses that block and
every later one. The request always asks for `thinking.block_binding.prefix_mismatch_behavior: drop_block`
(`AgentMessagesRequestComposer::THINKING`), so the provider drops instead of refusing, and names each drop in the
answer's `input_transformations` (reasons `prefix_binding_mismatch`, `model_binding_mismatch`). Valid changes: appended
messages, appended `role: "system"` messages, a `clear_at` note left in place, tools declared `defer_loading`, moved
cache markers, and removing the thinking of a LEADING run of steps (each block is chained to the one before it; only a
removal from the middle breaks).

The harness edits history on purpose (the notes at the end of each call, cleared tool results, shortened arguments),
so the history of a call has two parts, decided by `AgentMessagesWireReplay::decideFor()`:

| Part | Rendering |
|---|---|
| Below `frozenFromMessageIndex` | as on every other wire API: every edit applies, no thinking block rides |
| At or above it (frozen) | the assistant message is the content the provider returned (`RequestSnapshot::$wireReasoningItemsJson` with `wireReasoningApi = messages`), tool results in full, call arguments unshortened, an owner message with the cue it was sent with, and the notes of each call re-sent where they were sent (`RequestSnapshot::$messagesWireTailJson`) |

Thinking rides from `thinkingFromMessageIndex` on, never below the frozen part:

| Carry-over | Frozen floor | Thinking floor |
|---|---|---|
| `off` | none | none |
| `currentTurn` | the newest turn initiator | the same |
| `all` | the floor the newest earlier call recorded (`RequestSnapshot::$messagesWireFrozenFromMessageIndex`), else the newest turn initiator | the same |
| `capped` (the default) | as `all` | the cut of `AgentPreviousReasoningCut`: the newest steps whose thinking fits the cap, never below the frozen floor |

**After a drop.** The answer names each dropped block by its place (`messages.<position>.content.<block>`). The egress
knows the step of every assistant message it sent (`AgentMessagesConversation::$stepMarkerMessageIndexesByMessagePosition`)
and records the steps of the dropped blocks on the call's snapshot
(`RequestSnapshot::$messagesWireDroppedThinkingStepIndexes`). From the next call on exactly those steps go out
without their thinking (`AgentMessagesWireReplay::$droppedThinkingStepMessageIndexes`); every other step keeps its
blocks and the thinking floor stays. That is the request the provider read and cached. Probed on Opus 5.5: the next
call reads the whole cache and reports no drop, the blocks before the changed place and the reporting call's own
block stay valid (live, after a picture left at a turn end: the call after the drop read 66k of 68k cached tokens,
the last 2k behind a final answer whose thinking was dropped were written again). Sending the dropped blocks again also keeps the cache, but every call reports them again; removing
the thinking of ALL earlier steps rewrites the cache of the whole history (this was the behaviour of 0.2.0). A call
that reported a drop without the steps (a row of 0.2.0, or a path that names no step) lifts the thinking floor to its
own step. Under `capped` the dropped steps do not count against the cap. A stored answer is sent
verbatim only when its `tool_use` ids equal the call ids of the step's rows; otherwise the step is rendered from the
rows, without thinking. Stored content of any Anthropic model is sent to the model of the call: the provider drops the
blocks that model cannot read and reports it (`model_binding_mismatch`).

**Pictures.** A picture inside a tool result of the frozen part is sent again unchanged and breaks nothing. The
picture cap (`ContextRetentionSettings::getMaxToolCallImagesOnWire()`) is NOT suspended in the frozen part: when a
newer picture pushes an older one off the wire, that result changes, the provider drops every thinking block after it
(`prefix_binding_mismatch`, probed) and those steps go on without their thinking. The same happens when a picture
that is not durable leaves at the end of its worker invocation (a turn end, a worker chunk): the first call of the
next invocation sends the "no longer on the wire" marker in its place. Seen live: a page picture viewed in turn 1,
turn 2's first call lost the cache from that result on and three thinking blocks after it. One drop per picture that
leaves; the cache before the picture and the thinking before it stay. A tool whose pictures should survive a turn end
returns durable references (`ToolCallImageStore`). `app:agent:cache-diff` shows each drop.

**Notes of a call.** The volatile tail (signals, the plan, the time) goes out as ONE `role: "system"` message with
`clear_at: next_user_message` after the whole history: the model sees it on this call, later calls send it again at the
same place and the provider hides it. A tool result's user message counts as the next user message. The API accepts
such a note only after a user turn and before an assistant turn or at the end; a note placed elsewhere (after an
assistant message) goes out without `clear_at` and stays visible
(`AgentMessagesRequestComposer::withClearAtOnlyWhereTheApiAcceptsIt()`).

**Skill loads.** The instructions a load appends go out as a `role: "system"` message at that place, its tools as
`tool_addition` blocks; the tools are declared top-level with `defer_loading: true`, which changes neither the tool
set a block is bound to nor the tools hash of the snapshot (`RequestSnapshot::withoutDeferredTools()`,
`recordAppendedToolsHashOfDeferredTools()`).

**Request shape.** Headers `anthropic-version` and the betas of `AgentMessagesRequestComposer::BETAS`; `thinking:
adaptive`; `output_config.effort` from the step's effort; `max_tokens` from the catalog's maximum output; no
temperature; a forced tool choice goes out as `auto` plus the `[SIGNAL:tool-expected]` line where the catalog says so.

**Provider pin.** A Messages call is pinned to Anthropic (`ArgusAIAgentConversation::ANTHROPIC_PROVIDER_PIN`: `only`,
no fallback): the signatures are valid on the host that minted them and the cache is per provider. Nothing automatic
leaves the pin. An operator's console provider order (`forceProviderOrder()`, `hasConsoleProviderOrder()`) is itself a
pin for one process and replaces it; the egress then sends no carried reasoning on that call (the same holds for the
OpenAI pin of a call that carries reasoning items).

**Refusals.** A provider error naming one of `CARRIED_REASONING_REJECTION_ERROR_TEXTS` (a thinking block refused in
spite of `drop_block`) repeats the call once without carried thinking. Dropped blocks are recorded on the snapshot
(`RequestSnapshot::$inputTransformations`) and as the trace event `turn.modelCall.inputTransformations`.

## 6. The cached base prompt

`AgentBaseSystemPromptCacheService::resolveBaseSystemPrompt()` keeps the composed base prompt in the cache so a turn
rarely builds it on its critical path. Expiry `BASE_SYSTEM_PROMPT_TTL_SECONDS` (one hour) on both paths.

**`onceWhenChanged` (the package default).** The base prompt is a template without the space's data
(`resolveBaseSystemPromptTemplate()`). Its key (`buildTemplateCacheKey()`): account, space id, plan-dynamics mode, the
account's inputs version, the prompt-files fingerprint, the product name and `TEMPLATE_BUILD_FORMAT_VERSION`. The
space's context text is cached under its own key (`buildAgentSpaceContextCacheKey()`: space, the space's inputs
version, the fingerprint of its unversioned inputs) and resolved once per run (`resolveStateDeliveryBaseValues()`).
A miss builds at once; nothing waits.

**`everyCall`.** The whole prompt, space data and addressing included, under `buildCacheKey()`: account, space,
plan-dynamics mode, the inputs versions of space and account, the fingerprint of the inputs no typed write bumps
(`buildPromptInputsFingerprint()`), the prompt files and `BUILD_FORMAT_VERSION`. A worker that misses while a warm-up
of the same key runs polls for its result every `WAIT_FOR_WARM_POLL_MILLISECONDS` (50) while the warm marker exists,
at most `WAIT_FOR_WARM_MAX_MILLISECONDS` (the marker's lifetime, `WARM_MARKER_TTL_SECONDS` = 5), then builds itself.
The warm-up sets the marker (key plus `WARM_MARKER_SUFFIX`) with its own token, builds, and writes the result only
while the marker still holds that token; a younger warm-up of the same key replaces the marker, the older one then
writes nothing. Marker check and write, and marker check and delete, are one atomic cache step each.

On both paths:

- **Inputs versions.** A version is a random token per space or per account under `INPUTS_VERSION_KEY_PREFIX`, kept
  `INPUTS_VERSION_TTL_SECONDS` (one month); a missing token is created anew, never read as an old one, so a prompt
  cached under a token is always younger than it (`findInputsVersion()`).
- **Bumps.** `bumpInputsVersionOfAgentSpace()` / `bumpInputsVersionForAccountId()` write a new token, so the next run
  builds fresh. While a database transaction is open the bump is held on the service and written when the transaction
  ends (`AgentBaseSystemPromptTransactionEndListener` on commit and rollback; a rollback renews too, an outer
  transaction level still open defers it further): a reader that took the new token before the commit would cache the
  old rows under it.
- **The host's builders read fresh.** A bump changes only the key. The text stored under the new key is whatever the
  host's builder returns on the first read after it. A builder that takes its values from an object the process
  loaded before the write (the entity a conversation's space holds, a settings object filled once per process)
  returns the old text, and the old text is then cached under the new token for `BASE_SYSTEM_PROMPT_TTL_SECONDS`.
  How it shows: a write tool ran, the token changed, the prompt text did not, and no change note of the state
  delivery names the written field. Inside the builder, read the records past the entity registry.
- **Warm-up after the response.** `queueBaseSystemPromptWarmAfterResponse()` (called by the chat controller) remembers
  the conversation on the service instance of the request; `warmQueuedBaseSystemPrompts()` builds every queued entry
  that is not cached yet. Under `onceWhenChanged` the warm-up builds the template and the space's context text, without
  a marker.
- A conversation without an id is built uncached every time; a failed key build falls back to building.
- The package calls neither the bumps nor the warm-up runner: both are host duties, described in the entry skill
  `ddd-module-agents-specialist`.

## 7. What breaks the cache

| Cause | Where it hits | What contains it |
|---|---|---|
| A skill load or unload under the swap | tools, the SKILL_CATALOG lines of the skills it loads or unloads (in the part before the loaded skills, so from the catalog on) and LOADED_SKILLS | append-only loading; skill-granular unloading once per owner turn |
| A changed base-prompt input (space text, addressing) | from the base prompt on | state epochs: one note instead (`onceWhenChanged`) |
| A compaction round | the COMPACTION_SUMMARY and the history after it | deliberate; batched by the compaction trigger |
| A new prefix epoch (cover, situation, cold state epoch) | from the base prompt or the skills on | one miss per epoch |
| A deploy that edits a prompt file or a skill file | the base prompt or the item rendering it | one miss |
| A tool result stubbed later | the history from that row | the turn window moves once per owner turn; the token budget can still stub a result within a turn |
| A tiny result stubbed to a longer stub | that row | `ToolCall::MIN_RESULT_CHARS_TO_STUB` |
| An older `update_task_plan` call's arguments stubbed (`{"superseded":true}`) | the history from that call | only calls of earlier turns, once at the turn boundary; the running turn's plan calls keep their arguments |
| Carried reasoning items dropped by the `capped` cut | the history from the first dropped item | the cap (4000) well above the two kept steps; `all` drops none |
| Messages API: the frozen floor moves up at a turn start (`currentTurn`) or the thinking floor moves (`capped`) | the history from the first step that returns to the plain rendering or loses its thinking | `all` and `capped` keep the recorded frozen floor across turns |
| Messages API: an edit inside the frozen part (a picture leaving under the picture cap or at the end of its worker invocation; anything else should not happen) | the history from the edit on, once; the provider drops the thinking after it and reports it, and those steps go on without thinking | the frozen rendering; durable pictures; `app:agent:cache-diff` and `inspect --snapshots` name the drop |
| A picture leaving the image cap | that result | the cap keeps the newest pictures |

## 8. Host checklist

1. Give the model `effectiveContextTokens`, `contextWindowTokens`, `agentWireApi` and its prompt cache mode in the
   catalog (`mgamadeus/ddd-ai`).
2. Keep the space's context text deterministic: same inputs, same bytes (no timestamps, no unordered lists).
3. Call the inputs-version bumps from the writers of what the space contributes; register the warm-up subscriber.
4. Compare a call with the previous one with the request snapshot hashes (`RequestSnapshot::$systemSegmentHashes`,
   `$toolsHash`, `$appendedToolsHash`); the tooling around them belongs to the observability skill.
