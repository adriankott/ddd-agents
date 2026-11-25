import { useEffect, useSyncExternalStore } from "react";
import type { AgentVoiceCallerLabel } from "./agent-voice-caller-label";
import type { AgentVoiceRing } from "./agent-voice-ring";
import {
  type AgentVoiceAnswerRefusal,
  answerIncomingAgentVoiceCall,
  declineIncomingAgentVoiceCall,
} from "./answer-incoming-agent-voice-call";

const REFUSAL_SHOWN_MS = 4_000;

/**
 * The agent ringing this client, shared between the one listener (`AgentVoiceIncomingCall`, mounted once) and where
 * the ring is answered: the band over an open chat's input, or, with no chat in sight, the listener's own card.
 */
export interface AgentVoiceIncomingRingSnapshot {
  /** The ring this client shows, inside its deadline and not handled here yet. */
  readonly ring?: AgentVoiceRing;
  /** The caller line of `ring`, as the listener resolved it. */
  readonly callerLabel?: AgentVoiceCallerLabel;
  /** Calls answered, declined or let go in this client: never shown again, even before their ring is gone. */
  readonly handledCallIds: ReadonlySet<number>;
  readonly isAnswering: boolean;
  /** Why the last Accept did not get the call; shown for a few seconds. */
  readonly refusal?: AgentVoiceAnswerRefusal;
  /** How many chats show the ring in their band right now (presented); with none, the listener shows its card. */
  readonly bandHostCount: number;
  /** How many surfaces cover the chats right now; with any, the listener shows its card. */
  readonly ringCoverCount: number;
}

let snapshot: AgentVoiceIncomingRingSnapshot = {
  handledCallIds: new Set(),
  isAnswering: false,
  bandHostCount: 0,
  ringCoverCount: 0,
};
const listeners = new Set<() => void>();
let refusalTimer: number | undefined;

const updateSnapshot = (changes: Partial<AgentVoiceIncomingRingSnapshot>) => {
  snapshot = { ...snapshot, ...changes };
  listeners.forEach((listener) => listener());
};

const subscribe = (listener: () => void) => {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
};

const getSnapshot = () => snapshot;

export const useAgentVoiceIncomingRing = (): AgentVoiceIncomingRingSnapshot =>
  useSyncExternalStore(subscribe, getSnapshot, getSnapshot);

/** The incoming ring as it is now, outside React (a test). */
export const readAgentVoiceIncomingRingSnapshot = (): AgentVoiceIncomingRingSnapshot => snapshot;

/** What the listener's own card shows (the ring, else a refused Accept) while no chat band can be seen. */
export const pickAgentVoiceIncomingCard = (
  state: Pick<AgentVoiceIncomingRingSnapshot, "ring" | "refusal" | "bandHostCount" | "ringCoverCount">,
): "ring" | "refusal" | undefined => {
  if (state.bandHostCount > 0 && state.ringCoverCount === 0) return undefined;
  if (state.ring !== undefined) return "ring";
  return state.refusal !== undefined ? "refusal" : undefined;
};

/** The listener publishes the ring it picked with its caller line (undefined when nothing rings). */
export const publishAgentVoiceIncomingRing = (
  ring: AgentVoiceRing | undefined,
  callerLabel: AgentVoiceCallerLabel | undefined,
) => {
  if (
    ring?.callId === snapshot.ring?.callId &&
    ring?.ringExpiresAtUnixMs === snapshot.ring?.ringExpiresAtUnixMs &&
    callerLabel?.text === snapshot.callerLabel?.text &&
    callerLabel?.detail === snapshot.callerLabel?.detail
  )
    return;
  updateSnapshot({ ring, callerLabel: ring === undefined ? undefined : callerLabel });
};

const markHandled = (handledRing: AgentVoiceRing) => {
  const isShownRing = snapshot.ring?.callId === handledRing.callId;
  updateSnapshot({
    handledCallIds: new Set(snapshot.handledCallIds).add(handledRing.callId),
    ring: isShownRing ? undefined : snapshot.ring,
    callerLabel: isShownRing ? undefined : snapshot.callerLabel,
  });
};

const showRefusal = (refusal: AgentVoiceAnswerRefusal | undefined) => {
  window.clearTimeout(refusalTimer);
  updateSnapshot({ refusal });
  if (refusal === undefined) return;
  refusalTimer = window.setTimeout(() => updateSnapshot({ refusal: undefined }), REFUSAL_SHOWN_MS);
};

/**
 * Accept: answers the ring and, when this client won, starts the call on the ring's own conversation and agent space
 * (the ring may come from another agent space than the one the client shows). The application learns the call's
 * conversation through `AgentVoiceCallProvider.onConversationOfCallChanged` and shows it.
 */
export const acceptAgentVoiceIncomingRing = async () => {
  const acceptedRing = snapshot.ring;
  if (!acceptedRing || snapshot.isAnswering) return;
  updateSnapshot({ isAnswering: true });
  const answerRefusal = await answerIncomingAgentVoiceCall(acceptedRing);
  markHandled(acceptedRing);
  updateSnapshot({ isAnswering: false });
  showRefusal(answerRefusal);
};

/**
 * Decline: ends the ring for every open client of the account. The backend writes the declined call into its
 * conversation once nothing rings any more.
 */
export const declineAgentVoiceIncomingRing = () => {
  const declinedRing = snapshot.ring;
  if (!declinedRing || snapshot.isAnswering) return;
  markHandled(declinedRing);
  void declineIncomingAgentVoiceCall(declinedRing);
};

/** A chat that shows the ring in its band, while hosting: the listener's card stays away unless a cover is open. */
export const useAgentVoiceRingBandHost = (isHosting: boolean) => {
  useEffect(() => {
    if (!isHosting) return;
    updateSnapshot({ bandHostCount: snapshot.bandHostCount + 1 });
    return () => updateSnapshot({ bandHostCount: snapshot.bandHostCount - 1 });
  }, [isHosting]);
};

/**
 * A surface that covers the chats while covering (a settings sheet over them): their bands are out of sight then, so
 * the listener shows its own card on top instead.
 */
export const useAgentVoiceRingCover = (isCovering: boolean) => {
  useEffect(() => {
    if (!isCovering) return;
    updateSnapshot({ ringCoverCount: snapshot.ringCoverCount + 1 });
    return () => updateSnapshot({ ringCoverCount: snapshot.ringCoverCount - 1 });
  }, [isCovering]);
};
