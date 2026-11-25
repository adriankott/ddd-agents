import type { AgentVoiceCallSnapshot } from "./agent-voice-call-store";

/** Whose turn it is on the line right now: the band's status line and the input glow read it. */
export type AgentVoiceCallTurn = "connecting" | "muted" | "silentMicrophone" | "agent" | "owner" | "thinking" | "listening";

export const turnOfAgentVoiceCall = (call: AgentVoiceCallSnapshot): AgentVoiceCallTurn => {
  if (call.phase === "connecting") return "connecting";
  if (call.isMuted) return "muted";
  if (call.isMicrophoneSilent) return "silentMicrophone";
  if (call.isAgentSpeaking) return "agent";
  if (call.isOwnerSpeaking) return "owner";
  // The owner said the last words and the agent has not started its answer yet.
  if (call.lastSpeaker === "owner") return "thinking";
  return "listening";
};
