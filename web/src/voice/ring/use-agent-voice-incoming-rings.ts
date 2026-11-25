import { useEffect, useState } from "react";
import {
  agentVoiceIncomingRingsInDeadlineAt,
  withAgentVoiceIncomingRing,
  withoutAgentVoiceIncomingRingOfCall,
} from "./agent-voice-incoming-ring-list";
import type { AgentVoiceRing } from "./agent-voice-ring";
import type { AgentVoiceRingSource } from "./agent-voice-ring-source";

const NO_RINGS: readonly AgentVoiceRing[] = [];

export interface AgentVoiceIncomingRings {
  /** The rings that arrived and are still inside their deadline, one per call. */
  rings: readonly AgentVoiceRing[];
  /** The clock the deadlines were last read against: ticks every second while a ring is held. */
  nowMs: number;
}

/**
 * Listens for the agent's rings to this client while a ring source is given: a ring that arrives replaces the one of
 * the same call, a cleared one goes at once, and one whose deadline passed goes with the next tick of the clock. No
 * ring source (the application has no ring transport) or no ring: an empty list, and no clock running.
 */
export const useAgentVoiceIncomingRings = (ringSource: AgentVoiceRingSource | undefined): AgentVoiceIncomingRings => {
  const [rings, setRings] = useState<readonly AgentVoiceRing[]>(NO_RINGS);
  const [nowMs, setNowMs] = useState(() => Date.now());

  useEffect(() => {
    if (ringSource === undefined) return;
    const unsubscribe = ringSource.subscribe(
      (ring) => setRings((heldRings) => withAgentVoiceIncomingRing(heldRings, ring)),
      (callId) => setRings((heldRings) => withoutAgentVoiceIncomingRingOfCall(heldRings, callId)),
    );
    return () => {
      unsubscribe();
      setRings(NO_RINGS);
    };
  }, [ringSource]);

  const hasRings = rings.length > 0;
  useEffect(() => {
    if (!hasRings) return;
    setNowMs(Date.now());
    const clockTimer = window.setInterval(() => {
      const tickMs = Date.now();
      setNowMs(tickMs);
      setRings((heldRings) => agentVoiceIncomingRingsInDeadlineAt(heldRings, tickMs));
    }, 1_000);
    return () => window.clearInterval(clockTimer);
  }, [hasRings]);

  return { rings, nowMs };
};
