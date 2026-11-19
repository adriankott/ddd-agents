import { act, cleanup, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, test, vi } from "vitest";
import { DEFAULT_AGENTS_COMPONENTS } from "./default-components";

/** The default split view: the open side pane narrows the main pane, and covers it below `coversBelowWidth`. */

const installManualResizeObserver = () => {
  const callbacks: ResizeObserverCallback[] = [];
  globalThis.ResizeObserver = class {
    constructor(callback: ResizeObserverCallback) {
      callbacks.push(callback);
    }
    observe = vi.fn();
    unobserve = vi.fn();
    disconnect = vi.fn();
  } as unknown as typeof ResizeObserver;
  return (width: number) => {
    for (const callback of callbacks) callback([{ contentRect: { width } } as ResizeObserverEntry], {} as ResizeObserver);
  };
};

describe("DefaultSplitView", () => {
  const originalResizeObserver = globalThis.ResizeObserver;

  afterEach(() => {
    cleanup();
    globalThis.ResizeObserver = originalResizeObserver;
  });

  test("narrows the main pane when wide and covers it below coversBelowWidth", () => {
    const reportWidth = installManualResizeObserver();
    const { SplitView } = DEFAULT_AGENTS_COMPONENTS;
    render(
      <SplitView
        isSideOpen
        coversBelowWidth={850}
        main={{ width: "100%", narrowedWidth: "60%", content: <span>chat</span> }}
        side={{ width: "40%", content: <span>canvas</span> }}
      />,
    );
    const mainPane = screen.getByText("chat").parentElement as HTMLElement;
    const sidePane = screen.getByText("canvas").parentElement as HTMLElement;

    act(() => reportWidth(1200));
    expect(mainPane.style.width).toBe("60%");
    expect(sidePane.style.position).toBe("");

    act(() => reportWidth(480));
    expect(mainPane.style.width).toBe("100%");
    expect(sidePane.style.position).toBe("absolute");
    expect(sidePane.style.width).toBe("100%");
  });
});
