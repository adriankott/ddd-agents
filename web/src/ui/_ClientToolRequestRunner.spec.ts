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
});
