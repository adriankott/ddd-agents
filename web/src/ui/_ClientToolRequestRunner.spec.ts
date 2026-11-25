import assert from 'node:assert/strict';
import { describe, it } from "vitest";

import { ClientToolRequestRunner } from "./ClientToolRequestRunner";
import type { ClientToolAnswer, ScreenAdapters, ScreenNavigationBlocker, ScreenTarget } from "./client-tools";

const ORDER_TARGET: ScreenTarget = {
  key: 'order',
  label: 'Orders (order)',
  group: 'Sales',
  routePattern: '/orders/:orderId',
  parameters: ['orderId'],
  tabs: ['details', 'items'],
  filters: [],
  views: [],
};

const ORDERS_TARGET: ScreenTarget = {
  key: 'orders',
  label: 'Orders',
  group: null,
  routePattern: '/orders',
  parameters: [],
  tabs: ['create'],
  filters: [{ key: 'customerId' }],
  views: [],
};

const createAdapters = (blocker: ScreenNavigationBlocker | null = null) => {
  const opened: string[] = [];
  const marked: string[][] = [];
  const adapters: ScreenAdapters = {
    targets: { listTargets: () => [ORDERS_TARGET, ORDER_TARGET] },
    navigator: {
      describeBlockers: () => blocker,
      open: async (target, request) => {
        opened.push(`${target.key} ${request.recordIds.join(',')} ${request.tab ?? ''} ${JSON.stringify(request.filters)}`);
        return { route: '/orders/7/items', title: 'Order 7' };
      },
      currentLocation: () => ({ route: '/home', title: 'Home' }),
    },
    reader: {
      read: ({ focus }) => ({ markdown: focus ? `focus ${focus}` : '# Home', route: '/home', title: 'Home', references: {} }),
    },
    highlighter: {
      mark: references => {
        marked.push(references);
        return {
          marked: (references.includes('f.endDate') ? { 'f.endDate': 'End date' } : {}) as Record<string, string>,
          missing: references.filter(reference => reference !== 'f.endDate'),
          outOfView: [],
          collapsed: [],
        };
      },
      clear: () => undefined,
    },
    isVisible: () => true,
  };
  return { adapters, opened, marked };
};

const delivery = (requestType: string, parameters: Record<string, unknown>, secondsLeft = 10) => ({
  id: 1,
  requestType,
  argumentsJson: JSON.stringify(parameters),
  secondsLeft,
});

describe('ClientToolRequestRunner', () => {
  it('reads the screen, with focus when it is a valid reference', async () => {
    const runner = new ClientToolRequestRunner(createAdapters().adapters);
    assert.equal((await runner.run(delivery('READ_SCREEN', {}))).text, '# Home');
    assert.equal((await runner.run(delivery('READ_SCREEN', { focus: 'f.description' }))).text, 'focus f.description');
    assert.equal((await runner.run(delivery('READ_SCREEN', { focus: '<script>' }))).text, '# Home');
  });

  it('refuses a request that arrives with too few seconds left', async () => {
    const runner = new ClientToolRequestRunner(createAdapters().adapters);
    assert.equal((await runner.run(delivery('READ_SCREEN', {}, 2))).outcome, 'EXPIRED_BEFORE_START');
  });

  it('lists the targets filtered by every word', async () => {
    const runner = new ClientToolRequestRunner(createAdapters().adapters);
    const answer = await runner.run(delivery('LIST_TARGETS', { searchText: 'order items' }));
    assert.match(answer.text, /^Screens this user can open \(1\)/);
    assert.match(answer.text, /- order · Sales › Orders \(order\) \(orderId\) · tabs details, items/);
    assert.match((await runner.run(delivery('LIST_TARGETS', { searchText: 'nothing' }))).text, /No screen matches/);
  });

  it('opens a record screen with its tab and declared filters only', async () => {
    const { adapters, opened } = createAdapters();
    const runner = new ClientToolRequestRunner(adapters);
    const answer = await runner.run(delivery('OPEN_TARGET', { target: 'order', recordId: 7, tab: 'items' }));
    assert.equal(answer.outcome, 'OK');
    assert.deepEqual(answer.details, { route: '/orders/7/items', title: 'Order 7' });
    await runner.run(delivery('OPEN_TARGET', { target: 'orders', filters: ['customerId=3'] }));
    assert.deepEqual(opened, ['order 7 items {}', 'orders   {"customerId":"3"}']);
  });

  it('refuses unknown targets, tabs, filters and a missing record id without navigating', async () => {
    const { adapters, opened } = createAdapters();
    const runner = new ClientToolRequestRunner(adapters);
    const outcomes = await Promise.all([
      runner.run(delivery('OPEN_TARGET', { target: 'nope' })),
      runner.run(delivery('OPEN_TARGET', { target: 'order', recordId: 7, tab: 'secret' })),
      runner.run(delivery('OPEN_TARGET', { target: 'orders', filters: ['email=x'] })),
      runner.run(delivery('OPEN_TARGET', { target: 'order' })),
      runner.run(delivery('OPEN_TARGET', { target: '/admin/../x' })),
    ]);
    assert.deepEqual(
      outcomes.map(answer => answer.outcome),
      ['UNKNOWN_TARGET', 'UNKNOWN_TAB', 'UNKNOWN_FILTER', 'MISSING_PARAMETER', 'UNKNOWN_TARGET']
    );
    assert.deepEqual(opened, []);
  });

  it('never navigates while the screen has unsaved changes', async () => {
    const { adapters, opened } = createAdapters({ outcome: 'UNSAVED_CHANGES', reason: 'unsaved' });
    const answer = await new ClientToolRequestRunner(adapters).run(delivery('OPEN_TARGET', { target: 'orders' }));
    assert.equal(answer.outcome, 'UNSAVED_CHANGES');
    assert.deepEqual(opened, []);
  });

  it('marks what is on the screen and names what is not', async () => {
    const runner = new ClientToolRequestRunner(createAdapters().adapters);
    const answer = await runner.run(delivery('HIGHLIGHT', { references: ['f.endDate', 'b.save'], note: 'Hier' }));
    assert.equal(answer.outcome, 'OK');
    assert.equal(answer.text, 'Marked f.endDate "End date".\nNot on the screen: b.save.');
    assert.equal((await runner.run(delivery('HIGHLIGHT', { references: ['b.save'] }))).outcome, 'NOT_ON_SCREEN');
  });

  it('answers each request once, also when it is delivered again', async () => {
    const runner = new ClientToolRequestRunner(createAdapters().adapters);
    const answers: [number, ClientToolAnswer][] = [];
    const send = async (requestId: number, answer: ClientToolAnswer) => {
      answers.push([requestId, answer]);
    };
    runner.accept(delivery('READ_SCREEN', {}), send);
    runner.accept(delivery('READ_SCREEN', {}), send);
    await runner.whenIdle();
    assert.equal(answers.length, 1);
    assert.equal(answers[0]?.[1].outcome, 'OK');
  });

  it('answers an unknown request type with a refusal', async () => {
    const runner = new ClientToolRequestRunner(createAdapters().adapters);
    assert.equal((await runner.run(delivery('CAPTURE', {}))).outcome, 'UNKNOWN_REQUEST');
  });

  it('steps the agent window aside before opening, guides without waiting, and the guide never blocks the answer', async () => {
    const { adapters, opened } = createAdapters();
    const events: string[] = [];
    adapters.agentWindow = {
      stepAside: requestType => { events.push(`aside ${requestType}`); },
      restore: () => { events.push('restore'); },
      setMode: () => true,
    };
    adapters.pathGuide = {
      guide: () => { events.push('guide'); throw new Error('broken guide'); },
      cancel: () => undefined,
    };
    const runner = new ClientToolRequestRunner(adapters);
    const answer = await runner.run(delivery('OPEN_TARGET', { target: 'orders' }));
    assert.equal(answer.outcome, 'OK');
    assert.deepEqual(events, ['aside OPEN_TARGET', 'guide']);
    assert.equal(opened.length, 1);
  });

  it('sets the window mode, restores, and refuses unknown modes or a missing window', async () => {
    const { adapters } = createAdapters();
    const runnerWithoutWindow = new ClientToolRequestRunner(adapters);
    assert.equal((await runnerWithoutWindow.run(delivery('SET_WINDOW_MODE', { mode: 'aside' }))).outcome, 'UNKNOWN_MODE');
    const modes: string[] = [];
    adapters.agentWindow = {
      stepAside: () => undefined,
      restore: () => { modes.push('restore'); },
      setMode: mode => { modes.push(mode); return mode === 'aside'; },
    };
    const runner = new ClientToolRequestRunner(adapters);
    assert.equal((await runner.run(delivery('SET_WINDOW_MODE', { mode: 'aside' }))).outcome, 'OK');
    assert.equal((await runner.run(delivery('SET_WINDOW_MODE', { mode: 'restore' }))).outcome, 'OK');
    assert.equal((await runner.run(delivery('SET_WINDOW_MODE', { mode: 'huge' }))).outcome, 'UNKNOWN_MODE');
    assert.deepEqual(modes, ['aside', 'restore', 'huge']);
  });

  it('captures a picture with the window aside and restores it afterwards', async () => {
    const { adapters } = createAdapters();
    const runnerWithoutCapturer = new ClientToolRequestRunner(adapters);
    assert.equal((await runnerWithoutCapturer.run(delivery('READ_SCREEN', { format: 'picture' }))).outcome, 'PICTURE_UNAVAILABLE');
    const events: string[] = [];
    adapters.agentWindow = {
      stepAside: () => { events.push('aside'); },
      restore: () => { events.push('restore'); },
      setMode: () => true,
    };
    adapters.picture = {
      capture: async request => {
        events.push(`capture ${request.pictureSize} ${request.renderMode}`);
        return { base64: 'AAAA', mimeType: 'image/webp', width: 800, height: 600, byteSize: 3 };
      },
    };
    const runner = new ClientToolRequestRunner(adapters);
    const answer = await runner.run(delivery('READ_SCREEN', { format: 'picture', pictureSize: 'small', renderMode: 'machine' }));
    assert.equal(answer.outcome, 'OK');
    assert.equal(answer.pictureBase64, 'AAAA');
    assert.equal(answer.details.width, 800);
    assert.deepEqual(events, ['aside', 'capture small machine', 'restore']);
    const defaults = await runner.run(delivery('READ_SCREEN', { format: 'picture', pictureSize: 'huge' }));
    assert.equal(defaults.outcome, 'OK');
    assert.equal(events[4], 'capture medium colour');
  });

  it('runs a handler of the application for a further type, never for a type of the package', async () => {
    const { adapters } = createAdapters();
    adapters.requestHandlers = {
      PLAY_SOUND: parameters => ({ outcome: 'OK', text: `played ${String(parameters.sound)}`, details: {} }),
      READ_SCREEN: () => ({ outcome: 'OK', text: 'replaced', details: {} }),
    };
    const runner = new ClientToolRequestRunner(adapters);
    assert.equal((await runner.run(delivery('PLAY_SOUND', { sound: 'chime' }))).text, 'played chime');
    assert.equal((await runner.run(delivery('READ_SCREEN', {}))).text, '# Home');
  });
});
