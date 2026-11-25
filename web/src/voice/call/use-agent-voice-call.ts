import { useMemo } from "react";
import { type AgentVoiceCallSnapshot, useAgentVoiceCallSnapshot } from "./agent-voice-call-store";
import { useAgentVoiceCallElapsedSeconds } from "./use-agent-voice-call-elapsed-seconds";

/** The call with its clock: what `useAgentVoiceCall()` returns. */
export interface AgentVoiceCall extends AgentVoiceCallSnapshot {
  /** Seconds since the call connected: ticking while it is live, frozen once it ended, 0 before it connected. */
  readonly elapsedSeconds: number;
}

/**
 * The voice chat as it is now: the phase, the call and its conversation, the spoken lines, mute, the end reason and
 * the elapsed seconds. It renders again every second while the call is live; a component that needs one field only
 * reads it with `useAgentVoiceCallValue`, one that needs no clock with `useAgentVoiceCallSnapshot`.
 */
export const useAgentVoiceCall = (): AgentVoiceCall => {
  const call = useAgentVoiceCallSnapshot();
  const elapsedSeconds = useAgentVoiceCallElapsedSeconds(call);
  return useMemo(() => ({ ...call, elapsedSeconds }), [call, elapsedSeconds]);
};
