// `@mgamadeus/ddd-agents/voice/admin` — the admin screens of the voice calls on the blocks of
// `@mgamadeus/ddd-admin-ui`: the api of the admin call routes (`createAgentVoiceAdminApi`, `AdminAgentVoiceCallsTrait`),
// the list of calls and one call. The one entry that loads the optional peers `@mgamadeus/ddd-admin-ui`,
// `@mgamadeus/ddd-web` and `lucide-react` (`boundary.json` names them, `scripts/check-package.mjs` holds every other
// entry free of them): an application's chat installs none of the three.
export {
  AGENT_VOICE_CALLS_TAG,
  type AgentVoiceAdminApi,
  agentVoiceAdminEndpointDefinitions,
  buildAgentVoiceAdminCallsFilters,
  buildAgentVoiceAdminRequests,
  createAgentVoiceAdminApi,
  type CreateAgentVoiceAdminApiOptions,
  createStaticAgentVoiceAdminApi,
  type StaticAgentVoiceAdminData,
} from "../voice/admin/agent-voice-admin-api";
export type {
  AgentVoiceAdminCall,
  AgentVoiceAdminCallDerivedValues,
  AgentVoiceAdminCallDirection,
  AgentVoiceAdminCallEndReason,
  AgentVoiceAdminCallIdArg,
  AgentVoiceAdminCallResponse,
  AgentVoiceAdminCallsArg,
  AgentVoiceAdminCallsResponse,
  AgentVoiceAdminCallState,
  AgentVoiceAdminChangeHistory,
  AgentVoiceAdminCurrency,
  AgentVoiceAdminElements,
  AgentVoiceAdminMoneyAmount,
} from "../voice/admin/agent-voice-admin-types";
export {
  AGENT_VOICE_ADMIN_ERROR_CALL_NOT_FOUND,
  AGENT_VOICE_ADMIN_ERROR_FEATURE_NOT_AVAILABLE,
  AGENT_VOICE_CALL_DIRECTION,
  AGENT_VOICE_CALL_END_REASON,
  AGENT_VOICE_CALL_END_REASONS,
  AGENT_VOICE_CALL_STATE,
  agentVoiceAdminCallDerivedValuesByCallIdOf,
  agentVoiceAdminCallDurationInSecondsOf,
  agentVoiceAdminCallStartTimeOf,
  formatAgentVoiceAdminCallCosts,
  isAgentVoiceAdminError,
  readAgentVoiceAdminWholeNumber,
  toAgentVoiceAdminDayBound,
} from "../voice/admin/agent-voice-admin-format";
export {
  AgentVoiceCallConversationLink,
  type AgentVoiceCallConversationLinkProps,
} from "../voice/admin/AgentVoiceCallConversationLink/AgentVoiceCallConversationLink";
export {
  AgentVoiceCallStateBadges,
  type AgentVoiceCallStateBadgesProps,
} from "../voice/admin/AgentVoiceCallStateBadges/AgentVoiceCallStateBadges";
export {
  AGENT_VOICE_CALLS_MAX_PAGE_SIZE,
  AgentVoiceCallsScreen,
  type AgentVoiceCallsScreenProps,
} from "../voice/admin/AgentVoiceCallsScreen/AgentVoiceCallsScreen";
export {
  AgentVoiceCallDetailScreen,
  type AgentVoiceCallDetailScreenProps,
} from "../voice/admin/AgentVoiceCallDetailScreen/AgentVoiceCallDetailScreen";
