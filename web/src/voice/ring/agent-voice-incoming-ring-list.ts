import type { AgentVoiceRing } from "./agent-voice-ring";

/** The held rings with `ring` at the end: a ring that arrives replaces the one of the same call, so a call rings once. */
export const withAgentVoiceIncomingRing = (
  heldRings: readonly AgentVoiceRing[],
  ring: AgentVoiceRing,
): readonly AgentVoiceRing[] => [...heldRings.filter((heldRing) => heldRing.callId !== ring.callId), ring];

/** The held rings without the ring of the call `callId`: a cleared ring goes at once. */
export const withoutAgentVoiceIncomingRingOfCall = (
  heldRings: readonly AgentVoiceRing[],
  callId: number,
): readonly AgentVoiceRing[] => heldRings.filter((heldRing) => heldRing.callId !== callId);

/**
 * The held rings whose deadline is after `nowMs`. The same list when none has passed its deadline, so a tick of the
 * clock that drops nothing changes nothing.
 */
export const agentVoiceIncomingRingsInDeadlineAt = (
  heldRings: readonly AgentVoiceRing[],
  nowMs: number,
): readonly AgentVoiceRing[] => {
  const ringsInDeadline = heldRings.filter((heldRing) => heldRing.ringExpiresAtUnixMs > nowMs);
  return ringsInDeadline.length === heldRings.length ? heldRings : ringsInDeadline;
};
