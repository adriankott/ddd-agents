import type { AccountRealtimePoll } from "../../ui/account-realtime";
import type { AgentRealtimeMessageDelivery } from "../../ui/realtime-arrival";
import { type AgentVoiceRing, readAgentVoiceRingOfRealtimeMessage } from "./agent-voice-ring";

/**
 * The stream of the agent's rings to this account. `AgentVoiceCallProvider` takes one; the incoming call subscribes
 * while it is mounted. `onRing` gets every ring that arrives (a replaced one again); `onRingCleared` is called when
 * the source learns that a ring ended before its deadline (answered on another client, declined, canceled). A source
 * that cannot learn it never calls it: the ring then goes at its deadline, and an Accept after the end is refused by
 * the backend.
 */
export interface AgentVoiceRingSource {
  subscribe(onRing: (ring: AgentVoiceRing) => void, onRingCleared: (callId: number) => void): () => void;
}

interface AgentVoiceRingSubscriber {
  onRing: (ring: AgentVoiceRing) => void;
  onRingCleared: (callId: number) => void;
}

interface AgentVoiceKnownRing {
  ring: AgentVoiceRing;
  /** The subscribers the ring was handed to, as an arrival or when they came: only they are told its clear. */
  handedTo: Set<AgentVoiceRingSubscriber>;
}

/**
 * The ring source over the account realtime poll: every message of the type `agent.voice.ring` the poll delivers goes
 * to the subscribers as a ring, and a ring message the poll reports as cleared (a read found it gone: answered or
 * declined on another client, canceled, past its deadline) as a cleared ring. A subscriber is told `onRingCleared`
 * only for a ring it was handed, as an arrival or in the hand-over when it subscribed.
 *
 * It listens while somebody is subscribed: it subscribes to the poll with the first subscriber and leaves it with the
 * last. The poll stays the application's, which creates and stops it; the ring source never stops it.
 */
export class AgentVoiceAccountRealtimeRingSource implements AgentVoiceRingSource {
  protected readonly accountRealtimePoll: AccountRealtimePoll;
  protected readonly subscribers = new Set<AgentVoiceRingSubscriber>();
  /** The rings delivered while it listens, by call: a subscriber that comes later still gets the ones in their deadline. */
  protected readonly knownRingsByCallId = new Map<number, AgentVoiceKnownRing>();
  /** Set while it listens to the poll. */
  protected unsubscribeFromAccountRealtimePoll: (() => void) | undefined;

  constructor(accountRealtimePoll: AccountRealtimePoll) {
    this.accountRealtimePoll = accountRealtimePoll;
  }

  subscribe(onRing: (ring: AgentVoiceRing) => void, onRingCleared: (callId: number) => void): () => void {
    const subscriber: AgentVoiceRingSubscriber = { onRing, onRingCleared };
    this.subscribers.add(subscriber);
    const nowMs = Date.now();
    this.knownRingsByCallId.forEach((knownRing) => {
      if (knownRing.ring.ringExpiresAtUnixMs > nowMs) this.handRing(knownRing, subscriber);
    });
    if (this.unsubscribeFromAccountRealtimePoll === undefined) this.listenToAccountRealtimePoll();
    return () => {
      this.subscribers.delete(subscriber);
      if (this.subscribers.size === 0) this.leaveAccountRealtimePoll();
    };
  }

  /** Takes one message of the account's realtime messages; returns whether it was a ring. */
  acceptRealtimeMessage(message: AgentRealtimeMessageDelivery): boolean {
    const ring = readAgentVoiceRingOfRealtimeMessage(message);
    if (ring === null) return false;
    const knownRing: AgentVoiceKnownRing = { ring, handedTo: new Set() };
    this.knownRingsByCallId.set(ring.callId, knownRing);
    this.subscribers.forEach((subscriber) => this.handRing(knownRing, subscriber));
    return true;
  }

  /**
   * Takes one message the account's realtime messages hold no more; returns whether it was a ring. The clear goes to
   * the subscribers the ring of that call was handed to, and to nobody for a call this source holds no ring of.
   */
  acceptClearedRealtimeMessage(message: AgentRealtimeMessageDelivery): boolean {
    const ring = readAgentVoiceRingOfRealtimeMessage(message);
    if (ring === null) return false;
    const knownRing = this.knownRingsByCallId.get(ring.callId);
    this.knownRingsByCallId.delete(ring.callId);
    this.subscribers.forEach((subscriber) => {
      if (knownRing?.handedTo.has(subscriber)) subscriber.onRingCleared(ring.callId);
    });
    return true;
  }

  /** Hands a ring to a subscriber and notes it on the known ring. */
  protected handRing(knownRing: AgentVoiceKnownRing, subscriber: AgentVoiceRingSubscriber): void {
    knownRing.handedTo.add(subscriber);
    subscriber.onRing(knownRing.ring);
  }

  /** The poll hands over the messages open now, then every arrival and the clear of every message it handed over. */
  protected listenToAccountRealtimePoll(): void {
    this.unsubscribeFromAccountRealtimePoll = this.accountRealtimePoll.subscribe({
      onMessage: (message) => {
        this.acceptRealtimeMessage(message);
      },
      onMessageCleared: (message) => {
        this.acceptClearedRealtimeMessage(message);
      },
    });
  }

  protected leaveAccountRealtimePoll(): void {
    this.unsubscribeFromAccountRealtimePoll?.();
    this.unsubscribeFromAccountRealtimePoll = undefined;
    this.knownRingsByCallId.clear();
  }
}

/**
 * The ring source over the account realtime poll of `./ui`:
 * `createAgentVoiceRingSource(createAccountRealtimePoll({ readAccountRealtime, … }))`. An application with its own
 * ring transport passes its own `AgentVoiceRingSource` instead.
 */
export const createAgentVoiceRingSource = (accountRealtimePoll: AccountRealtimePoll): AgentVoiceRingSource =>
  new AgentVoiceAccountRealtimeRingSource(accountRealtimePoll);
