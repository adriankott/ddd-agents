// Spec support for the modules that import React at runtime (the call store, the incoming ring store and what imports
// them). No source imports this file, and no entry reaches it.
//
// The store modules use React only inside their hooks (`useSyncExternalStore`, `useEffect`); their module bodies never
// call it. A spec that loads them replaces `react` with the stand-in below, so their plain functions run outside a
// render:
//
//   vi.mock("react", async () => (await import("./agent-voice-call-store.spec-helper")).createFakeReactModule());
//
// The stand-in's `useSyncExternalStore` hands back the store's own `subscribe`, which is how a spec counts the store's
// notifications. No hook of the package is tested through the stand-in.
//
// WebRTC, the microphone, the audio element and `window` (timers, `pagehide`) are staged as plain fakes
// (`installFakeBrowser`). `window`'s timer functions call the global ones, which the specs replace with vitest's fake
// timers.
import { vi } from "vitest";
import type { AgentVoiceApi } from "../agent-voice-api";

export interface StoreHandle<Snapshot> {
  subscribe: (listener: () => void) => () => void;
  getSnapshot: () => Snapshot;
}

/** Every `useSyncExternalStore` call of the stand-in, in order. */
const storeHandles: StoreHandle<unknown>[] = [];

/** The stand-in for `react` (see the header): the hooks the store modules call, nothing that renders. */
export const createFakeReactModule = () => {
  const useSyncExternalStore = <Snapshot>(subscribe: StoreHandle<Snapshot>["subscribe"], getSnapshot: () => Snapshot) => {
    storeHandles.push({ subscribe, getSnapshot });
    return getSnapshot();
  };
  const useEffect = () => undefined;
  const useState = <Value>(initial: Value | (() => Value)) => [typeof initial === "function" ? (initial as () => Value)() : initial, () => undefined];
  const useMemo = <Value>(build: () => Value) => build();
  const useCallback = <Callback>(callback: Callback) => callback;
  const useRef = <Value>(current: Value) => ({ current });
  const useId = () => "test-id";
  const createContext = <Value>(defaultValue: Value) => ({ defaultValue });
  const useContext = <Value>(context: { defaultValue: Value }) => context.defaultValue;
  const hooks = { useSyncExternalStore, useEffect, useState, useMemo, useCallback, useRef, useId, createContext, useContext };
  return { ...hooks, default: hooks };
};

/** Calls a store hook once through the stand-in and returns the store's `subscribe` and `getSnapshot`. */
export const readStoreHandle = <Snapshot>(callHook: () => unknown): StoreHandle<Snapshot> => {
  const countBefore = storeHandles.length;
  callHook();
  if (storeHandles.length !== countBefore + 1) throw new Error("the hook did not call useSyncExternalStore once");
  return storeHandles[storeHandles.length - 1] as StoreHandle<Snapshot>;
};

/** Counts how often the store tells its subscribers; `stop` unsubscribes. */
export const countNotifications = (handle: StoreHandle<unknown>) => {
  const counter = { count: 0, stop: () => undefined as void };
  counter.stop = handle.subscribe(() => {
    counter.count += 1;
  });
  return counter;
};

// ---------------------------------------------------------------------------------------------------------------- browser

export class FakeAudioTrack {
  enabled = true;
  stopped = false;
  label = "fake microphone";
  muted = false;
  readyState = "live";

  stop() {
    this.stopped = true;
  }
}

export class FakeMicrophoneStream {
  readonly tracks = [new FakeAudioTrack()];

  getTracks() {
    return this.tracks;
  }

  getAudioTracks() {
    return this.tracks;
  }
}

export class FakeDataChannel {
  readonly label: string;
  readyState = "open";
  readonly sentMessages: string[] = [];
  closed = false;
  onmessage: ((messageEvent: { data: string }) => void) | null = null;
  onclose: (() => void) | null = null;

  constructor(label: string) {
    this.label = label;
  }

  send(message: string) {
    this.sentMessages.push(message);
  }

  close() {
    this.closed = true;
    this.readyState = "closed";
  }

  /** The provider sends one event over the channel. */
  deliverProviderEvent(providerEvent: unknown) {
    this.onmessage?.({ data: typeof providerEvent === "string" ? providerEvent : JSON.stringify(providerEvent) });
  }

  /** The provider closes the channel (not this browser). */
  closeFromProvider() {
    this.readyState = "closed";
    this.onclose?.();
  }
}

export interface FakeStatsReport {
  type: string;
  kind?: string;
  bytesSent?: number;
  bytesReceived?: number;
  audioLevel?: number;
}

export class FakePeerConnection {
  static readonly instances: FakePeerConnection[] = [];
  iceGatheringState = "complete";
  iceConnectionState = "new";
  localDescription: { type: string; sdp: string } | null = null;
  remoteDescription: { type: string; sdp: string } | null = null;
  readonly addedTracks: unknown[] = [];
  dataChannel: FakeDataChannel | undefined;
  closed = false;
  statsReports: FakeStatsReport[] = [];
  ontrack: ((trackEvent: { streams: unknown[] }) => void) | null = null;
  oniceconnectionstatechange: (() => void) | null = null;

  constructor() {
    FakePeerConnection.instances.push(this);
  }

  addTrack(track: unknown) {
    this.addedTracks.push(track);
  }

  createDataChannel(label: string) {
    this.dataChannel = new FakeDataChannel(label);
    return this.dataChannel;
  }

  async createOffer() {
    return { type: "offer", sdp: "v=0 fake offer" };
  }

  async setLocalDescription(description: { type: string; sdp: string }) {
    this.localDescription = description;
  }

  async setRemoteDescription(description: { type: string; sdp: string }) {
    this.remoteDescription = description;
  }

  addEventListener() {
    return undefined;
  }

  close() {
    this.closed = true;
  }

  async getStats() {
    return new Map(this.statsReports.map((report, index) => [String(index), report]));
  }

  changeIceConnectionState(iceConnectionState: string) {
    this.iceConnectionState = iceConnectionState;
    this.oniceconnectionstatechange?.();
  }
}

export class FakeAudioElement {
  static readonly instances: FakeAudioElement[] = [];
  autoplay = false;
  srcObject: unknown = null;
  paused = false;

  constructor() {
    FakeAudioElement.instances.push(this);
  }

  pause() {
    this.paused = true;
  }
}

export interface FakeBrowser {
  /** What `getUserMedia` answers next: a stream, or a rejection (the owner refused the microphone). */
  microphoneAnswer: "granted" | "denied";
  readonly microphoneRequests: unknown[];
  readonly microphoneStreams: FakeMicrophoneStream[];
  readonly pageHideListeners: (() => void)[];
  lastPeerConnection: () => FakePeerConnection;
}

/** Puts `window`, `navigator.mediaDevices`, `RTCPeerConnection` and `Audio` in place as fakes, for the spec file. */
export const installFakeBrowser = (): FakeBrowser => {
  const browser: FakeBrowser = {
    microphoneAnswer: "granted",
    microphoneRequests: [],
    microphoneStreams: [],
    pageHideListeners: [],
    lastPeerConnection: () => {
      const connection = FakePeerConnection.instances[FakePeerConnection.instances.length - 1];
      if (connection === undefined) throw new Error("no peer connection was created");
      return connection;
    },
  };
  const fakeWindow = {
    setTimeout: (callback: () => void, delayMs?: number) => globalThis.setTimeout(callback, delayMs),
    clearTimeout: (timer: unknown) => globalThis.clearTimeout(timer as ReturnType<typeof setTimeout>),
    setInterval: (callback: () => void, delayMs?: number) => globalThis.setInterval(callback, delayMs),
    clearInterval: (timer: unknown) => globalThis.clearInterval(timer as ReturnType<typeof setInterval>),
    addEventListener: (type: string, listener: () => void) => {
      if (type === "pagehide") browser.pageHideListeners.push(listener);
    },
  };
  vi.stubGlobal("window", fakeWindow);
  vi.stubGlobal("RTCPeerConnection", FakePeerConnection);
  vi.stubGlobal("Audio", FakeAudioElement);
  Object.defineProperty(globalThis.navigator, "mediaDevices", {
    configurable: true,
    value: {
      getUserMedia: async (constraints: unknown) => {
        browser.microphoneRequests.push(constraints);
        if (browser.microphoneAnswer === "denied") throw new Error("NotAllowedError");
        const stream = new FakeMicrophoneStream();
        browser.microphoneStreams.push(stream);
        return stream;
      },
    },
  });
  return browser;
};

/** Lets every pending promise reaction and I/O callback run (the specs' fake timers leave `setImmediate` real). */
export const flushPromises = async (rounds = 10) => {
  for (let round = 0; round < rounds; round += 1) {
    await new Promise<void>(resolve => setImmediate(resolve));
  }
};

// ------------------------------------------------------------------------------------------------------------ the adapter

export interface RecordedApiCall {
  operation: keyof AgentVoiceApi;
  args: Record<string, unknown>;
}

export interface Deferred<Value> {
  promise: Promise<Value>;
  resolve: (value: Value) => void;
  reject: (reason: unknown) => void;
}

export const createDeferred = <Value>(): Deferred<Value> => {
  const deferred = {} as Deferred<Value>;
  deferred.promise = new Promise<Value>((resolve, reject) => {
    deferred.resolve = resolve;
    deferred.reject = reject;
  });
  return deferred;
};

type OpenSessionAnswer = Awaited<ReturnType<AgentVoiceApi["openSession"]>>;
type CallStateAnswer = Awaited<ReturnType<AgentVoiceApi["readCallState"]>>;
type RingAnswer = Awaited<ReturnType<AgentVoiceApi["answerRing"]>>;

/**
 * An adapter that records every call in order and answers from the queues below (a value, or a function that may
 * throw or return a pending promise). An empty queue answers the default.
 */
export class RecordingAgentVoiceApi implements AgentVoiceApi {
  readonly calls: RecordedApiCall[] = [];
  readonly openSessionAnswers: (() => Promise<OpenSessionAnswer>)[] = [];
  readonly callStateAnswers: (() => Promise<CallStateAnswer>)[] = [];
  readonly ringAnswers: (() => Promise<RingAnswer>)[] = [];
  defaultOpenSessionAnswer: OpenSessionAnswer = { callId: 9, sdpAnswer: "v=0 fake answer", conversationId: 41 };

  callsOf(operation: keyof AgentVoiceApi) {
    return this.calls.filter(call => call.operation === operation).map(call => call.args);
  }

  /** The `status` of every device status report, in order. */
  reportedStatuses() {
    return this.callsOf("reportDeviceStatus").map(args => args.status);
  }

  async openSession(args: Parameters<AgentVoiceApi["openSession"]>[0]) {
    this.calls.push({ operation: "openSession", args });
    const answer = this.openSessionAnswers.shift();
    return answer ? answer() : this.defaultOpenSessionAnswer;
  }

  async hangUp(args: Parameters<AgentVoiceApi["hangUp"]>[0]) {
    this.calls.push({ operation: "hangUp", args });
  }

  async reportDeviceStatus(args: Parameters<AgentVoiceApi["reportDeviceStatus"]>[0]) {
    this.calls.push({ operation: "reportDeviceStatus", args });
  }

  async readCallState(args: Parameters<AgentVoiceApi["readCallState"]>[0]) {
    this.calls.push({ operation: "readCallState", args });
    const answer = this.callStateAnswers.shift();
    return answer ? answer() : { callId: args.callId };
  }

  async answerRing(args: Parameters<AgentVoiceApi["answerRing"]>[0]) {
    this.calls.push({ operation: "answerRing", args });
    const answer = this.ringAnswers.shift();
    return answer ? answer() : { won: true, conversationId: 41 };
  }

  async declineRing(args: Parameters<AgentVoiceApi["declineRing"]>[0]) {
    this.calls.push({ operation: "declineRing", args });
  }

  forget() {
    this.calls.length = 0;
  }
}
