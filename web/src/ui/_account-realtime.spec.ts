import { afterEach, describe, expect, test, vi } from "vitest";
import {
  ACCOUNT_REALTIME_PING_REREAD_DELAY_MS,
  createAccountRealtimePoll,
  type AccountRealtimeRead,
} from "./account-realtime";
import type { AgentRealtimeMessageDelivery } from "./realtime-arrival";

/**
 * The account-level realtime poll of a client that shows no conversation: a first read at once, a read every interval
 * while no transport is bound, none while one is; the arrival hook gets each message once (from the poll and from the
 * transport), a replacement and a message sent again after it was gone arrive again; a ping that overtook the write
 * reads once more.
 */
const ring = (messageKey: string, payloadJson = "{}"): AgentRealtimeMessageDelivery => ({
  messageType: "demo.voice.ring",
  messageKey,
  payloadJson,
  secondsLeft: 30,
});

const readOf = (...messages: AgentRealtimeMessageDelivery[]): AccountRealtimeRead => ({ messages, listenerCredential: null });

afterEach(() => {
  vi.useRealTimers();
});

describe("createAccountRealtimePoll", () => {
  test("reads at once and every interval while no transport is bound, never while one is", async () => {
    vi.useFakeTimers();
    let transportBound = false;
    const readAccountRealtime = vi.fn(async () => readOf());
    const poll = createAccountRealtimePoll({ readAccountRealtime, onMessage: () => undefined, isTransportBound: () => transportBound, intervalMs: 1000 });

    await vi.advanceTimersByTimeAsync(0);
    expect(readAccountRealtime).toHaveBeenCalledTimes(1);
    await vi.advanceTimersByTimeAsync(1000);
    expect(readAccountRealtime).toHaveBeenCalledTimes(2);

    transportBound = true;
    await vi.advanceTimersByTimeAsync(3000);
    expect(readAccountRealtime).toHaveBeenCalledTimes(2);

    transportBound = false;
    await vi.advanceTimersByTimeAsync(1000);
    expect(readAccountRealtime).toHaveBeenCalledTimes(3);

    poll.stop();
    await vi.advanceTimersByTimeAsync(5000);
    expect(readAccountRealtime).toHaveBeenCalledTimes(3);
  });

  test("the arrival hook gets each message once, a replacement and a re-sent message again", async () => {
    vi.useFakeTimers();
    const reads = [readOf(ring("call-1")), readOf(ring("call-1")), readOf(ring("call-1", '{"n":2}')), readOf(), readOf(ring("call-1"))];
    const arrived: string[] = [];
    const credentials: unknown[] = [];
    const poll = createAccountRealtimePoll({
      readAccountRealtime: async () => reads.shift() ?? readOf(),
      onMessage: message => arrived.push(`${message.messageKey}${message.payloadJson}`),
      onListenerCredential: credential => credentials.push(credential),
      intervalMs: 1000,
    });

    await vi.advanceTimersByTimeAsync(4000);
    poll.stop();

    expect(arrived).toEqual(["call-1{}", 'call-1{"n":2}', "call-1{}"]);
    expect(credentials).toEqual([null, null, null, null, null]);
  });

  test("a message the transport delivered does not arrive again from the poll", async () => {
    vi.useFakeTimers();
    const onMessage = vi.fn();
    const reads = [readOf(), readOf(ring("call-1"))];
    const poll = createAccountRealtimePoll({ readAccountRealtime: async () => reads.shift() ?? readOf(ring("call-1")), onMessage, intervalMs: 1000 });
    await vi.advanceTimersByTimeAsync(0);

    expect(poll.acceptArrivedMessage(ring("call-1"))).toBe(true);
    expect(poll.acceptArrivedMessage(ring("call-1"))).toBe(false);
    await vi.advanceTimersByTimeAsync(1000);
    poll.stop();

    expect(onMessage).toHaveBeenCalledTimes(1);
  });

  test("a ping whose read brought nothing reads exactly once more after the delay", async () => {
    vi.useFakeTimers();
    const reads = [readOf(), readOf(), readOf(ring("call-1"))];
    const readAccountRealtime = vi.fn(async () => reads.shift() ?? readOf(ring("call-1")));
    const onMessage = vi.fn();
    const poll = createAccountRealtimePoll({ readAccountRealtime, onMessage, isTransportBound: () => true });
    await vi.advanceTimersByTimeAsync(0);
    expect(readAccountRealtime).toHaveBeenCalledTimes(1);

    const arrived = poll.readAfterPing();
    await vi.advanceTimersByTimeAsync(ACCOUNT_REALTIME_PING_REREAD_DELAY_MS - 1);
    expect(readAccountRealtime).toHaveBeenCalledTimes(2);
    await vi.advanceTimersByTimeAsync(1);

    await expect(arrived).resolves.toBe(true);
    expect(readAccountRealtime).toHaveBeenCalledTimes(3);
    expect(onMessage).toHaveBeenCalledTimes(1);
    poll.stop();
  });

  test("a failed read is reported and the next interval reads again", async () => {
    vi.useFakeTimers();
    const onReadFailed = vi.fn();
    let readCount = 0;
    const poll = createAccountRealtimePoll({
      readAccountRealtime: async () => {
        readCount += 1;
        if (readCount === 1) throw new Error("offline");
        return readOf();
      },
      onMessage: () => undefined,
      onReadFailed,
      intervalMs: 1000,
    });
    await vi.advanceTimersByTimeAsync(1000);
    poll.stop();

    expect(onReadFailed).toHaveBeenCalledTimes(1);
    expect(readCount).toBe(2);
  });
});
