// Payloads of the admin call routes (`AdminAgentVoiceCallsTrait`).
// The serializer leaves a property without a value out, so every optional field may be missing. A date-time is a
// string `Y-m-d H:i:s` without an offset; the screens read it as the admin UI's date cells do. A call's task text is
// written by the agent or the owner: text only.

/** `AgentVoiceCall.direction`: `INBOUND` the owner called the agent, `OUTBOUND` the agent rang the owner. */
export type AgentVoiceAdminCallDirection = "INBOUND" | "OUTBOUND";

/** The end reasons the call writes itself; the teardown paths write diagnostic values of their own beside them. */
export type AgentVoiceAdminCallEndReason =
  "COMPLETED" | "LIMIT_REACHED" | "FAILED" | "NOT_ANSWERED" | "DECLINED" | "EXPIRED" | "CANCELED" | "ENDED_BY_AGENT";

/**
 * Where a call stands: it rings, it was answered and runs, or it is over. The server's reading at the time it answers:
 * a call a dead session worker left open, or a ring past its deadline, reads `ENDED` although it has no `endedAt`. A
 * screen shows it as it came and derives no state from the call's times.
 */
export type AgentVoiceAdminCallState = "RINGING" | "IN_PROGRESS" | "ENDED";

export interface AgentVoiceAdminChangeHistory {
  createdTime?: string | null;
  modifiedTime?: string | null;
}

/** One voice call as both routes answer it. */
export interface AgentVoiceAdminCall {
  id: number;
  /** The account the call belongs to. */
  accountId?: number | null;
  direction: AgentVoiceAdminCallDirection | string;
  /** When the call was answered; missing = never answered (yet). */
  startedAt?: string | null;
  /** When it hung up; missing = still ringing or running. */
  endedAt?: string | null;
  /** When the ringing stops; missing for a call the owner opened. */
  ringDeadlineAt?: string | null;
  /** Why it ended: an `AgentVoiceAdminCallEndReason`, or a diagnostic value of a teardown path. */
  endReason?: AgentVoiceAdminCallEndReason | string | null;
  /** The seconds booked as usage so far. */
  bookedSeconds: number;
  /** The conversation the call ran on. */
  aiConversationId?: number | null;
  /** What the agent works on in the call. Written by the agent or the owner: shown as text only. */
  callTask?: string | null;
  /** The name of the provider adapter the call runs on. */
  voiceProvider?: string | null;
  /** The provider's id of the session. */
  providerCallId?: string | null;
  /** The conversation in which the agent itself started the call. */
  startedByAgentInConversationId?: number | null;
  /** Present when the application's serializer settings keep it. */
  changeHistory?: AgentVoiceAdminChangeHistory | null;
}

export interface AgentVoiceAdminElements<T> {
  elements?: T[];
}

export interface AgentVoiceAdminCurrency {
  /** `USD` for a catalog price. */
  isoCode: string;
  displaySymbolBeforeAmount?: boolean | null;
}

/** A money value. `amount` is unrounded (`0.10799999999999998` is a real value): round it when showing it. */
export interface AgentVoiceAdminMoneyAmount {
  amount: number;
  currency: AgentVoiceAdminCurrency;
}

/** What the server derives for one call of the list. */
export interface AgentVoiceAdminCallDerivedValues {
  /** The `id` of the call. */
  callId: number;
  /** Where the call stands, read on the server with one clock reading for all rows of the answer. */
  callState: AgentVoiceAdminCallState;
  /** The costs of the call's booked seconds; missing = no price is known for its model, or nothing was booked. */
  bookedCosts?: AgentVoiceAdminMoneyAmount | null;
}

/** `GET /calls`: the calls the signed-in account may read, newest first. The answer carries no total count. */
export interface AgentVoiceAdminCallsResponse {
  agentVoiceCalls: AgentVoiceAdminElements<AgentVoiceAdminCall>;
  /** One entry per call of `agentVoiceCalls.elements`, in the same order; joined by `callId`. */
  agentVoiceCallDerivedValues: AgentVoiceAdminCallDerivedValues[];
}

/** `GET /calls/{callId}`: one call with what a screen would otherwise compute or look up. */
export interface AgentVoiceAdminCallResponse {
  agentVoiceCall: AgentVoiceAdminCall;
  /** Where the call stands, read on the server at answer time. */
  callState: AgentVoiceAdminCallState;
  /** `endedAt - startedAt`; missing while the call runs or when nobody answered. */
  durationInSeconds?: number | null;
  bookedSeconds: number;
  /** The costs of the booked seconds; missing = no price is known for the call's model, or nothing was booked. */
  bookedCosts?: AgentVoiceAdminMoneyAmount | null;
  endReason?: AgentVoiceAdminCallEndReason | string | null;
  /** The conversation whose messages are the transcript of the call. */
  conversationId?: number | null;
}

/** The filters and the page of the call list; a filter left out does not narrow. */
export interface AgentVoiceAdminCallsArg {
  /** Only the calls of this account (inside what the signed-in account may read). */
  accountId?: number;
  /** Only the calls that ran on this conversation. */
  conversationId?: number;
  /**
   * Only the calls created at or after this moment: a date-time `Y-m-d H:i:s` in the zone of the backend's date-times
   * (the screens send UTC, as the admin UI's date cells read it), or a day `Y-m-d`.
   */
  createdFrom?: string;
  /** Only the calls created at or before this moment; the same form as `createdFrom`. */
  createdTo?: string;
  /** Only the calls with at least this many booked seconds. */
  bookedSecondsFrom?: number;
  /** Only the calls with at most this many booked seconds. */
  bookedSecondsTo?: number;
  /** Only the calls that ended for this reason. */
  endReason?: AgentVoiceAdminCallEndReason | string;
  limit?: number;
  offset?: number;
}

export interface AgentVoiceAdminCallIdArg {
  callId: number;
}
