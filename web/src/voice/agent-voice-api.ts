/**
 * The adapter the application passes to `AgentVoiceCallProvider`, so the voice chat depends on no generated client:
 * one function per voice route of `AgentVoiceSessionsTrait` (`open`, `hangUp`, `deviceStatus`, the call state read,
 * `answer`, `decline`). Every function rejects with something `readAgentVoiceErrorCode` finds the route's error code in.
 */
export interface AgentVoiceApi {
  openSession(args: {
    agentSpaceId?: number;
    sdpOffer: string;
    conversationId?: number;
    callId?: number;
  }): Promise<{ callId: number; sdpAnswer: string; conversationId: number }>;
  hangUp(args: { callId: number; reason?: string }): Promise<void>;
  reportDeviceStatus(args: {
    callId: number;
    status: string;
    iceCandidateType?: string;
    roundTripTimeMs?: number;
    detail?: string;
    occurredAtUnixMs?: number;
  }): Promise<void>;
  readCallState(args: { callId: number }): Promise<{ callId: number; endedAt?: string; endReason?: string }>;
  answerRing(args: { callId: number; answerToken: string }): Promise<{ won: boolean; conversationId?: number }>;
  declineRing(args: { callId: number; answerToken: string }): Promise<void>;
}

/** A refused or broken request of the voice routes; `code` is the route's error code (`call_already_running`, …). */
export class AgentVoiceApiError extends Error {
  readonly code: string;
  readonly httpStatus: number | undefined;

  constructor(code: string, httpStatus?: number) {
    super(code);
    this.name = "AgentVoiceApiError";
    this.code = code;
    this.httpStatus = httpStatus;
  }
}

const readString = (value: unknown): string | undefined => (typeof value === "string" && value !== "" ? value : undefined);

/**
 * The error code of a rejected adapter call. The voice routes answer the code as the error message, so it is read
 * where the common request layers put it: `error` (the JSON error body itself), `data.error` (the body below a status),
 * `code`, or the message of an `Error`. Undefined when the failure names none (the network is down).
 */
export const readAgentVoiceErrorCode = (failure: unknown): string | undefined => {
  if (typeof failure !== "object" || failure === null) return undefined;
  const failureFields = failure as { error?: unknown; data?: unknown; code?: unknown; message?: unknown };
  const errorBody =
    typeof failureFields.data === "object" && failureFields.data !== null
      ? (failureFields.data as { error?: unknown })
      : undefined;
  return (
    readString(failureFields.error) ??
    readString(errorBody?.error) ??
    readString(failureFields.code) ??
    readString(failureFields.message)
  );
};

/** A request of the voice routes as the application's request layer receives it: path, method, JSON body. */
export interface AgentVoiceRequest {
  url: string;
  method?: "GET" | "POST";
  body?: unknown;
}

/**
 * The application's request layer as `createAgentVoiceApi` uses it. The call store runs outside React (the call
 * outlives the chat that shows it), so the layer answers a Promise per request and offers no hooks: `request` sends
 * with the application's origin, session and token refresh, resolves with the parsed JSON answer and rejects with the
 * failure (see `readAgentVoiceErrorCode`). `createAgentVoiceFetchBaseApi` is one over `fetch`.
 */
export interface AgentVoiceBaseApi {
  request: <Answer>(request: AgentVoiceRequest) => Promise<Answer>;
}

export interface CreateAgentVoiceApiOptions {
  /** The application's request layer. */
  baseApi: AgentVoiceBaseApi;
  /** Where the application mounts the voice session routes, without a trailing slash (`/api/agents/voiceSession`). */
  apiPrefix: string;
}

/** Without trailing slashes (`/api/agents/voiceSession/` → `/api/agents/voiceSession`). */
const trimApiPrefix = (apiPrefix: string): string => apiPrefix.replace(/\/+$/, "");

const callPath = (callId: number): string => `/${encodeURIComponent(String(callId))}`;

/** The request of every operation, on `apiPrefix`, with the routes' own field names. Pure: nothing is sent. */
export const buildAgentVoiceRequests = (apiPrefix: string) => {
  const prefix = trimApiPrefix(apiPrefix);
  return {
    openSession: ({
      agentSpaceId,
      sdpOffer,
      conversationId,
      callId,
    }: Parameters<AgentVoiceApi["openSession"]>[0]): AgentVoiceRequest => ({
      url: `${prefix}/open`,
      method: "POST",
      body: { agentSpaceId, sdpOffer, conversationId, callId },
    }),
    hangUp: ({ callId, reason }: Parameters<AgentVoiceApi["hangUp"]>[0]): AgentVoiceRequest => ({
      url: `${prefix}${callPath(callId)}/hangUp`,
      method: "POST",
      body: { reason },
    }),
    reportDeviceStatus: ({
      callId,
      status,
      iceCandidateType,
      roundTripTimeMs,
      detail,
      occurredAtUnixMs,
    }: Parameters<AgentVoiceApi["reportDeviceStatus"]>[0]): AgentVoiceRequest => ({
      url: `${prefix}${callPath(callId)}/deviceStatus`,
      method: "POST",
      body: { status, iceCandidateType, roundTripTimeMs, detail, occurredAtUnixMs },
    }),
    readCallState: ({ callId }: Parameters<AgentVoiceApi["readCallState"]>[0]): AgentVoiceRequest => ({
      url: `${prefix}${callPath(callId)}`,
    }),
    answerRing: ({ callId, answerToken }: Parameters<AgentVoiceApi["answerRing"]>[0]): AgentVoiceRequest => ({
      url: `${prefix}${callPath(callId)}/answer`,
      method: "POST",
      body: { answerToken },
    }),
    declineRing: ({ callId, answerToken }: Parameters<AgentVoiceApi["declineRing"]>[0]): AgentVoiceRequest => ({
      url: `${prefix}${callPath(callId)}/decline`,
      method: "POST",
      body: { answerToken },
    }),
  };
};

/** What `open` answers: the call (its id), the provider's SDP answer and the conversation the call writes into. */
interface AgentVoiceOpenAnswer {
  agentVoiceCall?: { id?: number | null } | null;
  sdpAnswer?: string | null;
  conversationId?: number | null;
}

interface AgentVoiceCallStateAnswer {
  callId?: number | null;
  endedAt?: string | null;
  endReason?: string | null;
}

interface AgentVoiceRingAnswer {
  won?: boolean | null;
  conversationId?: number | null;
}

/** The code of an `open` answer without a call id or a conversation: nothing the call could go on with. */
export const AGENT_VOICE_ERROR_CODE_INVALID_OPEN_ANSWER = "invalid_open_answer";

/**
 * The adapter for an application whose routes are the voice trait's own paths below `apiPrefix`, with the agent space
 * in the body of `open`:
 * `export const agentVoiceApi = createAgentVoiceApi({ baseApi, apiPrefix: "/api/agents/voiceSession" });`
 * An application whose `open` names its space another way (in the path) writes the six functions itself.
 */
export const createAgentVoiceApi = ({ baseApi, apiPrefix }: CreateAgentVoiceApiOptions): AgentVoiceApi => {
  const requests = buildAgentVoiceRequests(apiPrefix);
  return {
    openSession: async (args) => {
      const openAnswer = await baseApi.request<AgentVoiceOpenAnswer | null>(requests.openSession(args));
      const callId = openAnswer?.agentVoiceCall?.id ?? args.callId;
      const conversationId = openAnswer?.conversationId ?? args.conversationId;
      if (callId === undefined || conversationId === undefined)
        throw new AgentVoiceApiError(AGENT_VOICE_ERROR_CODE_INVALID_OPEN_ANSWER);
      // No SDP answer stays an empty one: the store ends the opened call on the server before it shows the failure.
      return { callId, sdpAnswer: openAnswer?.sdpAnswer ?? "", conversationId };
    },
    hangUp: async (args) => {
      await baseApi.request<unknown>(requests.hangUp(args));
    },
    reportDeviceStatus: async (args) => {
      await baseApi.request<unknown>(requests.reportDeviceStatus(args));
    },
    readCallState: async (args) => {
      const callState = await baseApi.request<AgentVoiceCallStateAnswer | null>(requests.readCallState(args));
      return {
        callId: callState?.callId ?? args.callId,
        endedAt: callState?.endedAt ?? undefined,
        endReason: callState?.endReason ?? undefined,
      };
    },
    answerRing: async (args) => {
      const ringAnswer = await baseApi.request<AgentVoiceRingAnswer | null>(requests.answerRing(args));
      return { won: ringAnswer?.won === true, conversationId: ringAnswer?.conversationId ?? undefined };
    },
    declineRing: async (args) => {
      await baseApi.request<unknown>(requests.declineRing(args));
    },
  };
};

/** What every request carries from the application: headers, credentials, mode, cache. */
export type AgentVoiceFetchOptions = Omit<RequestInit, "method" | "body">;

export interface CreateAgentVoiceFetchBaseApiOptions {
  /**
   * Request options every request carries (credentials, an authorization header). A function is called per request,
   * so it may hand out a fresh token. Default: none (the browser's defaults: same-origin credentials).
   */
  fetchOptions?: AgentVoiceFetchOptions | (() => AgentVoiceFetchOptions | Promise<AgentVoiceFetchOptions>);
  /** The fetch function. Default: the global `fetch`. */
  fetch?: typeof fetch;
}

const readJsonBody = async (response: Response): Promise<unknown> => {
  const bodyText = await response.text();
  if (bodyText === "") return undefined;
  try {
    return JSON.parse(bodyText);
  } catch {
    return undefined;
  }
};

/**
 * A request layer over `fetch`: JSON out, JSON in; a refused request rejects with `AgentVoiceApiError`, its code the
 * `error` of the JSON error body (`http_<status>` without one).
 */
export const createAgentVoiceFetchBaseApi = (options: CreateAgentVoiceFetchBaseApiOptions = {}): AgentVoiceBaseApi => ({
  request: async <Answer,>(request: AgentVoiceRequest): Promise<Answer> => {
    const fetchFunction = options.fetch ?? globalThis.fetch.bind(globalThis);
    const fetchOptions = typeof options.fetchOptions === "function" ? await options.fetchOptions() : (options.fetchOptions ?? {});
    const headers = new Headers(fetchOptions.headers);
    if (!headers.has("Accept")) headers.set("Accept", "application/json");
    if (request.body !== undefined && !headers.has("Content-Type")) headers.set("Content-Type", "application/json");
    const response = await fetchFunction(request.url, {
      ...fetchOptions,
      headers,
      method: request.method ?? "GET",
      body: request.body === undefined ? undefined : JSON.stringify(request.body),
    });
    const responseBody = await readJsonBody(response);
    if (!response.ok) {
      throw new AgentVoiceApiError(readAgentVoiceErrorCode(responseBody) ?? `http_${response.status}`, response.status);
    }
    return responseBody as Answer;
  },
});
