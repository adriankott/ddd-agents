// `@mgamadeus/ddd-agents/voice` — the voice chat with the agent in the browser window: the adapter the application
// fills (`AgentVoiceApi`, `createAgentVoiceApi` for the paths of `AgentVoiceSessionsTrait`), the call store (WebRTC to
// the voice provider), the provider and the components of a chat (start button, band, captions, input glow), and the
// agent's ring (the ring source over the account realtime poll of `./ui`, the incoming call). The styles are part of
// the package stylesheet, the texts of `i18n-keys.json`.
//
// The admin screens of the calls and their api are `./voice/admin`: they are built on `@mgamadeus/ddd-admin-ui`, an
// optional peer this entry never loads. Only the payload types of the admin call routes are named here too.
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
  AGENT_VOICE_ERROR_CODE_INVALID_OPEN_ANSWER,
  type AgentVoiceApi,
  AgentVoiceApiError,
  type AgentVoiceBaseApi,
  type AgentVoiceFetchOptions,
  type AgentVoiceOpenPathBuilder,
  type AgentVoiceRequest,
  buildAgentVoiceRequests,
  createAgentVoiceApi,
  type CreateAgentVoiceApiOptions,
  createAgentVoiceFetchBaseApi,
  type CreateAgentVoiceFetchBaseApiOptions,
  readAgentVoiceErrorCode,
} from "../voice/agent-voice-api";

// The call: the store outside React, its hooks and the rules a chat reads of it.
export {
  AGENT_VOICE_CALL_END_REASON_ENDED_BY_AGENT,
  AGENT_VOICE_CALL_END_REASON_LIMIT_REACHED,
  type AgentVoiceCallLine,
  type AgentVoiceCallPhase,
  type AgentVoiceCallSnapshot,
  type AgentVoiceCallSpeaker,
  bindAgentVoiceApi,
  checkAgentVoiceCallEndedOnServer,
  dismissAgentVoiceCallOutcome,
  endAgentVoiceCallFromServer,
  hangUpAgentVoiceCall,
  IDLE_AGENT_VOICE_CALL,
  isAgentVoiceCallActive,
  isAgentVoiceCallShownInAgentSpace,
  readAgentVoiceCallSnapshot,
  startAgentVoiceCall,
  type StartAgentVoiceCallParams,
  toggleAgentVoiceCallMute,
  useAgentVoiceCallSnapshot,
  useAgentVoiceCallValue,
  useIsAgentVoiceCallActive,
} from "../voice/call/agent-voice-call-store";
export { type AgentVoiceCall, useAgentVoiceCall } from "../voice/call/use-agent-voice-call";
export { type AgentVoiceCallTurn, turnOfAgentVoiceCall } from "../voice/call/agent-voice-call-turn";
export { formatAgentVoiceCallDuration } from "../voice/call/format-agent-voice-call-duration";
export { useAgentVoiceCallElapsedSeconds } from "../voice/call/use-agent-voice-call-elapsed-seconds";
export {
  type AgentVoiceConversationChangeSubscription,
  useAgentVoiceCallServerEnd,
} from "../voice/call/use-agent-voice-call-server-end";
export {
  type AgentVoiceConversationOfCallChangedHandler,
  useFollowAgentVoiceCallConversation,
} from "../voice/call/use-follow-agent-voice-call-conversation";
export {
  buildUnsavedAgentVoiceCallLines,
  type SavedAgentVoiceCallMessage,
} from "../voice/call/build-unsaved-agent-voice-call-lines";

// The agent's ring: the realtime message, the ring source, the ring a client shows and its answer.
export {
  AGENT_VOICE_RING_MESSAGE_TYPE,
  type AgentVoiceRing,
  pickRingingAgentVoiceCall,
  readAgentVoiceRingOfRealtimeMessage,
} from "../voice/ring/agent-voice-ring";
export {
  AgentVoiceAccountRealtimeRingSource,
  type AgentVoiceRingSource,
  createAgentVoiceRingSource,
} from "../voice/ring/agent-voice-ring-source";
export {
  acceptAgentVoiceIncomingRing,
  type AgentVoiceIncomingRingSnapshot,
  declineAgentVoiceIncomingRing,
  pickAgentVoiceIncomingCard,
  readAgentVoiceIncomingRingSnapshot,
  useAgentVoiceIncomingRing,
  useAgentVoiceRingBandHost,
  useAgentVoiceRingCover,
} from "../voice/ring/agent-voice-incoming-ring-store";
export {
  AGENT_VOICE_ANSWER_REFUSAL_TEXT_KEYS,
  type AgentVoiceAnswerRefusal,
} from "../voice/ring/answer-incoming-agent-voice-call";
export {
  type AgentVoiceCallerLabel,
  type AgentVoiceCallerLabelTextKey,
  buildAgentVoiceCallerLabel,
  pickAgentVoiceCallerLabelTextKey,
} from "../voice/ring/agent-voice-caller-label";
export { useAgentVoiceRingTone } from "../voice/ring/use-agent-voice-ring-tone";

// The provider and the components of a chat.
export { AgentVoiceCallProvider, type AgentVoiceCallProviderProps } from "../voice/AgentVoiceCallProvider/AgentVoiceCallProvider";
export {
  type AgentVoiceTranslateText,
  useAgentVoiceTranslateText,
} from "../voice/AgentVoiceCallProvider/agent-voice-call-context";
export {
  AgentVoiceChatStartButton,
  type AgentVoiceChatStartButtonProps,
} from "../voice/AgentVoiceChatStartButton/AgentVoiceChatStartButton";
export {
  AgentVoiceCallBand,
  type AgentVoiceCallBandProps,
  AgentVoiceCallBandView,
  type AgentVoiceCallBandViewProps,
  type AgentVoiceCallIncomingRing,
} from "../voice/AgentVoiceCallBand/AgentVoiceCallBand";
export { AgentVoiceCallCaptions, type AgentVoiceCallCaptionsProps } from "../voice/AgentVoiceCallCaptions/AgentVoiceCallCaptions";
export { AgentVoiceInputGlow, type AgentVoiceInputGlowProps } from "../voice/AgentVoiceInputGlow/AgentVoiceInputGlow";
export { AgentVoiceIncomingCall, type AgentVoiceIncomingCallProps } from "../voice/AgentVoiceIncomingCall/AgentVoiceIncomingCall";
// The mark of a voice chat (five sound bars): the icon the chat's call markers show.
export {
  VoiceBarsIcon as AgentVoiceBarsIcon,
  type VoiceBarsIconProps as AgentVoiceIconProps,
} from "../chat/ConversationMessage/ConversationCallMarkerChip/VoiceBarsIcon/VoiceBarsIcon";
