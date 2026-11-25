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

/**
 * Starts the account realtime poll (`createAccountRealtimePoll` of `./ui`) with the arrival hook the ring source hands in:
 * `onMessage => createAccountRealtimePoll({ readAccountRealtime: client.getAccountRealtimeMessages, onMessage })`.
 * For an application that wants the poll to run only while a ring can show: the ring source starts it with its first
 * subscriber and stops it with its last. An application that reads further message types from the same poll passes
 * the poll itself instead and subscribes to it beside the ring source.
 */
export type AgentVoiceAccountRealtimePollStarter = (
  onMessage: (message: AgentRealtimeMessageDelivery) => void,
) => AccountRealtimePoll;

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
 * It listens while somebody is subscribed. Given the poll itself, it subscribes to it with the first subscriber and
 * leaves it with the last; the poll stays the application's, which stops it. Given a function that starts the poll, it
 * starts the poll with the first subscriber and stops it with the last.
 */
export class AgentVoiceAccountRealtimeRingSource implements AgentVoiceRingSource {
  protected readonly accountRealtimePollOrStarter: AccountRealtimePoll | AgentVoiceAccountRealtimePollStarter;
  protected readonly subscribers = new Set<AgentVoiceRingSubscriber>();
  /** The rings delivered while it listens, by call: a subscriber that comes later still gets the ones in their deadline. */
  protected readonly knownRingsByCallId = new Map<number, AgentVoiceKnownRing>();
  /** The poll a starter created; this source stops it. Undefined for a poll of the application. */
  protected startedAccountRealtimePoll: AccountRealtimePoll | undefined;
  /** Set while it listens to the poll. */
  protected unsubscribeFromAccountRealtimePoll: (() => void) | undefined;

  constructor(accountRealtimePollOrStarter: AccountRealtimePoll | AgentVoiceAccountRealtimePollStarter) {
    this.accountRealtimePollOrStarter = accountRealtimePollOrStarter;
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

  /**
   * The poll hands over the messages open now, then every arrival and every clear. A started poll delivers its
   * arrivals through the hook it was started with, so only the clears are subscribed there.
   */
  protected listenToAccountRealtimePoll(): void {
    const accountRealtimePollOrStarter = this.accountRealtimePollOrStarter;
    if (typeof accountRealtimePollOrStarter !== "function") {
      this.unsubscribeFromAccountRealtimePoll = accountRealtimePollOrStarter.subscribe({
        onMessage: (message) => {
          this.acceptRealtimeMessage(message);
        },
        onMessageCleared: (message) => {
          this.acceptClearedRealtimeMessage(message);
        },
      });
      return;
    }
    const startedAccountRealtimePoll = accountRealtimePollOrStarter((message) => {
      this.acceptRealtimeMessage(message);
    });
    this.startedAccountRealtimePoll = startedAccountRealtimePoll;
    this.unsubscribeFromAccountRealtimePoll = startedAccountRealtimePoll.subscribe({
      onMessageCleared: (message) => {
        this.acceptClearedRealtimeMessage(message);
      },
    });
  }

  protected leaveAccountRealtimePoll(): void {
    this.unsubscribeFromAccountRealtimePoll?.();
    this.unsubscribeFromAccountRealtimePoll = undefined;
    this.startedAccountRealtimePoll?.stop();
    this.startedAccountRealtimePoll = undefined;
    this.knownRingsByCallId.clear();
  }
}

/**
 * The ring source over the account realtime poll of `./ui`:
 * `createAgentVoiceRingSource(createAccountRealtimePoll({ readAccountRealtime, … }))`. A function that starts the poll
 * (`AgentVoiceAccountRealtimePollStarter`) is taken as well. An application with its own ring transport passes its own
 * `AgentVoiceRingSource` instead.
 */
export const createAgentVoiceRingSource = (
  accountRealtimePollOrStarter: AccountRealtimePoll | AgentVoiceAccountRealtimePollStarter,
): AgentVoiceRingSource => new AgentVoiceAccountRealtimeRingSource(accountRealtimePollOrStarter);
