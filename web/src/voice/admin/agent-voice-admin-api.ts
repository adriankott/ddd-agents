import {
  type ApiQueryHook,
  type InjectableBaseApi,
  type InjectedEndpointDefinition,
  type InjectedRequest,
  injectEndpointsOnce,
  type StaticQueryFailure,
  staticQueryHook,
  toQueryString,
  trimApiPrefix,
} from "@mgamadeus/ddd-admin-ui/logic/pure";
import { buildFieldFilter, combineFilters } from "@mgamadeus/ddd-web/query";
import type {
  AgentVoiceAdminCallIdArg,
  AgentVoiceAdminCallResponse,
  AgentVoiceAdminCallsArg,
  AgentVoiceAdminCallsResponse,
} from "./agent-voice-admin-types";

/** The cache tag type of the call operations (ids `LIST`, `DETAIL`). */
export const AGENT_VOICE_CALLS_TAG = "AdminUiAgentVoiceCalls";

/** The hooks `createAgentVoiceAdminApi` returns; the call screens take them as their `api`. */
export interface AgentVoiceAdminApi {
  /** `GET /calls?filters&top&skip`: the calls the signed-in account may read, newest first. */
  useAgentVoiceCallsQuery: ApiQueryHook<AgentVoiceAdminCallsArg, AgentVoiceAdminCallsResponse>;
  /** `GET /calls/{callId}`: one call (404 `call_not_found`: unknown or not readable). */
  useAgentVoiceCallQuery: ApiQueryHook<AgentVoiceAdminCallIdArg, AgentVoiceAdminCallResponse>;
}

export interface CreateAgentVoiceAdminApiOptions {
  /** The host's RTK Query api (`createApi` with the React hooks module). */
  baseApi: InjectableBaseApi;
  /** Where the host mounts `AdminAgentVoiceCallsTrait`, without a trailing slash (`/api/admin/ai/voiceCalls`). */
  apiPrefix: string;
}

/**
 * The filter expression of the list's arguments, in the query options grammar of the route (`accountId eq '12' and
 * bookedSeconds ge '60'`); undefined when nothing narrows. The date range filters the moment a call was created, so a
 * call nobody answered is found too. Which accounts' calls are read at all is decided on the server.
 */
export const buildAgentVoiceAdminCallsFilters = (arg: AgentVoiceAdminCallsArg): string | undefined =>
  combineFilters(
    arg.accountId !== undefined && buildFieldFilter("accountId", arg.accountId, "eq"),
    arg.conversationId !== undefined && buildFieldFilter("aiConversationId", arg.conversationId, "eq"),
    arg.createdFrom !== undefined && arg.createdFrom !== "" && buildFieldFilter("created", arg.createdFrom, "ge"),
    arg.createdTo !== undefined && arg.createdTo !== "" && buildFieldFilter("created", arg.createdTo, "le"),
    arg.bookedSecondsFrom !== undefined && buildFieldFilter("bookedSeconds", arg.bookedSecondsFrom, "ge"),
    arg.bookedSecondsTo !== undefined && buildFieldFilter("bookedSeconds", arg.bookedSecondsTo, "le"),
    arg.endReason !== undefined && arg.endReason !== "" && buildFieldFilter("endReason", arg.endReason, "eq"),
  );

/** The request of every operation, on `apiPrefix`. Pure: no RTK runtime. */
export const buildAgentVoiceAdminRequests = (apiPrefix: string) => {
  const prefix = trimApiPrefix(apiPrefix);
  return {
    calls: (arg: AgentVoiceAdminCallsArg = {}): InjectedRequest => ({
      url: `${prefix}/calls${toQueryString({ filters: buildAgentVoiceAdminCallsFilters(arg), top: arg.limit, skip: arg.offset })}`,
    }),
    call: ({ callId }: AgentVoiceAdminCallIdArg): InjectedRequest => ({
      url: `${prefix}/calls/${encodeURIComponent(String(callId))}`,
    }),
  };
};

const LIST = { type: AGENT_VOICE_CALLS_TAG, id: "LIST" } as const;
const DETAIL = { type: AGENT_VOICE_CALLS_TAG, id: "DETAIL" } as const;

/** The operations as injected. */
export const agentVoiceAdminEndpointDefinitions = (apiPrefix: string) => {
  const requests = buildAgentVoiceAdminRequests(apiPrefix);
  return {
    adminUiAgentVoiceCalls: { kind: "query", request: requests.calls, providesTags: [LIST] },
    adminUiAgentVoiceCall: { kind: "query", request: requests.call, providesTags: [DETAIL] },
  } as const satisfies Record<string, InjectedEndpointDefinition<never>>;
};

/**
 * Injects the call operations into the host's RTK Query api and returns their hooks. Call it once at module scope:
 * `export const agentVoiceAdminApi = createAgentVoiceAdminApi({ baseApi: adminApi, apiPrefix: "/api/admin/ai/voiceCalls" });`
 */
export const createAgentVoiceAdminApi = ({ baseApi, apiPrefix }: CreateAgentVoiceAdminApiOptions): AgentVoiceAdminApi =>
  injectEndpointsOnce(
    baseApi,
    `agentVoiceCalls:${trimApiPrefix(apiPrefix)}`,
    [AGENT_VOICE_CALLS_TAG],
    agentVoiceAdminEndpointDefinitions(apiPrefix),
    (hookOf) => ({
      useAgentVoiceCallsQuery: hookOf("adminUiAgentVoiceCalls") as AgentVoiceAdminApi["useAgentVoiceCallsQuery"],
      useAgentVoiceCallQuery: hookOf("adminUiAgentVoiceCall") as AgentVoiceAdminApi["useAgentVoiceCallQuery"],
    }),
  );

/** Fixed answers of a `createStaticAgentVoiceAdminApi`; a query without one stays loading. */
export interface StaticAgentVoiceAdminData {
  calls?: (arg: AgentVoiceAdminCallsArg) => AgentVoiceAdminCallsResponse | StaticQueryFailure | undefined;
  call?: (arg: AgentVoiceAdminCallIdArg) => AgentVoiceAdminCallResponse | StaticQueryFailure | undefined;
}

/** An `AgentVoiceAdminApi` answering fixed payloads, without a store or a request (showcases, tests, reviews). */
export const createStaticAgentVoiceAdminApi = (data: StaticAgentVoiceAdminData): AgentVoiceAdminApi => ({
  useAgentVoiceCallsQuery: staticQueryHook((arg) => data.calls?.(arg ?? {})),
  useAgentVoiceCallQuery: staticQueryHook((arg) => data.call?.(arg)),
});
