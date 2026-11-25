import type { AgentRealtimeMessageDelivery } from "../../ui/realtime-arrival";

/** The realtime message type of the agent ringing the owner's open clients. Its key is the call id. */
export const AGENT_VOICE_RING_MESSAGE_TYPE = "agent.voice.ring";

/**
 * The agent ringing this account: what a client needs to show the ring and answer it. Written by the backend when the
 * agent starts a call to the owner, for every open client of the account; it ends for a client when the call was
 * answered or declined, when its deadline passed, or when the agent or the owner canceled.
 */
export interface AgentVoiceRing {
  callId: number;
  conversationId?: number | null;
  /** Single-use, bound to this call; redeemed by `answerRing` / `declineRing`. */
  answerToken: string;
  ringExpiresAt: string;
  ringExpiresAtUnixMs: number;
  /** A text key: the caller line of the incoming call. */
  callerLabelKey: string;
  /** What the application put into the ring for its own caller line (what tells an owner which space is calling). */
  callerContext: Readonly<Record<string, unknown>>;
  /**
   * The agent space of the call, where the ring names it: passed to `openSession` when the owner accepts. An
   * application whose `openSession` needs the space fills it in its ring source or sends it in the payload.
   */
  agentSpaceId?: number;
}

const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === "object" && value !== null && !Array.isArray(value);

/**
 * The ring a realtime message carries; null for a message of another type or a payload that is not a ring (no call id,
 * no answer token, no deadline).
 */
export const readAgentVoiceRingOfRealtimeMessage = (message: AgentRealtimeMessageDelivery): AgentVoiceRing | null => {
  if (message.messageType !== AGENT_VOICE_RING_MESSAGE_TYPE) return null;
  let payload: unknown;
  try {
    payload = JSON.parse(message.payloadJson);
  } catch {
    return null;
  }
  if (!isRecord(payload)) return null;
  const { callId, conversationId, answerToken, ringExpiresAt, ringExpiresAtUnixMs, callerLabelKey, callerContext, agentSpaceId } =
    payload;
  if (typeof callId !== "number" || typeof answerToken !== "string" || typeof ringExpiresAtUnixMs !== "number") return null;
  return {
    callId,
    conversationId: typeof conversationId === "number" ? conversationId : null,
    answerToken,
    ringExpiresAt: typeof ringExpiresAt === "string" ? ringExpiresAt : "",
    ringExpiresAtUnixMs,
    callerLabelKey: typeof callerLabelKey === "string" ? callerLabelKey : "",
    callerContext: isRecord(callerContext) ? callerContext : {},
    agentSpaceId: typeof agentSpaceId === "number" ? agentSpaceId : undefined,
  };
};

/**
 * The ring this client shows: the newest one still inside its deadline and not already handled here (answered,
 * declined or let go in this client).
 */
export const pickRingingAgentVoiceCall = (
  rings: readonly AgentVoiceRing[],
  nowMs: number,
  handledCallIds: ReadonlySet<number>,
): AgentVoiceRing | undefined =>
  rings
    .filter((ring) => ring.ringExpiresAtUnixMs > nowMs && !handledCallIds.has(ring.callId))
    .sort((left, right) => right.callId - left.callId)[0];
