# Memories and standing directives

**Reader:** host developer

Contents: 1. What belongs in a memory · 2. What the core asks of the host · 3. Standing directives in the model
input · 4. Episodic history · 5. Moments in memories · 6. Host checklist (own memory component) · 7. The package's
memory store

The core asks its memory questions through `AgentMemoriesService` (the port). Two ways to answer them:

- **The package's memory store** (section 7): switch it on with `AI.agentMemory.enabled` in
  `config/app/AI/agentMemory.php` once its table exists. It stores, curates and recalls memories and brings the memory
  tools. Off by default: then every method of the port answers "no memory" and nothing touches the table.
- **The host's own memory component**: a same-path subclass of `AgentMemoriesService` under the host's root namespace
  that overrides the methods (the switch stays off).

This reference says what the core asks, where the answers land in the model input, and how the package's store works.

## 1. What belongs in a memory

A memory holds only the durable, unstructured residual that has no field anywhere: a way the owner wants to be
addressed, a rule for the agent's own replies, a soft fact or approach of the owner. Anything with a home in the host's
structured data (settings, records a tool edits) is edited there through its tool and never copied into a memory: a
copy drifts from the source of truth. Standing directives are the one kind the core injects on every call; the rest is
searched on demand.

## 2. What the core asks of the host

| Method of `AgentMemoriesService` | Called | Default |
|---|---|---|
| `findStandingDirectivesForAgentSpace($accountId, $agentSpace)` | while building the model input (phase J), once per conversation object, again only after a call of a tool declaring `MCPToolWritesAgentMemoryOnly` completed | null (the store: its current directives) |
| `curateConversationAfterTurn($conversation, $async)` | after an owner turn of a root that ended with nothing new arriving during it (`AIConversationTurnEndFollowUpsService`), with `$async = true`; a failure is swallowed | nothing (the store: `AgentMemoryCurateMessage`) |
| `findSimilarMemories($query, $accountId, $agentSpace, $limit)` | by `SemanticSearchService::searchMemory()` | empty `AgentMemoryHits` (the store: its nearest memories) |
| `getEmbeddingRepairTargets()` | by the embedding repair ([recall-and-search.md](recall-and-search.md)) | empty `AgentEmbeddingRepairTargets` (the store: its table) |

`$agentSpace` null means the account-wide entries only, never those of the account's spaces.

## 3. Standing directives in the model input

- `AgentStandingDirectives::$directivesBlock` is appended as the MEMORY_DIRECTIVES segment exactly as returned, after
  the time rule and the owner presence, before the episodic history: the block carries its own leading separator and
  header.
- `AIConversation::findMemoryDirectivesBlock()` memoizes the block on the conversation object under a key of account,
  space and the number of completed calls of the tools declaring `MCPToolWritesAgentMemoryOnly`. **Trap:** a host
  memory tool without the attribute never refreshes the block within one conversation object; its change reaches the
  model with the next conversation object (the next run). Put the attribute on the host's memory tools (only on tools
  whose sole writes are memory rows: the same attribute lets a call run inline beside forked calls).
- Under `onceWhenChanged` the block is part of the epoch record; a change arrives once as a `standing-instructions`
  note that replaces the directives of the instructions ([volatile-context-once-per-change.md](volatile-context-once-per-change.md)). Under `everyCall` a
  change rewrites the prefix from that segment on.
- `AgentStandingDirectives::$newestValidFrom` lets the reply-language decision weigh a directive against the age of the
  conversation's anchor (decisions-and-guards skill).

Keep the block short and stable in byte order (sort the directives): it sits in the cached prefix of every call of the
space.

## 4. Episodic history

At creation a root gets a narrative of recent sessions (`AIConversationsService::composeAndPersistEpisodicHistory()` →
`AIConversations::buildEpisodicHistoryText()`), stored on `AIConversation::$episodicHistory` and rendered as the
EPISODIC_HISTORY segment for the whole conversation:

- the `EPISODIC_RECENT_WINDOW_SIZE` (5) newest sessions of the space (without a space: across all spaces of the account) that hold a
  usable summary, one line each with date, title, outcome and `#conversationId`;
- up to `EPISODIC_SURVIVOR_CAP` (3) older sessions whose summary importance is at least
  `EPISODIC_SURVIVOR_MIN_IMPORTANCE` (4);
- a closing line that points the model at the host's recall tools.

It is never re-read during the conversation, so it never moves the prefix. A purpose that loads earlier sessions itself
(`AIConversationPurpose::$loadsEarlierSessionsItself`) gets none; a delegation child never gets one. The pointer line
names no tool: a host that binds recall tools states them by overriding
`AIConversations::getSentenceOnReadingASessionInFull()` in its same-path subclass of `AIConversations`. The text is
stored once per conversation, so a conversation that has it keeps the sentence it was written with.

## 5. Moments in memories

A memory that records when something was said keeps the instant and the zone it was read in; the zone of a call is
`AgentToolCallProvenance::getTimezone()` and a recorded call's zone is on its request snapshot
([owner-presence-device-and-time.md](owner-presence-device-and-time.md)).

## 6. Host checklist (own memory component)

1. Same-path subclass of `AgentMemoriesService` (template in [SKILL.md](../SKILL.md)).
2. The memory tools the model calls, in a skill of the host that loads on demand, not as tools offered on every call
   (tools and skills-and-purposes skills): an always-offered tool's schema is sent and billed again on every call, and a
   long flat tool list weakens a cheap model's tool choice, while memory operations are rare. Only tools declaring
   `MCPToolWritesAgentMemoryOnly` refresh the directives within a run.
3. An embedding provider (`AITextEmbeddingsService` subclass) if `findSimilarMemories()` is vector-based, and the
   memory table in `getEmbeddingRepairTargets()`.
4. Curation off the critical path: `curateConversationAfterTurn()` is called with `$async = true`; dispatch a message,
   never run model calls inline.

## 7. The package's memory store

**Switch it on.** Create the table `AgentMemories` (the schema diff of the framework lists it; the generated model is
`DBAgentMemoryModel`), then ship `config/app/AI/agentMemory.php` with `'enabled' => true` (the file replaces the
package's whole). Run the compaction transport's worker: the curation message travels there. With an embedding provider
(`AITextEmbeddingsService` subclass) the search is semantic; without one a memory is stored with the zero vector, the
search falls back to a text match, and the embedding repair re-embeds the rows once a provider exists.

**What a memory is** (`AgentMemory`, set `AgentMemories`, service `AgentMemoryStoreService`):

| Column | Meaning |
|---|---|
| `accountId` | the owning account, set on every row; the read-rights anchor (`AgentAccountRightsService`) |
| `agentSpaceId` | the space of a memory about the business; empty = account level (a memory about the owner as a person, or one said without a space) |
| `memoryType` | `DIRECTIVE` (injected into every conversation of its scope), `FACT`, `SOLUTION` (searched on demand) |
| `subject` | `USER` (about the owner as a person → account level) or `BUSINESS` (→ the conversation's space) |
| `content`, `embedding` | the text (at most 1000 characters) and its vector (1536 dimensions; the zero vector when none was generated) |
| `source` | `USER_STATED`, `AGENT_INFERRED`, `ADMIN_CONFIG`, `USER_MANUAL` |
| `validFrom`, `validTo`, `invalidatedAt`, `supersededByMemoryId` | bitemporal: a newer value closes the old row, nothing is deleted |
| `contentHash`, `scopeKey`, generated `virtualCurrentDedupKey` (unique) | at most one current row per scope (`s:<space>` / `a:<account>`) and content |
| `sourceMessageId`, `sourceInstant`, `sourceTimezone` | where and when the memory was said |

A read "for a space" returns the account-level rows and that space's rows, never those of the account's other spaces.

**Curation** (`AgentMemoryCurationService`, model calls in `AgentMemoryCurationModelCallsService`): after an owner turn
of a conversation where the owner was present (never a scheduled, background, delegated, supervisor or evaluation run),
`AgentMemoryCurateMessage` (compaction transport) harvests the new owner messages since the last harvest:

1. **Extraction** (`ArgusAgentMemoryExtraction`, prompt `AI.Memory.Extract`): candidate memories of the new messages,
   with the earlier messages as read-only context. `AI.agentMemory.structuredHomes` (string list) names the host's data
   with a home of its own (opening hours, products, a writing setting …); such data is never copied into a memory.
2. **Reconciliation** (`ArgusAgentMemoryReconcile`, prompts `AI.Memory.Reconcile` and, for a candidate that settles a
   raised contradiction, `AI.Memory.ReconcileResolution`): ADD / UPDATE / INVALIDATE / NOOP / CONFLICT against the
   nearest stored memories of the scope (and every current directive when a candidate is a directive).
3. **Apply**: ADD stores unless the scope holds the same content; UPDATE / INVALIDATE store the successor and close the
   target (a target whose label or first words do not match falls back to an ADD); CONFLICT stores nothing and appends a
   `MEMORY_CONFLICT` signal (with where and when the old value was said, `AI.Memory.ConflictExcerpt`) so the agent asks
   the owner, and resumes the conversation.

Every content is written in the owner's interface language (`AgentSpacesService::resolveUserLanguageCode()`); the
costs are booked under `AI.MEMORY.EXTRACT.CANDIDATES` and `AI.MEMORY.RECONCILE.DECIDE` inside the envelope
`ENVELOPE.AI.MEMORY.CURATE`, billed to the space's billing entity. A model that gives no answer puts the harvest back
(at most five times); a turn still running defers it.

**Tools** (`AgentMemoriesMCPPackage`, id `agent.memories`, skill `Memory.Management`, bound in the package's default
`skillPackages.php`): `listMemories`, `searchMemory`, `remember`, `updateMemory`, `forget`. The account is the one the
call acts for; the space defaults to the conversation's. The three writes declare `MCPToolWritesAgentMemoryOnly`, so a
new directive reaches the running conversation. While the store is off the package reports itself unavailable
(`MCPPackage::isAvailable()`): the binding leaves it out, the skill disappears, and a direct call is refused. A host
whose `skillPackages.php` replaces the package's file lists `AISkill::MEMORY_MANAGEMENT => ['agent.memories']` itself.

**Adopting the store with an existing memory table.** A host that already keeps memories in its own table maps the
store onto it through `tableNames` (its database config, `Base.Repo.database.tableNames`) only when the columns match the table above;
otherwise it keeps its own component.
