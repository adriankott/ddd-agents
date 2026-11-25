import type { AgentRealtimeListenerCredential, AgentRealtimeMessageDelivery } from "./realtime-arrival";

/**
 * The account-level realtime read (`GET …/realtimeMessages`, `AgentAccountRealtimeMessagesGetResponseDto`): the open
 * messages of the realtime port addressed to the signed-in account — what reaches a client that shows no conversation,
 * e.g. a call's ring — and the credential to listen on the host's transport without a conversation.
 */
export interface AccountRealtimeRead {
  messages: AgentRealtimeMessageDelivery[];
  /** Null without a transport: the client then keeps polling the read. */
  listenerCredential: AgentRealtimeListenerCredential | null;
}

/** The pause between two account reads while no transport is bound. */
export const ACCOUNT_REALTIME_POLL_INTERVAL_MS = 5_000;

/** The pause before the one re-read of `readAfterPing` when the first read brought nothing new. */
export const ACCOUNT_REALTIME_PING_REREAD_DELAY_MS = 400;

/** What a subscriber of the poll is told; each hook is optional. */
export interface AccountRealtimePollSubscriber {
  /**
   * A message that arrived: every message once per type, key and payload (a replacement with a new payload arrives
   * again; a message cleared and sent again after a read saw it gone arrives again). From the poll and from the
   * transport.
   */
  onMessage?: (message: AgentRealtimeMessageDelivery) => void;
  /**
   * A message that is open no more: a read answered without any message of its type and key (it was answered or
   * withdrawn on the server, or its time is over). Called once, after the arrivals of the same read. A subscriber is
   * told the clear only of a message it was handed, as an arrival or in the hand-over of `subscribe`, and with that
   * message as it was handed to it last. A replacement under the same type and key is an arrival, never a clear. Only
   * a read tells it, and only of a message that was known when the read started: while a transport is bound, the next
   * `readNow()` or `readAfterPing()`.
   */
  onMessageCleared?: (message: AgentRealtimeMessageDelivery) => void;
}

export interface AccountRealtimePollOptions extends AccountRealtimePollSubscriber {
  /**
   * The account read of the binding (`AgentsConversationClient.getAccountRealtimeMessages`), called with the
   * `clientId` option (undefined without one). The read always states a client id (the HTTP binding: query parameter
   * `clientId`), by which the server knows a client that only polls; which id is the binding's word: the one handed
   * in, else the id of its `uiClient`, else one it creates once and keeps while it lives. A binding of the host's own
   * states one the same way.
   */
  readAccountRealtime: (clientId?: string) => Promise<AccountRealtimeRead>;
  /**
   * The client id the account read states, for a host that manages its own ids (8 to 64 letters, digits and `-`): it
   * wins over the binding's `uiClient` and over the id the binding creates. Default: none — the binding decides.
   */
  clientId?: string;
  /** The credential of every read (null without a transport) — the host binds its transport with it. */
  onListenerCredential?: (credential: AgentRealtimeListenerCredential | null) => void;
  /**
   * Whether the host's transport is bound now: while true the poll skips its reads (the transport delivers through
   * `acceptArrivedMessage` or pings `readAfterPing`). Default: never bound — the poll reads every interval.
   */
  isTransportBound?: () => boolean;
  /** The pause between two reads. Default `ACCOUNT_REALTIME_POLL_INTERVAL_MS`. */
  intervalMs?: number;
  /** The pause before the one re-read after a ping. Default `ACCOUNT_REALTIME_PING_REREAD_DELAY_MS`. */
  rereadDelayMs?: number;
  /** A failed read (the next interval reads again; nothing arrives and nothing is cleared). Default: ignored. */
  onReadFailed?: (error: unknown) => void;
}

export interface AccountRealtimePoll {
  /**
   * Reads at once (also while a transport is bound); resolves with the number of messages that arrived new. An answer
   * that comes back after the answer of a read started later is left out (0).
   */
  readNow: () => Promise<number>;
  /**
   * The arrival adapter for a host transport that carries the message: hands it to `onMessage` unless it already
   * arrived. Returns whether it arrived new.
   */
  acceptArrivedMessage: (message: AgentRealtimeMessageDelivery) => boolean;
  /**
   * The arrival adapter for a host transport that only pings: reads the account; when that read brought nothing new —
   * the ping overtook the server's write — it reads once more after the re-read delay. Resolves with whether a
   * message arrived.
   */
  readAfterPing: () => Promise<boolean>;
  /**
   * Adds a subscriber after the poll was created; the hooks the poll was created with keep working beside it. The
   * subscriber gets, before `subscribe` returns, the messages open now (those the last read saw and the transport
   * delivered since, inside their `secondsLeft`), then every arrival and the clear of every message it was handed: a
   * message past its `secondsLeft` when the subscriber came is not handed to it, and neither is its clear. Returns
   * the function that removes it; the poll keeps running.
   */
  subscribe: (subscriber: AccountRealtimePollSubscriber) => () => void;
  /**
   * Stops the poll (a read in flight still hands its messages). Nothing is reported as cleared and no subscriber is
   * removed: what `readNow()` or `acceptArrivedMessage()` bring afterwards still reaches them.
   */
  stop: () => void;
}

/** What makes two deliveries the same message: a new payload under the same type and key is a new message. */
const identityOf = (message: AgentRealtimeMessageDelivery): string =>
  `${message.messageType}\u0000${message.messageKey}\u0000${message.payloadJson}`;

/** Where a message stands on the realtime port: a replacement stands at the same place. */
const placeOf = (message: AgentRealtimeMessageDelivery): string => `${message.messageType}\u0000${message.messageKey}`;

interface AccountRealtimePollSubscription {
  subscriber: AccountRealtimePollSubscriber;
}

interface KnownAccountRealtimeMessage {
  message: AgentRealtimeMessageDelivery;
  /** How many reads had started when the message became known: none of them can tell that it is gone. */
  startedReadCount: number;
  /** When its `secondsLeft` are over on this client's clock. */
  expiresAtMs: number;
  /** The subscribers the message was handed to, as an arrival or when they came: only they are told its clear. */
  handedTo: Set<AccountRealtimePollSubscription>;
}

/**
 * The account-level realtime poll of a client that shows no conversation: reads the account at once, then every
 * interval while no transport is bound, hands each new message to `onMessage` once (the same message from the poll and
 * the transport arrives once) and each message a read finds gone to `onMessageCleared` once. Further subscribers come
 * and go through `subscribe`. The client of a conversation keeps its run-state read; this is the read without one.
 */
export const createAccountRealtimePoll = (options: AccountRealtimePollOptions): AccountRealtimePoll => {
  const intervalMs = options.intervalMs ?? ACCOUNT_REALTIME_POLL_INTERVAL_MS;
  const rereadDelayMs = options.rereadDelayMs ?? ACCOUNT_REALTIME_PING_REREAD_DELAY_MS;
  /**
   * The messages the last read saw (and the transport delivered since), by identity: one gone from a read may arrive
   * again. A read reports as cleared only what was known when it started.
   */
  let knownMessages = new Map<string, KnownAccountRealtimeMessage>();
  /** The hooks of the options first, then the subscribers in the order they came. */
  const subscriptions = new Set<AccountRealtimePollSubscription>([{ subscriber: options }]);
  let startedReadCount = 0;
  let newestAnsweredRead = 0;
  let stopped = false;
  let timer: ReturnType<typeof setTimeout> | null = null;

  const knownMessageOf = (message: AgentRealtimeMessageDelivery, nowMs: number): KnownAccountRealtimeMessage => ({
    message,
    startedReadCount,
    expiresAtMs: nowMs + message.secondsLeft * 1000,
    handedTo: new Set(),
  });

  /** Hands a message to a subscriber and notes it on the message, also for a subscriber without the hook. */
  const handMessage = (knownMessage: KnownAccountRealtimeMessage, subscription: AccountRealtimePollSubscription): void => {
    knownMessage.handedTo.add(subscription);
    subscription.subscriber.onMessage?.(knownMessage.message);
  };

  /**
   * A subscriber that came during the hand-over already got the open messages; one that left gets nothing more. Each
   * cleared place comes with its gone messages, the oldest first: a subscriber is told the newest one it was handed,
   * and nothing when it was handed none.
   */
  const handToSubscribers = (
    arrivedMessages: KnownAccountRealtimeMessage[],
    goneMessagesOfClearedPlaces: KnownAccountRealtimeMessage[][],
  ): void => {
    const subscriptionsNow = [...subscriptions];
    for (const arrivedMessage of arrivedMessages) {
      for (const subscription of subscriptionsNow) {
        if (subscriptions.has(subscription)) handMessage(arrivedMessage, subscription);
      }
    }
    for (const goneMessages of goneMessagesOfClearedPlaces) {
      for (const subscription of subscriptionsNow) {
        if (!subscriptions.has(subscription)) continue;
        const handedMessage = goneMessages.filter(goneMessage => goneMessage.handedTo.has(subscription)).pop();
        if (handedMessage !== undefined) subscription.subscriber.onMessageCleared?.(handedMessage.message);
      }
    }
  };

  const acceptArrivedMessage = (message: AgentRealtimeMessageDelivery): boolean => {
    const identity = identityOf(message);
    if (knownMessages.has(identity)) return false;
    const knownMessage = knownMessageOf(message, Date.now());
    knownMessages.set(identity, knownMessage);
    handToSubscribers([knownMessage], []);
    return true;
  };

  const readNow = async (): Promise<number> => {
    startedReadCount += 1;
    const readNumber = startedReadCount;
    const read = await options.readAccountRealtime(options.clientId);
    // the answer of a read started later is already in: this one tells an older state
    if (readNumber < newestAnsweredRead) return 0;
    newestAnsweredRead = readNumber;

    const nowMs = Date.now();
    const knownAfterRead = new Map<string, KnownAccountRealtimeMessage>();
    const openPlaces = new Set<string>();
    const arrivedMessages: KnownAccountRealtimeMessage[] = [];
    for (const message of read.messages) {
      const identity = identityOf(message);
      const knownMessage = knownMessages.get(identity) ?? knownMessageOf(message, nowMs);
      if (!knownMessages.has(identity)) arrivedMessages.push(knownMessage);
      knownAfterRead.set(identity, knownMessage);
      openPlaces.add(placeOf(message));
    }
    const goneMessages: KnownAccountRealtimeMessage[] = [];
    for (const [identity, knownMessage] of knownMessages) {
      if (knownAfterRead.has(identity)) continue;
      if (knownMessage.startedReadCount >= readNumber) {
        // it became known after this read had started (from the transport, or from a read answered in between):
        // this read cannot tell that it is gone
        knownAfterRead.set(identity, knownMessage);
        openPlaces.add(placeOf(knownMessage.message));
      } else {
        goneMessages.push(knownMessage);
      }
    }
    /** By place: a replaced message is no clear, and the gone messages of one place are one clear. */
    const goneMessagesByClearedPlace = new Map<string, KnownAccountRealtimeMessage[]>();
    for (const goneMessage of goneMessages) {
      const place = placeOf(goneMessage.message);
      if (openPlaces.has(place)) continue;
      goneMessagesByClearedPlace.set(place, [...(goneMessagesByClearedPlace.get(place) ?? []), goneMessage]);
    }
    knownMessages = knownAfterRead;

    handToSubscribers(arrivedMessages, [...goneMessagesByClearedPlace.values()]);
    options.onListenerCredential?.(read.listenerCredential);
    return arrivedMessages.length;
  };

  const subscribe = (subscriber: AccountRealtimePollSubscriber): (() => void) => {
    const subscription: AccountRealtimePollSubscription = { subscriber };
    subscriptions.add(subscription);
    const nowMs = Date.now();
    for (const knownMessage of [...knownMessages.values()]) {
      if (knownMessage.expiresAtMs > nowMs) handMessage(knownMessage, subscription);
    }
    return () => {
      subscriptions.delete(subscription);
    };
  };

  const schedule = (): void => {
    if (stopped) return;
    timer = setTimeout(tick, intervalMs);
  };

  const readIgnoringFailure = async (): Promise<number> => {
    try {
      return await readNow();
    } catch (error) {
      options.onReadFailed?.(error);
      return 0;
    }
  };

  const tick = async (): Promise<void> => {
    timer = null;
    if (stopped) return;
    if (!(options.isTransportBound?.() ?? false)) await readIgnoringFailure();
    schedule();
  };

  const readAfterPing = async (): Promise<boolean> => {
    if ((await readIgnoringFailure()) > 0) return true;
    await new Promise<void>(resolve => setTimeout(resolve, rereadDelayMs));
    return (await readIgnoringFailure()) > 0;
  };

  // the first read always happens: it carries the credential the host binds its transport with
  void readIgnoringFailure().then(schedule);

  return {
    readNow,
    acceptArrivedMessage,
    readAfterPing,
    subscribe,
    stop: () => {
      stopped = true;
      if (timer !== null) clearTimeout(timer);
      timer = null;
    },
  };
};
