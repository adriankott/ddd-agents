import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { type AccountRealtimePoll, type AccountRealtimePollSubscriber, type AccountRealtimeRead, createAccountRealtimePoll } from "../../ui/account-realtime";
import type { AgentRealtimeMessageDelivery } from "../../ui/realtime-arrival";
import { AGENT_VOICE_RING_MESSAGE_TYPE, type AgentVoiceRing } from "./agent-voice-ring";
import { AgentVoiceAccountRealtimeRingSource, createAgentVoiceRingSource } from "./agent-voice-ring-source";

const NOW_MS = 1_767_259_000_000;

const ringMessage = (callId: number, ringExpiresAtUnixMs: number, answerToken = `token-${callId}`): AgentRealtimeMessageDelivery => ({
  messageType: AGENT_VOICE_RING_MESSAGE_TYPE,
  messageKey: String(callId),
  payloadJson: JSON.stringify({ callId, conversationId: 41, answerToken, ringExpiresAt: "", ringExpiresAtUnixMs, callerLabelKey: "", callerContext: {} }),
  secondsLeft: 30,
});

/** A poll that only records: who subscribed, who left, whether it was stopped; the spec delivers and clears. */
class FakeAccountRealtimePoll implements AccountRealtimePoll {
  readonly subscribers = new Set<AccountRealtimePollSubscriber>();
  subscribeCount = 0;
  unsubscribeCount = 0;
  stopCount = 0;

  readNow = async () => 0;
  acceptArrivedMessage = () => true;
  readAfterPing = async () => false;

  subscribe = (subscriber: AccountRealtimePollSubscriber) => {
    this.subscribeCount += 1;
    this.subscribers.add(subscriber);
    return () => {
      this.unsubscribeCount += 1;
      this.subscribers.delete(subscriber);
    };
  };

  stop = () => {
    this.stopCount += 1;
  };

  deliver(message: AgentRealtimeMessageDelivery) {
    this.subscribers.forEach(subscriber => subscriber.onMessage?.(message));
  }

  clear(message: AgentRealtimeMessageDelivery) {
    this.subscribers.forEach(subscriber => subscriber.onMessageCleared?.(message));
  }
}

/** The starter of the account realtime poll: counts starts and stops and keeps the arrival hook of the running poll. */
class FakePollStarter {
  starts = 0;
  stops = 0;
  deliver: ((message: AgentRealtimeMessageDelivery) => void) | undefined;
  startedPoll: FakeAccountRealtimePoll | undefined;

  start = (onMessage: (message: AgentRealtimeMessageDelivery) => void) => {
    this.starts += 1;
    this.deliver = onMessage;
    const startedPoll = new FakeAccountRealtimePoll();
    startedPoll.stop = () => {
      startedPoll.stopCount += 1;
      this.stops += 1;
      this.deliver = undefined;
    };
    this.startedPoll = startedPoll;
    return startedPoll;
  };
}

interface RecordingSubscriber {
  rings: AgentVoiceRing[];
  clearedCallIds: number[];
  onRing: (ring: AgentVoiceRing) => void;
  onRingCleared: (callId: number) => void;
}

const recordingSubscriber = (): RecordingSubscriber => {
  const subscriber: RecordingSubscriber = {
    rings: [],
    clearedCallIds: [],
    onRing: ring => subscriber.rings.push(ring),
    onRingCleared: callId => subscriber.clearedCallIds.push(callId),
  };
  return subscriber;
};

describe("AgentVoiceAccountRealtimeRingSource, given a function that starts the poll", () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["Date"], now: NOW_MS });
  });
  afterEach(() => {
    vi.useRealTimers();
  });

  it("starts the poll with the first subscriber and stops it with the last", () => {
    const pollStarter = new FakePollStarter();
    const ringSource = createAgentVoiceRingSource(pollStarter.start);
    expect(ringSource).toBeInstanceOf(AgentVoiceAccountRealtimeRingSource);
    expect(pollStarter.starts).toBe(0);
    const first = recordingSubscriber();
    const second = recordingSubscriber();
    const unsubscribeFirst = ringSource.subscribe(first.onRing, first.onRingCleared);
    const unsubscribeSecond = ringSource.subscribe(second.onRing, second.onRingCleared);
    expect(pollStarter.starts).toBe(1);
    unsubscribeFirst();
    expect(pollStarter.stops).toBe(0);
    unsubscribeSecond();
    expect(pollStarter.stops).toBe(1);
  });

  it("hands every ring message to every subscriber as a ring, and nothing else", () => {
    const pollStarter = new FakePollStarter();
    const ringSource = new AgentVoiceAccountRealtimeRingSource(pollStarter.start);
    const first = recordingSubscriber();
    const second = recordingSubscriber();
    ringSource.subscribe(first.onRing, first.onRingCleared);
    ringSource.subscribe(second.onRing, second.onRingCleared);
    pollStarter.deliver?.(ringMessage(8815, NOW_MS + 30_000));
    pollStarter.deliver?.({ messageType: "agent.client.tool", messageKey: "1", payloadJson: "{}", secondsLeft: 10 });
    expect(first.rings.map(ring => ring.callId)).toStrictEqual([8815]);
    expect(second.rings.map(ring => ring.callId)).toStrictEqual([8815]);
    expect(first.rings[0].answerToken).toBe("token-8815");
  });

  it("answers whether a message was a ring", () => {
    const ringSource = new AgentVoiceAccountRealtimeRingSource(new FakePollStarter().start);
    expect(ringSource.acceptRealtimeMessage(ringMessage(8815, NOW_MS + 30_000))).toBe(true);
    expect(ringSource.acceptRealtimeMessage({ messageType: "agent.client.tool", messageKey: "1", payloadJson: "{}", secondsLeft: 10 })).toBe(false);
  });

  it("gives a later subscriber the rings of the running poll that are still inside their deadline, the newest payload per call", () => {
    const pollStarter = new FakePollStarter();
    const ringSource = new AgentVoiceAccountRealtimeRingSource(pollStarter.start);
    const first = recordingSubscriber();
    ringSource.subscribe(first.onRing, first.onRingCleared);
    pollStarter.deliver?.(ringMessage(8815, NOW_MS + 30_000, "first-token"));
    pollStarter.deliver?.(ringMessage(8815, NOW_MS + 60_000, "replaced-token"));
    pollStarter.deliver?.(ringMessage(8816, NOW_MS + 1_000));
    vi.setSystemTime(NOW_MS + 1_000);
    const later = recordingSubscriber();
    ringSource.subscribe(later.onRing, later.onRingCleared);
    expect(later.rings.map(ring => [ring.callId, ring.answerToken])).toStrictEqual([[8815, "replaced-token"]]);
    expect(pollStarter.starts).toBe(1);
  });

  it("forgets the rings when the last subscriber leaves, and a new poll starts empty", () => {
    const pollStarter = new FakePollStarter();
    const ringSource = new AgentVoiceAccountRealtimeRingSource(pollStarter.start);
    const first = recordingSubscriber();
    const unsubscribe = ringSource.subscribe(first.onRing, first.onRingCleared);
    pollStarter.deliver?.(ringMessage(8815, NOW_MS + 30_000));
    unsubscribe();
    const later = recordingSubscriber();
    ringSource.subscribe(later.onRing, later.onRingCleared);
    expect(later.rings).toStrictEqual([]);
    expect(pollStarter.starts).toBe(2);
  });

  it("stops telling a subscriber that left", () => {
    const pollStarter = new FakePollStarter();
    const ringSource = new AgentVoiceAccountRealtimeRingSource(pollStarter.start);
    const staying = recordingSubscriber();
    const leaving = recordingSubscriber();
    ringSource.subscribe(staying.onRing, staying.onRingCleared);
    const unsubscribeLeaving = ringSource.subscribe(leaving.onRing, leaving.onRingCleared);
    unsubscribeLeaving();
    pollStarter.deliver?.(ringMessage(8815, NOW_MS + 30_000));
    expect(staying.rings.length).toBe(1);
    expect(leaving.rings.length).toBe(0);
  });

  it("tells no later subscriber the clear of a ring past its deadline when it came, also with a started poll", () => {
    const pollStarter = new FakePollStarter();
    const ringSource = new AgentVoiceAccountRealtimeRingSource(pollStarter.start);
    const first = recordingSubscriber();
    ringSource.subscribe(first.onRing, first.onRingCleared);
    pollStarter.deliver?.(ringMessage(8815, NOW_MS + 5_000));
    vi.setSystemTime(NOW_MS + 10_000);
    const later = recordingSubscriber();
    ringSource.subscribe(later.onRing, later.onRingCleared);
    pollStarter.startedPoll?.clear(ringMessage(8815, NOW_MS + 5_000));

    expect(first.clearedCallIds).toStrictEqual([8815]);
    expect(later.rings).toStrictEqual([]);
    expect(later.clearedCallIds).toStrictEqual([]);
  });

  it("hands a ring the started poll clears to every subscriber as cleared, through the started poll's subscribe", () => {
    const pollStarter = new FakePollStarter();
    const ringSource = new AgentVoiceAccountRealtimeRingSource(pollStarter.start);
    const subscriber = recordingSubscriber();
    const unsubscribe = ringSource.subscribe(subscriber.onRing, subscriber.onRingCleared);
    expect(pollStarter.startedPoll?.subscribeCount).toBe(1);
    pollStarter.deliver?.(ringMessage(8815, NOW_MS + 30_000));
    pollStarter.startedPoll?.clear(ringMessage(8815, NOW_MS + 30_000));
    expect(subscriber.clearedCallIds).toStrictEqual([8815]);

    const startedPoll = pollStarter.startedPoll;
    unsubscribe();
    expect(startedPoll?.unsubscribeCount).toBe(1);
    expect(startedPoll?.stopCount).toBe(1);
  });
});

/** An account read that answers from the list, then with no message. */
const accountReadOf = (...reads: AgentRealtimeMessageDelivery[][]) => async (): Promise<AccountRealtimeRead> => ({
  messages: reads.shift() ?? [],
  listenerCredential: null,
});

/** Lets the poll's first read answer (only `Date` is faked here). */
const settle = () => new Promise<void>(resolve => setTimeout(resolve, 0));

describe("AgentVoiceAccountRealtimeRingSource, given the poll", () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["Date"], now: NOW_MS });
  });
  afterEach(() => {
    vi.useRealTimers();
  });

  it("subscribes to the poll with the first subscriber and leaves it with the last, without stopping it", () => {
    const poll = new FakeAccountRealtimePoll();
    const ringSource = createAgentVoiceRingSource(poll);
    expect(ringSource).toBeInstanceOf(AgentVoiceAccountRealtimeRingSource);
    expect(poll.subscribeCount).toBe(0);
    const first = recordingSubscriber();
    const second = recordingSubscriber();
    const unsubscribeFirst = ringSource.subscribe(first.onRing, first.onRingCleared);
    const unsubscribeSecond = ringSource.subscribe(second.onRing, second.onRingCleared);
    expect(poll.subscribeCount).toBe(1);
    unsubscribeFirst();
    expect(poll.unsubscribeCount).toBe(0);
    unsubscribeSecond();
    expect(poll.unsubscribeCount).toBe(1);
    expect(poll.stopCount).toBe(0);

    ringSource.subscribe(first.onRing, first.onRingCleared);
    expect(poll.subscribeCount).toBe(2);
  });

  it("hands over a ring the poll had open before the subscriber came", async () => {
    const poll = createAccountRealtimePoll({ readAccountRealtime: accountReadOf([ringMessage(8815, NOW_MS + 30_000)]), isTransportBound: () => true });
    await settle();
    const subscriber = recordingSubscriber();
    createAgentVoiceRingSource(poll).subscribe(subscriber.onRing, subscriber.onRingCleared);
    poll.stop();

    expect(subscriber.rings.map(ring => ring.callId)).toStrictEqual([8815]);
  });

  it("tells a ring the poll's read found gone as cleared, and hands it to no later subscriber", async () => {
    const poll = createAccountRealtimePoll({
      readAccountRealtime: accountReadOf([ringMessage(8815, NOW_MS + 30_000), ringMessage(8816, NOW_MS + 30_000)], [ringMessage(8816, NOW_MS + 30_000)]),
      isTransportBound: () => true,
    });
    const ringSource = createAgentVoiceRingSource(poll);
    const first = recordingSubscriber();
    ringSource.subscribe(first.onRing, first.onRingCleared);
    await settle();
    await poll.readNow();
    const later = recordingSubscriber();
    ringSource.subscribe(later.onRing, later.onRingCleared);
    poll.stop();

    expect(first.rings.map(ring => ring.callId)).toStrictEqual([8815, 8816]);
    expect(first.clearedCallIds).toStrictEqual([8815]);
    expect(later.rings.map(ring => ring.callId)).toStrictEqual([8816]);
    expect(later.clearedCallIds).toStrictEqual([]);
  });

  it("tells no subscriber the clear of a ring it never saw", async () => {
    const shortRing = { ...ringMessage(8816, NOW_MS + 5_000), secondsLeft: 5 };
    const poll = createAccountRealtimePoll({
      readAccountRealtime: accountReadOf([ringMessage(8815, NOW_MS + 30_000), shortRing], []),
      isTransportBound: () => true,
    });
    await settle();
    vi.setSystemTime(NOW_MS + 10_000);
    const ringSource = createAgentVoiceRingSource(poll);
    const subscriber = recordingSubscriber();
    ringSource.subscribe(subscriber.onRing, subscriber.onRingCleared);
    await poll.readNow();
    poll.stop();

    expect(subscriber.rings.map(ring => ring.callId)).toStrictEqual([8815]);
    expect(subscriber.clearedCallIds).toStrictEqual([8815]);
  });

  it("tells no later subscriber of the ring source the clear of a ring past its deadline when it came", async () => {
    const poll = createAccountRealtimePoll({
      readAccountRealtime: accountReadOf([ringMessage(8815, NOW_MS + 5_000)], []),
      isTransportBound: () => true,
    });
    const ringSource = createAgentVoiceRingSource(poll);
    const first = recordingSubscriber();
    ringSource.subscribe(first.onRing, first.onRingCleared);
    await settle();
    vi.setSystemTime(NOW_MS + 10_000);
    const later = recordingSubscriber();
    ringSource.subscribe(later.onRing, later.onRingCleared);
    await poll.readNow();
    poll.stop();

    expect(first.clearedCallIds).toStrictEqual([8815]);
    expect(later.rings).toStrictEqual([]);
    expect(later.clearedCallIds).toStrictEqual([]);
  });

  it("tells a later subscriber the clear of a ring it got in the hand-over", () => {
    const poll = new FakeAccountRealtimePoll();
    const ringSource = createAgentVoiceRingSource(poll);
    const first = recordingSubscriber();
    ringSource.subscribe(first.onRing, first.onRingCleared);
    poll.deliver(ringMessage(8815, NOW_MS + 30_000));
    const later = recordingSubscriber();
    ringSource.subscribe(later.onRing, later.onRingCleared);
    poll.clear(ringMessage(8815, NOW_MS + 30_000));

    expect(later.rings.map(ring => ring.callId)).toStrictEqual([8815]);
    expect(later.clearedCallIds).toStrictEqual([8815]);
  });

  it("tells a subscriber that came after the first ring's deadline the clear once it was handed the replacement", () => {
    const poll = new FakeAccountRealtimePoll();
    const ringSource = createAgentVoiceRingSource(poll);
    const first = recordingSubscriber();
    ringSource.subscribe(first.onRing, first.onRingCleared);
    poll.deliver(ringMessage(8815, NOW_MS + 5_000, "first-token"));
    vi.setSystemTime(NOW_MS + 10_000);
    const later = recordingSubscriber();
    ringSource.subscribe(later.onRing, later.onRingCleared);
    expect(later.rings).toStrictEqual([]);

    poll.deliver(ringMessage(8815, NOW_MS + 60_000, "replaced-token"));
    poll.clear(ringMessage(8815, NOW_MS + 60_000, "replaced-token"));

    expect(later.rings.map(ring => ring.answerToken)).toStrictEqual(["replaced-token"]);
    expect(first.clearedCallIds).toStrictEqual([8815]);
    expect(later.clearedCallIds).toStrictEqual([8815]);
  });

  it("tells nobody the clear of a call it holds no ring of, and still answers that the message was a ring", () => {
    const poll = new FakeAccountRealtimePoll();
    const ringSource = new AgentVoiceAccountRealtimeRingSource(poll);
    const subscriber = recordingSubscriber();
    ringSource.subscribe(subscriber.onRing, subscriber.onRingCleared);
    poll.deliver(ringMessage(8815, NOW_MS + 30_000));

    expect(ringSource.acceptClearedRealtimeMessage(ringMessage(9999, NOW_MS + 30_000))).toBe(true);
    expect(subscriber.clearedCallIds).toStrictEqual([]);
    expect(ringSource.acceptClearedRealtimeMessage(ringMessage(8815, NOW_MS + 30_000))).toBe(true);
    expect(ringSource.acceptClearedRealtimeMessage(ringMessage(8815, NOW_MS + 30_000))).toBe(true);
    expect(subscriber.clearedCallIds).toStrictEqual([8815]);
  });

  it("does not clear a replaced ring: the replacement arrives as a ring", async () => {
    const poll = createAccountRealtimePoll({
      readAccountRealtime: accountReadOf([ringMessage(8815, NOW_MS + 30_000, "first-token")], [ringMessage(8815, NOW_MS + 60_000, "replaced-token")]),
      isTransportBound: () => true,
    });
    const subscriber = recordingSubscriber();
    createAgentVoiceRingSource(poll).subscribe(subscriber.onRing, subscriber.onRingCleared);
    await settle();
    await poll.readNow();
    poll.stop();

    expect(subscriber.rings.map(ring => ring.answerToken)).toStrictEqual(["first-token", "replaced-token"]);
    expect(subscriber.clearedCallIds).toStrictEqual([]);
  });

  it("ignores a cleared message of another type", () => {
    const poll = new FakeAccountRealtimePoll();
    const subscriber = recordingSubscriber();
    createAgentVoiceRingSource(poll).subscribe(subscriber.onRing, subscriber.onRingCleared);
    poll.clear({ messageType: "agent.client.tool", messageKey: "8815", payloadJson: "{}", secondsLeft: 10 });

    expect(subscriber.clearedCallIds).toStrictEqual([]);
  });
});

describe("AgentVoiceAccountRealtimeRingSource.acceptClearedRealtimeMessage", () => {
  it("answers whether the cleared message was a ring", () => {
    const ringSource = new AgentVoiceAccountRealtimeRingSource(new FakeAccountRealtimePoll());
    expect(ringSource.acceptClearedRealtimeMessage(ringMessage(8815, NOW_MS + 30_000))).toBe(true);
    expect(ringSource.acceptClearedRealtimeMessage({ messageType: "agent.client.tool", messageKey: "1", payloadJson: "{}", secondsLeft: 10 })).toBe(false);
  });
});
