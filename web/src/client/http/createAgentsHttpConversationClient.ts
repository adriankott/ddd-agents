import type {
  AgentsConversationClient,
  AgentsConversationClientBindingOptions,
  AgentsConversationOperation,
} from "../AgentsConversationClient";
import {
  AGENTS_CLIENT_ERROR_CODE_REQUEST_ABORTED,
  AgentsClientError,
  type AgentsClientContext,
  type AgentsConversation,
  type ChangedConversationMessages,
  type ChangedMessagesRequest,
  type ConversationActivity,
  type ConversationAttachmentReference,
  type ConversationListQueryOptions,
  type ConversationMessageSearchHit,
  type ConversationMessagesPage,
  type ConversationRunStateRead,
  type ConversationSearchGroup,
  type ConversationSearchOptions,
  type ConversationSignal,
  type PostedMessage,
  type BoundConversationSubject,
  type ConversationsOfSubject,
  type ConversationSubject,
  type PurposeStartRequest,
  type SelectableModel,
  type StartedConversation,
  type StartedPurposeConversation,
} from "../../contract/conversation";
import { type AgentUIState, createAgentUIClientId } from "../../ui/agent-ui-state";
import type { AgentUIClientBinding } from "../../ui/AgentUIClient";
import type { ClientToolAnswer } from "../../ui/client-tools";
import {
  acceptArrivedRealtimeMessage,
  type AgentRealtimeListenerCredential,
  type AgentRealtimeMessageDelivery,
} from "../../ui/realtime-arrival";
import { composeAttachmentMessageText } from "../compose-attachment-message-text";
import type { PollingConversationChangeSubscriptionOptions } from "../polling/createPollingConversationChangeSubscription";
import { withPollingChangeSubscription } from "../polling/withPollingChangeSubscription";
import {
  mapClientContextToWire,
  mapHttpFailureToAgentsClientError,
  mapWireBoundConversationSubject,
  mapWireConversation,
  mapWireConversationActivity,
  mapWireConversationMessage,
  mapWireConversationRunState,
  mapWireSearchGroup,
  mapWireSearchHit,
  mapWireSelectableModel,
  readWireSetElements,
} from "./agents-http-mappers";
import type { AccountRealtimeRead } from "../../ui/account-realtime";
import type {
  WireAccountRealtimeMessagesResponse,
  WireConversationActivityResponse,
  WireConversationMessageSearchResponse,
  WireConversationMessagesResponse,
  WireConversationResponse,
  WireConversationRunStateResponse,
  WireConversationSearchResponse,
  WireBoundConversationSubjectsResponse,
  WireConversationsOfSubjectResponse,
  WireConversationsResponse,
  WireConversationSubjectResponse,
  WireConversationStartResponse,
  WireErrorBody,
  WirePurposeStartResponse,
  WireSelectableModelsResponse,
} from "./agents-http-wire";

/** What every request of the binding carries from the host: headers, credentials, mode, cache. */
export type AgentsHttpFetchOptions = Omit<RequestInit, "method" | "body">;

export interface AgentsHttpConversationClientOptions extends AgentsConversationClientBindingOptions {
  /**
   * Where the application's conversations controller is mounted (its `#[Route]`): a path such as
   * `/api/agents/conversations` or an absolute URL. The module's routes are relative to it.
   */
  baseUrl: string;
  /**
   * Request options every request carries (credentials, an authorization header). A function is called per request,
   * so it may hand out a fresh token. Default: none (the browser's defaults: same-origin credentials).
   */
  fetchOptions?: AgentsHttpFetchOptions | (() => AgentsHttpFetchOptions | Promise<AgentsHttpFetchOptions>);
  /** The fetch function. Default: the global `fetch`. */
  fetch?: typeof fetch;
  /**
   * The application's start route below `baseUrl` (`AgentConversationStartTrait` declares none). Default: `/start` for
   * a start without an agent space (key 0), `/start/{agentSpaceKey}` otherwise — the start DTO reads `agentSpaceId`
   * from the path.
   */
  buildStartPath?: (agentSpaceKey: number) => string;
  /**
   * The controller uses `AgentSelectableModelsTrait` (`GET …/selectable-models`): the client offers
   * `getSelectableModels` and the chat shows the model picker. Default false.
   */
  hasSelectableModelsRoute?: boolean;
  /**
   * The live updates: the polling default over the run-state read (`withPollingChangeSubscription`), which also reads
   * at once after a post, a signal or a retry the server accepted. The intervals of
   * `PollingConversationChangeSubscriptionOptions`; `false` = no subscription (the host adds its own push transport).
   * Default: polling with the default intervals.
   */
  polling?: PollingConversationChangeSubscriptionOptions | false;
  /**
   * What the owner's client shows, read on every start, posted message and injected signal and sent as the body field
   * `clientContext` (the route, its parameters, the record on screen, the tab). The agent reads the newest one as one
   * line beside the owner's newest message. A field over its cap is left out, never cut. Default: none.
   */
  clientContext?: (operation: AgentsClientContextOperation) => AgentsClientContext | null | undefined;
  /**
   * The UI side of `Agent.UI` (`AgentUIClient` of `@mgamadeus/ddd-agents/ui`): its client id and UI state ride on every
   * write and every run-state read; a client tool request the run-state read delivers goes to it, and the binding
   * posts its answer (`POST /{conversationId}/clientToolRequests/{requestId}/answer`). Default: none; the account
   * read alone then states a client id the binding creates once (`getAccountRealtimeMessages`).
   */
  uiClient?: AgentUIClientBinding;
  /**
   * The open messages of the realtime port of other types than a client tool request (types other packages
   * registered), as the run-state read delivers them; a client acts on one type and key once. Default: none.
   */
  onRealtimeMessage?: (conversationId: number, message: AgentRealtimeMessageDelivery) => void;
  /** What the client needs to listen on the host's transport, with every run-state read (null without one). Default: none. */
  onRealtimeListenerCredential?: (conversationId: number, credential: AgentRealtimeListenerCredential | null) => void;
}

/** The writes that carry the client context. */
export type AgentsClientContextOperation = "startConversation" | "postMessage" | "injectSignal";

/** The page size of a changed-messages read when the request names none (as the chat sends it). */
export const AGENTS_HTTP_DEFAULT_CHANGED_MESSAGES_PAGE_SIZE = 50;

/** The expand of every message page: the delegated children with the two fields the chat reads. */
const MESSAGES_EXPAND = "delegatedConversations(select=id,runState)";

const defaultBuildStartPath = (agentSpaceKey: number): string => (agentSpaceKey > 0 ? `/start/${agentSpaceKey}` : "/start");

type QueryParameters = Record<string, string | number | boolean | undefined | null>;

interface HttpRequest {
  operation: AgentsConversationOperation;
  method: "GET" | "POST" | "PATCH" | "DELETE";
  path: string;
  query?: QueryParameters;
  body?: unknown;
}

/** A filter value as the query-options grammar writes it: quoted. */
const quoteFilterValue = (value: number | string): string => `'${String(value).replace(/'/g, "\\'")}'`;

/** The module's conversation routes over fetch: one request per port function, the wire mapped onto the contract. */
class AgentsHttpConversationClient implements AgentsConversationClient {
  readonly getSelectableModels?: () => Promise<SelectableModel[]>;

  /** The client id this client created for its account read (no `uiClient` bound): once, kept while it lives. */
  protected ownAccountReadClientId: string | undefined;

  constructor(protected readonly options: AgentsHttpConversationClientOptions) {
    if (options.hasSelectableModelsRoute) this.getSelectableModels = this.readSelectableModels;
  }

  // ── Reads ─────────────────────────────────────────────────────────────────────────────────────────────────────

  getConversationMetadata = async (conversationId: number): Promise<AgentsConversation> => {
    const response = await this.request<WireConversationResponse>({
      operation: "getConversationMetadata",
      method: "GET",
      path: `/${conversationId}`,
    });
    return mapWireConversation(response.aiConversation ?? {});
  };

  getConversationRunState = async (conversationId: number): Promise<ConversationRunStateRead> => {
    const uiClient = this.options.uiClient;
    const uiState = uiClient?.readUIState() ?? null;
    const response = await this.request<WireConversationRunStateResponse>({
      operation: "getConversationRunState",
      method: "GET",
      path: `/${conversationId}/runState`,
      query: uiClient ? { clientId: uiClient.clientId, uiStateJson: uiState ? JSON.stringify(uiState) : undefined } : undefined,
    });
    const sendAnswer = (requestId: number, answer: ClientToolAnswer): Promise<void> =>
      this.answerClientToolRequest(conversationId, requestId, answer);
    if (uiClient && response.clientToolRequest) {
      uiClient.acceptClientToolRequest(conversationId, response.clientToolRequest, sendAnswer);
    }
    for (const message of readWireSetElements(response.realtimeMessages)) {
      if (uiClient) acceptArrivedRealtimeMessage(conversationId, message, uiClient, sendAnswer, this.options.onRealtimeMessage);
      else this.options.onRealtimeMessage?.(conversationId, message);
    }
    this.options.onRealtimeListenerCredential?.(conversationId, response.realtimeListenerCredential ?? null);
    return mapWireConversationRunState(response);
  };

  /**
   * The account-level realtime read (`GET /realtimeMessages?clientId=…`): the messages addressed to the signed-in
   * account for this client and the listener credential without a conversation. It always states a client id
   * (`resolveAccountReadClientId`), so the server knows a client that only polls. Arrival is the caller's
   * (`createAccountRealtimePoll` of `/ui`).
   *
   * @param clientId the client id the caller wants stated (the `clientId` option of the poll)
   */
  getAccountRealtimeMessages = async (clientId?: string): Promise<AccountRealtimeRead> => {
    const response = await this.request<WireAccountRealtimeMessagesResponse>({
      operation: "getAccountRealtimeMessages",
      method: "GET",
      path: "/realtimeMessages",
      query: { clientId: this.resolveAccountReadClientId(clientId) },
    });
    return {
      messages: readWireSetElements(response.realtimeMessages),
      listenerCredential: response.realtimeListenerCredential ?? null,
    };
  };

  /**
   * The client id the account read states, decided here and nowhere else: the caller's, else the bound `uiClient`'s,
   * else one this client creates once and keeps while it lives. Only the account read asks: every other request
   * states a client id only with a `uiClient`.
   */
  protected resolveAccountReadClientId(clientId: string | undefined): string {
    if (clientId) return clientId;
    if (this.options.uiClient) return this.options.uiClient.clientId;
    this.ownAccountReadClientId ??= createAgentUIClientId();
    return this.ownAccountReadClientId;
  }

  /** Posts the answer of the UI client to a client tool request (`Agent.UI`); a refused answer is the server's word. */
  answerClientToolRequest = async (conversationId: number, requestId: number, answer: ClientToolAnswer): Promise<void> => {
    await this.request<unknown>({
      operation: "answerClientToolRequest",
      method: "POST",
      path: `/${conversationId}/clientToolRequests/${requestId}/answer`,
      body: {
        clientId: this.options.uiClient?.clientId ?? "",
        outcome: answer.outcome,
        text: answer.text,
        detailsJson: JSON.stringify(answer.details ?? {}),
        pictureBase64: answer.pictureBase64,
        uiStateJson: this.readUIStateJsonAfterAnswer(),
      },
    });
  };

  /** What the UI shows after the request was carried out, so the server's `[SIGNAL:ui]` note is current at once. */
  protected readUIStateJsonAfterAnswer(): string | undefined {
    const uiState: AgentUIState | null = this.options.uiClient?.readUIState() ?? null;
    return uiState ? JSON.stringify(uiState) : undefined;
  }

  getNewestMessagesPage = (conversationId: number, pageSize: number): Promise<ConversationMessagesPage> =>
    this.readMessagesPage("getNewestMessagesPage", conversationId, pageSize, undefined);

  getOlderMessagesPage = (conversationId: number, beforeIndex: number, pageSize: number): Promise<ConversationMessagesPage> =>
    this.readMessagesPage(
      "getOlderMessagesPage",
      conversationId,
      pageSize,
      `indexInConversation lt ${quoteFilterValue(beforeIndex)}`,
    );

  getChangedMessages = async (
    conversationId: number,
    request: ChangedMessagesRequest,
  ): Promise<ChangedConversationMessages> => {
    const page = await this.readMessagesPage(
      "getChangedMessages",
      conversationId,
      request.pageSize ?? AGENTS_HTTP_DEFAULT_CHANGED_MESSAGES_PAGE_SIZE,
      `(id gt ${quoteFilterValue(request.highestKnownId)} or indexInConversation ge ${quoteFilterValue(request.fromIndex)})`,
    );
    return { messages: page.messages };
  };

  /** The query of the root conversations list (of one space, or of all), newest first: shared by the list and the read of its records. */
  protected buildConversationsListQuery(agentSpaceKey: number, options: ConversationListQueryOptions) {
    const filters = ["rootConversationId eq null"];
    if (agentSpaceKey > 0) filters.push(`agentSpaceId eq ${quoteFilterValue(agentSpaceKey)}`);
    return {
      filters: filters.join(" and "),
      orderBy: "id desc",
      top: options.paging?.top,
      skip: options.paging?.skip,
    };
  }

  listConversations = async (agentSpaceKey: number, options: ConversationListQueryOptions = {}): Promise<AgentsConversation[]> => {
    const response = await this.request<WireConversationsResponse>({
      operation: "listConversations",
      method: "GET",
      path: "/listAllConversations",
      query: this.buildConversationsListQuery(agentSpaceKey, options),
    });
    const conversations = readWireSetElements(response.aiConversations).map(mapWireConversation);
    // Without a summary there is no title yet; the list leaves such a conversation out unless asked for it.
    return options.includeUnsummarized ? conversations : conversations.filter((conversation) => conversation.title !== null);
  };

  listConversationsOfSubject = async (subject: ConversationSubject, options: ConversationListQueryOptions = {}): Promise<ConversationsOfSubject> => {
    const response = await this.request<WireConversationsOfSubjectResponse>({
      operation: "listConversationsOfSubject",
      method: "GET",
      path: "/listConversationsOfSubject",
      query: {
        subjectEntityClass: subject.entityClass,
        subjectEntityId: subject.entityId,
        filters: "rootConversationId eq null",
        orderBy: "id desc",
        top: options.paging?.top,
        skip: options.paging?.skip,
      },
    });
    const conversations = readWireSetElements(response.aiConversations).map(mapWireConversation);
    return {
      subject: mapWireBoundConversationSubject(response.subject),
      conversations: options.includeUnsummarized ? conversations : conversations.filter((conversation) => conversation.title !== null),
    };
  };

  listSubjectsOfConversations = async (agentSpaceKey: number, options: ConversationListQueryOptions = {}): Promise<BoundConversationSubject[]> => {
    const response = await this.request<WireBoundConversationSubjectsResponse>({
      operation: "listSubjectsOfConversations",
      method: "GET",
      path: "/listSubjectsOfConversations",
      query: this.buildConversationsListQuery(agentSpaceKey, options),
    });
    return readWireSetElements(response.subjects)
      .map(mapWireBoundConversationSubject)
      .filter((subject): subject is BoundConversationSubject => subject !== null);
  };

  getConversationSubject = async (conversationId: number): Promise<BoundConversationSubject | null> => {
    const response = await this.request<WireConversationSubjectResponse>({
      operation: "getConversationSubject",
      method: "GET",
      path: `/${conversationId}/subject`,
    });
    return mapWireBoundConversationSubject(response.subject);
  };

  searchConversations = async (text: string, options: ConversationSearchOptions = {}): Promise<ConversationSearchGroup[]> => {
    const response = await this.request<WireConversationSearchResponse>({
      operation: "searchConversations",
      method: "GET",
      path: "/search",
      query: { query: text, limit: options.limit, excerptChars: options.excerptLength },
    });
    return readWireSetElements(response.conversations).map(mapWireSearchGroup);
  };

  searchConversationMessages = async (
    conversationId: number,
    text: string,
    options: ConversationSearchOptions = {},
  ): Promise<ConversationMessageSearchHit[]> => {
    const response = await this.request<WireConversationMessageSearchResponse>({
      operation: "searchConversationMessages",
      method: "GET",
      path: `/${conversationId}/messages/search`,
      query: { query: text, limit: options.limit, excerptChars: options.excerptLength },
    });
    return readWireSetElements(response.hits).map(mapWireSearchHit);
  };

  getConversationActivity = async (conversationId: number, turnMessageId?: number | null): Promise<ConversationActivity> => {
    const response = await this.request<WireConversationActivityResponse>({
      operation: "getConversationActivity",
      method: "GET",
      path: `/${conversationId}/activity`,
      query: { turnMessageId: turnMessageId ?? undefined },
    });
    return mapWireConversationActivity(response);
  };

  composeSentMessageText = (text: string, attachments: ConversationAttachmentReference[] | undefined): string =>
    composeAttachmentMessageText(text, attachments);

  // ── Writes ────────────────────────────────────────────────────────────────────────────────────────────────────

  startConversation = async (request: Parameters<AgentsConversationClient["startConversation"]>[0]): Promise<StartedConversation> => {
    const buildStartPath = this.options.buildStartPath ?? defaultBuildStartPath;
    const response = await this.request<WireConversationStartResponse>({
      operation: "startConversation",
      method: "POST",
      path: buildStartPath(request.agentSpaceKey),
      query: { aiModelName: request.model },
      body: {
        aiConversationMessage: { message: composeAttachmentMessageText(request.text, request.attachments) },
        ...(request.startSignalText ? { signalText: request.startSignalText } : {}),
        ...(request.entryPointKey ? { entryPointKey: request.entryPointKey } : {}),
        ...(request.subject
          ? { subject: { subjectEntityClass: request.subject.entityClass, subjectEntityId: request.subject.entityId } }
          : {}),
        ...this.readClientContextBody("startConversation"),
      },
    });
    const wireConversation = response.aiConversation ?? {};
    const reopenedExisting = response.reopenedExistingConversation === true;
    return {
      conversation: mapWireConversation(wireConversation),
      messages: reopenedExisting ? [] : readWireSetElements(wireConversation.messages).map(mapWireConversationMessage),
      reopenedExisting,
    };
  };

  startPurpose = async (request: PurposeStartRequest): Promise<StartedPurposeConversation> => {
    const response = await this.request<WirePurposeStartResponse>({
      operation: "startPurpose",
      method: "POST",
      path: "/startPurpose",
      body: {
        purposeId: request.purposeId,
        ...(request.agentSpaceKey ? { agentSpaceId: request.agentSpaceKey } : {}),
        ...(request.subject
          ? { subject: { subjectEntityClass: request.subject.entityClass, subjectEntityId: request.subject.entityId } }
          : {}),
        ...(request.prefill ? { prefill: request.prefill } : {}),
      },
    });
    const conversationId = Number(response.aiConversationId ?? 0);
    if (!Number.isInteger(conversationId) || conversationId < 1) {
      throw new AgentsClientError("other", { message: "The purposed start answered no conversation id." });
    }
    return {
      conversationId,
      resumedExisting: response.resumedExistingConversation === true,
      prefillDelivered: response.prefillDelivered === true,
    };
  };

  postMessage = async (
    conversationId: number,
    message: { text: string; model?: string; attachments?: ConversationAttachmentReference[] },
  ): Promise<PostedMessage> => {
    const response = await this.request<WireConversationResponse>({
      operation: "postMessage",
      method: "POST",
      path: `/${conversationId}/postMessage`,
      query: { aiModelName: message.model },
      body: {
        aiConversationMessage: { message: composeAttachmentMessageText(message.text, message.attachments) },
        ...this.readClientContextBody("postMessage"),
      },
    });
    return { messages: readWireSetElements(response.aiConversation?.messages).map(mapWireConversationMessage) };
  };

  injectSignal = async (conversationId: number, signal: ConversationSignal): Promise<void> => {
    await this.request<WireConversationResponse>({
      operation: "injectSignal",
      method: "POST",
      path: `/${conversationId}/injectSignal`,
      body: {
        signalText: signal.text,
        ...(signal.source ? { signalSource: signal.source } : {}),
        ...this.readClientContextBody("injectSignal"),
      },
    });
  };

  retryConversation = async (conversationId: number): Promise<void> => {
    await this.request<WireConversationResponse>({
      operation: "retryConversation",
      method: "POST",
      path: `/${conversationId}/retry`,
    });
  };

  renameConversation = async (conversationId: number, title: string): Promise<AgentsConversation> => {
    const response = await this.request<WireConversationResponse>({
      operation: "renameConversation",
      method: "PATCH",
      path: `/${conversationId}/title`,
      body: { title },
    });
    return mapWireConversation(response.aiConversation ?? {});
  };

  deleteConversation = async (conversationId: number): Promise<void> => {
    await this.request<unknown>({ operation: "deleteConversation", method: "DELETE", path: `/${conversationId}` });
  };

  // ── Transport ─────────────────────────────────────────────────────────────────────────────────────────────────

  protected readSelectableModels = async (): Promise<SelectableModel[]> => {
    const response = await this.request<WireSelectableModelsResponse>({
      operation: "getSelectableModels",
      method: "GET",
      path: "/selectable-models",
    });
    return readWireSetElements(response.models).map(mapWireSelectableModel);
  };

  protected async readMessagesPage(
    operation: AgentsConversationOperation,
    conversationId: number,
    pageSize: number,
    filters: string | undefined,
  ): Promise<ConversationMessagesPage> {
    const response = await this.request<WireConversationMessagesResponse>({
      operation,
      method: "GET",
      path: `/${conversationId}/messages`,
      query: { filters, orderBy: "indexInConversation desc", top: pageSize, expand: MESSAGES_EXPAND },
    });
    return {
      messages: readWireSetElements(response.messages).map(mapWireConversationMessage),
      hasMoreOlder: response.hasMoreOlder === true,
    };
  }

  /** The body field `clientContext` of a write, from the host's option; nothing when it gives none. */
  protected readClientContextBody(operation: AgentsClientContextOperation): {
    clientContext?: ReturnType<typeof mapClientContextToWire>;
    clientId?: string;
    uiState?: AgentUIState;
  } {
    const wireClientContext = mapClientContextToWire(this.options.clientContext?.(operation));
    const uiClient = this.options.uiClient;
    const uiState = uiClient?.readUIState() ?? null;
    return {
      ...(wireClientContext ? { clientContext: wireClientContext } : {}),
      ...(uiClient ? { clientId: uiClient.clientId } : {}),
      ...(uiState ? { uiState } : {}),
    };
  }

  /** The URL of a route: the base, the path, the binding's query parameters with the host's extra ones over them. */
  protected buildUrl(request: HttpRequest): string {
    const searchParameters = new URLSearchParams();
    const extraParameters = this.options.extraRequestParameters?.(request.operation) ?? {};
    for (const [name, value] of Object.entries({ ...request.query, ...extraParameters })) {
      if (value !== undefined && value !== null) searchParameters.set(name, String(value));
    }
    const query = searchParameters.toString();
    const baseUrl = this.options.baseUrl.replace(/\/+$/, "");
    return `${baseUrl}${request.path}${query ? `?${query}` : ""}`;
  }

  protected async readFetchOptions(): Promise<AgentsHttpFetchOptions> {
    const { fetchOptions } = this.options;
    return typeof fetchOptions === "function" ? await fetchOptions() : (fetchOptions ?? {});
  }

  /** One request; every failure becomes an `AgentsClientError` (the port's one error shape). */
  protected async request<ResponseBody>(request: HttpRequest): Promise<ResponseBody> {
    const fetchFunction = this.options.fetch ?? globalThis.fetch.bind(globalThis);
    let response: Response;
    try {
      const fetchOptions = await this.readFetchOptions();
      const headers = new Headers(fetchOptions.headers);
      if (!headers.has("Accept")) headers.set("Accept", "application/json");
      if (request.body !== undefined && !headers.has("Content-Type")) headers.set("Content-Type", "application/json");
      response = await fetchFunction(this.buildUrl(request), {
        ...fetchOptions,
        headers,
        method: request.method,
        body: request.body === undefined ? undefined : JSON.stringify(request.body),
      });
    } catch (failure) {
      if (failure instanceof AgentsClientError) throw failure;
      const isAbort = failure instanceof Error && failure.name === "AbortError";
      throw isAbort
        ? new AgentsClientError("other", { code: AGENTS_CLIENT_ERROR_CODE_REQUEST_ABORTED, message: "The request was cancelled." })
        : new AgentsClientError("network", { message: failure instanceof Error ? failure.message : "The request failed." });
    }
    const responseText = await response.text().catch(() => "");
    const responseBody = readJsonBody(responseText);
    if (!response.ok) {
      throw mapHttpFailureToAgentsClientError(
        response.status,
        isPlainObject(responseBody) ? (responseBody as WireErrorBody) : null,
        response.headers.get("Retry-After"),
      );
    }
    return (responseBody ?? {}) as ResponseBody;
  }
}

const isPlainObject = (value: unknown): value is Record<string, unknown> =>
  typeof value === "object" && value !== null && !Array.isArray(value);

const readJsonBody = (text: string): unknown => {
  if (text.trim() === "") return null;
  try {
    return JSON.parse(text);
  } catch {
    return null;
  }
};

/**
 * The conversation client over the module's own conversation routes (`AgentConversationReadsTrait`,
 * `AgentConversationWritesTrait`, the application's start route of `AgentConversationStartTrait`, optionally
 * `AgentSelectableModelsTrait`): `baseUrl` is where the application mounted its conversations controller, `fetchOptions`
 * what every request carries. Every function rejects with `AgentsClientError`. The client comes with live updates —
 * the polling default over the run-state read, which also reads at once after an accepted send — unless `polling` is
 * `false`.
 *
 * ```ts
 * const client = createAgentsHttpConversationClient({
 *   baseUrl: "/api/agents/conversations",
 *   fetchOptions: () => ({ headers: { Authorization: `Bearer ${readToken()}` } }),
 * });
 * <AgentsProvider client={client}><AgentChat /></AgentsProvider>
 * ```
 */
export const createAgentsHttpConversationClient = (options: AgentsHttpConversationClientOptions): AgentsConversationClient => {
  const client = new AgentsHttpConversationClient(options);
  return options.polling === false ? client : withPollingChangeSubscription(client, options.polling);
};
