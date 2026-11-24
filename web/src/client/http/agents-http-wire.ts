/**
 * The wire shapes of the module's own conversation routes (`AgentConversationReadsTrait`, `AgentConversationWritesTrait`,
 * `AgentConversationStartTrait`, `AgentSelectableModelsTrait`): the response DTOs and the entities they serialize, as far
 * as the HTTP binding reads them. Every field is optional on the wire — a read hides some (the forensics outside an admin
 * session, the summary on message rows), and an application may derive the classes — so the mappers
 * (`agents-http-mappers.ts`) default each one. An entity set arrives as `{ elements: [...] }`; the mappers also take a
 * plain array.
 */

/** An entity set or object set as the server serializes it. */
export type WireSet<Element> = { elements?: Element[] | null } | Element[];

/** `AIConversationSummary`. */
export interface WireConversationSummary {
  title?: string | null;
  text?: string | null;
  manuallyEdited?: boolean | null;
}

/** `CustomerProgressStep`. */
export interface WireCustomerProgressStep {
  key?: string | null;
  customerLabel?: string | null;
  status?: string | null;
}

/** `ChangeHistory` (`ChangeHistoryTrait`). */
export interface WireChangeHistory {
  createdTime?: string | null;
  modifiedTime?: string | null;
}

/** `AIConversation`. */
export interface WireConversation {
  id?: number | null;
  agentSpaceId?: number | null;
  runState?: string | null;
  agentTurnOwedAt?: string | null;
  summary?: WireConversationSummary | null;
  changeHistory?: WireChangeHistory | null;
  /** Flat date-times, as a derived class or a projection may write them. */
  created?: string | null;
  updated?: string | null;
  purposeName?: string | null;
  initiatorType?: string | null;
  scheduleId?: number | null;
  entryPointKey?: string | null;
  customerProgressSteps?: WireSet<WireCustomerProgressStep> | null;
  lastMessageId?: number | null;
  aiPromptExtensionId?: number | null;
  messages?: WireSet<WireConversationMessage> | null;
}

/** `ToolCall`. */
export interface WireToolCall {
  toolCallId?: string | null;
  name?: string | null;
  arguments?: unknown;
  status?: string | null;
}

/** `DelegationSubAgentRun`. */
export interface WireDelegationSubAgentRun {
  runNumber?: number | null;
  status?: string | null;
}

/** `DelegationSubAgentRecord`. */
export interface WireDelegationSubAgentRecord {
  currentRunNumber?: number | null;
  runs?: WireSet<WireDelegationSubAgentRun> | Record<string, WireDelegationSubAgentRun> | null;
}

/** `AIConversationMessage`. */
export interface WireConversationMessage {
  id?: number | null;
  aiConversationId?: number | null;
  indexInConversation?: number | null;
  role?: string | null;
  messageType?: string | null;
  status?: string | null;
  authorType?: string | null;
  signalSource?: string | null;
  message?: string | null;
  createdDate?: string | null;
  toolCall?: WireToolCall | null;
  delegatedConversations?: WireSet<{ id?: number | null; runState?: string | null }> | null;
  /** `DelegationResults`: the sub-agent record of a newer `delegate` call row. */
  delegationResults?: { subAgent?: WireDelegationSubAgentRecord | null } | null;
  /** The admin-only forensics; hidden outside an impersonating admin session. */
  inputTokens?: number | null;
  outputTokens?: number | null;
  costInUSD?: number | null;
  requestSnapshot?: unknown;
}

/** `AgentActivityLine`. */
export interface WireActivityLine {
  toolCallMessageId?: number | null;
  batchId?: string | null;
  state?: string | null;
  kind?: string | null;
  childCount?: number | null;
  helperIndex?: number | null;
  line?: string | null;
}

/** `SemanticSearchHit`. */
export interface WireSemanticSearchHit {
  messageId?: number | null;
  conversationId?: number | null;
  indexInConversation?: number | null;
  role?: string | null;
  excerpt?: string | null;
  relevance?: number | null;
  created?: string | null;
  updated?: string | null;
  title?: string | null;
}

/** `AIConversationSearchGroup`. */
export interface WireConversationSearchGroup {
  conversationId?: number | null;
  title?: string | null;
  summary?: string | null;
  created?: string | null;
  updated?: string | null;
  relevance?: number | null;
  hits?: WireSet<WireSemanticSearchHit> | null;
}

/** `AgentCustomerSelectableModel`. */
export interface WireSelectableModel {
  name?: string | null;
  displayName?: string | null;
  description?: string | null;
  strengthLevel?: number | null;
  speedLevel?: number | null;
  creditsMultiplier?: number | null;
}

/** `AIConversationGetResponseDto` (get, postMessage, injectSignal, retry, title). */
export interface WireConversationResponse {
  aiConversation?: WireConversation | null;
}

/** `AIConversationStartWithMessagePostResponseDto`. */
export interface WireConversationStartResponse extends WireConversationResponse {
  reopenedExistingConversation?: boolean | null;
}

/** `AIConversationsGetResponseDto`. */
export interface WireConversationsResponse {
  aiConversations?: WireSet<WireConversation> | null;
}

/** `AIConversationMessagesGetResponseDto`. */
export interface WireConversationMessagesResponse {
  messages?: WireSet<WireConversationMessage> | null;
  hasMoreOlder?: boolean | null;
}

/** `AIConversationRunStateGetResponseDto`. */
export interface WireConversationRunStateResponse {
  id?: number | null;
  runState?: string | null;
  agentTurnOwedAt?: string | null;
  lastMessageId?: number | null;
  title?: string | null;
  titleManuallyEdited?: boolean | null;
  summary?: string | null;
  updated?: string | null;
  /** A request of `Agent.UI` for the reading client (`AgentClientToolRequestDelivery`) */
  clientToolRequest?: { id: number; requestType: string; argumentsJson: string; secondsLeft: number } | null;
}

/** `AIConversationActivityGetResponseDto`. */
export interface WireConversationActivityResponse {
  turnMessageId?: number | null;
  turnRunning?: boolean | null;
  elements?: WireSet<WireActivityLine> | null;
}

/** `AIConversationSearchGetResponseDto`. */
export interface WireConversationSearchResponse {
  conversations?: WireSet<WireConversationSearchGroup> | null;
}

/** `AIConversationMessageSearchGetResponseDto`. */
export interface WireConversationMessageSearchResponse {
  hits?: WireSet<WireSemanticSearchHit> | null;
}

/** `AIConversationSelectableModelsGetResponseDto`. */
export interface WireSelectableModelsResponse {
  models?: WireSet<WireSelectableModel> | null;
}

/** One route parameter of `AgentClientContext` on the wire. */
export interface WireClientContextRouteParameter {
  name: string;
  value: string;
}

/** `AgentClientContext`: the body field `clientContext` of a start, a posted message and an injected signal. */
export interface WireClientContext {
  routePath?: string;
  routeParameters?: WireClientContextRouteParameter[];
  recordKind?: string;
  recordId?: string;
  tab?: string;
}

/** The JSON error body of a refused request (`errorCode` on the coded exceptions, `retryAfterSeconds` on a quota). */
export interface WireErrorBody {
  error?: string | null;
  message?: string | null;
  errorCode?: string | null;
  code?: string | null;
  retryAfterSeconds?: number | null;
}
