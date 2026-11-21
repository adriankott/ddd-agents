# Tool delivery through `call_tool` and the cold-skill drop

**Reader:** host developer and package maintainer

Contents: 1. The two settings · 2. `callTool` / `callToolJsonSchema` · 3. `dropColdSkillsAtCompactionRebase`

## 1. The two settings

Two creation stamps on `ContextRetentionSettings`, both acting only under `skillLoadingStrategy: conversationAppend`.

| Setting | Values | Default for a new conversation | A conversation without the stamp |
|---|---|---|---|
| `toolDelivery` | `native` · `callTool` · `callToolJsonSchema` | `callTool` (`AI.contextRetention.toolDelivery`) | `native` |
| `dropColdSkillsAtCompactionRebase` | bool | `true` | no drop |

The default is read only in `ContextRetentionSettings::buildForNewConversation()`. `getToolDelivery()` and
`dropsColdSkillsAtCompactionRebase()` read the stamp, so a stored conversation never changes its delivery when the
default changes. `AIConversationsService::keepStampedToolDelivery()` refuses another delivery after the first model call
(the stored calls are rendered by it); the drop switch may change at any time. Sub-agents inherit both. Eval and console
arms: `--tool-delivery`, `--drop-cold-skills-at-rebase` (`ContextRetentionSettingsOptionsTrait`, `AgentEvalArmOptionsTrait`).

## 2. `callTool` / `callToolJsonSchema`

- The top-level `tools` are the meta tools (the conditional ones from the first call) plus ONE fixed tool
  `call_tool(name, arguments)`, last. They are the same bytes for the whole conversation; the reachable tool set never
  goes top-level.
- A loaded skill's tools are listed as text in the developer message of the load, before the skill instructions:
  `# Tools added now — call each one through call_tool`, then per tool its name, description and arguments — a compact
  signature (`callTool`) or the JSON schema (`callToolJsonSchema`). No `additional_tools` item is sent.
- The model's `call_tool` call is unwrapped right after the answer (`AgentCallToolDelivery::unwrapCall()`,
  `ToolCall::$deliveredThroughCallTool`). Dispatch, guards, stored rows, traces and eval reports read the real tool
  name; the history sends the call back as the `call_tool` call the model wrote.
- Tolerated: `arguments` as a JSON string, the arguments beside `name`, a twice-wrapped call, a direct call of an
  undeclared tool (flagged).
- Refusals: no tool name → the call form; a name that is no tool of a loaded skill → the list of callable tools; a tool
  of a listed but unloaded skill → "load the skill first".
- A pinned `forceToolChoice` of an undeclared tool pins `call_tool`. `RequestSnapshot::$toolDelivery` records each call.
- Every model-facing load text is worded per delivery; the native wording is byte-identical to the native-only release.

Why: on the Responses API a mid-turn call that appends `additional_tools` reads little from the prompt cache; with
`call_tool` the tool list never changes and the load is plain text in the appended tail.

Known limits: after a wrong skill preload the model calls the nearest listed tool somewhat more often than it loads
the right skill (native recovers more reliably); a `call_tool` can name a tool that exists nowhere (answered with the
callable list).

## 3. `dropColdSkillsAtCompactionRebase`

Inside an append-only epoch nothing is unloaded. With the setting on, the first model call of a turn after a compaction
changed the cover runs the skill-granular unloading (`AgentColdCapabilityUnloading`, `unloadSkillAfterUnusedTurns`,
`minResidencyTurns`) once per epoch (`AIConversationMessagesService::unloadColdCapabilitiesOfRun()`):

- The unused owner turns are counted across the cover: the initiator indexes below the loaded tail
  (`findTurnInitiatorIndexesBelowLoadedTail()`, one query) are added to the recency count.
- What left is recorded in the working state (`AgentWorkingState::getColdCapabilityDrops()`, one
  `AgentColdCapabilityDrop` per epoch: `epochStartIndex`, `afterMessageIndex`, `names`;
  an empty record too, so an epoch is never unloaded twice), removed from the epoch's base set and from the history
  items of the rows up to `afterMessageIndex`.
- A skill leaves as a whole group with its tools; a later need loads it again.

The compaction is the only moment a skill leaves, so its trigger (the whole context) decides how long cold skills stay.
