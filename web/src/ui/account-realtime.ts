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

export interface AccountRealtimePollOptions {
  /** The account read of the binding (`AgentsConversationClient.getAccountRealtimeMessages`). */
  readAccountRealtime: () => Promise<AccountRealtimeRead>;
  /**
   * The arrival hook: every message once per type, key and payload (a replacement with a new payload arrives again; a
   * message cleared and sent again after a read saw it gone arrives again). From the poll and from the transport.
   */
  onMessage: (message: AgentRealtimeMessageDelivery) => void;
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
  /** A failed read (the next interval reads again). Default: ignored. */
  onReadFailed?: (error: unknown) => void;
}

export interface AccountRealtimePoll {
  /** Reads at once (also while a transport is bound); resolves with the number of messages that arrived new. */
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
  /** Stops the poll (a read in flight still hands its messages). */
  stop: () => void;
}

const identityOf = (message: AgentRealtimeMessageDelivery): string =>
  `${message.messageType}\u0000${message.messageKey}\u0000${message.payloadJson}`;

/**
 * The account-level realtime poll of a client that shows no conversation: reads the account at once, then every
 * interval while no transport is bound, and hands each new message to `onMessage` once (the same message from the poll
 * and the transport arrives once). The client of a conversation keeps its run-state read; this is the read without one.
 */
export const createAccountRealtimePoll = (options: AccountRealtimePollOptions): AccountRealtimePoll => {
  const intervalMs = options.intervalMs ?? ACCOUNT_REALTIME_POLL_INTERVAL_MS;
  const rereadDelayMs = options.rereadDelayMs ?? ACCOUNT_REALTIME_PING_REREAD_DELAY_MS;
  /** The messages the last read saw (and the transport delivered since): a message gone from a read may arrive again. */
  let knownIdentities = new Set<string>();
  let stopped = false;
  let timer: ReturnType<typeof setTimeout> | null = null;

  const acceptArrivedMessage = (message: AgentRealtimeMessageDelivery): boolean => {
    const identity = identityOf(message);
    if (knownIdentities.has(identity)) return false;
    knownIdentities.add(identity);
    options.onMessage(message);
    return true;
  };

  const readNow = async (): Promise<number> => {
    const read = await options.readAccountRealtime();
    const identitiesOfRead = new Set(read.messages.map(identityOf));
    let arrivedCount = 0;
    for (const message of read.messages) {
      if (!knownIdentities.has(identityOf(message))) {
        arrivedCount += 1;
        options.onMessage(message);
      }
    }
    knownIdentities = identitiesOfRead;
    options.onListenerCredential?.(read.listenerCredential);
    return arrivedCount;
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
    stop: () => {
      stopped = true;
      if (timer !== null) clearTimeout(timer);
      timer = null;
    },
  };
};
