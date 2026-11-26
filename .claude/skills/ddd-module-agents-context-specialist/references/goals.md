# The goal register

**Reader:** host developer

Contents: 1. What a goal is · 2. Switching goals on (code before schema) · 3. The rules of the write path · 4. The
tools · 5. The standard goal tree · 6. The goal store as the host port · 7. Override points · 8. Host checklist

## 1. What a goal is

`AgentGoal` is one row of the goal register: what the owner wants to reach, or what the agent pursues in its service.
Four independent axes:

| Axis | Values | Rule |
|---|---|---|
| `holder` | `OWNER`, `AGENT` | an OWNER goal is the owner's belief (corrected or dropped, never blocked); an AGENT goal has work beneath it |
| `horizonLevel` | `VISION`, `LONG_TERM`, `SHORT_TERM` | a VISION never becomes ACHIEVED |
| `parentGoalId` | a goal id or empty | an OWNER goal never hangs under an AGENT goal; at most `MAX_TREE_DEPTH` (3) levels |
| `status` | `PROPOSED`, `ACTIVE`, `ACHIEVED`, `DROPPED`, `PAUSED`, `BLOCKED` | BLOCKED only for an AGENT goal, with a `statusNote` naming the blocker |

`provenance` (`USER_STATED`, `AGENT_DERIVED`, `CONFIRMED`, `PRODUCT_DEFAULT`, `LEARNED`) decides the initial status:
an agent-derived or learned goal starts PROPOSED, every other goal ACTIVE (`deriveInitialStatus()`). The owner's
confirmation of an agent-derived proposal (PROPOSED → ACTIVE) turns its provenance to CONFIRMED. Progress is never
stored. The scope is the memory store's: `accountId` on every row, `agentSpaceId` for a goal of one space; a read for
a space holds that space's goals and the account-level goals. A goal is not a memory (a fact or a rule for the
agent's work) and not a step of the task plan.

The row is bitemporal like a memory: a reworded or corrected goal is a new row, the old one is closed (`validTo`,
`invalidatedAt`, `supersededByGoalId`). The unique index on the generated `virtualCurrentDedupKey` (`scopeKey` and
`statementHash` while the row is current) keeps one current row per scope and statement.

## 2. Switching goals on (code before schema)

`AI.agentGoals.enabled` (`config/app/AI/agentGoals.php`, default off) means "goals are on", not "the package's table is
used". Off: `AgentGoalsMCPPackage` and `AgentGoalBlueprintMCPPackage` are not available, the skill `Agent.Goals` is not
offered, a direct call is refused (`GOALS_OFF_MESSAGE`). On:

- the store answers `isGoalStoreAvailable()`; the default asks the database's catalog whether `AgentGoals` exists (a
  yes is kept for the process, a no is asked again after `MISSING_TABLE_RECHECK_SECONDS`; `forgetGoalTableCheck()`
  resets it);
- while the table is missing every goal tool completes with the note `NOTE_REGISTER_NOT_AVAILABLE` and reads or writes
  nothing; a write's note opens with the marker `RESULT_MARKER_DOWNGRADED_TO_PROPOSAL`, so the call is no evidence for a
  plan item;
- `app:agent:check-installation` reports the missing table as a WARNING (the table is tolerated,
  `getEntityClassesWhoseMissingTableTheCodeTolerates()`), and only while goals are on and the package's own store
  answers them (`AgentFeatureTableConditionProviderService`).

Create the table from `app:entity:show-sql` for `AgentGoal` (or the schema diff of the database models); then the tools
work.

## 3. The rules of the write path

`AgentGoalsService::persistGoal()` is the one write path of a goal. It cuts the statement (`MAX_STATEMENT_CHARS`) and the
one-line texts, stamps `scopeKey` and `statementHash`, and for a new goal:

1. sets the initial status from the provenance;
2. checks the parent: readable, current, same account, same space or account level, `mayHaveParent()`, depth;
3. compares with the current goals of exactly its scope:
   - the same statement (normalized) returns the stored goal and writes nothing, unless it corrects the horizon, the
     target day, or a DROPPED goal (`isCorrectedByRestatement()`): then the old row is closed first, the new row is
     written, and the old row is pointed at it (`recordSuccessorOfClosedGoal()`) — the two rows share the dedup key;
   - a near-duplicate (`areStatementsNearDuplicates()`, `similar_text` at `NEAR_DUPLICATE_SIMILARITY_PERCENT`) is the
     same goal reworded: the new row is written first, then the old one is closed;
   - anything else is a new row.

`transitionGoalStatus()` refuses a closed version, an unknown status, a status the goal cannot reach
(`isStatusReachable()`) and BLOCKED without a note; `applyStatusTransition()` sets the flags (`requiresUserAction`,
`retryable` only on BLOCKED) and `achievedAt`.

## 4. The tools

| Tool | Effect | What it does |
|---|---|---|
| `getGoals` | READ, parallel-safe | the current goals of the scope, the highest `priority` first; `includeFinished` adds ACHIEVED, DROPPED, PAUSED |
| `addGoal` | WRITE | one goal; `statedByOwner` false stores the agent's proposal (PROPOSED, a plain note asks to confirm it; no marker: it was saved as asked) |
| `updateGoalStatus` | WRITE | one goal of the acting account by `#<id>` |
| `getGoalBlueprint` | READ, parallel-safe | the standard goal tree; offered only while a provider is bound |

Both packages require the right `useAgentGoals` (`AgentAccountRights::USE_AGENT_GOALS`); the package's
`config/app/AI/accountRights.php` maps it to every account. The account is the one the call acts for; the space is the
conversation's or the one `agentSpaceId` names (renamed on the wire with `AI.agentSpaceToolArgument`, checked by
`findAgentSpaceOfToolCall()`). The goal line names the space through `buildAgentSpaceLabelForTheModel()`.

## 5. The standard goal tree

`AgentGoalBlueprint` holds `AgentGoalBlueprintNodes` (an object set keyed by the node `key`) of `AgentGoalBlueprintNode`:
`key`, `parentKey`, `statement`, `holder`, `horizonLevel`, `doneCriterionKey`, `doneCriterionDescription`,
`criterionType` (`STATE`, `CADENCE`, `TREND`), `weight`, `status` (`ACTIVE`, `DEFERRED`), `assessment` (`MET`, `OPEN`,
`UNKNOWN`) and `assessmentDetail`. The render marks a node ✅ met, ⬜ open, ❔ unknown, `·` an unassessed leaf, `▸` an
area; a root is `🎯` and its statement.

The package ships no tree. `AgentGoalBlueprintProviderService` is the port: its default answers null and binds
nothing. An application binds one with a same-path subclass that answers `buildAssessedBlueprintForAgentSpace()`:
build the tree, prune it to the space, assess every leaf (`assess()`). `AgentGoalBlueprintMCPPackage` is available
while goals are on and `isBlueprintProviderBound()` (the container resolves the port to a subclass). An adopted node
is a goal with the node's key as `blueprintKey`; `addGoal` gives such a goal without a `successSignal` the node's
`doneCriterionDescription` as its signal (`applySuccessSignalOfStandardNode()`, before the refusal hook), looked up
with `findStandardNodeByKey()`.

## 6. The goal store as the host port

Every read and write goes through `AgentGoalStoreService`: `isGoalStoreAvailable()`, `findCurrentGoalsForScope()`,
`find()`, `writeGoal()`, `closeValidityWindow()`, `recordSuccessorOfClosedGoal()`, `invalidateCurrentGoalsOfScope()`,
`getColumnsNotPurgedByEvalPurge()`. A host whose goals live in rows of its own puts a same-path subclass of the store
under its root namespace, fills `AgentGoal` from its rows (`id` = the host row's id) and answers
`isGoalStoreAvailable()` itself; the rules of `AgentGoalsService` apply unchanged. It switches goals on without the
package's table; the installation check then does not require that table.

## 7. Override points

| Point | Default | A host overrides it to |
|---|---|---|
| `findReasonToRefuseGoal()` of `AgentGoalsMCPPackage` | accepts (null) | refuse a goal before the write (a completed call: the marker, nothing saved, the reason) or throw a 4xx the model must correct |
| `areStatementsNearDuplicates()` of `AgentGoalsService` | `similar_text` against the threshold | compare by meaning |
| `buildAgentSpaceLabelForTheModel()` of `AgentSpacesService` | `space:<id>` | name the space as its users know it |
| `buildAssessedBlueprintForAgentSpace()` of `AgentGoalBlueprintProviderService` | null | bind its standard goal tree |
| `findStandardNodeByKey()` of `AgentGoalBlueprintProviderService` | the node of the assessed tree | answer a node from the static tree without the assessment |

## 8. Host checklist

1. Create `AgentGoals` (or a same-path subclass of `AgentGoalStoreService` over the host's rows).
2. Switch goals on in the host's `config/app/AI/agentGoals.php`.
3. Map `useAgentGoals` in the host's `config/app/AI/accountRights.php` (or its own rights object).
4. Bind `Agent.Goals` in the host's `config/app/AI/skillPackages.php`: `AgentGoalsMCPPackage` and
   `AgentGoalBlueprintMCPPackage`.
5. Optional: a blueprint provider, the refusal hook, the near-duplicate test.
